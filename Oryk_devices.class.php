<?php

// Oryk_devices.class.php

namespace FreePBX\modules;
use BMO;
use PDO;
use FreePBX_Helpers;
class Oryk_devices extends FreePBX_Helpers implements \BMO
{
	private $table = 'oryk_devices_settings';

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

		switch ($page) {
			case 'oryk_devices':

				return load_view(__DIR__ . '/views/devices.php', [
				]);

			default:
				break;
		}
	}

	//Install method. use this or install.php using both may cause weird behavior
	public function install()
	{
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
}