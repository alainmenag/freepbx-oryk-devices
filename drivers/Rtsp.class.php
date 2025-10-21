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

		// $ip = gethostbyname(hostname: 'mediamtx');
		// $ips = explode(' ', trim(shell_exec('hostname -I')));
		// $ip = $ips[0] ?? null;
		$ip = $_SERVER['SERVER_ADDR'];

		$settings['link'] = array(
			'value' => "https://$ip:8889/$id/",
			'flag' => 0
		);

		foreach ($settings as $key => $setting) {
			$sth->execute(array($id, $key, $setting['value'], $setting['flag']));
		}
		
		$this->restart($id);

		return true;
	}

	public function delDevice($id)
	{
		// $this->mediamtx([
		// 	'method' => 'DELETE',
		// 	'api' => "/v3/config/paths/delete/$id",
		// ]);

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

	public function mediamtx($opitions)
	{
		$host = "http://0.0.0.0:9997";
		$path = $opitions['api'] ?? '/v3/paths/list';
		$data = $opitions['data'] ?? null;
		$method = $opitions['method'] ?? null;

		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, $host . $path);

		if ($method) {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		} else if ($data) {
			curl_setopt($ch, CURLOPT_POST, true);
		}

		if ($data) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);

		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		error_log("Mediamtx response code: {$httpCode}; body: " . $response);
	}

	public function restart($id)
	{
		$this->stop($id);

		return $this->start($id);
	}

	public function start($id)
	{
		$device = $this->getDevice($id);

		if (!$device) {
			throw new \Exception("Device not found");
		}

		if (!isset($device['stream_in'])) {
			throw new \Exception("Device stream in not set");
		}

		$this->mediamtx([
			'api' => "/v3/config/paths/replace/$id",
			'data' => [
				'source' => $device['stream_in'],
				'rtspTransport' => 'tcp',
				'sourceOnDemand' => false,
				'disablePublisherOverride' => true,
			]
		]);
	}

	public function stop($id)
	{
		$this->mediamtx([
			'api' => "/v3/config/paths/replace/$id",
			'data' => [
				'source' => 'publisher',
			]
		]);
	}
}




/*
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
*/


/*
ffmpeg -rtsp_transport tcp -re \
-i "rtsp://admin:password123@192.168.1.123:554/cam/realmonitor?channel=1&subtype=0" \
-vf "format=yuv420p" \
-c:v libx264 -preset ultrafast -tune zerolatency -b:v 4M -r 25 -g 50 \
-c:a aac -ar 8000 -ac 1 -b:a 64k \
-f flv "rtmp://0.0.0.0:1935/15thave"
*/

/*
$cmd = sprintf(
	'ffmpeg -rtsp_transport tcp -i %s -c:v libx264 -preset veryfast -b:v 4M -c:a aac -b:a 128k -f flv %s > /tmp/ffmpeg_%s.log 2>&1 & echo $!',
	escapeshellarg($device['stream_in']),
	escapeshellarg($device['stream_out']),
	escapeshellarg($id)
);

error_log($cmd);


return false;

$pid = exec($cmd);

$this->addDevice($id, array(
	'pid' => array(
		'value' =>$pid,
		'flag' => 0
	)
));

return $pid;
*/




/*
https://mediamtx.org/docs/references/control-api

curl http://192.168.10.78:9997/v3/paths/list
curl http://192.168.10.78:9997/v3/paths/get/9952047528

curl -X POST http://192.168.10.78:9997/v3/config/paths/replace/1 \
  -H "Content-Type: application/json" \
  -d '{"source":"rtsp://admin:password123@192.168.1.123:554/cam/realmonitor?channel=1&subtype=1"}'

curl -X POST http://192.168.10.78:9997/v3/config/paths/replace/2 \
  -H "Content-Type: application/json" \
  -d '{"source":"rtsp://admin:password123@192.168.1.123:554/cam/realmonitor?channel=2&subtype=0"}'

curl http://192.168.10.78:9997/v3/webrtcsessions/list
*/





