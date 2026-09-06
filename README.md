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
- Generate unique 10-digit device IDs beginning with `999`.
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

### Delete an Extension/User device

Deleting an **Extension/User** device deletes the extension and, when this
module owns it, the User Manager account. Once the last device pointing at the
extension has gone, everything the number left behind goes with it:

- The extensions the number was listed among in UCP, and any web client
  registered against it.
- Its call history, in `cdr`, the transient and replicate copies, and `cel`.
- The recordings those records were the only way to reach.

Handsets and softphones are not extensions, and deleting one leaves the
extension it pointed at, and that extension's history, alone.

> [!CAUTION]
> This is a permanent deletion, not an archive, and there is no undo. A record
> of a call between two extensions belongs to both of them, and it is removed
> even when the other one is still in service: the other extension loses those
> calls from its own history, and the recording of them from disk. Take a
> backup of `asteriskcdrdb` before deleting an extension whose history matters.

Nothing is deleted unless the extension itself was deleted first. A failure
part way leaves the records where they are and says so in the FreePBX log,
and the recordings are only unlinked once no surviving record names them.

#### How the records are found

The call detail records decide what goes; the event log follows them.

1. Find the records naming the number, matched exactly on `src` or `dst`.
2. Take the `uniqueid` and `linkedid` off each one.
3. Delete every `cel` row carrying either identifier, then every call detail
   row carrying either identifier, in `cdr` and in the transient and
   replicate copies.

The chain identifier is what makes this complete. A call is more than one row
in both tables, and only one of its channels carries the record's own
identifier: a plain extension-to-extension call is one call detail record and
fifteen events across two channels, of which six belong to the second channel
and are reachable only through `linkedid`. Matching on the record identifier
alone would leave those six behind.

It is also what makes it wide. A queue call or a transfer carries one chain
across every leg of it, so deleting an extension that answered one call in a
queue removes the event log for that whole interaction, including the legs
that rang other agents.

Two columns are enough to seed it because the rest of a call is reached
through the identifiers rather than by matching: the other channels carry
identifiers of their own and are found through the chain, not through the
number.

Nothing else a record holds is matched on directly — not `cnum`, the channel
names, the caller id string, `accountcode` or `peeraccount`. A false match
here is not one row: each record found contributes both its own identifier
and its chain, and everything carrying either is deleted from both tables, so
a caller id name that happens to read as this number, or an account code a
site uses for a tenant, would take whole calls belonging to somebody else
with it.

> [!NOTE]
> A call the extension answered through a ring group, a queue or follow-me is
> addressed to the **group**, not to the extension: the record carries the
> group's number in `dst` and the extension only in `dstchannel`. Such a call
> is not matched, so a queue member's answered calls are left behind. Matching
> `dstchannel` would find them, at the cost of deleting the whole queue
> interaction, including the legs that rang other agents.

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

When a new record does not have an ID, the module generates a 10-digit identifier beginning with `999`. When an existing device is saved, it is deleted and recreated through FreePBX Core.

## Project structure

```text
Oryk_devices.class.php   Main BMO class, device definitions, CRUD, and AJAX handlers
page.oryk_devices.php    FreePBX page entry point
functions.inc.php        FreePBX module hook placeholder
install.php              Legacy installation entry point
module.xml               Module metadata and FreePBX dependencies

src/
  Service.php            What every subsystem below is given: the FreePBX
                         application, the database, the manager connection
  DeviceSchema.php       What a device is, as a form: kinds, groups, fields
  DeviceManager.php      Saving and deleting a device
  NumberAllocator.php    Which numbers are free, and what the next one is
  ExtensionRenumberer.php  Moving a device to a different number, in order
  ExtensionManager.php   The Core extension and the Asterisk keys about it
  UsermanManager.php     The User Manager account behind an Extension/User
  VoicemailManager.php   Mailboxes, their aliases, and what dials them
  UcpAssignments.php     What a UCP account is allowed to open
  CdrHistory.php         Moving and removing an extension's call history

drivers/
  Rtsp.class.php         Custom FreePBX Core driver backed by MediaMTX

tests/
  smoke.php              Standalone checks, no FreePBX required
  stubs.php              Just enough FreePBX for them to run against

views/
  devices.php            Searchable device list and row actions
  device.php             Device create/edit form
  fields.php             Reusable form-field renderer

assets/css/
  devices.css            Module stylesheet placeholder
```

## Runtime flow

`page.oryk_devices.php` obtains the `Oryk_devices` BMO instance and calls `showPage()`.

`Oryk_devices` is the FreePBX side and nothing else. It registers the RTSP
driver, routes the request, hands the submitted form to the right subsystem,
and answers the AJAX the device list makes. It reads `$_REQUEST`; nothing
under `src/` does.

The work itself is delegated:

1. `DeviceSchema` decides what fields the selected kind is drawn with, and
   arranges a device into them.
2. `NumberAllocator` generates an id, or refuses a typed number that a
   device, an extension or a User Manager account already holds.
3. `DeviceManager` saves and deletes, sequencing everything below.
4. `ExtensionRenumberer` moves a number, in the order that keeps the
   extension, mailbox, account, handsets, assignments and call history
   together.
5. `ExtensionManager`, `UsermanManager`, `VoicemailManager`,
   `UcpAssignments` and `CdrHistory` each own one of those.

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

### Subsystems

Anything under `src/` is loaded by a small autoloader registered at the top
of `Oryk_devices.class.php`, since BMO autoloads only the module class
itself. A new class goes in `src/`, in the
`FreePBX\Modules\Oryk_Devices` namespace, named after its file, and needs
no registration anywhere.

The RTSP driver is deliberately left out of that loader. It is required
explicitly, after the Core class it extends has been made sure of, and that
order is worth keeping visible in the file.

### Smoke test

```bash
php tests/smoke.php
```

Runs anywhere, with nothing installed: `tests/stubs.php` stands in for
FreePBX, the database and Asterisk. It checks that every subsystem loads,
builds and wires together the way the module wires them; that a missing
FreePBX module is declined rather than thrown; that number allocation
refuses what it should; that the call history purge refuses anything that
is not a number; and that saving a device produces the settings Core is
meant to be handed, without touching the caller's copy of the form.

It is not a substitute for trying a renumber on a real PBX -- it knows
nothing about voicemail.conf, the Asterisk database or a CDR table. It
catches the kind of mistake refactoring makes, before a deploy does.

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
- Renumbering rewrites historical call detail records in place, and deleting
  an Extension/User removes them outright along with their recordings. Neither
  can be undone, and neither is reflected in a CDR export taken beforehand.
- Deletion is reached from the device list without a confirmation prompt. The
  device list shows every device on the system, not only the ones this module
  created, so an ordinary FreePBX extension deleted from this screen loses its
  call history the same way.
- A call recording is unlinked only when no surviving record names it, which
  costs one indexed lookup per recording.
- A mailbox is not moved onto a number that already has one of its own. The
  messages are left where they are, the renumbering continues, and the reason
  is written to the FreePBX log.
- Failures during a renumbering that follow the extension move (User Manager,
  mailbox, linked handsets) are logged rather than reported in the interface.
- There is no automated test suite.

## License

The module declares the **GNU Affero General Public License v3.0** (`AGPLv3`) in `module.xml`.

A repository-level `LICENSE` file containing the complete AGPL-3.0 license text should be added before distributing the module.
