# Bat Cave Road Farm — Bug Report & Fix Pack
Reviewed: 2026-10-23 · Source: code-review bundle (225 files, PHP 8.5.12, Bluehost/LiteSpeed)

Every fixed file in this folder sits at the **same path** it lives at under `public_html/`.
All PHP files pass `php -l` (syntax check).

---

## Before you start (2 min)

1. **Back up each file before replacing it.** In cPanel File Manager, select the file, tap **Copy**, and name the copy `filename.php.bak`. (`.bak` files are already blocked from the web by your `.htaccess`.)
2. To replace a file: open it, choose **Edit**, select everything, paste the new version, then tap **Save Changes**. Or upload the fixed file into the same folder and overwrite.
3. Do the fixes **in the order below**. After each group, open the site on your phone and make sure it still loads.

---

## Priority 1: Security (do today)

### 1. Admin action files anyone on the internet can use
These files have **no login check at all**. They aren't used anywhere in your code, so they look like leftovers.

| File | What a stranger could do | Fix |
|---|---|---|
| `admin/handlers/log_sale.php` | POST any item ID and quantity to **change inventory stock** and add fake sales | **Delete** |
| `admin/handlers/get_products.php` | Read the inventory list as JSON | **Delete** |
| `admin/handlers/get_sales_trends.php` | Nothing works without the console; dead code | **Delete** |
| `admin/handlers/check_stock_alerts.php` | Open the URL repeatedly to **trigger alert emails** | **Replace** (now cron/command-line only) |

If you run `check_stock_alerts.php` from a cPanel cron job as `php /home/.../check_stock_alerts.php`, it still works. A cron job that calls it by URL will stop. If that's your setup, tell me.

### 2. Customer accounts can open some staff-only pages
Customers who register on the site get a `user_id` in their session, just like staff. Four admin files only checked `user_id`, so **any logged-in customer** could open them by URL:

| File | What a customer could see |
|---|---|
| `admin/handlers/view_campaign.php` | Any newsletter campaign, including drafts (and raw database error text) |
| `admin/print_label.php` | Product labels |
| `admin/handlers/get_label_data.php` | Label and nutrition data |
| `admin/handlers/get_vault_images.php` | The full media library listing |

**Fix:** replace all four. They now also require a staff role (administrator, moderator, Shopkeep, Media), using the same list as `check_admin()`.

### 3. A large `.zip` is sitting in your web folder
The bundle script skipped one `.zip` inside `public_html`. It's probably the 125 MB backup you made earlier. Your `.htaccess` does **not** block `.zip`, so anyone who guesses the name can download your whole site.
**Fix:** find it in File Manager (sort by Size) and delete it, or move it above `public_html`.

### 4. Remove the code bundle from the server
Delete `code-review-…-part1.txt` from your Home folder, if you haven't already.

---

## Priority 2: Broken things visitors and AI tools see

### 5. `agent-instructions.md` returns "Forbidden"
**Cause:** `.htaccess` line 98 blocks **every** `.md` file, including this public one.
**Fix:** replace `.htaccess` (root). The blocking rule now exempts only `agent-instructions.md`. All other `.md` files stay blocked. Then upload `agent-instructions.md` to `public_html/`.

### 6. `llms.txt` shows garbled characters ("Tuesdayâ€“Sunday")
**Cause:** when the file was pasted or saved, the dashes were converted to the wrong encoding.
**Fix:** replace `llms.txt` with the version here. It now uses **only plain keyboard characters** (no fancy dashes), so it can't get garbled again. The new `.htaccess` also tells browsers that `.txt` and `.md` are UTF-8.

### 7. `includes/.htaccess` typo causes server errors
**Cause:** `Requireall  denied` isn't a real Apache command. It should be `Require all denied`. Right now any request to `/includes/…` gets a **500 server error** instead of a clean "Forbidden". The files stay hidden, but every attempt adds noise to `error_log`.
**Fix:** replace `includes/.htaccess`.

### 8. HSTS "preload" is turned on
Your `.htaccess` comment says *don't* preload yet, but the header includes `preload`. Preload is very hard to undo. If any subdomain ever needs plain HTTP, it breaks.
**Fix:** included in the new root `.htaccess` (preload removed; everything else unchanged).

---

## Priority 3: Admin and payment bugs

### 9. Audit Log filters always show an empty list
**Cause:** `admin/views/audit_log.php` line 27 ran the count query **without** its filter values. With any filter set, that query errors out, which skipped loading the rows.
**Fix:** replace `admin/views/audit_log.php`.

### 10. Annual pass email can show the wrong expiry date
**Cause:** in `admin/webhooks/stripe.php`, the pass was read from the database **before** its real duration was applied. A 6-month pass got an email saying it was valid for 1 year, even though the database was correct.
**Fix:** replace `admin/webhooks/stripe.php`. Activation now happens in one step using the pass type's duration, then the pass is re-read for the email. Bookings and passes are also only marked paid when Stripe reports `paid` (or `no_payment_required` for 100% coupons).

---

## Priority 4: Consistency

| # | Issue | File | Fix |
|---|---|---|---|
| 11 | Browser bar color is near-white `#fafafa` | `includes/head.php` | Now `#3b2010` (brand brown) |
| 12 | Facebook link in search data is a `profile.php?id=` URL | `includes/head.php` | Now `facebook.com/batcaveroadfarm` (matches footer) |
| 13 | `info@` in every email footer | `admin/includes/email_template.php` | Now `support@` |
| 14 | `info@` in humans.txt | `humans.txt` | Now `support@` |
| 15 | Newsletter "Reply-to" defaults to `hello@` | `admin/views/newsletter.php` line 129 | **Edit one line by hand** (this file had a value blanked out, so I didn't send a full copy): change `value="hello@batcaveroadfarm.com"` to `value="support@batcaveroadfarm.com"` |

---

## Found, not fixed yet (your call)

- **Two dead redirect rules.** `.htaccess` lines 46 and 65 send `/farm-stand/catalog/…` and old `/petting-zoo/animals/<name>/` links to `farm-stand/item_view.php`, which doesn't exist (404). These are probably old links. I can delete the rules or point them at the new item pages.
- **Unsubscribe/cron secret has a hard-coded fallback.** `config.php` line 33 falls back to a value written in the code if `LIST_SECRET` isn't in `bcrf-secrets.php`. That value also unlocks your cron URLs (`send_newsletter.php?key=…`). Changing it would break unsubscribe links in emails already sent. The safer fix is a **separate** cron key, which I can prepare.
- **Session cookie `secure` flag depends on how HTTPS is detected.** Behind Cloudflare it may not get set. Since your site is HTTPS-only, it can simply be `true`.
- **`css/min.print.css`** has one corrupted character in the printed gallery note (cosmetic).
- **Some code double-runs count queries** (`passes/index.php` line 27, `admin/handlers/pass_action.php` line 44). They work but are wasteful. Cleanup only.

## What I'd like next

- **Your 6 `error_log` files.** The bundle skipped them. They're the fastest way to find bugs that happen on the live site. Download them from File Manager (look in `public_html`, `admin`, and other subfolders), rename each to `.txt`, and upload them here.

---

## Added after reading the error logs (9 logs, May 25 to Oct 22)

Your site lives in `public_html/batcaveroadfarm/` (that's what the paths in the logs show). The log one level up, in `public_html/`, is the busy one: 8,437 lines.

### Still happening

**16. Low-stock alert emails have never worked (since at least June 1)**
Your cron job runs `admin/handlers/check_stock_alerts.php` every morning at 8:00 server time and **fails every time**: 144 failures, the last one yesterday. The file loads `../../config.php` using a path relative to the *current folder*, but cron doesn't start in the file's folder, so it can't find config.php.
**Fix:** already in the pack. The replacement `check_stock_alerts.php` now switches to its own folder first (`chdir(__DIR__)`). Tested: the old file fails exactly as in your log, and the new one runs cleanly from any folder.

**17. Sitemap is missing every animal, breed and thrift item page (since July 11)**
`sitemap.php` asks the `animals` table for `image_url`, but that table calls the column `photo_url`. The query fails, and because all sections shared **one** try/catch, everything after it was silently dropped. The live sitemap currently stops after the farm-stand items.
**Fix:** replace `sitemap.php` (new in the pack). It uses `photo_url`, and each section now has its own error catch so one bad query can't take out the rest.

**18. Pass admin forms send an empty security token**
`admin/views/passes.php` uses `$csrf` but never sets it (55 warnings in July). The forms still work only because `pass_action.php` doesn't check the token.
**Fix:** replace `admin/views/passes.php` (adds one line at the top).

### Already fixed (no action needed)

| Error | Last seen | Status |
|---|---|---|
| `session.gc_divisor must be greater than 0` (7,537 lines) | Jul 10 | Fixed in PHP settings |
| `bcrf-secrets.php` syntax error | Jun 22 | Fixed |
| Database "Access denied for user ''" | Jun 17 | Fixed (secrets file now loads) |
| `BCRF_LIST_SECRET already defined` | Jun 23 | Fixed |
| `imagick.so` failed to load | Jun 17 | Gone (extension off or repaired) |
| `unsubscribe.php` wrong include paths | May 25 | Fixed |
| `BCRF_ASSET_V` undefined in `passes/verify.php` | Jul 10 | Fixed (now checks `defined()`) |
| `$csrf` undefined in `event_inquiries.php` | Jul 9 | Fixed |
| Schema view "unexpected end of file" | Jul 12 | Fixed |
| Database "No such file or directory" (once) | Aug 5 | One-off MySQL restart at Bluehost |
| Stripe webhook "marked paid / activated" lines | Jul 11 | Not errors, just success notes |

### Content issue the sitemap revealed
Some farm-stand products are using the wrong photos: **Tomatoes** and **Bread** show `peacock1.webp`, and **Onions** shows `og-faq.jpg`. Update their images in the admin Inventory screen.

### After installing
Once the fixes are in and confirmed working, it's safe to **delete all the old `error_log` files**. Then any new log that appears means a new problem.
