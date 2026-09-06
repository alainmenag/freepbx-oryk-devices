<?php

// Oryk_devices.class.php

namespace FreePBX\modules;

use BMO;
use PDO;
use FreePBX_Helpers;
use FreePBX\Modules\Oryk_Devices\CdrHistory;
use FreePBX\Modules\Oryk_Devices\DeviceManager;
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
	 * Creating, saving and deleting a device.
	 *
	 * @var DeviceManager
	 */
	private $devices;

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
		$this->devices = new DeviceManager(
			$freepbx,
			$this->schema,
			$this->numbers,
			$this->renumberer,
			$this->extensions,
			$this->userman,
			$this->voicemail,
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
					$this->devices->remove($id);
				}

				header('Location: ?display=oryk_devices');

				return;

			case 'setkey': // save

				$submitted = $_REQUEST;

				try {
					$id = $this->devices->store($submitted);
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