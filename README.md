# Oryk Devices for FreePBX

A FreePBX administration module for managing PJSIP extensions, physical handsets, softphones, and RTSP video feeds from one interface.

Device records are managed through FreePBX Core. RTSP feeds are integrated with the MediaMTX Control API.

## Features

- List, search, sort, and paginate FreePBX devices.
- Create, edit, and delete devices.
- Manage multiple device kinds:
  - **Extension/User** — standard PJSIP device.
  - **Handset** — PJSIP device with manufacturer, model, and management-link metadata.
  - **Softphone** — PJSIP softphone credentials.
  - **RTSP Feed** — video source registered with MediaMTX.
- Generate unique 10-digit device IDs beginning with `99`.
- Trigger Endpoint Manager processing for PJSIP devices.
- Restart RTSP feeds from the device list.
- Generate browser playback links for RTSP feeds.

## Requirements

- FreePBX 16 or 17
- FreePBX modules:
  - `core`
  - `userman`
- PHP cURL extension for RTSP/MediaMTX integration
- For RTSP devices, a reachable [MediaMTX](https://github.com/bluenviron/mediamtx) instance with:
  - Control API on port `9997`
  - WebRTC/HTTP playback on port `8889`

> [!IMPORTANT]
> The MediaMTX address and playback port are currently hard-coded in `drivers/Rtsp.class.php`. Update that file if your deployment uses different endpoints.

## Installation

Clone the repository into the FreePBX modules directory:

```bash
cd /var/www/html/admin/modules
git clone https://github.com/alainmenag/freepbx-oryk-devices.git oryk_devices
```

Set the expected FreePBX ownership and install the module:

```bash
fwconsole chown
fwconsole ma install oryk_devices
fwconsole reload
```

To update an existing installation:

```bash
cd /var/www/html/admin/modules/oryk_devices
git pull
fwconsole ma upgrade oryk_devices
fwconsole chown
fwconsole reload
```

The installer attempts to add indexes for `devices.id` and `devices.user`. Back up the FreePBX database before installing or upgrading custom modules.

## Usage

After installation, open:

**FreePBX Administration → Oryk → Devices**

### Create a PJSIP device

1. Select **New**.
2. Choose **Extension/User**, **Handset**, or **Softphone**.
3. Enter a description.
4. Enter the extension/user and secret.
5. For a handset, optionally enter its management link, manufacturer, and model.
6. Select **Save**.
7. Apply the FreePBX configuration when prompted.

PJSIP saves are performed through FreePBX Core and trigger Endpoint Manager processing.

### Number an Extension/User device

An **Extension/User** device is its own device id, extension and User Manager
account, so the **Extension/User** field sets all three.

- **Left blank**, the module assigns the next free number in the reserved
  `999…` range, ten digits long. This is what a new device gets when no number
  is supplied.
- **Filled in**, the number is used as typed. It has to be digits only, at most
  ten of them, and free: a number already held by a device, an extension or a
  User Manager account is refused, the form is redrawn with the reason, and
  nothing is written.
- **Changed on an existing device**, the device is renumbered. The extension
  keeps its settings, the User Manager account keeps its password, groups and
  UCP settings, the mailbox and its messages move with it, the call history
  follows, and handsets or softphones pointed at the old extension are
  repointed at the new one.

The old number is only given up once the extension exists on the new one, so a
failed renumbering leaves the device where it was.

#### What follows the number

| | Where it lives | What moves |
| --- | --- | --- |
| Extension | `asterisk.users`, astdb | Settings, caller id, voicemail context |
| User Manager account | `asterisk.userman_users` | Renamed with the number when the module owns it, otherwise only the assignment |
| Mailbox | `voicemail.conf`, the voicemail spool | The entry, the messages on disk, and the `<id>@device` alias that message waiting and `*97` are reached through |
| UCP access | `userman_*_settings`, `webrtc_clients` | The extensions a UCP account is allowed to open |
| Call history | `asteriskcdrdb` | `src`, `dst`, `cnum`, `clid`, both channel names, across `cdr`, `transient_cdr`, `replicate_cdr` and `cel` |
| Handsets and softphones | `asterisk.devices` | Repointed at the new extension |

Nothing in FreePBX moves call detail records on its own: a record keeps the
number as it stood when the call was placed, the CDR module subscribes to no
core hook, and FreePBX has no renumbering of its own to hook into. The module
rewrites the columns the CDR reports match on and the caller id string they
display. Recording file names carry the extension too and are deliberately
left alone, because the name has to keep matching the file on disk or the
recording stops being playable.

> [!NOTE]
> Of the columns being rewritten only `dst` and `dstchannel` are indexed, so on
> a system with a long call history the rewrite reads the CDR table end to end
> and a renumbering can take a while. The PHP time limit is lifted for the
> duration.

### Create an RTSP feed

1. Select **New**.
2. Choose **RTSP Feed**.
3. Enter a description.
4. Set **In** to the complete RTSP source URL, for example:

   ```text
   rtsp://username:password@camera.example.test:554/stream
   ```

5. Select **Save**.

The module registers the source with MediaMTX using TCP transport. It then creates a playback link in the following form:

```text
https://<freepbx-server-address>:8889/<device-id>/
```

Use the refresh button beside an RTSP device to stop and restart its MediaMTX path.

## Supported device kinds

| Kind | Technology | Additional fields |
| --- | --- | --- |
| Extension/User | `pjsip` | Extension/user, account, secret |
| Handset | `pjsip` | Extension/user, account, secret, link, manufacturer, model |
| Softphone | `pjsip` | Extension/user, account, secret |
| RTSP Feed | `rtsp` | Input stream and generated playback link |

Additional device values are stored as key/value rows in the FreePBX `asterisk.sip` table.

When a new record does not have an ID, the module generates a 10-digit identifier beginning with `99`. When an existing device is saved, it is deleted and recreated through FreePBX Core.

## Project structure

```text
Oryk_devices.class.php   Main BMO class, device definitions, CRUD, and AJAX handlers
page.oryk_devices.php    FreePBX page entry point
functions.inc.php        FreePBX module hook placeholder
install.php              Legacy installation entry point
module.xml               Module metadata and FreePBX dependencies

drivers/
  Rtsp.class.php         Custom FreePBX Core driver backed by MediaMTX

views/
  devices.php            Searchable device list and row actions
  device.php             Device create/edit form
  fields.php             Reusable form-field renderer

assets/css/
  devices.css            Module stylesheet placeholder
```

## Runtime flow

`page.oryk_devices.php` obtains the `Oryk_devices` BMO instance and calls `showPage()`.

The `Oryk_devices` class:

1. Registers the custom RTSP driver with FreePBX Core.
2. Routes list, edit, save, and delete requests.
3. Generates form fields based on the selected device kind.
4. Validates the submitted Extension/User number before anything is written.
5. Creates, renumbers, or replaces devices through FreePBX Core.
6. Provides AJAX handlers for table data and RTSP restarts.

The device list in `views/devices.php` uses FreePBX's Bootstrap Table integration to request rows from:

```text
ajax.php?module=oryk_devices&command=list
```

RTSP restart actions use:

```text
ajax.php?module=oryk_devices&command=restart
```

## RTSP and MediaMTX behavior

The RTSP driver uses the MediaMTX v3 Control API.

- `addDevice()` stores settings, creates a playback link, and starts the feed.
- `start()` replaces the MediaMTX path with the configured RTSP source.
- `stop()` replaces the path source with `publisher`.
- `restart()` calls `stop()` followed by `start()`.
- `delDevice()` removes the device settings from the `sip` table.

The current Control API endpoint is:

```text
http://0.0.0.0:9997
```

The current playback URL uses the FreePBX server address and port `8889`.

MediaMTX responses are written to the PHP error log, including the HTTP status code and response body.

## Security considerations

- Device secrets and RTSP URLs may contain credentials.
- Restrict access to the FreePBX administrator interface, database, filesystem, and logs.
- Use dedicated, least-privilege credentials for cameras.
- Do not expose the MediaMTX Control API to untrusted networks.
- Configure valid TLS before exposing playback links outside a trusted network.
- Avoid placing production credentials in source code or documentation.
- Review generated playback URLs for compatibility with your network and reverse-proxy configuration.

## Development

This is a FreePBX module rather than a standalone PHP application.

Place or symlink the repository at:

```text
/var/www/html/admin/modules/oryk_devices
```

After modifying PHP files or module metadata, run:

```bash
fwconsole chown
fwconsole reload
```

After changing the module version or installation behavior, update `version` and `dbversion` in `module.xml`, then run:

```bash
fwconsole ma upgrade oryk_devices
fwconsole reload
```

## Known limitations

- MediaMTX endpoints are not configurable from the administration interface.
- RTSP API failures are logged but are not returned to the administrator interface.
- RTSP deletion does not currently remove the MediaMTX path.
- Backup and restore hooks are placeholders.
- `functions.inc.php` and `install.php` contain placeholder implementations.
- The module is marked as non-disableable and non-uninstallable in `module.xml`.
- Device updates delete and recreate the existing FreePBX device.
- Renumbering an Extension/User device does not check other FreePBX
  destinations, such as ring groups or queues, for the number.
- Renumbering rewrites historical call detail records in place. There is no
  undo, and the change is not reflected in any CDR export taken beforehand.
- A mailbox is not moved onto a number that already has one of its own. The
  messages are left where they are, the renumbering continues, and the reason
  is written to the FreePBX log.
- Failures during a renumbering that follow the extension move (User Manager,
  mailbox, linked handsets) are logged rather than reported in the interface.
- There is no automated test suite.

## License

The module declares the **GNU Affero General Public License v3.0** (`AGPLv3`) in `module.xml`.

A repository-level `LICENSE` file containing the complete AGPL-3.0 license text should be added before distributing the module.
