# Changelog

## Unreleased

* **Sign-out after 1 hour without activity** (was about 24 minutes, PHP's default, and not reliably enforced). The app
  now enforces the limit itself. It comes from `session.gc_maxlifetime`, which the installer sets to 3600, and
  `update.sh` adds to existing servers (an existing value is kept). The dashboard's automatic notification refresh
  does not count as activity. When the hour is up, an open dashboard goes to the sign-in page instead of staying on
  screen. The sign-in page says why. The self-check shows the current limit. See "Sign-out after inactivity" in
  docs/INSTALL.md.
* **Friendlier message when the sign-in timed out.** Submitting a form after the session expired showed only
  "Invalid CSRF token". It now shows *Your session has timed out* (and that what was entered was not saved), with a
  **Sign in again** button, and goes to the sign-in page by itself after 10 seconds. If the sign-in page itself had
  expired, it simply reloads with a message. A page left over from before signing in again (for example another tab)
  shows *This page is out of date* with a link to reload it. JSON requests get `session_expired` / `page_expired`.
  The self-check (`--base-url`) tests the timed-out case.

## 0.9.0-beta.3

**Check-In Gateway setup** (needs Check-In Gateway 0.9.0-beta.2 for the connection test)
* `install.sh` now asks whether to connect a Check-In Gateway: its address, and whether to generate the shared key here or
  paste the one the gateway installer showed. At the end it tests the connection and asks whether to switch the sync timer
  on (default Yes only if the test passed). The final screen says exactly what to do on the gateway, including the key ID
  and secret when they were generated here (shown on the terminal only, never in the log).
* New `tools/setup_checkin.sh`: the same steps for servers that are already installed. `--test` runs the connection test
  and offers to switch sync on; `--disable` switches it off; with no option it shows a menu (test / change / off).
  Switching on queues the current locations, expected resources and CIR/SAM users (the one-time backfill), sets
  `EXTERNAL_CHECKIN_ENABLED=1` and enables the timer. If any step fails, sync is left fully off.
* New `tools/rotate_checkin_secret.sh`: replaces the shared secret. It pauses sync, creates a new key ID and secret, tells you
  to run `tools/rotate_secret.sh` on the gateway, then tests and resumes. It can also undo, or stop and finish later.
* New `bin/external_checkin_ping.php`: read-only connection test. It checks the settings, trusted HTTPS, the gateway's
  database, the clock difference and the signed key, and explains each failure in plain words. The self-check
  (`bin/smoke_test.php`) runs it when sync is configured, and also checks that the public check-in link is set.
* Both installers make sure network time is on (installing `systemd-timesyncd` if no time service exists), since signed
  requests more than 5 minutes off are refused. Shared check in `deploy/lib/timesync.sh`.
* `docs/EXTERNAL_CHECKIN.md` rewritten as one step-by-step guide, including rotation and what to do with stuck items.

**Fixes**
* **A fresh install did not open the new-incident setup after the first login.** After signing in (password or
  Microsoft), users were sent straight to the dashboard, skipping the check that sends an administrator with no incident
  yet to *Set up incident*. Sign-in now goes through the start page, which picks the right destination.
* **Location QR codes / public links were never shown.** Admin > Locations read `EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL`
  from the process environment instead of `/etc/commandbridge/external_checkin_sync.env`. It now reads the settings file
  (an Apache `SetEnv` still works). The fallback link format and the example file now use the gateway's real address
  format, `https://checkin.example.ca/?location=<token>`.
* **Self-signed installs could lock browsers out.** The app sent `Strict-Transport-Security` on every HTTPS page, so a
  browser that once trusted the site refused it after a certificate change, with no way to proceed. The header is now
  sent only by the Apache site, and only for trusted certificates: Let's Encrypt, and now also your-own-certificate
  installs (mode 3). To turn it back on for an existing install with a trusted certificate, see "Self-signed certificates" in docs/INSTALL.md.
* Installer and updater mark `tools/*.sh` executable; the repository now stores `install.sh` and `update.sh` as executable.

No database changes. Update with `update.sh`.

## 0.9.0-beta.2

* **Fixed: ICS 204 could not be approved.** The Planning Section Chief approval step existed in the code but had no button,
  and it was only processed for users with edit permission, which the standard permissions do not give Planning. A submitted
  ICS 204 now shows an **Approve ICS 204 (Planning Section Chief)** button to the PSC (and TSC/Admin). Approval no longer
  requires edit rights on the form (view access is enough), only works on a *submitted* form, records the approver and time,
  and is written to the audit log. The approver's name and approval time now show on the approved form.
* The Resources Unit Leader (RESL) now has ICS 204 view/edit/submit permission by default (new installs via `seed.sql`; existing installs via migration `0001_ics204_resl_permission.sql`, applied by `update.sh`), matching the on-screen help text.
* Update with `update.sh`; the Check-In Gateway is unchanged (still 0.9.0-beta.1).

## 0.9.0-beta.1 - first public beta

First public release. Earlier builds (1.0.2.x) were private; their history is folded into this baseline.

**Packaging**
* New installer (`install.sh`): installs all packages (including `pdftk-java`), asks for install folder, server name,
  TLS mode (Let's Encrypt / self-signed / own certificate / none), time zone and first administrator, writes the Apache
  site, PHP settings and database, then runs the self-check. Default location `/var/www/commandbridge`.
* Friendlier whiptail (ncurses) dialogs in `install.sh` and `update.sh`, shared code in `deploy/lib/ui.sh`; plain prompts remain for unattended runs.
* New updater (`update.sh`) with automatic database backup and numbered migrations (`database/migrations/`).
* Single `database/schema.sql` + `database/seed.sql` (reference data only: no users, incidents or forms).
* Database settings now live in `/etc/commandbridge/db.env`; names default to `commandbridge`.
* Time zone is configurable (`APP_TIMEZONE`) instead of fixed to America/Edmonton.
* Licensed AGPL-3.0; footer shows version and licence.

**Features carried from 1.0.2.x**
* Automatic approver selection through the chain of command and sign-off workflow.
* ICS 209 resource summary, ICS 209-WF fillable official template.
* Check-in sync status page with retry; login throttle and lockouts; smoke test (`bin/smoke_test.php`).

**Upgrading from a private 1.0.2.x build:** see docs/UPDATING.md (`APP_TIMEZONE` must be added to `db.env`).
