# Restoring Bat Cave Road Farm from backup

<!-- docs/RESTORE.md — written Nov 2026 with the backup system (admin/cron/backup_db.php, admin/cron/backup_files.php) -->

Everything needed to rebuild the site lives in three places:

| What | Where | How often |
|---|---|---|
| Code | GitHub: `zacmalin/batcaveroadfarm-site` (`main`) | every change |
| Database | `/home2/ddailnmy/bcrf-backups/bcrf-db-*.sql.gz` (14 days) and Google Drive → **BCRF Backups** `bcrf-db-*.sql.gz.zip` (30 copies) | nightly, 3:15 AM |
| Photos, résumés, secrets | `/home2/ddailnmy/bcrf-backups/bcrf-files-*.zip` (4 copies) and the same Drive folder (4 copies) | weekly, Sunday 3:40 AM |

The `.zip` files are AES-256 encrypted with **BACKUP_PASSWORD** (in `bcrf-secrets.php` and in your password manager).
Without that password the Drive copies can't be opened, so keep it in the password manager.

## A. Undo a bad change to the database (server still fine)

1. cPanel → File Manager → `/home2/ddailnmy/bcrf-backups/`, download the `bcrf-db-…sql.gz` from before the problem.
2. cPanel → phpMyAdmin → select the site database → **Import** → choose that `.sql.gz` → Go.
   The backup replaces each table it contains (it drops and recreates them).
3. Purge LiteSpeed + Cloudflare caches.

## B. Restore photos or a single file

1. Download the newest `bcrf-files-…zip` (server folder or Google Drive).
2. Open it with the password. On a phone: Files (iPhone) or a zip app that supports AES-256 (e.g. ZArchiver on Android).
   On a computer: 7-Zip (Windows), Keka (Mac), or `7z x file.zip`.
3. Inside: `site/img/…` → upload to `public_html/batcaveroadfarm/img/`; `bcrf-uploads/…` → `/home2/ddailnmy/bcrf-uploads/`;
   `bcrf-secrets.php` → `/home2/ddailnmy/bcrf-secrets.php`.

## C. Full rebuild (hosting account lost)

1. New hosting with PHP 8.5 + MySQL/MariaDB. Create an empty database and user.
2. Code: download `main` from GitHub as a zip and upload it to the new web folder.
3. From Google Drive → **BCRF Backups**: the newest `bcrf-files-…zip` and `bcrf-db-…sql.gz.zip`. Open both with BACKUP_PASSWORD.
4. Put `bcrf-secrets.php` one folder above `public_html` and update the DB name/user/password in it for the new host.
5. Upload `site/img` (and `images/`, `media/`, `uploads/` if present) into the web folder; `bcrf-uploads/` next to `bcrf-secrets.php`.
6. phpMyAdmin → Import the `.sql.gz` from inside the database zip.
7. Recreate the cron jobs (see admin → Backups for the exact lines), reconnect Google Drive (admin → Backups),
   and point Cloudflare DNS at the new server.

## Quarterly restore test (15 minutes)

A backup only counts if it restores. Every 3 months:

1. admin → **Backups**: all lines green; the Drive copies are recent.
2. Download the newest database backup and import it into a **scratch** database
   (cPanel → MySQL Databases → create `…_restoretest`, import there — never over the live one).
   Spot-check a few tables (recipes, bookings, passes) have recent rows, then delete the scratch database.
3. Download the newest files backup from **Google Drive**, open it with the password from your password manager,
   and open one photo and one résumé PDF.
4. Note the date in the Notion backups task.
