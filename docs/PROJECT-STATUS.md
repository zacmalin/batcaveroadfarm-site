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
- Code-only zip: upload tools/make-code-zip.php to public_html, visit ?key=bcrf-7Qm4xV9pL2, download code-review-*-partN.txt file(s) from home folder (zip uploads not supported in Perplexity).

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

## GitHub repo (2026-10-23)
- PRIVATE https://github.com/zacmalin/batcaveroadfarm-site (branch main). Local checkout: /home/user/workspace/bcrf-repo
- Initial commit = src snapshot + packs A-H, deleted log_sale/get_products/get_sales_trends/newsletter_send, hello@->support@ in admin/views/newsletter.php.
- 7 files still have REDACTED placeholders (listed in README): nutrition_config.php (USDA key), schema.js, schema.php, panel_ingredient_nutrition.php, newsletter.php, passes/index.php, js/min.auth.js. Get live copies as .txt to fix.
- Going forward: commit every fix to the repo too.

## USDA key (2026-10-23): usda-key-1-file-2026-10-23.zip
- nutrition_config.php reads USDA_API_KEY from bcrf-secrets.php (fallback DEMO_KEY). Repo: all REDACTED placeholders restored from live copies.
- 2026-10-23: user confirmed cron URL check done (Forbidden).
- 2026-10-23: user confirmed installed/verified: audit FULL pack, cleanup E, SameSite F, USDA key, newsletter_send.php deleted, old public_html secrets copy removed. Only open item: stock-alert cron check 2026-10-24 (reminder automation f4cd8956).

## SEO / AI / performance review (2026-10-23 PM)
- GSC (sc-domain, Jul 20–Oct 20): poblano chicken recipe = top page (1,180 impr, pos 16, CTR 1.5%); "pollo poblano" 324 impr pos 6.5 CTR 0.9%. Homepage ~780 impr pos 8.8.
- Index: 44/59 sitemap URLs indexed. Not indexed: contact-us (crawled-not-indexed, last crawl Jun 18), directions (unknown), 2 blog posts (unknown), pickles item, 7 animal pages, pigs breed. 403 in July (Cloudflare) on private-events, animals 3/10, breeds/9 — request indexing in GSC.
- Bug: recipe meta description showed escaped "&lt;p&gt;" (DB HTML). Recipe pages had 3 H1s (auth modal). Poblano recipe nutrition 2,334 cal/serving looks wrong -> recalc in admin (check servings + ingredient units).
- SEO fix pack I (seo-fix-pack-I-6-files-2026-10-23.zip): .htaccess sitemap.xml rule; recipe_detail.php clean desc + title "X Recipe | Bat Cave Road Farm"; head.php one-line <title>; auth_modals h1->h2; min.auth.css + min.style.css selector. GitHub branch seo-pack-i (merge after install).
- Content to-dos (admin/DB): "souvernir" typo x3 on passes; events page empty (add fall/Halloween events with Event schema); FAQ admission answer should state $8/$5/under 3 free; short titles on shop/gallery/booking/contact/tickets/thrift/farmstand (add "Old Fort, NC"); img width/height missing on petting-zoo (11), recipes (8).
- GBP account UNVERIFIED in API (accounts/100339490590403771206) — check Business Profile verification.
- Cloudflare: spoofed bot UAs from datacenter get 403 (fine); real Googlebot was 403'd in July only.

## 2026-10-23 evening
- User confirmed: SEO pack I installed + all non-speed to-dos done. seo-pack-i merged to main.
- GBP: user confirms profile IS verified (the API "UNVERIFIED" was the personal account object, not the location) — ignore.
- Image size fix pack J (image-size-fix-pack-J-9-files-2026-10-23.zip): new includes/img_dims.php (getimagesize, local-only, path-safe); width/height on home, petting-zoo, recipes, blog, events, related images; height:auto on .zoo-card-img and .home-recipe-row img; BCRF_ASSET_V -> 20261023b. GitHub branch seo-pack-j (merge after install).
- Pack J confirmed working by user; seo-pack-j merged to main.

## Fix pack K: security + backups (2026-10-23): fix-pack-K-security-15-files-2026-10-23.zip
- Folder: fixes-2026-10-23k-security/ (INSTALL.md inside). GitHub branch pack-k (merge after install). No DB changes.
- BUG FOUND: contact-us/index.php form had no csrf_token but send-message.php requires it -> every contact message failed. Fixed (csrf_field in form).
- NEW includes/rate_limit.php: file-based server-side limiter in /home2/ddailnmy/bcrf-ratelimit/ (hashed keys, flock). Unlock = delete that folder.
- Staff login: CSRF + honeypot; 5 fails/IP/15 min, 10 fails/email/hour; audit rows table_name 'staff_login' (LOGIN OK / LOGIN FAILED / LOCKED OUT). erp_audit_security() in admin/includes/audit.php falls back to action 'update' if action column is ENUM. Optional SQL: ALTER TABLE audit_log MODIFY action VARCHAR(20) NOT NULL.
- Visitor login: +10 fails/IP/15 min, 20/email/hour. forgot.php: 3 emails/address/hour, 10/IP/hour.
- Mailing list double opt-in: subscribe.php emails signed link (HMAC with LIST_SECRET, 7 days); subscribe-confirm.php GET shows button, POST subscribes. 5 signups/IP/hour, 3 confirm emails/address/day.
- Contact: 3/10 min + 10/day per IP; auto-reply no longer echoes message, max 2/address/day.
- admin/cron/backup_db.php: CLI only; mysqldump (defaults-extra-file) else PHP exporter; /home2/ddailnmy/bcrf-backups/, keep 14 days (min 3), failure email to STOCK_ALERT_TO. Cron: daily 3:15 server time, log to /home2/ddailnmy/backup_db.log.
- Asset version 20261023k; min.subscribe.js popup stays 6 s.
- Tested on PHP 8.5.8 + MariaDB 11.8.3 (both backup modes restore identically). All 168 PHP files pass php -l.
- Waiting on: Zac to install, test contact form + staff login + signup, add backup cron, check first backup on 2026-10-24 (same day as stock-alert check).
- Not covered yet: booking/submit.php, cancel-booking.php, review/ have no CSRF (honeypot only).
- HOTFIX K1 (2026-10-23): Zac reported correct staff password -> "Invalid credentials". Cause: hidden honeypot "website" text input placed above the email field got autofilled by the phone password manager, tripping the bot check (which showed "Invalid credentials."). Fix: removed honeypot from admin/login.php only (CSRF + lockouts remain); added autocomplete=username/current-password. File: hotfix-K1-admin-login-1-file-2026-10-23.zip. Lesson: never put a honeypot text input directly before a login's email/password fields.
- CONFIRMED 2026-10-23: Zac reports Pack K + hotfix K1 installed and all good. pack-k merged into main. Still to check 2026-10-24: first nightly backup file in /home2/ddailnmy/bcrf-backups/ and backup_db.log 'OK' line (if cron added), plus stock-alert cron.

## Fix pack L: booking security tokens (2026-10-23): fix-pack-L-booking-tokens-3-files-2026-10-23.zip
- Folder fixes-2026-10-23l-booking-tokens/. GitHub branch pack-l. Requires pack K.
- booking/index.php csrf_field(); booking/submit.php csrf_verify + 5 requests/IP/hour (bcrf_rl 'booking_ip'); cancel-booking.php csrf on POST, expired token re-shows the confirm button with "tap once more".
- review/index.php is only a redirect (no form); checkout -> stripe_session already had CSRF; booking/cancel.php is a message page only.
- Tested PHP 8.5 + MariaDB 11.8, 13 checks pass. Waiting on Zac to install + test.
