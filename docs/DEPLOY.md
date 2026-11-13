# Deploy and rollback checklist

<!-- docs/DEPLOY.md — Nov 12, 2026. Phone-only: GitHub app/browser, cPanel in the browser, Cloudflare dashboard. No terminal. -->

**Rule zero:** GitHub `main` is the source of truth. Committed is not deployed, and nothing is Done until step 5 passes on a phone.

## Pick the lane

| Lane | Which files | Who uploads | Backup | Rollback |
|---|---|---|---|---|
| **A. Connector** | Almost everything: pages, includes, admin views/handlers, CSS, JS | Claude, through the BCRF connector | Automatic (every write is backed up) | Claude runs `restore_file_backup` (or cPanel → `/home2/ddailnmy/bcrf-mcp-backups/`) |
| **B. cPanel pack** | What the connector can't write: `config.php`, `.htaccess` (any folder), `admin/admin.php`, `admin/includes/audit.php`, `permission_checks.php`, `permissions.php`, `admin/mcp_connections.php`, login/logout, `includes/auth.php`, `includes/mcp/*`, `api/`. Also new folders and images. | Zac, cPanel File Manager | Manual `.bak` copy (step 1) | Restore the `.bak` |

Never in a pack: `bcrf-secrets.php`, `vendor/`, `uploads/`, `img/` (the Deploy pack workflow refuses them).

## Before you start (2 minutes)

- The change is on `main` and the commit has green ticks (Code check and All tests, plus Migrations test if it touched the database). Note the short SHA.
- You know the exact file list. No other deploy is half-finished.
- Not during an open day rush, an event, or while someone is mid-checkout.
- Database change? admin → **Backups** → run a database backup now. admin → **Database** → Run pending refuses without a backup from the last 24 hours anyway.

## Lane A: connector (Claude does it)

1. Claude reads the live file (sha256 noted = the rollback point), writes main's version, PHP is syntax-checked server-side before it saves.
2. Claude confirms the live sha256 now equals GitHub's.
3. You do steps 4 to 6 below (purge, phone check, record).

## Lane B: cPanel pack (Zac)

1. **Snapshot.** cPanel → File Manager → `/home2/ddailnmy/public_html/batcaveroadfarm/` → for each file you'll overwrite: select it → **Copy** → path `…/same-folder/filename.bak-YYYYMMDD` → Copy File(s). Turn on **Settings → Show Hidden Files** first for `.htaccess`.
2. **Build the pack.** GitHub → Actions → **Deploy pack** → Run workflow (branch `main`) → type the files (e.g. `js/min.site.js .htaccess`) → Run. When it's green, open the run: the summary lists each file's sha256; the zip is under **Artifacts** at the bottom. Download it.
3. **Upload.** File Manager, in the site folder → **Upload** → pick the zip → back → select the zip → **Extract** → into the site folder (overwrites) → then **Delete** the zip so it isn't downloadable.
4. **Purge, in this order.** cPanel → **LiteSpeed Web Cache Manager** → Flush All. Then Cloudflare → batcaveroadfarm.com → **Caching → Configuration → Purge Everything** (or Custom Purge for one URL). Cloudflare first would just re-cache the stale LiteSpeed copy. CSS/JS links get a new `?v=` automatically from the file's modified time; no version bump needed.
5. **Verify on the phone, on cellular (wifi off):**
   - `batcaveroadfarm.com/health.php` shows **BCRF-OK**
   - the homepage loads normally
   - the exact thing you changed is visible / works
   - if admin was touched: one admin save works
   - if a form was touched: one real submission
   - cPanel → **Errors** (or ask Claude to read the error log): nothing new
6. **Record** on the Notion card's Upload & verify: date, lane, SHA, files, what you saw. Ask Claude to confirm the live sha256 matches the pack summary (works for every file the connector can read). Only then set Status to Done.

## Rollback (do it first, diagnose after)

- **Lane A:** tell Claude "roll back <file>". It restores the backup and confirms the old sha256.
- **Lane B:** File Manager → delete (or rename) the uploaded file → rename `filename.bak-YYYYMMDD` back to `filename`.
- **Database mistake:** `docs/RESTORE.md` section A (phpMyAdmin → Import the newest `bcrf-db-…sql.gz` from `/home2/ddailnmy/bcrf-backups/`).
- Then purge LiteSpeed → Cloudflare, check `health.php` = BCRF-OK and the old page is back on cellular. Write the rollback on the card.

## What you're seeing

| Symptom | Likely cause | Do |
|---|---|---|
| White page or 500 | PHP fatal / missing file | Roll back. The commit's Code check (PHP 8.5 lint) shows the line. |
| Change not visible, all else fine | Cache | Purge again, LiteSpeed then Cloudflare. Check `cf-cache-status` / `x-litespeed-cache` headers if needed. |
| `health.php` = 503 BCRF-DOWN | Database, not the deploy | Stop. cPanel → MySQL; ask Claude to read the error log. |
| `health.php?jobs=1` = BCRF-JOBS-LATE | Scheduler, not the deploy | admin → System health. |
| Admin bounces to login | Session/cookie/secrets mismatch, usually after `config.php` | Roll back `config.php`. |
| 403 on a page that worked | `.htaccess` rule or a Cloudflare rule | Roll back `.htaccess`; Cloudflare → Security → Events shows which rule. |

## Rules

- No live change without a backup (automatic in lane A, `.bak` in lane B).
- One pack, one purpose: two unrelated fixes can't be rolled back halfway.
- Live-only edits get wiped by the next pack: commit the identical file to `main` and match the sha256.
- Delete `.bak` files after a week once the change has held.
