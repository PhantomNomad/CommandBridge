# Installing Command Bridge

## 1. Prepare

* A fresh Debian 12 or 13 server with root (or sudo) access. A DNS name is recommended.
* Unpack the release and `cd` into it. Do **not** run it from the final install folder.

## 2. Run the installer

```bash
sudo ./install.sh
```

| Question | Notes |
|---|---|
| Install folder | Default `/var/www/commandbridge`. You are asked to confirm. The web root is its `app/` subfolder only. |
| Server name | The DNS name or IP address people will use. |
| HTTPS mode | **1** Let's Encrypt (server must be reachable from the internet on port 80/443 with a public DNS name; falls back to self-signed if issuance fails). **2** Self-signed (default; works on internal networks). **3** Your own certificate and key files, for example a wildcard certificate: full-chain PEM plus an unencrypted key, placed in their permanent location first (Apache uses them where they are). **4** Plain HTTP (testing only). |
| Time zone | Saved as `APP_TIMEZONE` in `db.env`. |
| Administrator | Username, name, optional email; a strong password is generated and shown once, or type your own (12+ characters). |
| Check-In Gateway (optional) | Whether to connect a gateway, its address, and whether to generate the shared key here or paste the one the gateway installer showed. At the end it tests the connection and asks whether to switch the sync timer on. See [EXTERNAL_CHECKIN.md](EXTERNAL_CHECKIN.md); you can also do this later with `tools/setup_checkin.sh`. |

On a terminal the installer uses whiptail dialogs (it installs the small `whiptail` package itself if missing); with `CB_PLAIN=1`, over a non-interactive session, or with `CB_ASSUME_YES=1` it uses plain text prompts.

The installer writes a log to `/var/log/commandbridge-install.log` (no passwords), and ends with a self-check.
Then open the address, sign in and follow the first-incident setup.

For unattended installs see the variables listed at the top of `install.sh` (`CB_ASSUME_YES=1`, `CB_SERVER_NAME`, ...).

## What gets installed where

| Item | Location |
|---|---|
| Application | `<install folder>/app` (served), plus `database/`, `deploy/`, `docs/`, `tools/` |
| Uploads and logos | `<install folder>/app/storage` (owned by www-data; back this up) |
| Database | MariaDB database and user `commandbridge`, password in `/etc/commandbridge/db.env` |
| Apache site | `/etc/apache2/sites-available/commandbridge.conf` (default sites are disabled; others are left alone) |
| PHP settings | `/etc/php/<ver>/{apache2,cli}/conf.d/90-commandbridge.ini` |
| Check-in sync (optional) | `/etc/commandbridge/external_checkin_sync.env`; systemd timer switched on only after a passing connection test; tools `tools/setup_checkin.sh` and `tools/rotate_checkin_secret.sh` |

## Sign-out after inactivity

Users are signed out after **60 minutes** without activity (clicking, saving or opening pages; the dashboard's automatic
notification refresh does not count). A form submitted after that shows *Your session has timed out* and returns to the
sign-in page. What was typed on that page is not saved.

The limit is PHP's `session.gc_maxlifetime` in `/etc/php/<version>/apache2/conf.d/90-commandbridge.ini` (and the `cli`
copy), in seconds. To change it, edit both files, then run `sudo systemctl reload apache2`. Updates keep your value.

## Self-signed certificates

Browsers warn until the certificate is trusted. The certificate is `/etc/ssl/commandbridge/commandbridge.crt`
(valid 825 days); import it into your devices, or later switch to a certificate from your own CA by editing the two
`SSLCertificate*` lines in the Apache site and running `systemctl reload apache2`.

Self-signed sites do not send `Strict-Transport-Security` (HSTS); Let's Encrypt and your-own-certificate sites send it
from the Apache site. If you switch a self-signed install to a trusted certificate, you can add
`Header always set Strict-Transport-Security "max-age=15552000"` to the `<VirtualHost *:443>` section and set
`TLS_MODE=3` in `/etc/commandbridge/install.conf`.

## Backups

```bash
mariadb-dump --single-transaction commandbridge | gzip > commandbridge-$(date +%F).sql.gz
tar czf commandbridge-storage-$(date +%F).tgz -C /var/www/commandbridge/app storage
```

Also keep `/etc/commandbridge/`. The in-app Backup/Restore page covers operational data.

## Self-check

```bash
sudo -u www-data php /var/www/commandbridge/app/bin/smoke_test.php --base-url=https://your-server --insecure
```

## Uninstall

Remove the Apache site (`a2dissite commandbridge`), the install folder, `/etc/commandbridge`, and
`DROP DATABASE commandbridge; DROP USER 'commandbridge'@'localhost', 'commandbridge'@'127.0.0.1';`.

## Troubleshooting

* Installer stopped: read `/var/log/commandbridge-install.log`; it is safe to fix the cause and re-run only if
  nothing was created - otherwise remove the partial install (see Uninstall) first.
* Blank page / 500: `/var/log/apache2/commandbridge-error.log`.
* Official PDFs fail: `pdftk --version` must work for the www-data user.
