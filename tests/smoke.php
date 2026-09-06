<?php

// tests/smoke.php
//
// Run from anywhere, with nothing installed:
//
//     php tests/smoke.php
//
// This does not need FreePBX, a database or Asterisk. It stands the
// subsystems up against stubs and checks the things that are true whatever
// the site looks like: that the classes load and build at all, that a
// missing module is declined rather than thrown, that the two lists the
// call history lines up by position stay the same length, and that the
// purge guard refuses anything that is not a number.
//
// It is not a substitute for trying a renumber on a real PBX. It is here to
// catch the class of mistake a refactor makes -- a namespace, a constructor,
// a method that moved and left a caller behind -- before a deploy does.

define('FPBX_LOG_ERROR', 'ERROR');
define('FPBX_LOG_WARNING', 'WARNING');
define('FPBX_LOG_INFO', 'INFO');

$LOG = [];

function freepbx_log($level, $message)
{
	global $LOG;

	$LOG[] = $level . ': ' . $message;
}

/** Stands in for the Modules object, with nothing installed by default. */
class OrykSmokeModules
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

/** Stands in for the FreePBX application object. */
class OrykSmokeFreePBX
{
	public $Modules;
	public $Database;
	public $astman;

	public function __construct()
	{
		$this->Modules = new OrykSmokeModules();
		$this->Database = null;
		$this->astman = null;
	}
}

if (!class_exists('FreePBX')) {
	class FreePBX
	{
		public static function Config()
		{
			return new class {
				public function get($key)
				{
					return '/var/spool/asterisk';
				}
			};
		}
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

use FreePBX\Modules\Oryk_Devices\CdrHistory;
use FreePBX\Modules\Oryk_Devices\UsermanManager;
use FreePBX\Modules\Oryk_Devices\VoicemailManager;

$passed = 0;
$failed = 0;

function is_eq($label, $got, $want)
{
	global $passed, $failed;

	$ok = $got === $want;
	$ok ? $passed++ : $failed++;

	printf(
		"  [%s] %-52s %s\n",
		$ok ? 'ok' : 'FAIL',
		$label,
		$ok ? '' : 'got ' . json_encode($got) . ', wanted ' . json_encode($want)
	);
}

$freepbx = new OrykSmokeFreePBX();

echo "they build:\n";

$voicemail = new VoicemailManager($freepbx);
$cdr = new CdrHistory($freepbx, $voicemail);
$userman = new UsermanManager($freepbx);

is_eq('VoicemailManager', get_class($voicemail), 'FreePBX\Modules\Oryk_Devices\VoicemailManager');
is_eq('CdrHistory', get_class($cdr), 'FreePBX\Modules\Oryk_Devices\CdrHistory');
is_eq('UsermanManager', get_class($userman), 'FreePBX\Modules\Oryk_Devices\UsermanManager');

echo "\nwhat reaches a mailbox, which the call history lines up by position:\n";

is_eq(
	'a long enough number gets the prefix as well',
	$voicemail->dialableNumbers('1001'),
	['vmu1001', 'vmb1001', 'vms1001', 'vmi1001', '*1001']
);
is_eq(
	'a two digit one would collide with the feature codes',
	$voicemail->dialableNumbers('98'),
	['vmu98', 'vmb98', 'vms98', 'vmi98']
);
is_eq(
	'two numbers of the same shape line up',
	count($voicemail->dialableNumbers('1001')),
	count($voicemail->dialableNumbers('2002'))
);

echo "\nnothing installed: everything declines rather than throwing:\n";

is_eq('moveMailbox', $voicemail->moveMailbox('1001', '2002'), false);
is_eq('hasMailbox', $voicemail->hasMailbox('1001'), false);
is_eq('syncEmail', $voicemail->syncEmail('1001', 'someone@example.com'), false);
is_eq('cdr migrate', $cdr->migrate('1001', '2002'), 0);
is_eq('cdr purge', $cdr->purge('1001'), ['rows' => 0, 'recordings' => 0]);
is_eq('userman findByExtension', $userman->findByExtension('1001'), null);
is_eq('userman ownedAccount', $userman->ownedAccount('1001'), null);
is_eq('userman ensure', $userman->ensure('1001', 'Desk Phone'), false);
is_eq('userman sync', $userman->sync('1001', 'Desk Phone'), false);
is_eq('userman removeOwnedAccount', $userman->removeOwnedAccount('1001'), false);

echo "\nthe purge guard: what is not a number would match the whole table:\n";

$LOG = [];

is_eq('a path is refused', $cdr->purge('../etc'), ['rows' => 0, 'recordings' => 0]);
is_eq('and said so once', count($LOG), 1);
is_eq('with the module prefix', strpos($LOG[0], 'ERROR: oryk_devices: refusing to purge') === 0, true);
is_eq('nothing is refused', $cdr->purge(''), ['rows' => 0, 'recordings' => 0]);
is_eq('a fragment of SQL is refused', $cdr->purge('1001 OR 1=1'), ['rows' => 0, 'recordings' => 0]);
is_eq('a number with a space is refused', $cdr->purge('10 01'), ['rows' => 0, 'recordings' => 0]);

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed ? 1 : 0);
