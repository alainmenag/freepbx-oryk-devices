<?php

// Oryk_devices.class.php

namespace FreePBX\modules;

use BMO;
use PDO;
use FreePBX_Helpers;

if (!class_exists('FreePBX\\Modules\\Core\\Driver', false)) {
	require_once(\FreePBX::Config()->get('AMPWEBROOT') . '/admin/modules/core/functions.inc/Driver.class.php');
}

require_once __DIR__ . '/drivers/Rtsp.class.php';

class Oryk_devices extends FreePBX_Helpers implements \BMO
{
	/**
	 * Database table used by this module.
	 *
	 * @var string
	 */
	private $table = 'oryk_devices_settings';

	/**
	 * Prefix every generated device id starts with.
	 */
	const NUMBER_PREFIX = '999';

	/**
	 * Total length of a generated device id, prefix included.
	 */
	const NUMBER_LENGTH = 10;

	/**
	 * Supported device types and their configuration definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public $types = [
		'pjsip' => [
			'title' => 'Extension/User',
			'icon' => 'fa-phone',
			'suffix' => '',
			'tech' => 'pjsip',
			// The device is the extension: a user with the same id is created and linked.
			'creates_user' => true,
			'fields' => ['DEVICE_USER', 'DEVICE_EMAIL', 'HEADER_CREDENTIALS', 'DEVICE_ACCOUNT', 'DEVICE_SECRET'],
		],
		'handset' => [
			'title' => 'Handset',
			'icon' => 'fa-phone',
			'suffix' => '001',
			'tech' => 'pjsip',
			'fields' => ['DEVICE_USER', 'HEADER_CREDENTIALS', 'DEVICE_ACCOUNT', 'DEVICE_SECRET', 'DEVICE_LINK', 'DEVICE_MANUFACTURER', 'DEVICE_MODEL'],
		],
		'softphone' => [
			'title' => 'Softphone',
			'icon' => 'fa-phone',
			'suffix' => '002',
			'tech' => 'pjsip',
			'fields' => ['DEVICE_USER', 'HEADER_CREDENTIALS', 'DEVICE_ACCOUNT', 'DEVICE_SECRET'],
		],
		'rtsp' => [
			'title' => 'RTSP Feed',
			'icon' => 'fa-video',
			'suffix' => '',
			'tech' => 'rtsp',
			'fields' => ['HEADER_STREAM', 'DEVICE_STREAM_IN'],
			'actions' => [
				'restart' => [
					'title' => 'Restart',
					'icon' => 'fa-redo',
				],
			]
		],
	];

	/**
	 * Available field groups.
	 *
	 * @var array<string, array<string, string>>
	 */
	public $groups = [
		'basics' => [
			'title' => 'Basics',
		],
		'authentication' => [
			'title' => 'Authentication',
		],
		'location' => [
			'title' => 'Location',
		],
		'make' => [
			'title' => 'Make',
		],
	];

	/**
	 * Device field definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public $fields = [
		'HEADER_CREDENTIALS' => [
			'html' => '<h2>Credentials</h2>',
			'group' => 'authentication',
		],
		'HEADER_LOCATION' => [
			'html' => '<h2>Location</h2>',
			'group' => 'location',
		],
		'HEADER_STREAM' => [
			'html' => '<h2>Stream</h2>',
			'group' => 'location',
		],
		'HEADER_MAKE' => [
			'html' => '<h2>Make</h2>',
			'group' => 'make',
		],
		'DEVICE_ID' => [
			'type' => 'hidden',
			'name' => 'id',
			'maxLength' => 15,
			'group' => 'basics',
		],
		'DEVICE_DESCRIPTION' => [
			'type' => 'text',
			'title' => 'Description',
			'example' => 'Desk Phone',
			'name' => 'description',
			//'required' => true,
			'maxLength' => 255,
			'group' => 'basics',
		],
		'DEVICE_EMAIL' => [
			'type' => 'email',
			'title' => 'Email',
			'example' => 'user@example.com',
			'name' => 'email',
			'maxLength' => 255,
			'group' => 'basics',
			'help' => 'Used for the User Manager account and its welcome email.',
		],
		'DEVICE_KIND' => [
			'type' => 'select',
			'disabled' => false,
			'title' => 'Kind',
			'name' => 'kind',
			'maxLength' => 255,
			'group' => 'basics',
		],
		'DEVICE_ACCOUNT' => [
			'type' => 'span',
			'title' => 'Account',
			'name' => 'account',
			'maxLength' => 255,
			'group' => 'authentication',
			//'disabled' => true,
		],
		'DEVICE_SECRET' => [
			'type' => 'password',
			'title' => 'Secret',
			'name' => 'secret',
			'maxLength' => 255,
			'group' => 'authentication',
		],
		'DEVICE_USER' => [
			'type' => 'text',
			'title' => 'Extension/User',
			'example' => '1001',
			'name' => 'user',
			'maxLength' => 10,
			'group' => 'location',
		],
		'DEVICE_STREAM_IN' => [
			'type' => 'text',
			'title' => 'In',
			'example' => 'rtsp://',
			'name' => 'stream_in',
			'maxLength' => 255,
			'group' => 'location',
		],
		'DEVICE_STREAM_OUT' => [
			'type' => 'text',
			'title' => 'Out',
			'example' => 'rtmp://',
			'name' => 'stream_out',
			'maxLength' => 255,
			'group' => 'location',
		],
		'DEVICE_LINK' => [
			'type' => 'url',
			'title' => 'Link',
			'example' => 'http(s)://',
			'name' => 'link',
			'maxLength' => 255,
			'group' => 'location',
		],
		'DEVICE_MANUFACTURER' => [
			'type' => 'text',
			'title' => 'Manufacturer',
			'name' => 'manufacturer',
			'maxLength' => 255,
			'group' => 'make',
		],
		'DEVICE_MODEL' => [
			'type' => 'text',
			'title' => 'Model',
			'name' => 'model',
			'maxLength' => 255,
			'group' => 'make',
		],
	];

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
			throw new Exception("Not given a FreePBX Object");
		}

		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->astman = $freepbx->astman;

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
					'types' => $this->types,
				]);

			case 'view':

				return load_view(__DIR__ . '/views/device.php', [
					'types' => $this->types,
					'groups' => $this->groups,
					'file' => $this->pull($_REQUEST['id'] ?? null),
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
					$id = $this->store($this->fields);
				} catch (\Exception $e) {
					// Nothing was written: redraw the form with what was typed
					return load_view(__DIR__ . '/views/device.php', [
						'types' => $this->types,
						'groups' => $this->groups,
						'file' => $this->pull($submitted['DEVICE_ID'] ?? null, $submitted),
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
	 * Generate the next sequential device identifier.
	 *
	 * Identifiers are NUMBER_LENGTH digits long and always start with
	 * NUMBER_PREFIX, so the range runs from 9990000001 to 9999999999. The
	 * highest identifier already taken by a device or a user is incremented,
	 * which keeps the numbers sequential and never reuses a freed id.
	 *
	 * @return string Device identifier.
	 *
	 * @throws \Exception When the identifier range is exhausted.
	 */
	public function generateNumber()
	{
		$digits = self::NUMBER_LENGTH - strlen(self::NUMBER_PREFIX);
		$floor = (int) (self::NUMBER_PREFIX . str_repeat('0', $digits)); // 9990000000
		$ceiling = (int) (self::NUMBER_PREFIX . str_repeat('9', $digits)); // 9999999999
		$pattern = '^' . self::NUMBER_PREFIX . '[0-9]{' . $digits . '}$';

		// Users are included so an extension left behind by a device is not reused.
		$sql = 'SELECT MAX(CAST(id AS UNSIGNED)) FROM ('
			. ' SELECT id FROM devices WHERE id REGEXP ?'
			. ' UNION ALL'
			. ' SELECT extension AS id FROM users WHERE extension REGEXP ?'
			. ') AS taken';

		$sth = $this->db->prepare($sql);
		$sth->execute([$pattern, $pattern]);
		$highest = (int) $sth->fetchColumn();

		$next = ($highest >= $floor ? $highest : $floor) + 1;

		if ($next > $ceiling) {
			throw new \Exception(sprintf(
				'No device id left in the %d-%d range',
				$floor + 1,
				$ceiling
			));
		}

		return (string) $next;
	}

	/**
	 * Validate an Extension/User number typed into the form.
	 *
	 * An Extension/User device is its own device id, extension and User
	 * Manager account, so the number has to be digits only and free:
	 * anything already holding it would otherwise be overwritten.
	 *
	 * @param int|string      $number    Number typed into the form.
	 * @param int|string|null $currentId Device being edited, if any.
	 *
	 * @return string The validated number.
	 *
	 * @throws \Exception When the number is malformed or already taken.
	 */
	public function assertNumberAvailable($number, $currentId = null)
	{
		$number = trim((string) $number);

		if (!preg_match('/^[0-9]+$/', $number)) {
			throw new \Exception(sprintf(
				_('"%s" is not a valid Extension/User number: digits only.'),
				$number
			));
		}

		if (strlen($number) > self::NUMBER_LENGTH) {
			throw new \Exception(sprintf(
				_('Extension/User %s is too long: %d digits at most.'),
				$number,
				self::NUMBER_LENGTH
			));
		}

		// The number a device already carries is its own, not a conflict
		if ((string) $currentId !== '' && (string) $currentId === $number) {
			return $number;
		}

		$conflict = $this->findNumberConflict($number);

		if ($conflict !== null) {
			throw new \Exception($conflict);
		}

		return $number;
	}

	/**
	 * Describe what already holds a number.
	 *
	 * @param int|string $number Extension/user number.
	 *
	 * @return string|null Why the number is taken, null when it is free.
	 */
	public function findNumberConflict($number)
	{
		$sth = $this->db->prepare('SELECT description FROM devices WHERE id = ? LIMIT 1');
		$sth->execute([$number]);
		$description = $sth->fetchColumn();

		if ($description !== false) {
			return sprintf(
				_('Extension/User %s is already taken by the device "%s".'),
				$number,
				(string) $description !== '' ? $description : $number
			);
		}

		$sth = $this->db->prepare('SELECT name FROM users WHERE extension = ? LIMIT 1');
		$sth->execute([$number]);
		$name = $sth->fetchColumn();

		if ($name !== false) {
			return sprintf(
				_('Extension/User %s is already taken by the extension "%s".'),
				$number,
				(string) $name !== '' ? $name : $number
			);
		}

		$account = $this->findUsermanUser($number);

		if ($account) {
			return sprintf(
				_('Extension/User %s is already taken by the User Manager account "%s".'),
				$number,
				$account['username']
			);
		}

		return null;
	}

	/**
	 * Look up the User Manager account tied to an extension.
	 *
	 * An account named after the extension is preferred over one that merely
	 * has the extension assigned to it, so the caller can tell the account
	 * this module owns from a person's account linked by hand.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return array<string, mixed>|null The account, or null when there is none.
	 */
	private function findUsermanUser($extension)
	{
		if (trim((string) $extension) === '' || !$this->FreePBX->Modules->checkStatus('userman')) {
			return null;
		}

		try {
			$userman = \FreePBX::Userman();
			$user = $userman->getUserByUsername($extension);

			if (empty($user['id'])) {
				$user = $userman->getUserByDefaultExtension($extension);
			}
		} catch (\Exception $e) {
			return null;
		}

		return empty($user['id']) ? null : $user;
	}

	/**
	 * Move an Extension/User device to a different number.
	 *
	 * The extension, its User Manager account, its mailbox and every handset
	 * pointed at it follow the device, so the number stays one thing across
	 * Core, User Manager and Voicemail.
	 *
	 * The new number is expected to be free: assertNumberAvailable() is what
	 * stops a collision and it runs before anything here is written. The old
	 * number is only given up once the new extension is in place, so a
	 * failure leaves the device where it was.
	 *
	 * @param int|string  $old         Number being left behind.
	 * @param int|string  $new         Number being moved to.
	 * @param string      $displayname Display name for the extension.
	 * @param string      $tech        Device technology.
	 * @param string|null $email       Email address for the account.
	 *
	 * @return bool True when the number was moved.
	 *
	 * @throws \Exception When the extension cannot be recreated on the new number.
	 */
	public function renumber($old, $new, $displayname, $tech = 'pjsip', $email = null)
	{
		$old = trim((string) $old);
		$new = trim((string) $new);

		if ($old === '' || $new === '' || $old === $new) {
			return false;
		}

		$displayname = ($displayname === null || $displayname === '') ? $new : $displayname;

		// Everything the old number carries is read before any of it is removed
		$hadUser = $this->hasUser($old);
		$settings = [];

		if ($hadUser) {
			try {
				$settings = \FreePBX::Core()->getUser($old);
			} catch (\Exception $e) {
				$settings = [];
			}
		}

		$account = $this->findUsermanUser($old);

		// Carry the extension's own settings over when they could be read
		if (!empty($settings['extension'])) {
			$settings['extension'] = $new;
			$settings['name'] = $displayname;
			$settings['device'] = $new;

			// A caller id pinned to the old number would follow the extension
			if ((string) ($settings['cid_masquerade'] ?? '') === $old) {
				$settings['cid_masquerade'] = '';
			}

			try {
				\FreePBX::Core()->addUser($new, $settings);
			} catch (\Exception $e) {
				throw new \Exception(sprintf(
					_('Unable to move extension %s to %s: %s'),
					$old,
					$new,
					$e->getMessage()
				));
			}
		} else {
			$this->ensureUser($new, $displayname);
		}

		// addUser() reads the mailbox before it has moved, so the voicemail
		// context is written back once the box is on the new number
		$context = $this->moveVoicemailBox($old, $new);

		if ($context) {
			$sth = $this->db->prepare('UPDATE users SET voicemail = ? WHERE extension = ?');
			$sth->execute([$context, $new]);

			if ($this->astman && $this->astman->connected()) {
				$this->astman->database_put('AMPUSER', $new . '/voicemail', $context);
			}
		}

		// Only now is the old number given up
		try {
			\FreePBX::Core()->delDevice($old);
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to delete device ' . $old . ': ' . $e->getMessage());
		}

		if ($hadUser) {
			try {
				\FreePBX::Core()->delUser($old);
			} catch (\Exception $e) {
				freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to delete user ' . $old . ': ' . $e->getMessage());
			}
		}

		// After the old extension is gone, so User Manager is not left
		// unassigning the extension it has just been pointed at
		$this->moveUsermanUser($account, $old, $new, $displayname, $tech, $email);

		// Handsets and softphones registered against the old extension follow it
		$this->repointDevices($old, $new);

		return true;
	}

	/**
	 * Carry a User Manager account over to a new extension.
	 *
	 * The account is updated rather than replaced, so its password, groups,
	 * UCP settings and login history survive the renumbering. An account
	 * named after the old extension is this module's own and is renamed with
	 * it; one that merely has the extension assigned belongs to a person, so
	 * only the assignment follows.
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
		if (!$this->FreePBX->Modules->checkStatus('userman')) {
			return false;
		}

		// Nothing to carry over: the new number gets a fresh account
		if (empty($account['id'])) {
			$this->ensureUsermanUser($new, $displayname, $tech, $email);

			return $this->syncUsermanUser($new, $displayname, $email);
		}

		$username = ((string) $account['username'] === (string) $old) ? (string) $new : $account['username'];
		$extraData = ['displayname' => $displayname];

		if ($email !== null) {
			$extraData['email'] = $email;
		}

		try {
			$status = \FreePBX::Userman()->updateUser(
				$account['id'],
				$account['username'],
				$username,
				(string) $new,
				$account['description'] ?? null,
				$extraData,
				null,
				true
			);

			if (is_array($status) && isset($status['status']) && !$status['status']) {
				throw new \Exception(trim(strip_tags((string) ($status['message'] ?? ''))));
			}
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to move userman user ' . $old . ' to ' . $new . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Move a mailbox from one extension to another.
	 *
	 * Voicemail boxes live in voicemail.conf rather than the database, so the
	 * entry is rewritten under the new number and the messages on disk are
	 * moved with it. Extensions without a mailbox are skipped.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return string|false The voicemail context, or false when nothing moved.
	 */
	public function moveVoicemailBox($old, $new)
	{
		if (!$this->FreePBX->Modules->checkStatus('voicemail')) {
			return false;
		}

		try {
			$voicemail = \FreePBX::Voicemail();
			$vmconf = $voicemail->getVoicemail();
			$context = null;

			foreach ($vmconf as $name => $boxes) {
				if (is_array($boxes) && isset($boxes[$old]) && is_array($boxes[$old])) {
					$context = $name;

					break;
				}
			}

			// No mailbox, or the new number already has one of its own
			if ($context === null || isset($vmconf[$context][$new])) {
				return false;
			}

			$vmconf[$context][$new] = $vmconf[$context][$old];

			unset($vmconf[$context][$old]);

			$voicemail->saveVoicemail($vmconf);

			// The messages themselves are stored under the old number
			$spool = \FreePBX::Config()->get('ASTSPOOLDIR') . '/voicemail/' . $context;

			if (is_dir($spool . '/' . $old) && !file_exists($spool . '/' . $new)) {
				@rename($spool . '/' . $old, $spool . '/' . $new);
			}
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to move the mailbox from ' . $old . ' to ' . $new . ': ' . $e->getMessage());

			return false;
		}

		return $context;
	}

	/**
	 * Point every device registered against one extension at another.
	 *
	 * Handsets and softphones carry the extension they belong to, so they
	 * have to follow it when an Extension/User device is renumbered.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return int How many devices were moved.
	 */
	private function repointDevices($old, $new)
	{
		$sth = $this->db->prepare('SELECT id FROM devices WHERE user = ? AND id != ?');
		$sth->execute([$old, $new]);
		$ids = $sth->fetchAll(PDO::FETCH_COLUMN);

		if (!$ids) {
			return 0;
		}

		$update = $this->db->prepare('UPDATE devices SET user = ? WHERE id = ?');
		$connected = $this->astman && $this->astman->connected();

		foreach ($ids as $device) {
			$update->execute([$new, $device]);

			if ($connected) {
				$this->astman->database_put('DEVICE', $device . '/user', $new);
				$this->astman->database_put('DEVICE', $device . '/default_user', $new);
			}
		}

		if ($connected) {
			$linked = explode('&', (string) $this->astman->database_get('AMPUSER', $new . '/device'));
			$linked = array_unique(array_filter(array_merge($linked, $ids), 'strlen'));

			$this->astman->database_put('AMPUSER', $new . '/device', implode('&', $linked));
		}

		return count($ids);
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
	 * Determine whether a user/extension already exists.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when the user is present in the users table.
	 */
	private function hasUser($extension)
	{
		$sth = $this->db->prepare('SELECT extension FROM users WHERE extension = ? LIMIT 1');
		$sth->execute([$extension]);

		return (bool) $sth->fetchColumn();
	}

	/**
	 * Determine whether any device is still assigned to a user/extension.
	 *
	 * @param int|string $user Extension/user number.
	 *
	 * @return bool True when at least one device references the user.
	 */
	private function userHasDevices($user)
	{
		$sth = $this->db->prepare('SELECT id FROM devices WHERE user = ? LIMIT 1');
		$sth->execute([$user]);

		return (bool) $sth->fetchColumn();
	}

	/**
	 * Make sure a user/extension exists for the given id.
	 *
	 * Device kinds flagged with `creates_user` are their own user, so the
	 * matching row in the FreePBX users table is created when it is missing.
	 *
	 * @param int|string  $extension   Extension/user number.
	 * @param string|null $displayname Display name used for a new user.
	 *
	 * @return bool True when the user exists after the call.
	 */
	public function ensureUser($extension, $displayname = null)
	{
		if (trim((string) $extension) === '') {
			return false;
		}

		if ($this->hasUser($extension)) {
			return true;
		}

		$settings = \FreePBX::Core()->generateDefaultUserSettings(
			$extension,
			($displayname === null || $displayname === '') ? (string) $extension : $displayname
		);

		// Link the user to the device carrying the same id.
		$settings['device'] = (string) $extension;

		try {
			\FreePBX::Core()->addUser($extension, $settings);
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to create user ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Keep the user/extension name in step with the device description.
	 *
	 * Only the name is touched. Deleting and re-adding the user the way the
	 * FreePBX extension screen does would reset voicemail, ring timers and
	 * every other setting this module does not own.
	 *
	 * @param int|string $extension Extension/user number.
	 * @param string     $name      Name to store.
	 *
	 * @return bool True when the name was written.
	 */
	public function syncUserName($extension, $name)
	{
		if (trim((string) $extension) === '' || !$this->hasUser($extension)) {
			return false;
		}

		$sth = $this->db->prepare('UPDATE users SET name = ? WHERE extension = ?');
		$sth->execute([$name, $extension]);

		// The same value addUser() writes: Asterisk reads it for the caller id name
		if ($this->astman && $this->astman->connected()) {
			$this->astman->database_put('AMPUSER', $extension . '/cidname', $name);
		}

		return true;
	}

	/**
	 * Make sure a User Manager account exists for the given extension.
	 *
	 * This uses the same entry point as the FreePBX extension screen
	 * (Userman::processQuickCreate), so the account lands in the default
	 * directory, gets the extension assigned, and picks up the configured
	 * groups, UCP template and welcome email.
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
		if (!$this->FreePBX->Modules->checkStatus('userman')) {
			return false;
		}

		if ($this->findUsermanUser($extension)) {
			return true;
		}

		try {
			$userman = \FreePBX::Userman();

			$userman->processQuickCreate($tech, $extension, [
				'um' => 'yes',
				'name' => ($displayname === null || $displayname === '') ? (string) $extension : $displayname,
				'email' => (string) $email,
				'um-groups' => [],
			]);
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to create userman user ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		$created = $userman->getUserByUsername($extension);

		return !empty($created['id']);
	}

	/**
	 * Keep the User Manager display name in step with the device description.
	 *
	 * Only an account whose username matches the extension is touched, and
	 * only its display name: every field left out of the update is carried
	 * over by User Manager, so email, groups and the rest survive.
	 *
	 * @param int|string  $extension   Extension/user number.
	 * @param string      $displayname Display name to store.
	 * @param string|null $email       Email to store, null to leave it alone.
	 *
	 * @return bool True when the account was updated.
	 */
	public function syncUsermanUser($extension, $displayname, $email = null)
	{
		if (!$this->FreePBX->Modules->checkStatus('userman')) {
			return false;
		}

		try {
			$userman = \FreePBX::Userman();
			$user = $this->findUsermanUser($extension);

			// Never rename an account a person linked to this extension by hand
			if (empty($user['id']) || (string) $user['username'] !== (string) $extension) {
				return false;
			}

			$extraData = ['displayname' => $displayname];

			// A supplied email is written through, including a blank one to clear it
			if ($email !== null) {
				$extraData['email'] = $email;
			}

			$sameName = (string) ($user['displayname'] ?? '') === (string) $displayname;
			$sameEmail = $email === null || (string) ($user['email'] ?? '') === (string) $email;

			if ($sameName && $sameEmail) {
				return true;
			}

			$userman->updateUser(
				$user['id'],
				$user['username'],
				$user['username'],
				$user['default_extension'] ?? $extension,
				$user['description'] ?? null,
				$extraData,
				null,
				true
			);
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to update userman user ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Keep the extension's voicemail email in step with the device email.
	 *
	 * Voicemail addresses live in voicemail.conf rather than the database, so
	 * this edits the mailbox in place the same way the voicemail module does
	 * and leaves the password, greeting name, pager and options untouched.
	 * Extensions without a mailbox are skipped.
	 *
	 * @param int|string  $extension Extension/user number.
	 * @param string|null $email     Email to store, null to leave it alone.
	 *
	 * @return bool True when the mailbox was updated.
	 */
	public function syncVoicemailEmail($extension, $email)
	{
		if ($email === null || !$this->FreePBX->Modules->checkStatus('voicemail')) {
			return false;
		}

		try {
			$voicemail = \FreePBX::Voicemail();
			$mailbox = $voicemail->getVoicemailBoxByExtension($extension);

			if (empty($mailbox['vmcontext'])) {
				return false; // no mailbox for this extension
			}

			$context = $mailbox['vmcontext'];
			$vmconf = $voicemail->getVoicemail();

			if (empty($vmconf[$context][$extension])) {
				return false;
			}

			// saveVoicemail() turns commas into the '|' separator on the way out
			if ((string) ($mailbox['email'] ?? '') === (string) $email) {
				return true;
			}

			$vmconf[$context][$extension]['email'] = $email;

			$voicemail->saveVoicemail($vmconf);
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to update voicemail email for ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Delete the User Manager account belonging to an extension.
	 *
	 * Only an account whose username matches the extension is removed, so an
	 * account that merely has the extension assigned to it (a real person
	 * linked by hand) is left alone.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when an account was deleted.
	 */
	public function removeUsermanUser($extension)
	{
		if (!$this->FreePBX->Modules->checkStatus('userman')) {
			return false;
		}

		try {
			$userman = \FreePBX::Userman();
			$user = $this->findUsermanUser($extension);

			if (empty($user['id']) || (string) $user['username'] !== (string) $extension) {
				return false;
			}

			$userman->deleteUserByID($user['id']);
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to delete userman user ' . $extension . ': ' . $e->getMessage());

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
		$type = $this->types[$kind] ?? null;
		$user = $device['user'] ?? null;

		\FreePBX::Core()->delDevice($device['id']);

		// An Extension/User device owns its user, so it goes with the device
		if (!empty($type['creates_user'])
			&& (string) $user !== ''
			&& (string) $user === (string) $device['id']
			&& !$this->userHasDevices($user)
		) {
			$this->removeUsermanUser($user);

			try {
				\FreePBX::Core()->delUser($user);
			} catch (\Exception $e) {
				freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to delete user ' . $user . ': ' . $e->getMessage());
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
		$type = $this->types[$deviceType] ?? $this->types['pjsip'];
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
				? $this->generateNumber()
				: $this->assertNumberAvailable($requested, $id);

			$user = $uid;
		} else {
			$uid = $id === '' ? $this->generateNumber() : $id;
			$user = $requested;
		}

		$description = $_REQUEST['DEVICE_DESCRIPTION'] ?? null;

		$_REQUEST['DEVICE_DESCRIPTION'] = $description ? $description : $uid;

		// A changed number takes the extension, the User Manager account, the
		// mailbox and any handset pointed at it along to the new number
		if ($ownsUser && $device && (string) $device['id'] !== (string) $uid) {
			$this->renumber($device['id'], $uid, $_REQUEST['DEVICE_DESCRIPTION'], $tech, $email);

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

		//die(json_encode($generated));

		// Make sure the matching user exists and owns this device
		if ($ownsUser) {
			$generated['user']['value'] = "$uid";
			$this->ensureUser($uid, $_REQUEST['DEVICE_DESCRIPTION']);
			$this->ensureUsermanUser($uid, $_REQUEST['DEVICE_DESCRIPTION'], $tech, $email);

			// Keep the names and the email in step with the form on every save
			$this->syncUserName($uid, $_REQUEST['DEVICE_DESCRIPTION']);
			$this->syncUsermanUser($uid, $_REQUEST['DEVICE_DESCRIPTION'], $email);
			$this->syncVoicemailEmail($uid, $email);
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
	 * Load a device and map its values to the configured fields.
	 *
	 * @param int|string|null                 $id     Device identifier.
	 * @param array<string, mixed>|null       $values Submitted values that
	 *                                                override what is stored.
	 *
	 * @return array<string, mixed> Device data grouped by field group.
	 */
	public function pull($id, $values = null)
	{
		$base = [
			'id' => $id ?? null,
		];

		$match = ($id === null || $id === '') ? [] : \FreePBX::Core()->getDevice($id);
		$device = isset($match['id']) ? $match : null;
		$kind = $device['kind'] ?? $device['tech'] ?? '';

		// A redrawn form follows the kind that was submitted, not the stored one
		if (is_array($values) && isset($values['DEVICE_KIND'])) {
			$kind = $values['DEVICE_KIND'];
		}

		$type = $this->types[$kind] ?? null;
		$keys = array_merge(
			[
				'DEVICE_ID',
				'DEVICE_DESCRIPTION',
				'DEVICE_KIND',
			],
			($type['fields'] ?? []),
		);

		foreach ($keys as $key) {
			$obj = $this->fields[$key] ?? null;

			if (!$obj) {
				continue;
			}

			if ($key == 'DEVICE_KIND') {
				$obj['type'] = 'select';
				$obj['options'] = $this->types;
			}

			// Get value from alias or name
			if (isset($obj['alias']) && isset($device[$obj['alias']])) {
				$obj['value'] = $device[$obj['alias']];
			} else if (isset($obj['name']) && isset($device[$obj['name']])) {
				$obj['value'] = $device[$obj['name']];
			}

			// Anything the form sent back wins over the stored value
			if (is_array($values) && array_key_exists($key, $values)) {
				$obj['value'] = $values[$key];
			}

			$base[$obj['group'] ?? 'other'][$key] = $obj;
		}

		$base['type'] = $device['type'] ?? null;

		return $base;
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
				$sort = $_REQUEST['sort'] ?? 'user';
				$order = $_REQUEST['order'] ?? 'asc';

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

				$sql = "
					SELECT 
					d.id,
					d.description,
					d.user,
					MAX(CASE WHEN s.keyword = 'link'   THEN s.data END) AS link,
					COALESCE(MAX(CASE WHEN s.keyword = 'kind' THEN s.data END), d.tech) AS kind
					FROM devices d
					LEFT JOIN asterisk.sip s ON s.id = d.id
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