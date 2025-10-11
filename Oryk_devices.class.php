<?php

// Oryk_devices.class.php

namespace FreePBX\modules;
use BMO;
use PDO;
use FreePBX_Helpers;
class Oryk_devices extends FreePBX_Helpers implements \BMO
{
	private $table = 'oryk_devices_settings';

	public $sets = [

		'DEVICE_ID' => [
			'type' => 'hidden',
			'name' => 'id',
			'maxLength' => 15,
		],

		'DEVICE_DESCRIPTION' => [
			'type' => 'text',
			'title' => 'Description',
			'example' => 'Desk Phone',
			'name' => 'description',
			'maxLength' => 255,
		],

		'CREDENTIALS' => [
			'html' => '<h2>Credentials</h2>',
		],

		'DEVICE_USERNAME' => [
			'type' => 'text',
			'title' => 'Username',
			'name' => 'username',
			'maxLength' => 255,
		],

		'DEVICE_PASSWORD' => [
			'type' => 'password',
			'title' => 'Password',
			'name' => 'password',
			'maxLength' => 255,
		],

		'LOCATION' => [
			'html' => '<h2>Location</h2>',
		],

		'DEVICE_USER' => [
			'type' => 'number',
			'title' => 'User/Extension',
			'example' => '1001',
			'name' => 'user',
			'maxLength' => 10,
		],

		'DEVICE_ENDPOINT' => [
			'type' => 'text',
			'title' => 'Endpoint',
			'example' => 'rtsp://',
			'name' => 'endpoint',
			'maxLength' => 255,
		],

		'DEVICE_LINK' => [
			'type' => 'url',
			'title' => 'Link',
			'example' => 'http(s)://',
			'name' => 'link',
			'maxLength' => 255,
		],

		'MAKE' => [
			'html' => '<h2>Make</h2>',
		],

		'DEVICE_MODEL' => [
			'type' => 'text',
			'title' => 'Model',
			'name' => 'model',
			'maxLength' => 255,
		],

		'DEVICE_MANUFACTURER' => [
			'type' => 'text',
			'title' => 'Manufacturer',
			'name' => 'manufacturer',
			'maxLength' => 255,
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
	}

	public function showPage()
	{
		$request = $_REQUEST;
		$page = isset($_REQUEST['display']) ? $_REQUEST['display'] : 'default';
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';

		switch ($action) {
			case 'list':

				return load_view(__DIR__ . '/views/devices.php', [
				]);

			case 'view':

				$this->sets = $this->fill_data(sets: $this->sets);

				return load_view(__DIR__ . '/views/device.php', [
					'sets' => $this->sets,
					'id' => isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : '',
				]);

			case 'del':

				$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : '';

				if ($id) {
					$this->db->exec("DELETE FROM {$this->table} WHERE id = " . (int)$_REQUEST['id']);
				}

				header('Location: ?display=oryk_devices');

				return;

			case 'setkey':

				$id = $this->store_data(sets: $this->sets);

				$url = $_SERVER['REQUEST_URI'] . '&id=' . $id;

				header('Location: ' . $url);

			default:
				break;
		}
	}

	//Install method. use this or install.php using both may cause weird behavior
	public function install()
	{
		// ensure module settings table is created
		$this->db->exec("
			CREATE TABLE IF NOT EXISTS {$this->table} (
				id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
				updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			)
		");

		foreach ($this->sets as $key => $set) {
			if (isset($set['name'])) {
				$column = $set['name'];
				$length = isset($set['maxLength']) ? (int)$set['maxLength'] : 255;
				$this->db->exec("
					ALTER TABLE {$this->table} 
					ADD COLUMN IF NOT EXISTS {$column} VARCHAR($length) DEFAULT NULL,
					MODIFY COLUMN {$column} VARCHAR($length) DEFAULT NULL
				");
			}
		}
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
		return false;
	}

	public function ajaxHandler()
	{
		return false;
	}

	public function getActionBar($request)
	{
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';

		switch ($action) {
			case 'list':
				$buttons = array(
					'add' => array(
						'name' => 'add',
						'id' => 'add',
						'value' => _('Add')
					),
				); break;
			case 'view':
				$buttons = array(
					'submit' => array(
						'name' => 'submit',
						'id' => 'submit',
						'value' => _('Save')
					),
					'delete' => array(
						'name' => 'delete',
						'id' => 'delete',
						'value' => _('Delete')
					),
					'close' => array(
						'name' => 'close',
						'id' => 'close',
						'value' => _('Close')
					),
				); break;
			default:
				break;
		}
		return $buttons;
	}

	public function store_data($sets)
	{
		$id = $_REQUEST['DEVICE_ID'] ?? null;
		$keys = [];
		$values = [];
		$data = [];
		
		if ($id < 1) {
			unset($_REQUEST['DEVICE_ID']);
		}

		foreach ($sets as $key => $set) {
			if (isset($_REQUEST[$key]) && !($set['disabled'] ?? false) && isset($set['name'])) {
				$data[':' . $set['name']] = $_REQUEST[$key];
				$keys[] = $set['name'];
				$values[] = ':' . $set['name'];
			}
		}
		
		$keys = implode(',', $keys);
		$values = implode(',', $values);
		$updates = implode(', ', array_map(function($k) {
			return "$k = :$k";
		}, explode(',', $keys)));

		$sql = "
			INSERT INTO {$this->table} ($keys)
			VALUES ($values)
			ON DUPLICATE KEY UPDATE $updates
		";

		//echo $sql; die();
		
		$stmt = $this->db->prepare($sql);
		$save = $stmt->execute($data);
		$id = $id ? $id : $this->db->lastInsertId();

		return $save ? $id : null;
	}

	public function fill_data($sets)
	{
		$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;

		if (!$id) {
			return $sets;
		}

		$sql = "SELECT * FROM {$this->table} WHERE id = $id";
		$stmt = $this->db->prepare($sql);
		
		$stmt->execute();

		$results = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$results) {
			return $sets;
		}

		foreach ($sets as $key => &$set) {
			if (isset($set['name']) && isset($results[$set['name']])) {
				$set['value'] = $results[$set['name']];
			}
		}

		return $sets;
	}
}