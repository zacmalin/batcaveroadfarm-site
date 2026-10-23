# batcaveroadfarm.com

Custom PHP 8 + MySQL site for Bat Cave Road Farm (Marion, NC): public site, farm stand, petting zoo,
bookings, passes, events, recipes, blog/newsletter, and a staff admin (ERP) console.

Hosting: Bluehost cPanel, LiteSpeed, PHP 8.5, behind Cloudflare.
Live path: `/home2/ddailnmy/public_html/batcaveroadfarm/` (this repo = that folder).

## Secrets
Never in this repo. `config.php` loads `bcrf-secrets.php` (returns an array) from, in order:
1. `BCRF_SECRETS` env var
2. `/home2/ddailnmy/bcrf-secrets.php` (home folder, outside every web folder) <- live
3. `/home2/ddailnmy/public_html/bcrf-secrets.php` (old location, fallback)

Keys: `DB_HOST DB_USER DB_PASS DB_NAME LIST_SECRET USDA_API_KEY SMTP_* STRIPE_PK STRIPE_SK STRIPE_WH_SEC PAYPAL_*`
(optional `CRON_KEY`, leave unset so cron runs from the command line only).


## Not tracked
`vendor/` (PHPMailer), uploads/images, logs, zips. See `.gitignore`.

## Cron (cPanel, PHP CLI path)
- `admin/handlers/check_stock_alerts.php` daily 8:00 Boise time
- `admin/cron/send_newsletter.php`, `send_booking_reminders.php`, `send_review_requests.php`, `send_social.php`

## Change history
See `docs/PROJECT-STATUS.md` and `docs/BUG-REPORT.md`. Fix packs installed 2026-10-23:
A security/bug pack, B farm-stand rich results, C cron key, D audit log (all modules),
E cleanup (.htaccess redirects, secure cookie, print CSS), F SameSite=Lax + same-site delete check,
G secrets moved above public_html, H one-tap unsubscribe (RFC 8058).

## Deploy
Edit -> commit -> upload changed files via cPanel File Manager (or cPanel Git Version Control).
Always back up the server copy of a file before overwriting it.
