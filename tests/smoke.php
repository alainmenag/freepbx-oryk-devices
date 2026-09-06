<?php

// tests/smoke.php
//
// Run from anywhere, with nothing installed:
//
//     php tests/smoke.php
//
// This does not need FreePBX, a database or Asterisk -- tests/stubs.php
// stands in for all three. It is not a substitute for trying a renumber on
// a real PBX. It is here to catch the class of mistake refactoring makes --
// a namespace, a constructor, a method that moved and left a caller behind,
// a guard that throws instead of declining -- before a deploy does.

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/namespacing.php';

use FreePBX\Modules\Oryk_Connect\CdrHistory;
use FreePBX\Modules\Oryk_Connect\DeviceManager;
use FreePBX\Modules\Oryk_Connect\DeviceSchema;
use FreePBX\Modules\Oryk_Connect\ExtensionManager;
use FreePBX\Modules\Oryk_Connect\ExtensionRenumberer;
use FreePBX\Modules\Oryk_Connect\NumberAllocator;
use FreePBX\Modules\Oryk_Connect\UcpAssignments;
use FreePBX\Modules\Oryk_Connect\UsermanManager;
use FreePBX\Modules\Oryk_Connect\VoicemailManager;

$passed = 0;
$failed = 0;

function is_eq($label, $got, $want)
{
	global $passed, $failed;

	$ok = $got === $want;
	$ok ? $passed++ : $failed++;

	printf(
		"  [%s] %-54s %s\n",
		$ok ? 'ok' : 'FAIL',
		$label,
		$ok ? '' : 'got ' . json_encode($got) . ', wanted ' . json_encode($want)
	);
}

/** Build the whole set, the way the module class does. */
function build()
{
	FreePBX::$core = new StubCore();
	FreePBX::$cdr = new StubCdr();

	$app = new StubApp();
	$schema = new DeviceSchema($app);
	$voicemail = new VoicemailManager($app);
	$cdr = new CdrHistory($app, $voicemail);
	$userman = new UsermanManager($app);
	$ucp = new UcpAssignments($app);
	$extensions = new ExtensionManager($app);
	$numbers = new NumberAllocator($app, $userman);
	$renumberer = new ExtensionRenumberer($app, $extensions, $voicemail, $userman, $ucp, $cdr);
	$devices = new DeviceManager(
		$app, $schema, $numbers, $renumberer, $extensions, $userman, $voicemail, $ucp, $cdr
	);

	return compact(
		'app', 'schema', 'voicemail', 'cdr', 'userman', 'ucp',
		'extensions', 'numbers', 'renumberer', 'devices'
	);
}

$s = build();

echo "everything builds, and wires together the way the module does:\n";

foreach (['schema' => 'DeviceSchema', 'voicemail' => 'VoicemailManager',
          'cdr' => 'CdrHistory', 'userman' => 'UsermanManager',
          'ucp' => 'UcpAssignments', 'extensions' => 'ExtensionManager',
          'numbers' => 'NumberAllocator', 'renumberer' => 'ExtensionRenumberer',
          'devices' => 'DeviceManager'] as $key => $class) {
	is_eq($class, get_class($s[$key]), 'FreePBX\Modules\Oryk_Connect\\' . $class);
}

echo "\nthe schema still describes every kind of device:\n";

foreach (['pjsip', 'handset', 'softphone', 'rtsp'] as $kind) {
	is_eq('kind ' . $kind, isset($s['schema']->types[$kind]), true);
}

is_eq('only Extension/User creates a user',
	array_keys(array_filter($s['schema']->types, function ($t) {
		return !empty($t['creates_user']);
	})), ['pjsip']);
is_eq('Extension/User forces media encryption on',
	$s['schema']->types['pjsip']['settings']['media_encryption'] ?? null, 'sdes');
$undefined = [];

foreach ($s['schema']->types as $kind => $type) {
	foreach (($type['fields'] ?? []) as $field) {
		if (!isset($s['schema']->fields[$field])) {
			$undefined[] = $kind . '.' . $field;
		}
	}
}

is_eq('every field a type names is defined', $undefined, []);

echo "\nwhat reaches a mailbox, which the call history lines up by position:\n";

is_eq('a long enough number gets the prefix as well',
	$s['voicemail']->dialableNumbers('1001'),
	['vmu1001', 'vmb1001', 'vms1001', 'vmi1001', '*1001']);
is_eq('a two digit one would collide with the feature codes',
	$s['voicemail']->dialableNumbers('98'),
	['vmu98', 'vmb98', 'vms98', 'vmi98']);
is_eq('two numbers of the same shape line up',
	count($s['voicemail']->dialableNumbers('1001')),
	count($s['voicemail']->dialableNumbers('2002')));

echo "\nnumber allocation:\n";

$s['app']->Database->answers = ['MAX(CAST(id' => '9990000005'];
is_eq('the next id follows the highest taken', $s['numbers']->generate(), '9990000006');

$s['app']->Database->answers = [];
is_eq('nothing taken yet starts the range', $s['numbers']->generate(), '9990000001');
is_eq('a free number is accepted', $s['numbers']->assertAvailable('1001'), '1001');
is_eq('a free number is not a conflict', $s['numbers']->findConflict('1001'), null);

foreach (['abc', '10a1', '', '10 01', '-5'] as $bad) {
	$threw = false;
	try {
		$s['numbers']->assertAvailable($bad);
	} catch (\Exception $e) {
		$threw = true;
	}
	is_eq('"' . $bad . '" is refused', $threw, true);
}

$threw = false;
try {
	$s['numbers']->assertAvailable('12345678901');
} catch (\Exception $e) {
	$threw = true;
}
is_eq('too long is refused', $threw, true);

$s['app']->Database->answers = ['SELECT description FROM devices' => 'Front Desk'];
is_eq('a number a device holds is a conflict',
	is_string($s['numbers']->findConflict('1001')), true);
is_eq('but the device may keep its own number',
	$s['numbers']->assertAvailable('1001', '1001'), '1001');
$s['app']->Database->answers = [];

echo "\nnothing installed: every subsystem declines rather than throwing:\n";

is_eq('moveMailbox', $s['voicemail']->moveMailbox('1001', '2002'), false);
is_eq('hasMailbox', $s['voicemail']->hasMailbox('1001'), false);
is_eq('syncEmail', $s['voicemail']->syncEmail('1001', 'a@example.com'), false);
is_eq('cdr migrate', $s['cdr']->migrate('1001', '2002'), 0);
is_eq('cdr purge', $s['cdr']->purge('1001'), ['rows' => 0, 'recordings' => 0]);
is_eq('userman findByExtension', $s['userman']->findByExtension('1001'), null);
is_eq('userman ownedAccount', $s['userman']->ownedAccount('1001'), null);
is_eq('userman ensure', $s['userman']->ensure('1001', 'Desk'), false);
is_eq('userman sync', $s['userman']->sync('1001', 'Desk'), false);
is_eq('userman removeOwnedAccount', $s['userman']->removeOwnedAccount('1001'), false);

echo "\nthe purge guard: what is not a number would match the whole table:\n";

$LOG = [];
is_eq('a path is refused', $s['cdr']->purge('../etc'), ['rows' => 0, 'recordings' => 0]);
is_eq('and said so once', count($LOG), 1);
is_eq('with the module prefix',
	strpos($LOG[0], 'ERROR: oryk_connect: refusing to purge') === 0, true);
is_eq('nothing is refused', $s['cdr']->purge(''), ['rows' => 0, 'recordings' => 0]);
is_eq('a fragment of SQL is refused', $s['cdr']->purge('1001 OR 1=1'), ['rows' => 0, 'recordings' => 0]);
is_eq('a number with a space is refused', $s['cdr']->purge('10 01'), ['rows' => 0, 'recordings' => 0]);

echo "\nsaving a device -- what store() actually builds:\n";

$s = build();
$input = [
	'DEVICE_ID' => '',
	'DEVICE_KIND' => 'pjsip',
	'DEVICE_USER' => '1001',
	'DEVICE_DESCRIPTION' => 'Front Desk',
	'DEVICE_EMAIL' => 'desk@example.com',
	'DEVICE_SECRET' => 'from-the-form',
	'media_encryption' => 'no',
];
$before = $input;

$uid = $s['devices']->store($input);
$added = FreePBX::$core->added;
$settings = $added['settings'];

is_eq('the typed number becomes the device id', $uid, '1001');
is_eq('store() leaves the caller\'s form untouched', $input, $before);
is_eq('the device is added under that id', (string) $added['id'], '1001');
is_eq('as pjsip', $added['tech'], 'pjsip');
is_eq('account is the id', $settings['account']['value'], '1001');
is_eq('dial follows the driver', $settings['dial']['value'], 'PJSIP/1001');
is_eq('mailbox is the device alias', $settings['mailbox']['value'], '1001@device');
is_eq('user is the id, since this kind owns it', $settings['user']['value'], '1001');
is_eq('the description is what was typed', $settings['description']['value'], 'Front Desk');
is_eq('the secret from the form wins', $settings['secret']['value'], 'from-the-form');
is_eq('emergency cid defaults to the id', $settings['emergency_cid']['value'], '1001');
is_eq('every setting has a value', count(array_filter($settings, function ($x) {
	return !array_key_exists('value', $x);
})), 0);
is_eq('every setting has a flag', count(array_filter($settings, function ($x) {
	return !array_key_exists('flag', $x);
})), 0);
is_eq('including the ones not returned by the driver',
	[isset($settings['account']['flag']), isset($settings['dial']['flag']),
	 isset($settings['mailbox']['flag']), isset($settings['media_encryption_optimistic']['flag'])],
	[true, true, true, true]);

echo "\n  the type pins some settings down, whatever the form said:\n";

is_eq('media encryption is forced on', $settings['media_encryption']['value'], 'sdes');
is_eq('and optimistically', $settings['media_encryption_optimistic']['value'], 'yes');

echo "\n  a blank description falls back to the number:\n";

$s = build();
$uid = $s['devices']->store([
	'DEVICE_ID' => '', 'DEVICE_KIND' => 'pjsip', 'DEVICE_USER' => '1002',
	'DEVICE_DESCRIPTION' => '',
]);
is_eq('the device is named after itself',
	FreePBX::$core->added['settings']['description']['value'], '1002');
is_eq('and the extension is created with that name',
	FreePBX::$core->users['1002']['name'] ?? null, '1002');

echo "\n  a blank number is generated, not left empty:\n";

$s = build();
$s['app']->Database->answers = ['MAX(CAST(id' => '9990000012'];
$uid = $s['devices']->store([
	'DEVICE_ID' => '', 'DEVICE_KIND' => 'pjsip', 'DEVICE_USER' => '',
	'DEVICE_DESCRIPTION' => 'Generated',
]);
is_eq('the next id in the range', $uid, '9990000013');
is_eq('the endpoint manager is run for it', FreePBX::$core->epm, ['9990000013']);

echo "\n  a kind that does not own a user leaves the extension alone:\n";

$s = build();
$s['app']->Database->answers = ['MAX(CAST(id' => '9990000020'];
$uid = $s['devices']->store([
	'DEVICE_ID' => '', 'DEVICE_KIND' => 'handset', 'DEVICE_USER' => '1001',
	'DEVICE_DESCRIPTION' => 'Desk Handset',
]);
is_eq('the handset gets a generated id', $uid, '9990000021');
is_eq('and points at the extension that was typed',
	FreePBX::$core->added['settings']['user']['value'], '1001');
is_eq('no extension was created', FreePBX::$core->users, []);

echo "\n  a number already taken is refused, and nothing is written:\n";

$s = build();
$s['app']->Database->answers = ['SELECT description FROM devices' => 'Somebody Else'];
$threw = false;
try {
	$s['devices']->store([
		'DEVICE_ID' => '', 'DEVICE_KIND' => 'pjsip', 'DEVICE_USER' => '1001',
		'DEVICE_DESCRIPTION' => 'Mine',
	]);
} catch (\Exception $e) {
	$threw = true;
}
is_eq('it throws', $threw, true);
is_eq('no device was added', FreePBX::$core->added, null);
is_eq('no extension was created', FreePBX::$core->users, []);

echo "\nreading a device back into the form:\n";

$s = build();
FreePBX::$core->devices['1001'] = [
	'id' => '1001', 'tech' => 'pjsip', 'kind' => 'pjsip',
	'description' => 'Front Desk', 'user' => '1001',
];
$form = $s['schema']->buildFormData('1001');

is_eq('the id comes back', $form['id'], '1001');
is_eq('the description is in the basics group',
	$form['basics']['DEVICE_DESCRIPTION']['value'] ?? null, 'Front Desk');
is_eq('the kind field offers every type',
	array_keys($form['basics']['DEVICE_KIND']['options'] ?? []),
	['pjsip', 'handset', 'softphone', 'rtsp']);

$redraw = $s['schema']->buildFormData('1001', [
	'DEVICE_DESCRIPTION' => 'What Was Typed',
	'DEVICE_KIND' => 'pjsip',
]);
is_eq('a redraw shows what was typed, not what is stored',
	$redraw['basics']['DEVICE_DESCRIPTION']['value'] ?? null, 'What Was Typed');

echo "\nevery global class named in src/ is qualified or imported:\n";

$unqualified = [];

foreach (glob(__DIR__ . '/../src/*.php') as $file) {
	$unqualified = array_merge($unqualified, oryk_unqualified_classes($file));
}

// PDO::FETCH_COLUMN inside namespace FreePBX\Modules\Oryk_Connect is
// FreePBX\Modules\Oryk_Connect\PDO, which does not exist. It fatals only
// when the line runs, and the line that found this one runs when somebody
// deletes a device with call history.
is_eq('nothing unqualified', $unqualified, []);

echo "\nwith the CDR module installed, the history is actually walked:\n";

$s = build();
$s['app']->Modules->active = ['cdr'];

$LOG = [];
$rows = $s['cdr']->migrate('1001', '2002');

is_eq('migrate rewrites rows', $rows > 0, true);
is_eq('and says how many it moved',
	(bool) array_filter($LOG, function ($l) {
		return strpos($l, 'moved') !== false && strpos($l, 'call history rows') !== false;
	}), true);

$statements = FreePBX::$cdr->handle->statements;

// migrate() deliberately does not read the columns first: it names the ones
// the reports read and lets runUpdate() step over any this site does not
// have. purge() cannot do that -- it has to build one match clause -- which
// is why only it reads them.
is_eq('it checked which tables the site has',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SHOW TABLES LIKE') !== false;
	}), true);
is_eq('and did not need to read the columns',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SHOW COLUMNS') !== false;
	}), false);
is_eq('it rewrote the plain number columns',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SET `src` = :new') !== false;
	}), true);
is_eq('it rewrote inside channel names',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'REPLACE(`channel`') !== false;
	}), true);
is_eq('it rewrote the caller id string',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SET clid = REPLACE(clid') !== false;
	}), true);
is_eq('it moved the voicemail pseudo extensions too',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SET dst = :new WHERE dst = :old') !== false;
	}), true);

$s = build();
$s['app']->Modules->active = ['cdr'];

$LOG = [];
$removed = $s['cdr']->purge('1001');

is_eq('purge finds and deletes the calls', $removed['rows'] > 0, true);
is_eq('it read the columns before matching',
	(bool) array_filter(FreePBX::$cdr->handle->statements, function ($q) {
		return strpos($q, 'SHOW COLUMNS') !== false;
	}), true);
is_eq('it deleted by call identifier, not by extension',
	(bool) array_filter(FreePBX::$cdr->handle->statements, function ($q) {
		return strpos($q, 'DELETE FROM') !== false
			&& (strpos($q, 'uniqueid') !== false || strpos($q, 'linkedid') !== false);
	}), true);
is_eq('nothing was logged as a failure',
	array_values(array_filter($LOG, function ($l) {
		return strpos($l, 'ERROR') === 0;
	})), []);

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed ? 1 : 0);
