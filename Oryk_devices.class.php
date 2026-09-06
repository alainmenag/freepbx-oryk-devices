<?php

// Oryk_devices.class.php

namespace FreePBX\modules;

use BMO;
use PDO;
use FreePBX_Helpers;
use FreePBX\Modules\Oryk_Devices\CdrHistory;
use FreePBX\Modules\Oryk_Devices\DeviceSchema;
use FreePBX\Modules\Oryk_Devices\ExtensionManager;
use FreePBX\Modules\Oryk_Devices\ExtensionRenumberer;
use FreePBX\Modules\Oryk_Devices\NumberAllocator;
use FreePBX\Modules\Oryk_Devices\UcpAssignments;
use FreePBX\Modules\Oryk_Devices\UsermanManager;
use FreePBX\Modules\Oryk_Devices\VoicemailManager;

if (!class_exists('FreePBX\\Modules\\Core\\Driver', false)) {
	require_once(\FreePBX::Config()->get('AMPWEBROOT') . '/admin/modules/core/functions.inc/Driver.class.php');
}

require_once __DIR__ . '/drivers/Rtsp.class.php';

// The subsystems this module is made of live in src/ and are loaded as they
// are asked for. BMO autoloads the module class itself, by rawname, and
// nothing else, so anything standing alongside it has to say where it lives.
// The driver is left out: it is required above, before the Core class it
// extends can go missing, and that order is worth keeping deliberate.
if (!defined('ORYK_DEVICES_AUTOLOADER')) {
	define('ORYK_DEVICES_AUTOLOADER', true);

	spl_autoload_register(function ($class) {
		$prefix = 'FreePBX\\Modules\\Oryk_Devices\\';

		if (strpos($class, $prefix) !== 0) {
			return;
		}

		$relative = substr($class, strlen($prefix));

		if (strpos($relative, 'Drivers\\') === 0) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

		if (is_file($file)) {
			require_once $file;
		}
	});
}

class Oryk_devices extends FreePBX_Helpers implements \BMO
{
	/**
	 * Database table used by this module.
	 *
	 * @var string
	 */
	private $table = 'oryk_devices_settings';

	/**
	 * FreePBX application instance.
	 *
	 * @var object
	 */
	public $FreePBX;

	/**
	 * Asterisk database handle.
	 *
	 * @var \PDO
	 */
	public $db;

	/**
	 * Asterisk manager connection, when one is available.
	 *
	 * @var object|null
	 */
	public $astman;

	/**
	 * The mailbox side of an extension.
	 *
	 * @var VoicemailManager
	 */
	private $voicemail;

	/**
	 * The call history belonging to an extension.
	 *
	 * @var CdrHistory
	 */
	private $cdr;

	/**
	 * The User Manager account behind an Extension/User device.
	 *
	 * @var UsermanManager
	 */
	private $userman;

	/**
	 * What an account is allowed to open.
	 *
	 * @var UcpAssignments
	 */
	private $ucp;

	/**
	 * The Core extension behind an Extension/User device.
	 *
	 * @var ExtensionManager
	 */
	private $extensions;

	/**
	 * Which numbers are free, and what the next one is.
	 *
	 * @var NumberAllocator
	 */
	private $numbers;

	/**
	 * Moving an Extension/User device to a different number.
	 *
	 * @var ExtensionRenumberer
	 */
	private $renumberer;

	/**
	 * What a device is, as a form.
	 *
	 * @var DeviceSchema
	 */
	private $schema;

	/**
	 * Create an Oryk devices module instance.
	 *
	 * @param object|null $freepbx FreePBX application instance.
	 *
	 * @throws \Exception If no FreePBX instance is provided.
	 */
	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception('Not given a FreePBX Object');
		}

		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->astman = $freepbx->astman;

		$this->schema = new DeviceSchema($freepbx);
		$this->voicemail = new VoicemailManager($freepbx);
		$this->cdr = new CdrHistory($freepbx, $this->voicemail);
		$this->userman = new UsermanManager($freepbx);
		$this->ucp = new UcpAssignments($freepbx);
		$this->extensions = new ExtensionManager($freepbx);
		$this->numbers = new NumberAllocator($freepbx, $this->userman);
		$this->renumberer = new ExtensionRenumberer(
			$freepbx,
			$this->extensions,
			$this->voicemail,
			$this->userman,
			$this->ucp,
			$this->cdr
		);

		$this->tryRegisterDriver();
	}

	/**
	 * Register the RTSP driver with the Core module.
	 *
	 * Registration failures are intentionally ignored so module loading can
	 * continue when the Core module is not ready.
	 *
	 * @return void
	 */
	private function tryRegisterDriver()
	{
		try {
			$core = $this->FreePBX->Core ?? null;

			if (!$core) {
				return; // Core not ready yet
			}

			// Use reflection since $drivers is private
			$ref = new \ReflectionClass($core);
			$prop = $ref->getProperty('drivers');
			$prop->setAccessible(true);
			$drivers = $prop->getValue($core);

			if (!isset($drivers['rtsp'])) {
				$drivers['rtsp'] = new \FreePBX\Modules\Oryk_Devices\Drivers\Rtsp($this->FreePBX);
			}

			$prop->setValue($core, $drivers);
		} catch (\Throwable $e) {
			// fail silently
		}
	}

	/**
	 * Render the requested module page.
	 *
	 * @return mixed Rendered page output or a redirect response.
	 */
	public function showPage()
	{
		$request = $_REQUEST;
		$page = isset($_REQUEST['display']) ? $_REQUEST['display'] : 'default';
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';

		switch ($action) {
			case 'list':

				return load_view(__DIR__ . '/views/devices.php', [
					'types' => $this->schema->types,
				]);

			case 'view':

				return load_view(__DIR__ . '/views/device.php', [
					'types' => $this->schema->types,
					'groups' => $this->schema->groups,
					'file' => $this->schema->buildFormData($_REQUEST['id'] ?? null),
				]);

			case 'del':

				$id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : '';

				if ($id) {
					$this->remove($id);
				}

				header('Location: ?display=oryk_devices');

				return;

			case 'setkey': // save

				$submitted = $_REQUEST;

				try {
					$id = $this->store($this->schema->fields);
				} catch (\Exception $e) {
					// Nothing was written: redraw the form with what was typed
					return load_view(__DIR__ . '/views/device.php', [
						'types' => $this->schema->types,
						'groups' => $this->schema->groups,
						'file' => $this->schema->buildFormData($submitted['DEVICE_ID'] ?? null, $submitted),
						'error' => $e->getMessage(),
					]);
				}

				$url = $_SERVER['REQUEST_URI'] . '&id=' . $id;

				header('Location: ' . $url);

				return;

			default:
				break;
		}
	}

	/**
	 * Move a mailbox from one extension to another.
	 *
	 * @deprecated Use $this->voicemail->moveMailbox() instead.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return string|false The voicemail context, or false when nothing moved.
	 */
	public function moveVoicemailBox($old, $new)
	{
		return $this->voicemail->moveMailbox($old, $new);
	}

	/**
	 * Keep the extension's voicemail email in step with the device email.
	 *
	 * @deprecated Use $this->voicemail->syncEmail() instead.
	 *
	 * @param int|string  $extension Extension/user number.
	 * @param string|null $email     Email to store, null to leave it alone.
	 *
	 * @return bool True when the mailbox was updated.
	 */
	public function syncVoicemailEmail($extension, $email)
	{
		return $this->voicemail->syncEmail($extension, $email);
	}

	/**
	 * Load a device and map its values to the configured fields.
	 *
	 * @deprecated Use $this->schema->buildFormData() instead.
	 *
	 * @param int|string|null           $id     Device identifier.
	 * @param array<string, mixed>|null $values Submitted values that
	 *                                          override what is stored.
	 *
	 * @return array<string, mixed> Device data grouped by field group.
	 */
	public function pull($id, $values = null)
	{
		return $this->schema->buildFormData($id, $values);
	}

	/**
	 * Move an Extension/User device to a different number.
	 *
	 * @deprecated Use $this->renumberer->renumber() instead.
	 *
	 * @param int|string  $old         Number being left behind.
	 * @param int|string  $new         Number being moved to.
	 * @param string      $displayname Display name for the extension.
	 * @param string      $tech        Device technology.
	 * @param string|null $email       Email address for the account.
	 *
	 * @return bool True when the number was moved.
	 */
	public function renumber($old, $new, $displayname, $tech = 'pjsip', $email = null)
	{
		return $this->renumberer->renumber($old, $new, $displayname, $tech, $email);
	}

	/**
	 * Generate the next sequential device identifier.
	 *
	 * @deprecated Use $this->numbers->generate() instead.
	 *
	 * @return string Device identifier.
	 */
	public function generateNumber()
	{
		return $this->numbers->generate();
	}

	/**
	 * Validate an Extension/User number typed into the form.
	 *
	 * @deprecated Use $this->numbers->assertAvailable() instead.
	 *
	 * @param int|string      $number    Number typed into the form.
	 * @param int|string|null $currentId Device being edited, if any.
	 *
	 * @return string The validated number.
	 */
	public function assertNumberAvailable($number, $currentId = null)
	{
		return $this->numbers->assertAvailable($number, $currentId);
	}

	/**
	 * Describe what already holds a number.
	 *
	 * @deprecated Use $this->numbers->findConflict() instead.
	 *
	 * @param int|string $number Extension/user number.
	 *
	 * @return string|null Why the number is taken, null when it is free.
	 */
	public function findNumberConflict($number)
	{
		return $this->numbers->findConflict($number);
	}

	/**
	 * Make sure a user/extension exists for the given id.
	 *
	 * @deprecated Use $this->extensions->ensure() instead.
	 *
	 * @param int|string  $extension   Extension/user number.
	 * @param string|null $displayname Display name used for a new user.
	 *
	 * @return bool True when the user exists after the call.
	 */
	public function ensureUser($extension, $displayname = null)
	{
		return $this->extensions->ensure($extension, $displayname);
	}

	/**
	 * Keep the user/extension name in step with the device description.
	 *
	 * @deprecated Use $this->extensions->syncName() instead.
	 *
	 * @param int|string $extension Extension/user number.
	 * @param string     $name      Name to store.
	 *
	 * @return bool True when the name was written.
	 */
	public function syncUserName($extension, $name)
	{
		return $this->extensions->syncName($extension, $name);
	}

	/**
	 * Move the extension in the settings that decide what an account may see.
	 *
	 * @deprecated Use $this->ucp->move() instead.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return int How many settings were moved.
	 */
	public function moveUcpAssignments($old, $new)
	{
		return $this->ucp->move($old, $new);
	}

	/**
	 * Take an extension out of the settings that decide what an account sees.
	 *
	 * @deprecated Use $this->ucp->forget() instead.
	 *
	 * @param int|string $extension Number being deleted.
	 *
	 * @return int How many settings were changed.
	 */
	public function forgetUcpAssignments($extension)
	{
		return $this->ucp->forget($extension);
	}

	/**
	 * Carry a User Manager account over to a new extension.
	 *
	 * @deprecated Use $this->userman->move() instead.
	 *
	 * @param array<string, mixed>|null $account     Account on the old number.
	 * @param int|string                $old         Number being left behind.
	 * @param int|string                $new         Number being moved to.
	 * @param string                    $displayname Display name to store.
	 * @param string                    $tech        Device technology.
	 * @param string|null               $email       Email address for the account.
	 *
	 * @return bool True when an account is on the new number afterwards.
	 */
	public function moveUsermanUser($account, $old, $new, $displayname, $tech = 'pjsip', $email = null)
	{
		return $this->userman->move($account, $old, $new, $displayname, $tech, $email);
	}

	/**
	 * Make sure a User Manager account exists for the given extension.
	 *
	 * @deprecated Use $this->userman->ensure() instead.
	 *
	 * @param int|string  $extension   Extension/user number.
	 * @param string|null $displayname Display name for a new account.
	 * @param string      $tech        Device technology.
	 * @param string|null $email       Email address for the account.
	 *
	 * @return bool True when the account exists after the call.
	 */
	public function ensureUsermanUser($extension, $displayname = null, $tech = 'pjsip', $email = null)
	{
		return $this->userman->ensure($extension, $displayname, $tech, $email);
	}

	/**
	 * Keep the User Manager display name in step with the device description.
	 *
	 * @deprecated Use $this->userman->sync() instead.
	 *
	 * @param int|string  $extension   Extension/user number.
	 * @param string      $displayname Display name to store.
	 * @param string|null $email       Email to store, null to leave it alone.
	 *
	 * @return bool True when the account was updated.
	 */
	public function syncUsermanUser($extension, $displayname, $email = null)
	{
		return $this->userman->sync($extension, $displayname, $email);
	}

	/**
	 * Delete the User Manager account belonging to an extension.
	 *
	 * @deprecated Use $this->userman->removeOwnedAccount() instead.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when an account was deleted.
	 */
	public function removeUsermanUser($extension)
	{
		return $this->userman->removeOwnedAccount($extension);
	}

	/**
	 * Carry the call history over to a new extension number.
	 *
	 * @deprecated Use $this->cdr->migrate() instead.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	public function migrateCdr($old, $new)
	{
		return $this->cdr->migrate($old, $new);
	}

	/**
	 * Take an extension's call history out of the CDR database.
	 *
	 * @deprecated Use $this->cdr->purge() instead.
	 *
	 * @param int|string $extension Number being deleted.
	 *
	 * @return array{rows: int, recordings: int} What was removed.
	 */
	public function purgeCdr($extension)
	{
		return $this->cdr->purge($extension);
	}

	/**
	 * Apply the pending configuration (equivalent to `fwconsole reload`).
	 *
	 * The Apply Config flag is raised first so it stays visible when the
	 * reload itself fails. Failures are logged rather than thrown so a save
	 * or delete is never lost behind a reload error.
	 *
	 * @return bool True when the reload completed successfully.
	 */
	public function reload()
	{
		needreload();

		try {
			$result = \FreePBX::Framework()->doReload();
		} catch (\Throwable $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: reload failed: ' . $e->getMessage());

			return false;
		}

		if (empty($result['status'])) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: reload failed: ' . ($result['message'] ?? 'unknown error'));

			return false;
		}

		return true;
	}

	/**
	 * Delete a device and, for kinds that own their user, the matching
	 * user/extension.
	 *
	 * The user is only removed when it carries the device id and no other
	 * device is still assigned to it.
	 *
	 * @param int|string $id Device identifier.
	 *
	 * @return bool True when a device was deleted.
	 */
	public function remove($id)
	{
		$match = \FreePBX::Core()->getDevice($id);
		$device = isset($match['id']) ? $match : null;

		if (!$device) {
			return false;
		}

		$kind = $device['kind'] ?? $device['tech'] ?? '';
		$type = $this->schema->types[$kind] ?? null;
		$user = $device['user'] ?? null;

		\FreePBX::Core()->delDevice($device['id']);

		// An Extension/User device owns its user, so it goes with the device
		if (!empty($type['creates_user'])
			&& (string) $user !== ''
			&& (string) $user === (string) $device['id']
			&& !$this->extensions->hasDevices($user)
		) {
			$this->userman->removeOwnedAccount($user);

			$deleted = true;

			try {
				\FreePBX::Core()->delUser($user);
			} catch (\Exception $e) {
				$deleted = false;

				freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to delete user ' . $user . ': ' . $e->getMessage());
			}

			// Only once the extension has actually gone. An extension still in
			// service, left behind because its deletion failed, must not lose
			// the history and recordings it is still making.
			if ($deleted) {
				// An account belonging to a person outlives the extension, so
				// the number comes out of what that account is allowed to open
				$this->ucp->forget($user);

				// The history outlives it too, and nothing in FreePBX clears it
				$this->cdr->purge($user);
			}
		}

		$this->reload();

		return true;
	}

	/**
	 * Store or update a device.
	 *
	 * @param array<string, array<string, mixed>> $sets Device field definitions.
	 *
	 * @return string Device identifier.
	 */
	public function store($sets)
	{
		$id = trim((string) ($_REQUEST['DEVICE_ID'] ?? ''));
		$requested = trim((string) ($_REQUEST['DEVICE_USER'] ?? ''));
		$email = $_REQUEST['DEVICE_EMAIL'] ?? null;
		$deviceType = $_REQUEST['DEVICE_KIND'] ?? 'pjsip';
		$type = $this->schema->types[$deviceType] ?? $this->schema->types['pjsip'];
		$tech = $type['tech'] ?? 'pjsip';
		$ownsUser = !empty($type['creates_user']);
		$match = $id === '' ? [] : \FreePBX::Core()->getDevice($id);
		$device = isset($match['id']) ? $match : null;
		$flags = 0;

		if ($ownsUser) {
			// An Extension/User device is its own user, so the number typed
			// into the form is the device id as well: a blank field asks for
			// a generated one, a filled one has to be free.
			$uid = $requested === ''
				? $this->numbers->generate()
				: $this->numbers->assertAvailable($requested, $id);

			$user = $uid;
		} else {
			$uid = $id === '' ? $this->numbers->generate() : $id;
			$user = $requested;
		}

		$description = $_REQUEST['DEVICE_DESCRIPTION'] ?? null;

		$_REQUEST['DEVICE_DESCRIPTION'] = $description ? $description : $uid;

		// A changed number takes the extension, the User Manager account, the
		// mailbox and any handset pointed at it along to the new number
		if ($ownsUser && $device && (string) $device['id'] !== (string) $uid) {
			$this->renumberer->renumber($device['id'], $uid, $_REQUEST['DEVICE_DESCRIPTION'], $tech, $email);

			$device = null; // the row on the old number is gone
		}

		$generated = \FreePBX::Core()->generateDefaultDeviceSettings(
			$tech === 'pjsip' ? 'pjsip' : 'custom',
			$user,
			$_REQUEST['DEVICE_DESCRIPTION'],
			false,
		);

		$defaults = \FreePBX::Core()->getDriver($tech)->getDefaultDeviceSettings(
			$uid,
			$_REQUEST['DEVICE_DESCRIPTION'],
			$flags
		);

		unset($_REQUEST['DEVICE_ID']);
		unset($_REQUEST['DEVICE_USER']);

		foreach ($sets as $key => $set) {
			if (isset($_REQUEST[$key])) {
				$fieldName = $set['alias'] ?? $set['name'] ?? null;

				if (!$fieldName) {
					continue;
				}

				if (!isset($generated[$fieldName])) {
					$generated[$fieldName] = ['value' => null];
				}

				$generated[$fieldName]['value'] = $_REQUEST[$key];
			}
		}

		// Ensure all fields have 'value' and 'flag'
		foreach ($generated as &$s) {
			$s['value'] = $s['value'] ?? '';
			$s['flag'] = $s['flag'] ?? 0;
		}

		$dial = $defaults['dial'] ?? 'DEVICE';

		$generated['account']['value'] = "$uid";
		$generated['dial']['value'] = "$dial/$uid";
		$generated['mailbox']['value'] = "$uid@device";

		if (isset($generated['emergency_cid']['value']) && empty($generated['emergency_cid']['value'])) {
			$generated['emergency_cid']['value'] = $uid;
		}

		// Settings the type pins down are not on the form, so they are applied
		// here on every save and win over both the driver defaults and anything
		// that came back from the browser
		foreach (($type['settings'] ?? []) as $keyword => $value) {
			if (!isset($generated[$keyword])) {
				$generated[$keyword] = ['value' => null, 'flag' => 0];
			}

			$generated[$keyword]['value'] = $value;
		}

		//die(json_encode($generated));

		// Make sure the matching user exists and owns this device
		if ($ownsUser) {
			$generated['user']['value'] = "$uid";
			$this->extensions->ensure($uid, $_REQUEST['DEVICE_DESCRIPTION']);
			$this->userman->ensure($uid, $_REQUEST['DEVICE_DESCRIPTION'], $tech, $email);

			// Keep the names and the email in step with the form on every save
			$this->extensions->syncName($uid, $_REQUEST['DEVICE_DESCRIPTION']);
			$this->userman->sync($uid, $_REQUEST['DEVICE_DESCRIPTION'], $email);
			$this->voicemail->syncEmail($uid, $email);
		}

		// If device exists, delete it first
		if ($device) {
			\FreePBX::Core()->delDevice($device['id'], true);
		}

		$ret = \FreePBX::Core()->addDevice($uid, $tech, $generated, true);

		//update the associated endpoint configuration
		if ($ret && $tech === 'pjsip') {
			\FreePBX::Core()->processEPM($uid, $tech, true);
		}

		if ($ret) {
			$this->reload();
		}

		return $uid;
	}

	/**
	 * Install the module.
	 *
	 * @return bool True when installation completes.
	 */
	public function install()
	{
		try {
			$this->db->exec("ALTER TABLE devices ADD UNIQUE KEY id (id)");
		} catch (\PDOException $e) {
		}
		try {
			$this->db->exec("ALTER TABLE devices ADD KEY user (user)");
		} catch (\PDOException $e) {
		}
		try {
			// userman_users.email is TEXT, so the key needs a prefix length
			$this->db->exec("ALTER TABLE userman_users ADD KEY oryk_email (email(191))");
		} catch (\PDOException $e) {
		}

		return true;
	}

	/**
	 * Uninstall the module.
	 *
	 * @return void
	 */
	public function uninstall()
	{
	}

	/**
	 * Create a module backup.
	 *
	 * @return void
	 */
	public function backup()
	{
	}

	/**
	 * Restore module data from a backup.
	 *
	 * @param mixed $backup Backup data.
	 *
	 * @return void
	 */
	public function restore($backup)
	{
	}

	/**
	 * Initialise the module configuration page.
	 *
	 * @param string $page Current configuration page.
	 *
	 * @return void
	 */
	public function doConfigPageInit($page)
	{
	}

	/**
	 * Determine whether an AJAX request is supported.
	 *
	 * @param string $req Requested AJAX operation.
	 * @param mixed &$setting Request settings passed by reference.
	 *
	 * @return bool True when the request is supported.
	 */
	public function ajaxRequest($req, &$setting)
	{
		// tell FreePBX you handle AJAX requests
		switch ($req) {
			case 'list':
				return true;
			case 'restart':
				return true;
			default:
				return false;
		}
	}

	/**
	 * Process an AJAX request.
	 *
	 * @return array<string, mixed>|null AJAX response data.
	 */
	public function ajaxHandler()
	{
		switch ($_REQUEST['command']) {
			case 'restart':

				$restart = \FreePBX::Core()->getDriver('rtsp')->restart(
					$_REQUEST['id'] ?? null,
				);

				return;

			case 'list':

				$limit = $_REQUEST['limit'] ?? 10;
				$offset = $_REQUEST['offset'] ?? 0;
				$search = $_REQUEST['search'] ?? '';
				// The sort column and its direction are written into the
				// statement rather than bound, so neither can be taken from
				// the request as it stands. Only what the table offers as a
				// sortable heading is accepted, and anything else sorts by
				// extension rather than being refused.
				$sortable = [
					'user' => 'd.user',
					'description' => 'd.description',
					'kind' => 'kind',
					'id' => 'd.id',
				];

				$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['user'];
				$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

				$params = [];
				$where = '';

				if ($search) {
					$where = "WHERE d.id LIKE :search OR d.user LIKE :search OR d.description LIKE :search";
					$params[':search'] = "%$search%";
				}

				// Count both tables
				$countSql = "
					SELECT COUNT(*) 
					FROM devices d
					$where
				";
				$countStmt = $this->db->prepare($countSql);
				$countStmt->execute($params);
				$total = (int) $countStmt->fetchColumn();

				// devices.id is unique (install() adds the key), so the
				// server can see description and user are decided by it and
				// ONLY_FULL_GROUP_BY is satisfied
				$sql = "
					SELECT 
					d.id,
					d.description,
					d.user,
					MAX(CASE WHEN s.keyword = 'link'   THEN s.data END) AS link,
					COALESCE(MAX(CASE WHEN s.keyword = 'kind' THEN s.data END), d.tech) AS kind
					FROM devices d
					LEFT JOIN sip s ON s.id = d.id
					$where
					GROUP BY d.id
					ORDER BY $sort $order
					LIMIT :limit OFFSET :offset;
				";
				$stmt = $this->db->prepare($sql);
				foreach ($params as $k => $v) {
					$stmt->bindValue($k, $v);
				}
				$stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
				$stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
				$stmt->execute();

				$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

				return [
					'total' => $total,
					'rows' => $rows
				];

			default:
				return null;
		}
	}

	/**
	 * Return action-bar buttons for the current page.
	 *
	 * @param array<string, mixed> $request Current request data.
	*
	 * @return array<string, array<string, string>> Action-bar button definitions.
	 */
	public function getActionBar($request)
	{
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';
		$id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : '';
		$buttons = array();

		// A rejected save redraws the form, so it keeps the same buttons
		if ($action === 'setkey') {
			$action = 'view';
			$id = isset($_REQUEST['DEVICE_ID']) ? (int) $_REQUEST['DEVICE_ID'] : '';
		}

		switch ($action) {
			case 'list':
				$buttons = array(
					'new' => array(
						'name' => 'new',
						'id' => 'new',
						'value' => _('New')
					),
					'refresh' => array(
						'name' => 'refresh',
						'id' => 'refresh',
						'value' => _('Refresh')
					),
				);
				break;
			case 'view':
				$buttons = array(
					'submit' => array(
						'name' => 'submit',
						'id' => 'submit',
						'value' => _('Save')
					),
					'close' => array(
						'name' => 'close',
						'id' => 'close',
						'value' => _('Close')
					),
				);

				if ($id) {
					$buttons['new'] = array(
						'name' => 'new',
						'id' => 'new',
						'value' => _('New')
					);
				}

				if ($id) {
					$buttons['delete'] = array(
						'name' => 'delete',
						'id' => 'delete',
						'value' => _('Delete')
					);
				}
				break;
			default:
				break;
		}
		return $buttons;
	}
}