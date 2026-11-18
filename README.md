# batcaveroadfarm.com

Custom PHP 8 + MySQL site for Bat Cave Road Farm (Old Fort, NC): public site, farm stand, petting zoo,
bookings, passes, events, recipes, blog/newsletter, and a staff admin (Farm OS).

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
- `admin/handlers/check_stock_alerts.php` daily 8:00 AM (cron.php queue)
- `admin/cron/send_newsletter.php`, `send_booking_reminders.php`, `send_review_requests.php`, `send_social.php`

## Change history
Git history. The Oct 2026 fix-pack notes, bug report and status page are kept in `docs/archive/` for reference.
Public JS bundle: `node tools/build-js.mjs` (or the "Build JS" workflow) builds `js/min.site.js`.

## Deploy
Follow docs/DEPLOY.md: connector lane for most files, "Deploy pack" workflow + cPanel for the files the connector can't write.
Always back up the server copy of a file before overwriting it.
