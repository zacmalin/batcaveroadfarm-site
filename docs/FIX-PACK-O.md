# Fix Pack O: Audit Log Coverage (2026-10-24)

18 files. No database changes and no new settings.

## What was missing from the Audit Log

I checked every admin file that changes data. These actions were not being logged and now are:

| Action | Shows in Audit Log as |
|---|---|
| Changing a role's permissions (who can see or edit what) | `role_permissions`: "Permissions for Shopkeep: 9 pages, view 12 / edit 5 tables" |
| Theme schedule added, turned on or off, deleted; theme override or default changed | `theme_schedules` / `theme_settings` |
| Approving or rejecting recipe comments | `recipe_comments`: "Rejected 3 comment(s): #12, #13, #14" |
| A visitor automatically restricted from commenting (5+ rejections) | `users`: "Comments restricted 60 days" |
| Adding a recipe category, blog category or cuisine from a form | `categories` / `blog_categories` / `cuisines` |
| Recalculating a recipe's nutrition | `recipes`: "Nutrition recalculated: Poblano Chicken (2334 cal -> 412 cal per serving)" |
| Saving USDA nutrition data for an ingredient | `ingredients`: "Nutrition data: Butternut squash (45 cal/100g)" |
| Media: image added, replaced or rotated (including quick upload from forms) | `media`: "Image added: /img/zoo/goat.webp" |
| A guest cancelling a booking from the email link | user **Guest**, `bookings`: "Cancelled by guest (email link): Smith (2026-10-28)" |
| The scheduler publishing a blog post | user **Scheduler**, `blog_posts`: "Published on schedule: ..." |

Already logged before (unchanged): record add, edit and delete in the Schema editor, bookings, events, inquiries, passes, inventory, Farm Stand sales (POS), time clock, Site Info, newsletters, Stripe payments, subscribe/unsubscribe, and staff logins.

Not logged on purpose: email reminders and newsletter sending progress, automatic SKU numbers when printing labels, and visitors managing their own accounts. These are routine automatic steps and would only add noise.

## Security fix found along the way

- **Quick upload (`admin/handlers/quick_upload.php`):** it only checked that *someone* was logged in. A regular website visitor account could upload images to `/img/`. Now it's staff only (administrator, moderator, Shopkeep, Media), like the other admin tools. I confirmed the old file accepted a visitor's upload and the new one refuses it.
- **Image tool folder (`image_processing.php`):** the folder name is now cleaned so a saved image can never land outside `/img/`. It was staff-only already; this is extra safety.

## PHP 8.5 cleanup (fewer error-log lines)

- Removed `imagedestroy()` from the 3 image handlers. It has done nothing since PHP 8.0 and logs a "deprecated" warning on PHP 8.5.
- The USDA lookup and social posting now read response headers the new way (`http_get_last_response_headers()`), with a fallback for older PHP.
- 6 files no longer call `session_start()` a second time. config.php already starts the session, so the second call logged a notice on every request.

## Install (2 minutes)

1. Optional: in File Manager, compress `admin/` and `cancel-booking.php` into `before-pack-o.zip` as an undo copy.
2. Upload `fix-pack-O-audit-coverage-18-files-2026-10-24.zip` to `public_html/batcaveroadfarm/`. Choose Extract and allow it to overwrite, then delete the zip.

## Test (3 minutes, on your phone)

1. In Admin, go to **Permissions**, open any role, and press Save without changing anything. Then open **Audit Log**: the top line should read "Permissions for ...".
2. In **Media**, rotate any image left, then right again (so it ends up as it was). Two "Image rotated" lines should appear.
3. Open a record form with a photo picker (for example, an inventory item) and use quick upload with any photo. It should still work, and an "Image added (quick upload)" line should appear. Delete that test image afterwards if you like.
4. Optional: open a recipe and press Calculate nutrition. A "Nutrition recalculated" line should appear.

## Undo

Extract `before-pack-o.zip` over the site.

## Tested (PHP 8.5 + MariaDB 11.8)

Every new log line was produced by running the real handlers as a logged-in admin: permissions, 4 theme actions, category, blog category, cuisine, approve and reject comments with an automatic restriction, ingredient nutrition, recipe nutrition, image add, replace and rotate, quick upload, guest cancel (a second press doesn't log twice), and the scheduled publish. Folder names with `../` stayed inside `/img/`. A visitor account got "Staff only" from quick upload. The PHP error log was clean after all tests, and every PHP file in the repo checks clean on PHP 8.5.
