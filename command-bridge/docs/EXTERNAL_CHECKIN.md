# Connecting the Check-In Gateway

The Check-In Gateway ([`checkin-gateway/`](../../checkin-gateway/)) is a small, separate, internet-facing server where
Check-In Recorders (CIR) and Staging Area Managers (SAM) check resources in from their phones. Command Bridge stays
inside your network: once a minute a timer on Command Bridge polls the gateway over HTTPS. It sends locations, expected
resources and CIR/SAM users to the gateway, then collects new check-ins. The gateway never connects to Command Bridge or
its database.

Every request is signed with a **key ID** and **shared secret** that both servers hold, and carries the current time.
A request more than 5 minutes off the receiving server's clock is refused, so **both servers need accurate clocks**
(both installers switch on network time; check with `timedatectl status`).

## Setting it up

The order does not matter. Whichever server you install first creates the key; you paste it into the second.

### A. Command Bridge first (or Command Bridge is already running)

1. On Command Bridge, either answer **Yes** to *Connect this Command Bridge to a Check-In Gateway?* in `install.sh`, or
   on an existing server run:
   ```bash
   sudo /var/www/commandbridge/tools/setup_checkin.sh
   ```
   Enter the gateway address (for example `https://checkin.example.ca`) and choose **No - generate a new key here**.
   The last screen shows the key ID and shared secret **once** (they are also kept in
   `/etc/commandbridge/external_checkin_sync.env`). The connection test fails at this point because the gateway does not
   exist yet, which is expected. Leave sync off.
2. On the gateway server, run `sudo ./install.sh` ([gateway install guide](../../checkin-gateway/docs/INSTALL.md)).
   When asked *Generate a strong random shared secret?* choose **No** and paste the secret. Enter the same key ID.
   Answer **Yes** to *Accept synchronisation from Command Bridge*.
3. Back on Command Bridge:
   ```bash
   sudo /var/www/commandbridge/tools/setup_checkin.sh --test
   ```
   When the test passes it offers to switch sync on. Say **Yes**.

### B. Gateway first

1. Install the gateway and let it generate the secret. Its last screen shows the address, key ID and secret.
2. On Command Bridge, run `setup_checkin.sh` (or answer Yes in `install.sh`), enter the address, and choose
   **Yes - paste the key ID and secret the gateway installer showed me**.
3. The test runs straight away. If it passes, say **Yes** to switch sync on.

Switching sync on queues the current locations, expected resources and CIR/SAM users for the gateway (the one-time
*backfill*), sets `EXTERNAL_CHECKIN_ENABLED=1` and starts `commandbridge-external-checkin-sync.timer`.

## The connection test

```bash
sudo /var/www/commandbridge/tools/setup_checkin.sh --test
```

It checks, in order: the settings are complete; the gateway answers over **trusted** HTTPS and its database is up;
the two clocks agree; a signed *ping* is accepted (same key ID and secret, gateway sync switched on). It reports how many
check-ins are waiting on the gateway and how many have stopped retrying. The ping only reads counts. It never collects,
changes or deletes check-ins, so it is safe during an incident. The self-check (`bin/smoke_test.php`) runs the same test.

Common failures and what they mean:

| Message | Fix |
|---|---|
| certificate is not trusted | Give the gateway a real certificate (Let's Encrypt or your own). Command Bridge does not accept a self-signed gateway. |
| Could not connect / Timed out | The gateway is down, or a firewall blocks outbound HTTPS from Command Bridge. |
| key ID or shared secret does not match | Compare `EXTERNAL_CHECKIN_HMAC_KEY_ID` / `_SECRET` on both servers, or rotate (below). |
| clocks are too far apart | Fix the time on both servers (`timedatectl status`, `timedatectl set-ntp true`). |
| not accepting sync yet | On the gateway set `GATEWAY_SYNC_ENABLED=1` in `/etc/commandbridge/checkin_gateway.env`. |
| no connection-test page (ping.php) | Update the gateway to 0.9.0-beta.2 or newer. |

## Day-to-day

* **Status:** Admin > Sync status in Command Bridge, or `journalctl -u commandbridge-external-checkin-sync.service -n 30`.
* **Switch sync off** (settings are kept; check-ins wait on the gateway): `sudo .../tools/setup_checkin.sh --disable`.
  Switch it back on with `--test`.
* **Command Bridge was down or could not reach the gateway:** nothing is lost. Check-ins wait on the gateway with no time
  limit and are collected on the next successful poll.
* **Updates Command Bridge could not send** (locations, expected resources, CIR/SAM users, including revocations) retry for
  about 3 hours and then stop. Re-send them with the retry button on Admin > Sync status.
* **Check-ins that stopped retrying on the gateway** (collected 12 times without being confirmed, or rejected): on the
  gateway, `sudo php /var/www/commandbridge-gateway/bin/requeue_checkins.php --list`, then `--id=N` or `--all`.
  Command Bridge ignores check-ins it already recorded, so re-sending is safe.

## Changing the shared secret (rotation)

Use this if the secret may have been exposed, or as routine hygiene. Sync is paused for the few minutes it takes.
Check-ins wait on the gateway meanwhile, so nothing is lost.

1. On Command Bridge: `sudo /var/www/commandbridge/tools/rotate_checkin_secret.sh`.
   It pauses sync, creates a new key ID (`commandbridge-1` becomes `commandbridge-2`) and secret, saves them, and prints them.
2. On the gateway: `sudo /var/www/commandbridge-gateway/tools/rotate_secret.sh`. Enter the new key ID and paste the secret.
3. Back on Command Bridge choose **Test**. When it passes, sync resumes.
   Choose **Undo** to go back to the old key, or **Stop here** to finish later with `setup_checkin.sh --test`.

Both scripts keep a backup of the previous settings file next to it (`*.bak-YYYYMMDD-HHMMSS`, readable by root only).
Delete the backups once you are happy.

## The settings file

`/etc/commandbridge/external_checkin_sync.env` (root:www-data, 0640) is written by `setup_checkin.sh`; you normally
never edit it. For reference:

```ini
EXTERNAL_CHECKIN_ENABLED=1
EXTERNAL_CHECKIN_GATEWAY_URL=https://checkin.example.ca/api/v1/sync/events.php
EXTERNAL_CHECKIN_GATEWAY_PULL_URL=https://checkin.example.ca/api/v1/sync/pull.php
EXTERNAL_CHECKIN_GATEWAY_ACK_URL=https://checkin.example.ca/api/v1/sync/ack.php
EXTERNAL_CHECKIN_HMAC_KEY_ID=commandbridge-1
EXTERNAL_CHECKIN_HMAC_SECRET=...
EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL=https://checkin.example.ca/?location={location_token}
```

`EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL` is the link printed on each location's QR code (Admin > Locations).

## What happens to a check-in

A processed check-in updates the Resources Unit intake record and creates or updates the linked ICS 211 entry for
personnel/crew resources, or the ICS 218 entry for vehicle/equipment resources. Submitted ICS forms are not altered;
those check-ins stay queued for review.
