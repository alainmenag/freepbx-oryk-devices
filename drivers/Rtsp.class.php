<?php
namespace FreePBX\Modules\Oryk_Devices\Drivers;

use FreePBX\Modules\Core\Driver;

/*
ffmpeg -rtsp_transport tcp \
  -i "rtsp://admin:password123@192.168.1.123:554/cam/realmonitor?channel=1&subtype=0" \
  -c:v libx264 -preset veryfast -b:v 4M \
  -c:a aac -b:a 128k \
  -f flv rtmp://192.168.10.121:1935/15thave2
*/

class Rtsp extends Driver
{
	// public function getDefaultDeviceSettings($id, $displayname, &$flag)
	// {
	// 	$dial = 'RTSP';
	// 	$settings = array(
	// 		// "endpoint" => array(
	// 		// 	"value" => $this->freepbx->Config->get('DEVICE_ENDPOINT'),
	// 		// 	"flag" => $flag++
	// 		// ),
	// 	);
	// 	return array(
	// 		"dial" => $dial,
	// 		"settings" => $settings
	// 	);
	// }

	public function addDevice($id, $settings)
	{
		$sql = 'INSERT INTO sip (id, keyword, data, flags) values (?,?,?,?)';
		$sth = $this->database->prepare($sql);
		$settings = is_array($settings) ? $settings : array();
		foreach ($settings as $key => $setting) {
			$sth->execute(array($id, $key, $setting['value'], $setting['flag']));
		}
		
		//$this->start($id);

		return true;
	}

	public function delDevice($id)
	{
		//$this->stop($id);

		$sql = "DELETE FROM sip WHERE id = ?";
		$sth = $this->database->prepare($sql);
		$sth->execute(array($id));
		return true;
	}

	public function delSetting($id, $keyword)
	{
		$sql = "DELETE FROM sip WHERE id = ? AND keyword = ?";
		$sth = $this->database->prepare($sql);
		$sth->execute(array($id, $keyword));

		return true;
	}

	public function getDevice($id)
	{
		$sql = "SELECT keyword,data FROM sip WHERE id = ?";
		$sth = $this->database->prepare($sql);
		$tech = array();
		try {
			$sth->execute(array($id));
			$tech = $sth->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP);
			//reformulate into what is expected
			//This is in the try catch just for organization
			foreach ($tech as &$value) {
				$value = $value[0];
			}
		} catch (\Exception $e) {
		}

		return $tech;
	}

	public function restart($id)
	{
		return $this->start($id);
	}

	public function start($id)
	{
		$device = $this->getDevice($id);

		if (!$device) {
			throw new \Exception("Device not found");
		}

		$this->stop($id);

		if (!isset($device['stream_in'])) {
			throw new \Exception("Device stream in not set");
		}

		if (!isset($device['stream_out'])) {
			throw new \Exception("Device stream out not set");
		}

		$cmd = sprintf(
			'ffmpeg -rtsp_transport tcp -i %s -c:v libx264 -preset veryfast -b:v 4M -c:a aac -b:a 128k -f flv %s > /tmp/ffmpeg_%s.log 2>&1 & echo $!',
			escapeshellarg($device['stream_in']),
			escapeshellarg($device['stream_out']),
			escapeshellarg($id)
		);

		$pid = exec($cmd);

		$this->addDevice($id, array(
			'pid' => array(
				'value' =>$pid,
				'flag' => 0
			)
		));

		return $pid;
	}

	public function stop($id)
	{
		$device = $this->getDevice($id);

		if (!$device) {
			throw new \Exception("Device not found");
		}

		$killed = false;
		$pid = isset($device['pid']) ? $device['pid'] : null;

		if ($pid) {
			exec('kill ' . escapeshellarg($pid));
		}

		$this->delSetting($id, 'pid');

		return $killed;
	}
}
