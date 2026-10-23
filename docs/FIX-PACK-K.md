# Fix Pack K: Security + Backups (2026-10-23)

15 files (4 new, 11 updated). Builds on everything through Pack J.
No database changes needed. Every file was syntax-checked on PHP 8.5 and tested
against MariaDB 11.8 before packaging.

## What it does

| Area | Change |
|---|---|
| Contact form (BUG FIX) | The contact page never sent the security token that `send-message.php` requires, so real messages were failing with "That didn't go through". The token is now in the form. |
| Contact form | Limit: 3 messages per 10 minutes and 10 per day from one connection. The auto-reply no longer repeats the visitor's message text and goes to any one address at most 2 times a day (stops spammers using the form to email other people). |
| Staff login (`/admin/login.php`) | Now has a security token and a hidden bot trap. Lockout after 5 wrong passwords from one connection (15 minutes) or 10 wrong passwords on one email from anywhere (1 hour). Every success, failure, and lockout is written to the Audit Log. |
| Visitor login | Server-side limit added on top of the old one: 10 failures per connection per 15 minutes, 20 per email per hour. |
| Forgot password | Max 3 reset emails per address per hour, 10 requests per connection per hour. |
| Mailing list | Double opt-in. Signing up now sends a "please confirm" email. The address is only added after the owner taps the link and presses Confirm. Limits: 5 signups per connection per hour, 3 confirmation emails per address per day. Existing subscribers are not affected. |
| Audit Log screen | New "Staff logins" button; action filter has login / failed / blocked. |
| Nightly backup | New `admin/cron/backup_db.php`. Saves a compressed copy of the whole database to `/home2/ddailnmy/bcrf-backups/`, keeps 14 days, and emails you if it fails. Command line only. A browser gets 403. |

The limits are stored as small files in `/home2/ddailnmy/bcrf-ratelimit/` (outside the website,
created automatically). Only hashed values are stored, never raw IP or email addresses.

## Files

New:
- `includes/rate_limit.php`
- `includes/subscribe_confirm.php`
- `subscribe-confirm.php`
- `admin/cron/backup_db.php`

Updated:
- `admin/login.php`, `admin/includes/audit.php`, `admin/views/audit_log.php`
- `account/login.php`, `account/login_ajax.php`, `account/forgot.php`
- `subscribe.php`, `send-message.php`, `contact-us/index.php`
- `includes/head.php` (asset version is now `20261023k`, so browsers load the new script)
- `js/min.subscribe.js` (signup popup stays open 6 seconds so people can read "check your inbox")

## Install (cPanel File Manager, phone-friendly)

1. Optional safety copy: in File Manager, open `public_html/batcaveroadfarm/`, select the
   11 "Updated" files above, then choose Compress, and name the zip `before-pack-k.zip`.
2. Upload `fix-pack-K-security-15-files-2026-10-23.zip` into `public_html/batcaveroadfarm/`.
3. Select it and choose Extract. Allow it to overwrite. The folders inside the zip match the site.
4. Delete the uploaded zip.

## Test (about 10 minutes)

1. Contact form. Send yourself a test message at /contact-us/. You should see "Message Sent!"
   and get the email at support@. **This is the important one**: it was failing before.
2. Staff login. Log in at /admin/login.php as normal. Then open Audit Log and tap
   "Staff logins". You should see a `LOGIN OK` row with your name.
3. Mailing list. Tap "Join our mailing list" in the footer and enter an email you can check.
   The popup says "Almost done! Check your inbox". Open the email, tap "Yes, sign me up",
   then press the Confirm button. You should see "You're on the list!"
4. Optional lockout test (use phone data, not home Wi-Fi). Type a wrong staff password
   5 times. The 5th time says "Too many failed attempts. Please wait 15 minutes".
   To unlock right away, delete the folder `/home2/ddailnmy/bcrf-ratelimit` in File Manager.

## Add the backup cron job

cPanel > Cron Jobs > Add New Cron Job:

- Common Settings: Once Per Day. Then set Minute `15` and Hour `3`
  (server time is Boise, so this runs 5:15 AM Eastern).
- Command, using the same PHP path as your other cron jobs:

```
/usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/backup_db.php >> /home2/ddailnmy/backup_db.log 2>&1
```

Tomorrow, check that `/home2/ddailnmy/bcrf-backups/` has a `bcrf-db-....sql.gz` file, and
that `/home2/ddailnmy/backup_db.log` ends with a line starting with `OK`.

To restore: phpMyAdmin > choose the database > Import > pick the `.sql.gz` file.
(It replaces the tables in the backup, so only do this if something went wrong.)

Tip: download one backup to your phone or Google Drive now and then. A backup that is
only on the same server won't help if the hosting account itself has a problem.

## Optional: clearer Audit Log labels

Your `audit_log.action` column probably only allows create/update/delete. If so, login
events are saved as "update" with labels like `LOGIN FAILED: email`. That works fine.
If you'd like them to show as "login", "failed", or "blocked" instead, run this once in
phpMyAdmin > SQL (existing rows are kept):

```sql
ALTER TABLE audit_log MODIFY action VARCHAR(20) NOT NULL;
```

## Undo

Extract `before-pack-k.zip` over the site, then delete the 4 new files listed above.

## Tested

- Staff login: no token, bot trap, correct login, visitor account blocked from the console,
  5-failure IP lockout, lockout holds even with the right password, other connection still
  works, 10-failure email lockout across 10 connections, Audit Log rows for both column types.
- Visitor login (page + popup), forgot password (3 emails per hour per address).
- Signup: nothing saved until confirmed; a plain link click doesn't subscribe (protects
  against email scanners); confirm works; tampered, wrong, or expired links are rejected;
  previously unsubscribed address comes back; bot trap; per-address and per-connection limits.
- Contact form: 3 per 10 minutes, then blocked; auto-reply capped at 2 per address; message
  text not repeated in the auto-reply; every message still reaches the farm inbox.
- Backup: mysqldump mode and built-in PHP mode both restore with identical data (binary
  data, quotes, backslashes, emoji, views); works with proc_open disabled; 14-day cleanup;
  failure email; browser access gets 403.
- Rate limiter: 20 parallel requests counted correctly, time window, recovery from a corrupt file.
- All 168 site PHP files pass `php -l` on PHP 8.5.
