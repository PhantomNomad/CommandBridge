# Security

Report vulnerabilities privately through GitHub's **Report a vulnerability** (Security tab) rather than a public issue.
Include the component (Command Bridge or Check-In Gateway), its `VERSION`, what you did and what happened.

## Deploying safely

**Both servers**
* Use HTTPS. The installers' plain-HTTP option is for testing only.
* Settings and credentials live in `/etc/commandbridge/` (root:www-data, mode 0640), outside the web root. Only `app/` (Command Bridge) or `public/` (gateway) is served.
* Keep Debian patched (`unattended-upgrades` is recommended). Back up the databases and test restoring.

**Command Bridge (internal)**
* Keep it off the public internet. It needs only *outbound* HTTPS to the gateway.
* Run `php bin/smoke_test.php --base-url=https://your-server --insecure` (as www-data) after any server change; it confirms internal files are not web-accessible.
* Sign-in has per-account lockout and per-network throttling.

**Check-In Gateway (public)**
* Use a unique shared secret (32+ characters) and enable synchronisation only once HTTPS works.
* It has its own database and a data-only account and never connects to Command Bridge's database.
* Failed PIN attempts are throttled; `bin/clear_lockouts.php --list` shows who is blocked.

This is a beta: assume undiscovered issues and avoid exposing anything more than necessary.
