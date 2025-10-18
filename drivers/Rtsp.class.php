<?php
namespace FreePBX\Modules\Oryk_Devices\Drivers;

use FreePBX\Modules\Core\Driver;

class Rtsp extends Driver
{
	public function getDefaultDeviceSettings($id, $displayname, &$flag) {
		$dial = 'RTSP';
		$settings  = array(
			// "endpoint" => array(
			// 	"value" => $this->freepbx->Config->get('DEVICE_ENDPOINT'),
			// 	"flag" => $flag++
			// ),
		);
		return array(
			"dial" => $dial,
			"settings" => $settings
		);
	}

	public function addDevice($id, $settings) {
		$sql = 'INSERT INTO sip (id, keyword, data, flags) values (?,?,?,?)';
		$sth = $this->database->prepare($sql);
		$settings = is_array($settings)?$settings:array();
		foreach($settings as $key => $setting) {
			$sth->execute(array($id,$key,$setting['value'],$setting['flag']));
		}
		return true;
	}

	public function delDevice($id) {
		$sql = "DELETE FROM sip WHERE id = ?";
		$sth = $this->database->prepare($sql);
		$sth->execute(array($id));
		return true;
	}

	public function getDevice($id) {
		$sql = "SELECT keyword,data FROM sip WHERE id = ?";
		$sth = $this->database->prepare($sql);
		$tech = array();
		try {
			$sth->execute(array($id));
			$tech = $sth->fetchAll(\PDO::FETCH_COLUMN|\PDO::FETCH_GROUP);
			//reformulate into what is expected
			//This is in the try catch just for organization
			foreach($tech as &$value) {
				$value = $value[0];
			}
		} catch(\Exception $e) {}

		return $tech;
	}
}
