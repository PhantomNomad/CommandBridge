# Changelog

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
