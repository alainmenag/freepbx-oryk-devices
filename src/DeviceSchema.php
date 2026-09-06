<?php

// src/DeviceSchema.php

namespace FreePBX\Modules\Oryk_Devices;

/**
 * What a device is, as a form.
 *
 * Three definitions, and one method that puts a device through them. The
 * kinds of device this module offers, what each kind is made of and what
 * is forced on it whatever the form said; the sections a form is drawn in;
 * and every field, once, with the name it is stored under.
 *
 * buildFormData() is the method. It is not a read from storage -- Core is
 * what holds a device -- it takes a device and returns it arranged the way
 * the form draws it: fields grouped by section, each carrying its own
 * definition and its current value, with anything the browser just sent
 * back winning over what is stored so a rejected save redraws as it was
 * typed rather than as it was saved.
 *
 * The definitions are public because the views are handed them whole.
 */
class DeviceSchema extends Service
{
	/**
	 * Supported device types and their configuration definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public $types = [
		'pjsip' => [
			'title' => 'Extension/User',
			'icon' => 'fa-phone',
			'suffix' => '',
			'tech' => 'pjsip',
			// The device is the extension: a user with the same id is created and linked.
			'creates_user' => true,
			'fields' => ['DEVICE_USER', 'DEVICE_EMAIL', 'HEADER_CREDENTIALS', 'DEVICE_ACCOUNT', 'DEVICE_SECRET'],
			// Driver settings forced on every save, whatever the form sent
			'settings' => [
				'media_encryption' => 'sdes',
				'media_encryption_optimistic' => 'yes',
			],
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

	/**
	 * Available field groups.
	 *
	 * @var array<string, array<string, string>>
	 */
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

	/**
	 * Device field definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
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
		'DEVICE_EMAIL' => [
			'type' => 'email',
			'title' => 'Email',
			'example' => 'user@example.com',
			'name' => 'email',
			'maxLength' => 255,
			'group' => 'basics',
			'help' => 'Used for the User Manager account and its welcome email.',
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

	/**
	 * Load a device and map its values to the configured fields.
	 *
	 * @param int|string|null                 $id     Device identifier.
	 * @param array<string, mixed>|null       $values Submitted values that
	 *                                                override what is stored.
	 *
	 * @return array<string, mixed> Device data grouped by field group.
	 */
	public function buildFormData($id, $values = null)
	{
		$base = [
			'id' => $id ?? null,
		];

		$match = ($id === null || $id === '') ? [] : \FreePBX::Core()->getDevice($id);
		$device = isset($match['id']) ? $match : null;
		$kind = $device['kind'] ?? $device['tech'] ?? '';

		// A redrawn form follows the kind that was submitted, not the stored one
		if (is_array($values) && isset($values['DEVICE_KIND'])) {
			$kind = $values['DEVICE_KIND'];
		}

		$type = $this->types[$kind] ?? null;
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

			// Anything the form sent back wins over the stored value
			if (is_array($values) && array_key_exists($key, $values)) {
				$obj['value'] = $values[$key];
			}

			$base[$obj['group'] ?? 'other'][$key] = $obj;
		}

		$base['type'] = $device['type'] ?? null;

		return $base;
	}
}
