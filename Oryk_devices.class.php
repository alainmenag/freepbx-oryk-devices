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
	 * Columns of each CDR table, as they were read this request.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $cdrColumns = [];

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
			// Driver settings forced on every save, whatever the form sent
			'settings' => [
				'media_encryption' => 'sdes',
				'media_encryption_optimistic' => 'yes',
			],
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
			throw new \Exception('Not given a FreePBX Object');
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
		$hadMailbox = $this->hasMailbox($old);
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
			// Voicemail deletes the mailbox behind Core::delUser(), which is
			// right for a number being retired but not for one whose mailbox
			// is still sitting on it because the move did not come off. Edit
			// mode holds that hook back; the astdb keys it also spares are
			// taken out here instead.
			$stranded = $hadMailbox && !$context;

			if ($stranded) {
				freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: the mailbox on ' . $old . ' did not move to ' . $new . ' and has been left where it is');
			}

			try {
				\FreePBX::Core()->delUser($old, $stranded);
			} catch (\Exception $e) {
				freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to delete user ' . $old . ': ' . $e->getMessage());
			}

			if ($stranded) {
				$this->forgetExtension($old);
			}
		}

		// After the old extension is gone, so User Manager is not left
		// unassigning the extension it has just been pointed at
		$this->moveUsermanUser($account, $old, $new, $displayname, $tech, $email);

		// Handsets and softphones registered against the old extension follow it
		$this->repointDevices($old, $new);

		// What the account is allowed to open, before the history it opens
		$this->moveUcpAssignments($old, $new);

		// The call history keeps the number as it stood when the call was
		// placed, and no part of FreePBX moves it
		$this->migrateCdr($old, $new);

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

			if ($context === null) {
				return false; // nothing to move
			}

			// The new number already has a mailbox of its own. Merging two
			// mailboxes is not something to decide here, so the move stops and
			// the caller keeps the messages where they are.
			if (isset($vmconf[$context][$new])) {
				freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: not moving the mailbox from ' . $old . ' to ' . $new . ': ' . $new . ' already has one');

				return false;
			}

			$vmconf[$context][$new] = $vmconf[$context][$old];

			unset($vmconf[$context][$old]);

			// The messages themselves are stored under the old number
			$spool = \FreePBX::Config()->get('ASTSPOOLDIR') . '/voicemail/' . $context;

			if (is_dir($spool . '/' . $old) && !file_exists($spool . '/' . $new)) {
				@rename($spool . '/' . $old, $spool . '/' . $new);
			}

			// A mailbox is reached through an alias keyed on the number rather
			// than directly, so the alias has to move with it
			$this->moveVoicemailAlias($voicemail, $old, $new, $context, $spool);

			// saveVoicemail() rebuilds the alias section from the key/value
			// store but merges into whatever is already there, so the parsed
			// copy of it goes before the old alias can be written back out
			unset($vmconf['pbxaliases']);

			// Written out once, with the mailbox and its alias both moved
			$voicemail->saveVoicemail($vmconf);
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to move the mailbox from ' . $old . ' to ' . $new . ': ' . $e->getMessage());

			return false;
		}

		return $context;
	}

	/**
	 * Move the device-to-mailbox alias that follows a mailbox.
	 *
	 * A FreePBX mailbox is not reached directly. The device asks for
	 * `<id>@device` and an alias maps that onto the real
	 * `<mailbox>@<context>`. On Asterisk 16.2 and later the alias is a
	 * [pbxaliases] section that saveVoicemail() builds from the voicemail
	 * module's own key/value store; before that it was a symlink under
	 * voicemail/device. Both are keyed on the number, so a mailbox that
	 * moves without its alias is a mailbox nothing points at: no message
	 * waiting indicator, and *97 answering on an empty box.
	 *
	 * Nothing is saved here. The caller writes voicemail.conf out once the
	 * mailbox and its alias have both been moved.
	 *
	 * @param object     $voicemail Voicemail module instance.
	 * @param int|string $old       Number being left behind.
	 * @param int|string $new       Number being moved to.
	 * @param string     $context   Voicemail context holding the mailbox.
	 * @param string     $spool     Spool directory for that context.
	 *
	 * @return bool True when the alias was moved.
	 */
	private function moveVoicemailAlias($voicemail, $old, $new, $context, $spool)
	{
		try {
			// The alias map, for the Asterisk versions that use one
			$voicemail->delConfig((string) $old, 'vmmapping');
			$voicemail->updateAliasDeviceMapping((string) $new, $new . '@' . $context, false);

			// The symlink, for the ones that do not
			$devices = dirname($spool) . '/device/';

			if (is_link($devices . $old)) {
				@unlink($devices . $old);
			}

			// file_exists() follows the link, so a dangling one reads as absent
			// and would leave the symlink below failing quietly
			if (is_link($devices . $new)) {
				@unlink($devices . $new);
			}

			if (is_dir($devices) && !file_exists($devices . $new)) {
				@symlink($spool . '/' . $new, $devices . $new);
			}
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to move the voicemail alias from ' . $old . ' to ' . $new . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Report whether an extension has a mailbox.
	 *
	 * Asked before a mailbox is moved so the caller can tell a number that
	 * never had one from a number whose mailbox failed to follow it.
	 *
	 * @param int|string $extension Extension to look at.
	 *
	 * @return bool True when a mailbox is configured for the extension.
	 */
	private function hasMailbox($extension)
	{
		if (!$this->FreePBX->Modules->checkStatus('voicemail')) {
			return false;
		}

		try {
			$mailbox = \FreePBX::Voicemail()->getVoicemailBoxByExtension((string) $extension);
		} catch (\Exception $e) {
			return false;
		}

		return !empty($mailbox['vmcontext']);
	}

	/**
	 * Take an extension's Asterisk database entries out.
	 *
	 * Core clears these itself when it deletes a user outright, and skips
	 * them in edit mode. A renumbering that has to use edit mode to protect a
	 * mailbox still wants them gone, so they are removed here.
	 *
	 * @param int|string $extension Number being retired.
	 *
	 * @return void
	 */
	private function forgetExtension($extension)
	{
		if (!$this->astman || !$this->astman->connected()) {
			return;
		}

		foreach (['AMPUSER/', 'CustomDevstate/FOLLOWME', 'DEVICE/', 'ZULU/'] as $family) {
			$this->astman->database_deltree($family . $extension);
		}
	}

	/**
	 * Carry the call history over to a new extension number.
	 *
	 * Nothing in FreePBX does this. A call detail record keeps the number as
	 * it stood when the call was placed, the CDR module subscribes to no
	 * core hook, and FreePBX has no renumbering of its own to hook into, so
	 * the history is rewritten here or it stays behind on a number that no
	 * longer answers.
	 *
	 * What is rewritten is what the reports read: the CDR report matches on
	 * src, dst, cnum and the two channel names, and displays clid. Recording
	 * file names carry the extension as well and are deliberately left
	 * alone, because the name has to keep matching the file on disk or the
	 * recording stops being playable.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	public function migrateCdr($old, $new)
	{
		if (!$this->FreePBX->Modules->checkStatus('cdr')) {
			return 0;
		}

		try {
			$cdrdb = \FreePBX::Cdr()->getCdrDbHandle();
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: no CDR database to move ' . $old . ' in: ' . $e->getMessage());

			return 0;
		}

		// Of the columns being rewritten only dst and dstchannel are indexed,
		// so on a system with a long history these statements read the table
		// end to end. A move that is cut short half way through leaves the
		// history split across two numbers, so it is allowed to take its time.
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		$rows = 0;

		foreach ($this->cdrTables($cdrdb) as $table) {
			$rows += $this->migrateCdrTable($cdrdb, $table, $old, $new);
		}

		// Channel event logging, when the site records it
		if ($this->cdrTableExists($cdrdb, 'cel')) {
			$rows += $this->migrateCelTable($cdrdb, 'cel', $old, $new);
		}

		freepbx_log(FPBX_LOG_INFO, 'oryk_devices: moved ' . $rows . ' call history rows from ' . $old . ' to ' . $new);

		return $rows;
	}

	/**
	 * Report whether a table is present in the CDR database.
	 *
	 * Which of the CDR tables exist depends on the site: the transient copy
	 * only appears once the CDR trigger has been set up, and channel event
	 * logging is optional.
	 *
	 * @param object $cdrdb CDR database handle.
	 * @param string $table Table to look for.
	 *
	 * @return bool True when the table is there.
	 */
	private function cdrTableExists($cdrdb, $table)
	{
		try {
			$sth = $cdrdb->prepare('SHOW TABLES LIKE ?');
			$sth->execute([$table]);

			return (bool) $sth->fetchColumn();
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Rewrite one call detail table for a number that has moved.
	 *
	 * @param object     $cdrdb CDR database handle.
	 * @param string     $table Table to rewrite.
	 * @param int|string $old   Number being left behind.
	 * @param int|string $new   Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	private function migrateCdrTable($cdrdb, $table, $old, $new)
	{
		$t = '`' . $table . '`';
		$rows = 0;

		// Columns holding the number on its own. accountcode and peeraccount
		// only hold an extension on a site that has chosen to put one there,
		// so they are matched exactly and are a no-op everywhere else.
		foreach (['src', 'dst', 'cnum', 'accountcode', 'peeraccount'] as $column) {
			$rows += $this->runCdrUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET `' . $column . '` = :new WHERE `' . $column . '` = :old',
				[':old' => $old, ':new' => $new]
			);
		}

		// dst also carries the voicemail pseudo extensions that Core adds to
		// the dialplan, and the prefix that dials a mailbox directly
		$to = $this->voicemailNumbers($new);

		foreach ($this->voicemailNumbers($old) as $index => $dialled) {
			if (!isset($to[$index])) {
				continue;
			}

			$rows += $this->runCdrUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET dst = :new WHERE dst = :old',
				[':old' => $dialled, ':new' => $to[$index]]
			);
		}

		// The number inside a channel name, in both the shapes it takes:
		// PJSIP/1001-0000abcd and Local/1001@from-internal-0000abcd
		foreach (['channel', 'dstchannel'] as $column) {
			$rows += $this->replaceInColumn($cdrdb, $t, $column, $old, $new);
		}

		// The caller id string the reports display
		$rows += $this->runCdrUpdate(
			$cdrdb,
			'UPDATE ' . $t . ' SET clid = REPLACE(clid, :needle, :replacement) WHERE clid LIKE :match',
			[
				':needle' => '<' . $old . '>',
				':replacement' => '<' . $new . '>',
				':match' => '%<' . $old . '>%',
			]
		);

		return $rows;
	}

	/**
	 * Rewrite the channel event log for a number that has moved.
	 *
	 * @param object     $cdrdb CDR database handle.
	 * @param string     $table Table to rewrite.
	 * @param int|string $old   Number being left behind.
	 * @param int|string $new   Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	private function migrateCelTable($cdrdb, $table, $old, $new)
	{
		$t = '`' . $table . '`';
		$rows = 0;

		foreach (['cid_num', 'cid_ani', 'exten', 'accountcode', 'peeraccount'] as $column) {
			$rows += $this->runCdrUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET `' . $column . '` = :new WHERE `' . $column . '` = :old',
				[':old' => $old, ':new' => $new]
			);
		}

		$to = $this->voicemailNumbers($new);

		foreach ($this->voicemailNumbers($old) as $index => $dialled) {
			if (!isset($to[$index])) {
				continue;
			}

			$rows += $this->runCdrUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET exten = :new WHERE exten = :old',
				[':old' => $dialled, ':new' => $to[$index]]
			);
		}

		foreach (['channame', 'peer'] as $column) {
			$rows += $this->replaceInColumn($cdrdb, $t, $column, $old, $new);
		}

		return $rows;
	}

	/**
	 * Take an extension's call history out of the CDR database.
	 *
	 * A deleted Extension/User leaves its records behind, because nothing in
	 * FreePBX removes them: the CDR module subscribes to no core hook and
	 * call detail records outlive the extension that made them. Left alone
	 * they are a set of records belonging to somebody who no longer exists,
	 * and they come back into view the moment the number is reissued.
	 *
	 * What goes is worked out from the call detail records and applied to
	 * both tables. The records naming the extension are found first, and the
	 * call identifiers on them -- the identifier of the record itself, and
	 * the identifier of the chain it belongs to -- are what everything else
	 * is deleted by. A call is more than one row in both tables: the sample
	 * of a plain extension-to-extension call has one call detail record and
	 * fifteen events across two channels, and only one of those two channels
	 * carries the record's own identifier. Deleting by the chain is what
	 * takes the other one with it.
	 *
	 * A call between two extensions belongs to both of them, and one of the
	 * two may still be in service. It is removed all the same: the point of
	 * this is that the deleted extension leaves nothing behind, and half a
	 * call naming only the surviving party would be a record of a call with
	 * nobody. The surviving extension loses those calls from its own history
	 * as a consequence, and there is no undo.
	 *
	 * @param int|string $extension Number being deleted.
	 *
	 * @return array{rows: int, recordings: int} What was removed.
	 */
	public function purgeCdr($extension)
	{
		$removed = ['rows' => 0, 'recordings' => 0];
		$extension = trim((string) $extension);

		// The match below is a set of ORs against columns that are empty on
		// plenty of rows, so an extension that is not a number would not
		// select this extension's history: it would select the whole table.
		// The length allows for extensions Core made as well as this module's.
		if (!preg_match('/^[0-9]{1,20}$/', $extension)) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: refusing to purge the call history for "' . $extension . '", which is not a number');

			return $removed;
		}

		if (!$this->FreePBX->Modules->checkStatus('cdr')) {
			return $removed;
		}

		try {
			$cdrdb = \FreePBX::Cdr()->getCdrDbHandle();
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: no CDR database to purge ' . $extension . ' from: ' . $e->getMessage());

			return $removed;
		}

		// None of the matched columns is indexed, so on a system with a long
		// history this reads the table end to end
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		$tables = $this->cdrTables($cdrdb);
		$complete = true;

		// Which calls the extension was part of
		$calls = $this->findCalls($cdrdb, $tables, $extension);

		if (!$calls) {
			freepbx_log(FPBX_LOG_INFO, 'oryk_devices: no call history found for ' . $extension);

			return $removed;
		}

		// What those calls recorded, read before the records naming it go,
		// because a call detail record is the only index into its audio
		$recordings = $this->findRecordings($cdrdb, $tables, $calls);

		// The events first, then the records. A record whose events have
		// gone is still a record; an event whose record has gone is not
		// reachable by anything, so this is the order that fails better.
		if ($this->cdrTableExists($cdrdb, 'cel')) {
			$removed['rows'] += $this->deleteCalls($cdrdb, 'cel', $calls, $complete);
		}

		foreach ($tables as $table) {
			$removed['rows'] += $this->deleteCalls($cdrdb, $table, $calls, $complete);
		}

		// The audio goes last, and only once the records that named it are
		// actually gone. A deletion that failed leaves records still
		// standing, and those records still need something to play.
		if ($complete) {
			foreach ($recordings as $file => $ignored) {
				if ($this->recordingIsOrphaned($cdrdb, $tables, $file) && $this->deleteRecording($file)) {
					$removed['recordings']++;
				}
			}
		} else {
			freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: the call history for ' . $extension . ' was not fully removed, so its recordings have been left on disk');
		}

		freepbx_log(FPBX_LOG_INFO, 'oryk_devices: removed ' . $removed['rows'] . ' call history rows and ' . $removed['recordings'] . ' recordings across ' . count($calls) . ' calls for ' . $extension);

		return $removed;
	}

	/**
	 * Find the calls an extension was part of.
	 *
	 * Both identifiers are collected from every matching record: the
	 * identifier of the record itself, and the identifier of the chain it
	 * belongs to. The second is what reaches the other channels of the same
	 * call, which carry an identifier of their own and would otherwise be
	 * left behind.
	 *
	 * @param object             $cdrdb     CDR database handle.
	 * @param array<int, string> $tables    Call detail tables to look in.
	 * @param int|string         $extension Number to find.
	 *
	 * @return array<string, bool> Call identifiers, as keys.
	 */
	private function findCalls($cdrdb, $tables, $extension)
	{
		$calls = [];

		foreach ($tables as $table) {
			$columns = $this->tableColumns($cdrdb, $table);
			$match = $this->cdrMatchClause($extension, $columns);
			$wanted = array_values(array_intersect(['uniqueid', 'linkedid'], $columns));

			if ($match === null || !$wanted) {
				continue; // nothing to match on, or nothing to collect
			}

			try {
				$sth = $cdrdb->prepare(
					'SELECT ' . implode(', ', array_map(function ($column) {
						return '`' . $column . '`';
					}, $wanted)) . ' FROM `' . $table . '` WHERE ' . $match['sql']
				);
				$sth->execute($match['params']);

				while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
					foreach ($wanted as $column) {
						if (!empty($row[$column])) {
							$calls[$row[$column]] = true;
						}
					}
				}
			} catch (\Exception $e) {
				freepbx_log(FPBX_LOG_WARNING, 'oryk_devices: unable to read ' . $table . ' for ' . $extension . ': ' . $e->getMessage());
			}
		}

		return $calls;
	}

	/**
	 * Find the recordings a set of calls made.
	 *
	 * @param object                $cdrdb  CDR database handle.
	 * @param array<int, string>    $tables Call detail tables to look in.
	 * @param array<string, bool>   $calls  Call identifiers, as keys.
	 *
	 * @return array<string, bool> Recording file names, as keys.
	 */
	private function findRecordings($cdrdb, $tables, $calls)
	{
		$recordings = [];

		foreach ($tables as $table) {
			$columns = $this->tableColumns($cdrdb, $table);

			if (!in_array('recordingfile', $columns, true)) {
				continue; // this table never names a recording
			}

			foreach ($this->callBatches($calls, $columns) as $batch) {
				try {
					$sth = $cdrdb->prepare(
						'SELECT DISTINCT recordingfile FROM `' . $table . '`'
							. ' WHERE (' . $batch['sql'] . ") AND recordingfile <> ''"
					);
					$sth->execute($batch['params']);

					while (($file = $sth->fetchColumn()) !== false) {
						if ((string) $file !== '') {
							$recordings[$file] = true;
						}
					}
				} catch (\Exception $e) {
					freepbx_log(FPBX_LOG_WARNING, 'oryk_devices: unable to read recordings from ' . $table . ': ' . $e->getMessage());
				}
			}
		}

		return $recordings;
	}

	/**
	 * Delete every row belonging to a set of calls.
	 *
	 * Used for the events and for the records alike: both tables carry the
	 * same two identifiers, so both are cleared the same way.
	 *
	 * @param object              $cdrdb    CDR database handle.
	 * @param string              $table    Table to clear.
	 * @param array<string, bool> $calls    Call identifiers, as keys.
	 * @param bool                $complete Set to false when a delete fails.
	 *
	 * @return int How many rows were removed.
	 */
	private function deleteCalls($cdrdb, $table, $calls, &$complete)
	{
		$columns = $this->tableColumns($cdrdb, $table);
		$rows = 0;

		foreach ($this->callBatches($calls, $columns) as $batch) {
			$rows += $this->runCdrUpdate(
				$cdrdb,
				'DELETE FROM `' . $table . '` WHERE ' . $batch['sql'],
				$batch['params'],
				$failed
			);

			if ($failed) {
				$complete = false;
			}
		}

		return $rows;
	}

	/**
	 * Break a set of calls into conditions a statement can carry.
	 *
	 * A busy extension is a great many calls, and one statement naming all
	 * of them at once is a statement no database will take, so they are
	 * handed out in batches.
	 *
	 * @param array<string, bool> $calls   Call identifiers, as keys.
	 * @param array<int, string>  $columns Columns the table has.
	 *
	 * @return array<int, array{sql: string, params: array<string, string>}>
	 *         One condition per batch, empty when there is nothing to match.
	 */
	private function callBatches($calls, $columns)
	{
		$keys = array_values(array_intersect(['uniqueid', 'linkedid'], $columns));

		if (!$calls || !$keys) {
			return [];
		}

		$batches = [];

		foreach (array_chunk(array_keys($calls), 250) as $batch) {
			$holders = [];
			$params = [];

			foreach ($batch as $index => $call) {
				$holders[] = ':c' . $index;
				$params[':c' . $index] = $call;
			}

			$in = ' IN (' . implode(', ', $holders) . ')';
			$clauses = [];

			foreach ($keys as $key) {
				$clauses[] = '`' . $key . '`' . $in;
			}

			$batches[] = ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
		}

		return $batches;
	}

	/**
	 * List the call detail tables this system keeps.
	 *
	 * The main table is named in Advanced Settings, and the name the CDR
	 * module reports is the one it is currently reading, which on a system
	 * running the CDR trigger is the transient copy rather than the
	 * configured table. Both are asked for, and the defaults kept alongside.
	 *
	 * The transient copy exists because the trigger behind it only fires on
	 * insert, so it is a second set of the same rows that nothing else
	 * maintains and every one of them has to be handled in its own right.
	 *
	 * @param object $cdrdb CDR database handle.
	 *
	 * @return array<int, string> Tables that are actually there.
	 */
	private function cdrTables($cdrdb)
	{
		$configured = [];

		try {
			$configured[] = (string) \FreePBX::Config()->get('CDRDBTABLENAME');
			$configured[] = (string) \FreePBX::Cdr()->getDbTable();
		} catch (\Exception $e) {
			// whatever could not be read is covered by the defaults below
		}

		$tables = array_unique(array_filter(array_merge(
			$configured,
			['cdr', 'transient_cdr', 'replicate_cdr']
		)));

		return array_values(array_filter($tables, function ($table) use ($cdrdb) {
			return $this->cdrTableExists($cdrdb, $table);
		}));
	}

	/**
	 * List the columns a table actually has.
	 *
	 * Which columns are present varies with the FreePBX version and with the
	 * optional modules a site has installed. A statement naming a column
	 * that is not there fails as a whole, and unlike the rewrites -- which
	 * run one column at a time and can afford to lose one -- a match clause
	 * is a single condition, so it is built from what is really there.
	 *
	 * @param object $cdrdb CDR database handle.
	 * @param string $table Table to describe.
	 *
	 * @return array<int, string> Column names.
	 */
	private function tableColumns($cdrdb, $table)
	{
		// Asked once per table and kept, because the recording check asks for
		// the same answer once per recording
		if (isset($this->cdrColumns[$table])) {
			return $this->cdrColumns[$table];
		}

		try {
			$sth = $cdrdb->prepare('SHOW COLUMNS FROM `' . $table . '`');
			$sth->execute();

			$this->cdrColumns[$table] = $sth->fetchAll(PDO::FETCH_COLUMN);
		} catch (\Exception $e) {
			return [];
		}

		return $this->cdrColumns[$table];
	}

	/**
	 * Build the condition that finds an extension in a call detail record.
	 *
	 * Two columns, matched exactly: the two ends of the call. Nothing else a
	 * record holds is matched on directly, and the reason is that a false
	 * match here is not one row. Each record found contributes the call it
	 * is and the chain it belongs to, and everything carrying either is
	 * deleted from both tables -- so a caller id name that happens to read
	 * as this number, or an account code a site uses for a tenant, would
	 * take whole calls belonging to somebody else with it.
	 *
	 * The rest of a call is reached through those identifiers rather than by
	 * matching, which is what makes two columns enough: the other channels
	 * of the same call carry identifiers of their own and are found through
	 * the chain, not through the number.
	 *
	 * @param int|string         $extension Number to find.
	 * @param array<int, string> $columns   Columns the table has.
	 *
	 * @return array{sql: string, params: array<string, mixed>}|null
	 *         The condition, or null when the table holds neither of them.
	 */
	private function cdrMatchClause($extension, $columns)
	{
		$clauses = [];
		$params = [];

		foreach (['src', 'dst'] as $column) {
			if (!in_array($column, $columns, true)) {
				continue;
			}

			$key = ':m' . count($params);
			$params[$key] = $extension;
			$clauses[] = '`' . $column . '` = ' . $key;
		}

		if (!$clauses) {
			return null;
		}

		return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
	}

	/**
	 * The numbers that reach an extension's mailbox rather than the extension.
	 *
	 * Core adds a set of pseudo extensions to the dialplan for a mailbox, and
	 * a prefix dials one directly. The prefix is a feature code and the
	 * feature codes are themselves that prefix and two digits, so on a two
	 * digit extension it collides with them: taking *98 for extension 98
	 * would be taking everybody's voicemail. Short extensions therefore get
	 * the pseudo extensions and nothing else.
	 *
	 * The four pseudo extensions come first, always, in a fixed order, and
	 * the prefixed number last. Callers rewriting one number into another
	 * line the two lists up by position, so nothing here may become
	 * conditional ahead of them.
	 *
	 * @param int|string $extension Extension whose mailbox is wanted.
	 *
	 * @return array<int, string> Numbers that reach the mailbox.
	 */
	private function voicemailNumbers($extension)
	{
		$dialled = [];

		foreach (['vmu', 'vmb', 'vms', 'vmi'] as $prefix) {
			$dialled[] = $prefix . $extension;
		}

		if (strlen((string) $extension) < 3) {
			return $dialled;
		}

		$prefix = $this->directVoicemailPrefix();

		if ($prefix !== '') {
			$dialled[] = $prefix . $extension;
		}

		return $dialled;
	}

	/**
	 * The prefix that dials a mailbox directly.
	 *
	 * This is the voicemail module's own feature code rather than a setting,
	 * so it is asked for where feature codes live. An administrator can
	 * change it, and can turn it off, in which case no such numbers were ever
	 * put in the dialplan and there is nothing of that shape to find.
	 *
	 * @return string The prefix, or an empty string when there is none.
	 */
	private function directVoicemailPrefix()
	{
		try {
			if (!class_exists('featurecode')) {
				$this->FreePBX->Modules->loadFunctionsInc('featurecodes');
			}

			if (!class_exists('featurecode')) {
				return '*'; // the module's own default
			}

			// Asked of the feature code itself rather than through the
			// convenience function, which answers a disabled code with a
			// human readable complaint rather than with nothing
			$code = new \featurecode('voicemail', 'directdialvoicemail');

			// Empty when the administrator has turned the code off, in which
			// case no numbers of that shape were ever put in the dialplan
			return (string) $code->getCodeActive();
		} catch (\Throwable $e) {
			return '*';
		}
	}

	/**
	 * Report whether nothing points at a recording any more.
	 *
	 * A recording belongs to a call rather than to one leg of it, and its
	 * name is written onto every record of that call, so one named by the
	 * records that have just gone may still be named by a record that stayed.
	 * Being unable to tell counts as still in use: an orphaned file costs
	 * disk, and a wrongly deleted one costs the call.
	 *
	 * @param object             $cdrdb  CDR database handle.
	 * @param array<int, string> $tables Call detail tables to look in.
	 * @param string             $file   Recording file name.
	 *
	 * @return bool True when no record names the recording.
	 */
	private function recordingIsOrphaned($cdrdb, $tables, $file)
	{
		foreach ($tables as $table) {
			if (!in_array('recordingfile', $this->tableColumns($cdrdb, $table), true)) {
				continue; // this table never names a recording
			}

			try {
				$sth = $cdrdb->prepare('SELECT 1 FROM `' . $table . '` WHERE recordingfile = ? LIMIT 1');
				$sth->execute([$file]);

				if ($sth->fetchColumn()) {
					return false;
				}
			} catch (\Exception $e) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete the audio a call recording left on disk.
	 *
	 * Recordings are filed by the date in their own name rather than by the
	 * call, which is how the CDR module finds them to play. The name is
	 * taken as a name and nothing else -- the path is built here -- so a row
	 * carrying something unexpected cannot reach outside the recordings
	 * directory.
	 *
	 * @param string $file Recording file name from the call detail record.
	 *
	 * @return bool True when a file was deleted.
	 */
	private function deleteRecording($file)
	{
		$file = basename(trim((string) $file));

		if ($file === '' || $file === '.' || $file === '..') {
			return false;
		}

		$parts = explode('-', $file);

		// type-destination-source-YYYYMMDD-HHMMSS-uniqueid.fmt
		if (!isset($parts[3]) || !preg_match('/^\d{8}/', $parts[3])) {
			return false;
		}

		$spool = \FreePBX::Config()->get('ASTSPOOLDIR');
		$base = rtrim((string) (\FreePBX::Config()->get('MIXMON_DIR') ?: $spool . '/monitor'), '/');

		$path = $base . '/' . substr($parts[3], 0, 4)
			. '/' . substr($parts[3], 4, 2)
			. '/' . substr($parts[3], 6, 2)
			. '/' . $file;

		if (!is_file($path)) {
			return false;
		}

		return @unlink($path);
	}

	/**
	 * Take an extension out of the settings that decide what an account sees.
	 *
	 * An account this module owns goes with the extension, but one belonging
	 * to a person outlives it with the number still listed among the
	 * extensions they may open. UCP shows that as an extension that is not
	 * assigned to them rather than as one that is gone.
	 *
	 * @param int|string $extension Number being deleted.
	 *
	 * @return int How many settings were changed.
	 */
	public function forgetUcpAssignments($extension)
	{
		$changed = 0;

		foreach (['userman_users_settings', 'userman_groups_settings'] as $table) {
			try {
				$sth = $this->db->prepare(
					'SELECT DISTINCT val FROM `' . $table . '` WHERE `key` = ? AND module LIKE ?'
				);
				$sth->execute(['assigned', 'ucp|%']);
				$values = $sth->fetchAll(PDO::FETCH_COLUMN);
			} catch (\Exception $e) {
				continue; // the table is not there on this install
			}

			$update = null;

			foreach ($values as $val) {
				$assigned = json_decode((string) $val, true);

				if (!is_array($assigned)) {
					continue;
				}

				$kept = array_values(array_filter($assigned, function ($listed) use ($extension) {
					return (string) $listed !== (string) $extension;
				}));

				if (count($kept) === count($assigned)) {
					continue;
				}

				try {
					$update = $update ?: $this->db->prepare(
						'UPDATE `' . $table . '` SET val = ? WHERE `key` = ? AND module LIKE ? AND val = ?'
					);
					$update->execute([json_encode($kept), 'assigned', 'ucp|%', $val]);
					$changed += $update->rowCount();
				} catch (\Exception $e) {
					freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to unassign ' . $extension . ': ' . $e->getMessage());
				}
			}
		}

		// Any web client registered against the extension, whichever module
		// put it there. The table is absent unless one of them is installed,
		// which is the common reason this does nothing.
		try {
			$sth = $this->db->prepare('DELETE FROM webrtc_clients WHERE `user` = ?');
			$sth->execute([$extension]);
			$changed += $sth->rowCount();
		} catch (\Exception $e) {
			freepbx_log(FPBX_LOG_WARNING, 'oryk_devices: no web client rows removed for ' . $extension . ': ' . $e->getMessage());
		}

		return $changed;
	}

	/**
	 * Swap one number for another inside a channel name column.
	 *
	 * A channel name carries the number between the technology and the call
	 * identifier, in three shapes: `PJSIP/1001-0000abcd`,
	 * `Local/1001@from-internal-0000abcd`, and the follow-me channel
	 * `Local/FMPR-1001@findmefollow-ringallv2-0000abcd`, which is the one the
	 * CDR module's own history query looks for as `%-1001@%`. Matching on the
	 * delimiters either side is what keeps 1001 from being found inside 11001
	 * or inside the call identifier that follows it.
	 *
	 * @param object     $cdrdb  CDR database handle.
	 * @param string     $t      Quoted table name.
	 * @param string     $column Column to rewrite.
	 * @param int|string $old    Number being left behind.
	 * @param int|string $new    Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	private function replaceInColumn($cdrdb, $t, $column, $old, $new)
	{
		$rows = 0;

		foreach ([['/', '-'], ['/', '@'], ['-', '@']] as $delimiters) {
			list($opens, $closes) = $delimiters;
			$needle = $opens . $old . $closes;

			$rows += $this->runCdrUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET `' . $column . '` = REPLACE(`' . $column . '`, :needle, :replacement)'
					. ' WHERE `' . $column . '` LIKE :match',
				[
					':needle' => $needle,
					':replacement' => $opens . $new . $closes,
					':match' => '%' . $needle . '%',
				]
			);
		}

		return $rows;
	}

	/**
	 * Run one call history update.
	 *
	 * The columns present in the CDR database vary with the FreePBX version
	 * and with which optional modules the site has installed, so a statement
	 * naming a column that is not there is logged and stepped over rather
	 * than being allowed to abandon the rest of the move.
	 *
	 * A caller that is deleting rather than rewriting cannot read a return
	 * of zero as nothing to do, because a statement that failed returns zero
	 * as well, so it is told which of the two happened.
	 *
	 * @param object                $cdrdb  CDR database handle.
	 * @param string                $sql    Statement to run.
	 * @param array<string, mixed>  $params Values to bind.
	 * @param bool|null             $failed Set to whether the statement failed.
	 *
	 * @return int How many rows the statement changed.
	 */
	private function runCdrUpdate($cdrdb, $sql, $params, &$failed = null)
	{
		$failed = false;

		try {
			$sth = $cdrdb->prepare($sql);
			$sth->execute($params);

			return $sth->rowCount();
		} catch (\Exception $e) {
			$failed = true;

			freepbx_log(FPBX_LOG_WARNING, 'oryk_devices: ' . $sql . ': ' . $e->getMessage());

			return 0;
		}
	}

	/**
	 * Move the extension in the settings that decide what an account may see.
	 *
	 * User Manager stores the extensions a UCP account is allowed to open as
	 * a list per module, and Sangoma Connect keeps its own row naming the
	 * device. Both hold the number outright, so an account left pointing at
	 * the old one opens its call history and voicemail on an extension that
	 * is no longer there, which reads to the person as though the records
	 * were lost along with the number.
	 *
	 * Accounts set to follow their own extension rather than a number are
	 * already correct and are left alone.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return int How many settings were moved.
	 */
	public function moveUcpAssignments($old, $new)
	{
		$moved = 0;

		foreach (['userman_users_settings', 'userman_groups_settings'] as $table) {
			try {
				// Matched on the stored value rather than on the account, so
				// this does not depend on how either table is keyed
				$sth = $this->db->prepare(
					'SELECT DISTINCT val FROM `' . $table . '` WHERE `key` = ? AND module LIKE ?'
				);
				$sth->execute(['assigned', 'ucp|%']);
				$values = $sth->fetchAll(PDO::FETCH_COLUMN);
			} catch (\Exception $e) {
				continue; // the table is not there on this install
			}

			$update = null;

			foreach ($values as $val) {
				$assigned = json_decode((string) $val, true);

				if (!is_array($assigned) || !in_array((string) $old, array_map('strval', $assigned), true)) {
					continue;
				}

				// array_values() keeps this a JSON list rather than an object,
				// and array_unique() stops an account already holding both
				// numbers from ending up with the new one listed twice
				$replaced = json_encode(array_values(array_unique(array_map(
					function ($extension) use ($old, $new) {
						return ((string) $extension === (string) $old) ? (string) $new : (string) $extension;
					},
					$assigned
				))));

				try {
					$update = $update ?: $this->db->prepare(
						'UPDATE `' . $table . '` SET val = ? WHERE `key` = ? AND module LIKE ? AND val = ?'
					);
					$update->execute([$replaced, 'assigned', 'ucp|%', $val]);
					$moved += $update->rowCount();
				} catch (\Exception $e) {
					freepbx_log(FPBX_LOG_ERROR, 'oryk_devices: unable to move UCP assignment ' . $old . ' to ' . $new . ': ' . $e->getMessage());
				}
			}
		}

		// Sangoma Connect names the device it registered for the account, and
		// the call history query reads it to find those calls
		try {
			$sth = $this->db->prepare('UPDATE webrtc_clients SET `user` = ? WHERE `user` = ?');
			$sth->execute([$new, $old]);
			$moved += $sth->rowCount();
		} catch (\Exception $e) {
			// Sangoma Connect is not installed on this system
		}

		return $moved;
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
				$this->forgetUcpAssignments($user);

				// The history outlives it too, and nothing in FreePBX clears it
				$this->purgeCdr($user);
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