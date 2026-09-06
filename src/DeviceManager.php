<?php

// src/DeviceManager.php

namespace FreePBX\Modules\Oryk_Connect;

/**
 * Creating, saving and deleting a device.
 *
 * These are the two application-level operations the module offers, and
 * both of them are a sequence over most of the rest of this namespace
 * rather than a write of their own. Saving a device may allocate or
 * validate a number, renumber everything attached to the old one, build
 * the driver settings, create the extension and its account, and sync
 * three different names and an email. Deleting one may take an extension,
 * an account, a set of UCP assignments and a call history with it.
 *
 * That is why this holds so many collaborators: the breadth is real, and
 * it belongs in one place that can be read top to bottom rather than
 * spread across the module class.
 *
 * store() takes the submitted form as an argument. It used to read
 * $_REQUEST directly and write back to it, which meant it could only run
 * inside a request and could not be told what to save.
 */
class DeviceManager extends Service
{
	/**
	 * What a device is, as a form.
	 *
	 * @var DeviceSchema
	 */
	private $schema;

	/**
	 * Which numbers are free.
	 *
	 * @var NumberAllocator
	 */
	private $numbers;

	/**
	 * Moving a device to a different number.
	 *
	 * @var ExtensionRenumberer
	 */
	private $renumberer;

	/**
	 * The Core extension behind an Extension/User device.
	 *
	 * @var ExtensionManager
	 */
	private $extensions;

	/**
	 * The User Manager account behind it.
	 *
	 * @var UsermanManager
	 */
	private $userman;

	/**
	 * Its mailbox.
	 *
	 * @var VoicemailManager
	 */
	private $voicemail;

	/**
	 * What an account is allowed to open.
	 *
	 * @var UcpAssignments
	 */
	private $ucp;

	/**
	 * Its call history.
	 *
	 * @var CdrHistory
	 */
	private $cdr;

	/**
	 * @param object              $freepbx    FreePBX application instance.
	 * @param DeviceSchema        $schema     Device and field definitions.
	 * @param NumberAllocator     $numbers    Number allocation.
	 * @param ExtensionRenumberer $renumberer Renumbering.
	 * @param ExtensionManager    $extensions Core extensions.
	 * @param UsermanManager      $userman    User Manager accounts.
	 * @param VoicemailManager    $voicemail  Mailboxes.
	 * @param UcpAssignments      $ucp        UCP assignments.
	 * @param CdrHistory          $cdr        Call history.
	 */
	public function __construct(
		$freepbx,
		DeviceSchema $schema,
		NumberAllocator $numbers,
		ExtensionRenumberer $renumberer,
		ExtensionManager $extensions,
		UsermanManager $userman,
		VoicemailManager $voicemail,
		UcpAssignments $ucp,
		CdrHistory $cdr
	) {
		parent::__construct($freepbx);

		$this->schema = $schema;
		$this->numbers = $numbers;
		$this->renumberer = $renumberer;
		$this->extensions = $extensions;
		$this->userman = $userman;
		$this->voicemail = $voicemail;
		$this->ucp = $ucp;
		$this->cdr = $cdr;
	}

	/**
	 * Store or update a device.
	 *
	 * Everything it needs comes in through $input, which is the submitted
	 * form. It is a copy: nothing here writes to the caller's array, and
	 * nothing here reads the request on its own.
	 *
	 * @param array<string, mixed> $input Submitted form values.
	 *
	 * @return string Device identifier.
	 *
	 * @throws \Exception When the requested number is not available.
	 */
	public function store(array $input)
	{
		$id = trim((string) ($input['DEVICE_ID'] ?? ''));
		$requested = trim((string) ($input['DEVICE_USER'] ?? ''));
		$email = $input['DEVICE_EMAIL'] ?? null;
		$deviceType = $input['DEVICE_KIND'] ?? 'pjsip';
		$type = $this->schema->types[$deviceType] ?? $this->schema->types['pjsip'];
		$tech = $type['tech'] ?? 'pjsip';
		$ownsUser = !empty($type['creates_user']);
		$match = $id === '' ? [] : \FreePBX::Core()->getDevice($id);
		$device = isset($match['id']) ? $match : null;
		$flags = 0;

		if ($ownsUser) {
			// An Extension/User device is its own user, so the number typed
			// into the form is the device id as well: a blank field asks for
			// a generated one, a filled one has to be free.
			$uid = $requested === ''
				? $this->numbers->generate()
				: $this->numbers->assertAvailable($requested, $id);

			$user = $uid;
		} else {
			$uid = $id === '' ? $this->numbers->generate() : $id;
			$user = $requested;
		}

		// A device with nothing typed in the description is named after its
		// own number. The default is written back into the input as well as
		// held here, because the field loop below is what stores it.
		$description = $input['DEVICE_DESCRIPTION'] ?? null;
		$description = $description ? $description : $uid;

		$input['DEVICE_DESCRIPTION'] = $description;

		// A changed number takes the extension, the User Manager account, the
		// mailbox and any handset pointed at it along to the new number
		if ($ownsUser && $device && (string) $device['id'] !== (string) $uid) {
			$this->renumberer->renumber($device['id'], $uid, $description, $tech, $email);

			$device = null; // the row on the old number is gone
		}

		$generated = \FreePBX::Core()->generateDefaultDeviceSettings(
			$tech === 'pjsip' ? 'pjsip' : 'custom',
			$user,
			$description,
			false,
		);

		$defaults = \FreePBX::Core()->getDriver($tech)->getDefaultDeviceSettings(
			$uid,
			$description,
			$flags
		);

		// The device id and the extension are decided above, not copied out
		// of the form: dropping them here keeps the loop below from writing
		// what the browser sent over what was worked out.
		unset($input['DEVICE_ID']);
		unset($input['DEVICE_USER']);

		foreach ($this->schema->fields as $key => $set) {
			if (isset($input[$key])) {
				$fieldName = $set['alias'] ?? $set['name'] ?? null;

				if (!$fieldName) {
					continue;
				}

				if (!isset($generated[$fieldName])) {
					$generated[$fieldName] = ['value' => null];
				}

				$generated[$fieldName]['value'] = $input[$key];
			}
		}

		// Everything the driver gave back, filled in. This runs here rather
		// than only at the end because the emergency caller id check below
		// asks whether a value is empty, which a missing one is not.
		foreach ($generated as &$setting) {
			$setting['value'] = $setting['value'] ?? '';
			$setting['flag'] = $setting['flag'] ?? 0;
		}

		unset($setting); // or the next loop over $generated writes through it

		$dial = $defaults['dial'] ?? 'DEVICE';

		$generated['account']['value'] = "$uid";
		$generated['dial']['value'] = "$dial/$uid";
		$generated['mailbox']['value'] = "$uid@device";

		if (isset($generated['emergency_cid']['value']) && empty($generated['emergency_cid']['value'])) {
			$generated['emergency_cid']['value'] = $uid;
		}

		// Settings the type pins down are not on the form, so they are applied
		// here on every save and win over both the driver defaults and anything
		// that came back from the browser
		foreach (($type['settings'] ?? []) as $keyword => $value) {
			if (!isset($generated[$keyword])) {
				$generated[$keyword] = ['value' => null, 'flag' => 0];
			}

			$generated[$keyword]['value'] = $value;
		}

		// account, dial and mailbox are set above rather than coming from the
		// driver, and the type's own settings are added after that, so any of
		// them the driver did not already return was created here with a
		// value and nothing else. Core expects both keys on every setting.
		foreach ($generated as $keyword => $setting) {
			$generated[$keyword]['value'] = $setting['value'] ?? '';
			$generated[$keyword]['flag'] = $setting['flag'] ?? 0;
		}

		// Make sure the matching user exists and owns this device
		if ($ownsUser) {
			$generated['user']['value'] = "$uid";
			$this->extensions->ensure($uid, $description);
			$this->userman->ensure($uid, $description, $tech, $email);

			// Keep the names and the email in step with the form on every save
			$this->extensions->syncName($uid, $description);
			$this->userman->sync($uid, $description, $email);
			$this->voicemail->syncEmail($uid, $email);
		}

		// If device exists, delete it first
		if ($device) {
			\FreePBX::Core()->delDevice($device['id'], true);
		}

		$ret = \FreePBX::Core()->addDevice($uid, $tech, $generated, true);

		//update the associated endpoint configuration
		if ($ret && $tech === 'pjsip') {
			\FreePBX::Core()->processEPM($uid, $tech, true);
		}

		if ($ret) {
			$this->reload();
		}

		return $uid;
	}

	/**
	 * Delete a device and, for kinds that own their user, the matching
	 * user/extension.
	 *
	 * The user is only removed when it carries the device id and no other
	 * device is still assigned to it.
	 *
	 * @param int|string $id Device identifier.
	 *
	 * @return bool True when a device was deleted.
	 */
	public function remove($id)
	{
		$match = \FreePBX::Core()->getDevice($id);
		$device = isset($match['id']) ? $match : null;

		if (!$device) {
			return false;
		}

		$kind = $device['kind'] ?? $device['tech'] ?? '';
		$type = $this->schema->types[$kind] ?? null;
		$user = $device['user'] ?? null;

		\FreePBX::Core()->delDevice($device['id']);

		// An Extension/User device owns its user, so it goes with the device
		if (!empty($type['creates_user'])
			&& (string) $user !== ''
			&& (string) $user === (string) $device['id']
			&& !$this->extensions->hasDevices($user)
		) {
			$this->userman->removeOwnedAccount($user);

			$deleted = true;

			try {
				\FreePBX::Core()->delUser($user);
			} catch (\Exception $e) {
				$deleted = false;

				$this->logError('unable to delete user ' . $user . ': ' . $e->getMessage());
			}

			// Only once the extension has actually gone. An extension still in
			// service, left behind because its deletion failed, must not lose
			// the history and recordings it is still making.
			if ($deleted) {
				// An account belonging to a person outlives the extension, so
				// the number comes out of what that account is allowed to open
				$this->ucp->forget($user);

				// The history outlives it too, and nothing in FreePBX clears it
				$this->cdr->purge($user);
			}
		}

		$this->reload();

		return true;
	}

	/**
	 * Apply the pending configuration (equivalent to `fwconsole reload`).
	 *
	 * The Apply Config flag is raised first so it stays visible when the
	 * reload itself fails. Failures are logged rather than thrown so a save
	 * or delete is never lost behind a reload error.
	 *
	 * @return bool True when the reload completed successfully.
	 */
	public function reload()
	{
		needreload();

		try {
			$result = \FreePBX::Framework()->doReload();
		} catch (\Throwable $e) {
			$this->logError('reload failed: ' . $e->getMessage());

			return false;
		}

		if (empty($result['status'])) {
			$this->logError('reload failed: ' . ($result['message'] ?? 'unknown error'));

			return false;
		}

		return true;
	}
}
