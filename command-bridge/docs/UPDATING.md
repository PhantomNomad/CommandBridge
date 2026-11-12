# Updating Command Bridge

```bash
# unpack the new release in a separate folder, then as root:
cd command-bridge-<new-version>
sudo ./update.sh
```

`update.sh` reads `/etc/commandbridge/install.conf`, backs up the database to `/var/backups/commandbridge/`, replaces
the application code (never `storage/` or `db.env`), applies any new files in `database/migrations/` in order
(recorded in the `schema_migrations` table) and runs the environment self-check. To roll back, restore the code from
the previous release and the SQL backup (`gunzip -c backup.sql.gz | mariadb commandbridge`).

## Coming from a private 1.0.2.x build

Those builds used `/opt/.../command-bridge-webapp`, `ics_app` and `database_changes_*.sql`. There is no in-place
converter. Recommended path: install 0.9.0-beta.1 on a new server and move data with the in-app Backup/Restore page,
or keep running the old build. If you migrate in place yourself:

1. Back up the database and `storage/`.
2. Add `APP_TIMEZONE=<your zone>` to the old `db.env` (times default to UTC otherwise).
3. Apply every outstanding `database_changes_*.sql` from the old build, then
   `CREATE TABLE schema_migrations` and `INSERT INTO schema_migrations (version) VALUES ('0000_baseline');` (see `database/schema.sql`).
4. Point Apache at the new `app/` folder, keeping the deny rules from `deploy/apache/commandbridge-common.inc.template`.

## Writing a migration (developers)

See `database/migrations/README.md`.
