# Changelog

## 0.9.0-beta.1 - first public beta

First public release; version numbering reset (earlier private builds were 1.0.x, last 1.0.10) to match Command Bridge 0.9.0-beta.1.

* New installer (`install.sh`, whiptail dialogs with plain fallback): packages, own database and restricted account, Apache site,
  TLS (Let's Encrypt / self-signed / own certificate / none), settings file, generated shared secret, self-check.
  Default location `/var/www/commandbridge-gateway`; database `commandbridge_gateway`.
* New updater (`update.sh`) with automatic database backup and numbered migrations (`database/migrations/`).
* Single `database/schema.sql` baseline (includes all 1.0.9 and 1.0.10 changes); old `database_changes_*.sql` removed.
* Settings stay in `/etc/commandbridge/checkin_gateway.env`. Default time zone is now UTC and is set by the installer.
* Only `public/` is served. Apache rules deny config-like file types.
* Footer shows version, licence and source link (AGPL-3.0 section 13).
* Check-in page: the expected-resource list now refreshes in place while the page is open without losing in-progress entries.
* Carried over: escalating PIN throttle (per address, repeat offender, location-wide) and `bin/clear_lockouts.php`;
  pull-mode synchronisation API; companion-user (CIR/SAM) sync.

Upgrading a private 1.0.x gateway: see docs/UPDATING.md.
