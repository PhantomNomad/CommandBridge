# Installing the Check-In Gateway

The gateway is a separate, public-facing server. Command Bridge itself stays internal and exchanges signed messages with it.

## Requirements
* A fresh Debian 12 or 13 server with root access, reachable from the internet on ports 80 and 443.
* A DNS name pointing at it (for example `checkin.example.ca`) - needed for Let's Encrypt.
* Command Bridge 0.9.0-beta.1 or newer for the other end.

## Install
```bash
sudo ./install.sh
```
Questions: install folder (default `/var/www/commandbridge-gateway`), server name, HTTPS mode (Let's Encrypt recommended),
time zone, delivery mode (**pull** recommended: Command Bridge contacts the gateway, so it needs no inbound access),
the shared-secret key ID and secret (generated or pasted), and whether to accept synchronisation immediately.

The final screen lists the exact lines to put in Command Bridge's `/etc/commandbridge/external_checkin_sync.env`
(URLs, key ID and the generated secret - shown once and not written to the log).

## Then, on Command Bridge
1. Edit `/etc/commandbridge/external_checkin_sync.env` with those values and set `EXTERNAL_CHECKIN_ENABLED=1`.
2. On the gateway, set `GATEWAY_SYNC_ENABLED=1` in `/etc/commandbridge/checkin_gateway.env` if you left it disabled.
3. Run the Command Bridge check-in backfill once (locations before CIR/SAM users), then enable its sync timer: `sudo systemctl enable --now commandbridge-external-checkin-sync.timer` (see `command-bridge/docs/EXTERNAL_CHECKIN.md`).
4. Verify a CIR/SAM user can sign in only at an authorised location with their own PIN, and a revoked user cannot.

## Where things go
| Item | Location |
|---|---|
| Application | `<install folder>`; only `public/` is served |
| Settings | `/etc/commandbridge/checkin_gateway.env` (root:www-data 0640) |
| Database | `commandbridge_gateway` (the app account has SELECT/INSERT/UPDATE/DELETE only) |
| Apache site | `/etc/apache2/sites-available/commandbridge-gateway.conf` |
| Dispatcher (push mode only) | systemd `commandbridge-gateway-dispatch.timer` |

## Operations
* Health: `https://your-gateway/health.php` returns `{"ok":true}`.
* Lockouts: `sudo php <install folder>/bin/clear_lockouts.php --list`.
* Logs: `/var/log/apache2/commandbridge-gateway-error.log`, install log `/var/log/commandbridge-gateway-install.log`.
* Uninstall: `a2dissite commandbridge-gateway`, remove the folder, `/etc/commandbridge/checkin_gateway.env`, `gateway-install.conf`, and drop database/user `commandbridge_gateway`.
