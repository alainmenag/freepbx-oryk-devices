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
	private $table = 'oryk_devices_settings';

	public $types = [
		'pjsip' => [
			'title' => 'Extension/User',
			'icon' => 'fa-phone',
			'suffix' => '',
			'tech' => 'pjsip',
			'fields' => ['DEVICE_USER', 'HEADER_CREDENTIALS', 'DEVICE_ACCOUNT', 'DEVICE_SECRET'],
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
					\FreePBX::Core()->delDevice($id);
				}

				header('Location: ?display=oryk_devices');

				return;

			case 'setkey': // save

				//die(json_encode($_REQUEST));

				$id = $this->store($this->fields);

				$url = $_SERVER['REQUEST_URI'] . '&id=' . $id;

				header('Location: ' . $url);

				return;

			default:
				break;
		}
	}

	public function generateNumber()
	{
		$ms = round(microtime(true) * 1000);
		// use 4 ms digits + 4 random digits + '99' prefix
		$rand = random_int(1000, 9999); // secure random 4-digit number
		return '99' . substr($ms, -4) . $rand; // total = 2 + 4 + 4 = 10 digits
	}

	public function store($sets)
	{
		$id = $_REQUEST['DEVICE_ID'] ?? null;
		$user = $_REQUEST['DEVICE_USER'] ?? null;
		$deviceType = $_REQUEST['DEVICE_KIND'] ?? 'pjsip';
		$type = $this->types[$deviceType] ?? $this->types['pjsip'];
		$tech = $type['tech'] ?? 'pjsip';
		$match = \FreePBX::Core()->getDevice($id);
		$device = isset($match['id']) ? $match : null;
		$flags = 0;
		//$gid = (round(microtime(true) * 1000)); // Generate temporary ID
		$gid = $this->generateNumber();
		$uid = $id ? $id : $gid;

		$description = $_REQUEST['DEVICE_DESCRIPTION'] ?? null;

		$_REQUEST['DEVICE_DESCRIPTION'] = $description ? $description : $gid;

		$generated = \FreePBX::Core()->generateDefaultDeviceSettings(
			$tech === 'pjsip' ? 'pjsip' : 'custom',
			$user,
			$_REQUEST['DEVICE_DESCRIPTION'],
			false,
		);

		$defaults = \FreePBX::Core()->getDriver($tech)->getDefaultDeviceSettings(
			$id,
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

		// If device exists, delete it first
		if ($device) {
			\FreePBX::Core()->delDevice($device['id'], true);
		}

		$ret = \FreePBX::Core()->addDevice($uid, $tech, $generated, true);

		//update the associated endpoint configuration
		if ($ret && $tech === 'pjsip') {
			\FreePBX::Core()->processEPM($uid, $tech, true);
			needreload();
		}

		return $uid;
	}

	public function pull($id)
	{
		$base = [
			'id' => $id ?? null,
		];

		$match = \FreePBX::Core()->getDevice($id);
		$device = isset($match['id']) ? $match : null;
		$type = $this->types[$device['kind'] ?? $device['tech'] ?? ''] ?? null;
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

			$base[$obj['group'] ?? 'other'][$key] = $obj;
		}

		$base['type'] = $device['type'] ?? null;

		return $base;
	}

	//Install method. use this or install.php using both may cause weird behavior
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

		return true;
	}

	//Uninstall method. use this or install.php using both may cause weird behavior
	public function uninstall()
	{
	}

	//Not yet implemented
	public function backup()
	{
	}

	//not yet implimented
	public function restore($backup)
	{
	}

	//process form
	public function doConfigPageInit($page)
	{
	}

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

				// Combine FreePBX and Oryk devices
				// $sql = "
				// SELECT 
				// d.id,
				// d.description,
				// d.user,
				// d.tech,
				// COALESCE(k.data, d.tech) AS kind,
				// CONCAT('{', GROUP_CONCAT(CONCAT('\"', s.keyword, '\":\"', s.data, '\"')), '}') AS settings
				// FROM devices d
				// LEFT JOIN asterisk.sip k 
				// ON k.id = d.id 
				// AND k.keyword = 'kind'
				// LEFT JOIN asterisk.sip s 
				// ON s.id = d.id
				// /** search filter */
				// $where
				// GROUP BY d.id
				// ORDER BY $sort $order
				// LIMIT :limit OFFSET :offset;
				// ";
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

				// Loop through rows to decode settings JSON
				// foreach ($rows as &$row) {
				// 	$settingsJson = $row['settings'] ?? '{}';
				// 	$row['settings'] = json_decode($settingsJson, true) ?: [];
				// }

				return [
					'total' => $total,
					'rows' => $rows
				];

			default:
				return null;
		}
	}

	public function getActionBar($request)
	{
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';
		$id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : '';

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