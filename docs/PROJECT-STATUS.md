# Bat Cave Road Farm — Project Status
Updated: 2026-10-23 (code review done)

## Decisions
- Hosting: Bluehost, deployed via cPanel File Manager (Zac works mobile-only).
- Official email: support@batcaveroadfarm.com (replace info@ / hello@ everywhere).
- Hours are the same for every area: Tue–Sun 10:00 AM–4:00 PM, closed Monday.
- Admin system (breeds, ingredients/nutrition, recipes, media vault) is in scope.
- Priority: bugs first.
- MySQL connection to Perplexity: not working yet.

## Bug list
1. [URGENT] /llms.txt serves raw PHP admin source (~2,000 lines). Replace with site-root/llms.txt.
2. /agent-instructions.md missing (referenced in robots.txt + agents.json). Upload site-root/agent-instructions.md.
3. humans.txt uses info@ — change to support@.
4. JSON-LD sameAs Facebook uses profile.php?id=… — match footer (facebook.com/batcaveroadfarm).
5. theme-color is #fafafa — change to #3b2010.
6. Check Cloudflare bot settings (challenge pages hit some automated requests).

## Waiting on
- Code-only zip: upload tools/make-code-zip.php to public_html, visit ?key=<one-time key>, download code-review-*-partN.txt file(s) from home folder (zip uploads not supported in Perplexity).

## Code review (2026-10-23)
- Source snapshot (secrets blanked): src-snapshot-2026-10-23/ — 225 files. Secrets live in bcrf-secrets.php ABOVE public_html.
- Stack: PHP 8.5 (ea-php85 lsphp), LiteSpeed, Cloudflare in front, PDO/MySQL, PHPMailer (vendor/), Stripe via cURL (includes/stripe.php), Tailwind admin console admin/admin.php?view=...
- Staff roles: administrator, moderator, Shopkeep, Media (check_admin() in config.php). Public accounts also set $_SESSION['user_id'] — always check role for admin endpoints.
- Fix pack: fixes-2026-10-23/ (+ .zip) with BUG-REPORT.md. Delete: admin/handlers/log_sale.php, get_products.php, get_sales_trends.php.
- Open items: dead rewrite rules -> farm-stand/item_view.php; separate CRON_KEY from LIST_SECRET; cookie secure flag; min.print.css bad byte; collect 6 error_log files.
- Note: Perplexity uploads don't accept .zip — use make-code-zip script (.txt bundle output).

## Error log review (2026-10-23)
- Site root on server: ~/public_html/batcaveroadfarm/ (addon/subfolder). Server timezone America/Boise.
- Active bugs fixed in pack: check_stock_alerts cron path (chdir), sitemap animals.photo_url + per-section try/catch, admin/views/passes.php $csrf.
- Data: farm-stand items 3 (Tomatoes), 4 (Bread) use peacock1.webp; 6 (Onions) uses og-faq.jpg.

## Install verified (2026-10-23)
- Fix pack installed by Zac. Live checks pass: llms.txt clean, agent-instructions.md serves, sitemap 68 URLs (17 animal, 8 breed; 0 thrift = none listed online), theme-color #3b2010, Facebook link fixed, humans.txt support@, /includes/ now 403.
- Search Console: property sc-domain:batcaveroadfarm.com. Sitemap resubmitted 2026-10-23 (was last submitted 2026-06-07). Key pages indexed; animal pages "unknown to Google" pending recrawl.
- Next: farm-stand page rich results FAIL (structured data); cron key split; dead rewrite rules; cookie secure flag; print css byte; product photos (items 3,4,6); GitHub repo.
- Zac to verify: Audit Log filter; no new error_log in admin/handlers after next 8:00 AM (Boise) cron.

## Farm stand rich results (2026-10-23)
- Cause: 6 static Product nodes on /farm-stand/ had offers without price (eggs/honey also no image) -> GSC rich results FAIL.
- Fix pack B (fixes-2026-10-23b-farmstand.zip): index.php -> categories as OfferCatalog only; items/item.php -> dynamic Product JSON-LD with DB price (only if price > 0).
- Live item prices: Tomatoes $1, Bread $2, Pickles $3, Onions $3 (possibly test values — ask Zac).
- Fix pack B installed + verified live 2026-10-23: /farm-stand/ has 0 Product nodes, valid JSON-LD; items 3-6 output valid Product with price/InStoreOnly/real inventory photos (photos for 3,4,6 now updated).

## Cron key split (2026-10-23) — fix pack C prepared
- Server home: /home2/ddailnmy/ ; site root public_html/batcaveroadfarm/ ; secrets public_html/bcrf-secrets.php (returns array).
- config.php: BCRF_LIST_SECRET no fallback (new value to add to bcrf-secrets as LIST_SECRET); new BCRF_CRON_KEY (CRON_KEY, unset = CLI only).
- 4 cron scripts: URL access requires BCRF_CRON_KEY >= 24 chars via X-Cron-Key header or ?key=.
- unsubscribe.php ignores e/t tokens (POST email form only) — future: verify token for true one-click + List-Unsubscribe-Post.
- Waiting on: Zac's current cPanel cron job list.
- Fix pack C installed 2026-10-23. All cron jobs are command-line (PHP path), so no CRON_KEY set = URL cron fully blocked. LIST_SECRET added to bcrf-secrets.php. Site + DB pages verified loading after config.php change.
- Remaining: dead rewrite rules (.htaccess -> farm-stand/item_view.php), session cookie secure flag, min.print.css bad byte, optional move of bcrf-secrets.php above public_html, one-click unsubscribe, GitHub repo.
- Audit log fix verified 2026-10-23 against MariaDB 11.8 with site PDO options (EMULATE_PREPARES=false): old code shows 0 rows for any filter; new code correct. Live admin check still up to Zac (I can't log in; robots blocks /admin/).

## Audit log (2026-10-23) — fix pack D
- Root cause of blank log: erp_audit() defined but never called; also read first_name/last_name while session has user_name.
- Fix: admin/includes/audit.php shared helper; schema_record logs create/update after commit; schema_delete logs delete (label fetched first). Tested on MariaDB.
- Only schema-editor changes are logged; other modules (bookings, passes, events, POS, inventory_update, newsletter, timesheets) not yet.
- Fix pack D expanded (same zip name): audit for bookings, passes, events, inquiries, POS receipts, inventory quick-edit, newsletter campaigns, time clock, Stripe webhook (actor 'Stripe (automatic)'). erp_audit() gained optional $actor; erp_row_label() helper. Tested 17 handler actions on MariaDB. Also fixed manual pass welcome email expiry. admin/newsletter_send.php is unused legacy.

## Cleanup pack E (2026-10-23): cleanup-3-files-2026-10-23.zip
- .htaccess: dead item_view.php rules -> 301 to /farm-stand/ and /petting-zoo/ (numeric animal ids untouched).
- config.php: session cookie secure flag trusts X-Forwarded-Proto / CF-Visitor / live domain.
- css/min.print.css: 4 corrupted chars fixed (gallery note; footer dots now \00B7). File is pure ASCII.
- Still open: move bcrf-secrets.php to /home2/ddailnmy/, one-click unsubscribe, GitHub repo, delete admin/newsletter_send.php (unused). SameSite=Strict may log users out on return from Stripe/email links (consider Lax).

## SameSite Lax (2026-10-23): samesite-lax-2-files-2026-10-23.zip
- config.php (builds on cleanup pack E): samesite Strict -> Lax.
- admin/handlers/schema_delete.php (builds on audit pack D): GET delete now requires Sec-Fetch-Site same-origin/none, or same-domain Referer; else blocked + logged.
- Only GET-triggered admin write was schema delete. Can't ship admin/views/schema.php or admin/assets/schema.js in full (csrf_token lines redacted in snapshot).

## Secrets move (2026-10-23): secrets-move-config-2026-10-23.zip
- config.php (builds on SameSite pack): looks for bcrf-secrets.php in BCRF_SECRETS env, then /home2/ddailnmy/ (dirname(__DIR__,2)), then old public_html/ location. Defines BCRF_SECRETS_PATH. Tested 4 cases.
- User steps: install config, move file to home folder via File Manager, permissions 600, verify site + admin login.
- 2026-10-23: user reported homepage failure at step 3; was a user-side issue, config installed and site working. Next: move file to home folder + chmod 600.
- 2026-10-23: secrets move done; user reports all working (earlier failures were user-side). Confirm old public_html copy deleted and secrets-check.php gone.

## One-tap unsubscribe (2026-10-23): unsubscribe-5-files-2026-10-23.zip
- NEW includes/unsubscribe.php: bcrf_unsub_token/url/verify (HMAC-SHA256 of lowercased email with BCRF_LIST_SECRET, 24 chars).
- unsubscribe.php rewritten: RFC 8058 one-click POST (List-Unsubscribe=One-Click) -> instant; signed GET -> confirm button (avoids link-scanner unsubscribes); bad/no token -> form prefilled. Case-insensitive match. Audit as 'Subscriber (self)'.
- email_template.php bcf_send_mail: unsubscribe=true -> per-recipient signed footer link + List-Unsubscribe (+mailto) + List-Unsubscribe-Post.
- campaign_action.php (builds on pack D): signed footer link + headers + text-part link.
- send_newsletter.php (builds on pack C): uses helper.
- Tested 8 cases on MariaDB. Old emails (old secret) fall back to prefilled form.
- Watch: Cloudflare bot protection could block Gmail's one-click POST; add WAF skip for /unsubscribe.php if needed.
- 2026-10-23: unsubscribe pack installed and tested by user: OK.
