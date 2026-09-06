<?php

// tests/stubs.php
//
// Just enough FreePBX to stand the subsystems up outside one. Everything
// here is a stub: no database, no Asterisk, no configuration files. What it
// gives back is fixed and what it is asked is recorded, so a test can check
// what a subsystem tried to do rather than what came of it.

define('FPBX_LOG_ERROR', 'ERROR');
define('FPBX_LOG_WARNING', 'WARNING');
define('FPBX_LOG_INFO', 'INFO');

$LOG = [];

function freepbx_log($level, $message)
{
	global $LOG;

	$LOG[] = $level . ': ' . $message;
}

function needreload()
{
	return true;
}

/** A prepared statement that answers with whatever it was handed. */
class StubStatement
{
	public $column = false;
	public $rows = [];
	public $params = null;

	public function execute($params = null)
	{
		$this->params = $params;

		return true;
	}

	public function fetchColumn($n = 0)
	{
		return $this->column;
	}

	public function fetchAll($mode = null)
	{
		return $this->rows;
	}

	public function bindValue($k, $v, $t = null)
	{
		return true;
	}

	public function rowCount()
	{
		return 0;
	}
}

/**
 * A database that answers by what the statement looks like.
 *
 * $answers maps a distinctive fragment of SQL onto the value fetchColumn()
 * should give back. Anything not listed answers false, which for every
 * query this module makes means "nothing holds that".
 */
class StubDatabase
{
	public $answers = [];
	public $seen = [];

	public function prepare($sql)
	{
		$this->seen[] = $sql;
		$statement = new StubStatement();

		foreach ($this->answers as $fragment => $value) {
			if (strpos($sql, $fragment) !== false) {
				$statement->column = $value;

				break;
			}
		}

		return $statement;
	}

	public function exec($sql)
	{
		$this->seen[] = $sql;

		return 0;
	}
}

/** The bit of a Core driver that store() asks about. */
class StubDriver
{
	public function getDefaultDeviceSettings($id, $displayname, &$flag)
	{
		return ['dial' => 'PJSIP', 'settings' => []];
	}
}

/** The parts of the Core module this module calls. */
class StubCore
{
	public $devices = [];
	public $users = [];
	public $added = null;
	public $deleted = [];
	public $epm = [];

	public function getDevice($id)
	{
		return $this->devices[(string) $id] ?? [];
	}

	public function generateDefaultDeviceSettings($tech, $user, $displayname, $flag)
	{
		return [
			'description' => ['value' => $displayname, 'flag' => 1],
			'user' => ['value' => $user, 'flag' => 2],
			'secret' => ['value' => 'from-core', 'flag' => 3],
			'emergency_cid' => ['value' => '', 'flag' => 4],
			'media_encryption' => ['value' => 'no', 'flag' => 5],
		];
	}

	public function getDriver($tech)
	{
		return new StubDriver();
	}

	public function generateDefaultUserSettings($extension, $displayname)
	{
		return ['extension' => $extension, 'name' => $displayname];
	}

	public function addUser($extension, $settings)
	{
		$this->users[(string) $extension] = $settings;

		return true;
	}

	public function delUser($extension, $editmode = false)
	{
		unset($this->users[(string) $extension]);

		return true;
	}

	public function addDevice($id, $tech, $settings, $editmode = false)
	{
		$this->added = ['id' => $id, 'tech' => $tech, 'settings' => $settings];

		return true;
	}

	public function delDevice($id, $editmode = false)
	{
		$this->deleted[] = [(string) $id, $editmode];

		return true;
	}

	public function processEPM($id, $tech, $flag)
	{
		$this->epm[] = (string) $id;

		return true;
	}
}

class StubModules
{
	public $active = [];

	public function checkStatus($module)
	{
		return in_array($module, $this->active, true);
	}

	public function loadFunctionsInc($module)
	{
		return false;
	}
}

class StubApp
{
	public $Modules;
	public $Database;
	public $astman;

	public function __construct()
	{
		$this->Modules = new StubModules();
		$this->Database = new StubDatabase();
		$this->astman = null;
	}
}

/** The static entry points, pointed at one shared set of stubs. */
class FreePBX
{
	public static $core;
	public static $config = ['ASTSPOOLDIR' => '/var/spool/asterisk'];

	public static function Core()
	{
		if (self::$core === null) {
			self::$core = new StubCore();
		}

		return self::$core;
	}

	public static function Config()
	{
		return new class {
			public function get($key)
			{
				return FreePBX::$config[$key] ?? '';
			}
		};
	}

	public static function Framework()
	{
		return new class {
			public function doReload()
			{
				return ['status' => true];
			}
		};
	}
}

// The same loader the module registers, pointed at this checkout
spl_autoload_register(function ($class) {
	$prefix = 'FreePBX\\Modules\\Oryk_Devices\\';

	if (strpos($class, $prefix) !== 0) {
		return;
	}

	$relative = substr($class, strlen($prefix));

	if (strpos($relative, 'Drivers\\') === 0) {
		return;
	}

	$file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

	if (is_file($file)) {
		require_once $file;
	}
});
