# Google Analytics & Search Console setup

Set up Nov 2026 (task "GA4 & Search Console: privacy, conversions, admin reports"). Code:
`includes/ga.php`, `js/min.gtag.js`, `includes/google_reports.php`, `admin/views/search_analytics.php`,
`admin/cron/fetch_google_reports.php`.

## 1. Purchases sent from the server (Measurement Protocol)

Online ticket, pass and booking payments are sent to GA as `purchase` events when Stripe confirms them
(webhook, or the success page if the webhook is late). Each sale is sent once.

1. GA → Admin → Data streams → your web stream → **Measurement Protocol API secrets** → Create.
   Name it `bcrf-server`. Copy the secret value.
2. In `/home2/ddailnmy/bcrf-secrets.php` (cPanel File Manager), add inside the array:
   `'GA4_API_SECRET' => 'the-secret-value',`
3. GA → Admin → Events: mark `purchase`, `generate_lead`, `sign_up` (and `job_application` if wanted) as **key events**.

Until the secret is added, purchases simply aren't sent (nothing breaks).

## 2. Staff visits excluded

Signed-in staff pages are tagged `traffic_type=internal`.
GA → Admin → Data collection and modification → **Data filters** → "Internal Traffic" → set to **Active**.
(It starts in "Testing", which only labels the visits.)

## 3. Google Business Profile link

In Business Profile → Edit profile → Website, use:
`https://batcaveroadfarm.com/?utm_source=google&utm_medium=organic&utm_campaign=gbp`
For GBP posts, use `utm_campaign=gbp-post` on the button link.

## 4. Admin "Search & Analytics" screen (service account)

Google's device sign-in (used for Drive backups) can't read Analytics or Search Console, so this uses a
service account: a robot Google account that only has the access you give it.

1. **Google Cloud console** (same project as the Drive backups, or a new one):
   APIs & Services → Library → enable **Google Search Console API** and **Google Analytics Data API**.
2. IAM & Admin → Service accounts → **Create service account**, name `bcrf-reports`. No roles needed.
   Open it → Keys → Add key → Create new key → **JSON**. A file downloads.
3. cPanel File Manager: upload that file to `/home2/ddailnmy/` (your home folder, NOT public_html),
   rename it `bcrf-google-sa.json`, set permissions to **600**.
4. Copy the service account's email (ends in `.iam.gserviceaccount.com`).
   - **Search Console** → Settings → Users and permissions → Add user → that email → **Restricted**.
   - **GA** → Admin → Property access management → + → that email → **Viewer**.
5. GA → Admin → Property details: copy the **Property ID** (digits). In `bcrf-secrets.php` add:
   `'GA4_PROPERTY_ID' => '123456789',`
6. cPanel → Cron Jobs → add (daily 6:10 AM):
   `10 6 * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/fetch_google_reports.php`
7. Admin → Admin → **Search & Analytics** → Refresh now.

Optional secrets: `GOOGLE_SA_KEY_FILE` (key somewhere else), `GSC_SITE` (default `sc-domain:batcaveroadfarm.com`).

## What the browser side does

- `js/min.gtag.js` removes these query values from page addresses (and same-site referrers) before GA
  sees them: `e`, `email`, `t`, `token`, `ts`, `sig`, `key`, `code`, `session_id`. GA's rules forbid
  sending email addresses, and tokens are private links (pass QR, password reset).
- Lead events: `bcrf_ga_flash()` queues an event on the real success path; the next page's `<body>`
  carries `data-ga-event`, and `js/min.analytics.js` sends it. Honeypot/bot submissions skip it.
