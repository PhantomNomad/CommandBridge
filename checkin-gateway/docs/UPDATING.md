# Updating the Check-In Gateway

```bash
# unpack the new release in a separate folder, then as root:
sudo ./update.sh
```
It reads `/etc/commandbridge/gateway-install.conf`, backs up the database to `/var/backups/commandbridge-gateway/`, replaces the code
(never `/etc/commandbridge/checkin_gateway.env`), applies new files from `database/migrations/` (recorded in `schema_migrations`) and checks `health.php`.

## Coming from a private 1.0.x gateway
Old builds used `/var/www/checkin-gateway`, database `checkin_gateway` and `database_changes_*.sql`; there is no in-place converter.
Recommended: install 0.9.0-beta.1 on a fresh server and re-run the Command Bridge backfill so locations and users re-synchronise.
To convert in place yourself: apply the 1.0.9 and 1.0.10 changes, create the `schema_migrations` table and record `0000_baseline` (see `database/schema.sql`),
write `/etc/commandbridge/gateway-install.conf` (INSTALL_DIR, DB_NAME, SITE_NAME, SERVER_NAME, TLS_MODE), and point Apache at the new `public/` folder.
