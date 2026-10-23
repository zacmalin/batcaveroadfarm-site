# Fix Pack N: Uptime Alerts (2026-10-23)

2 files for the server, plus a 5-minute UptimeRobot setup (free, no card).

## What's in the pack

| File | Change |
|---|---|
| `health.php` (new) | Health check for the monitor. Shows `BCRF-OK` (status 200) when PHP, the secrets file, and the database all work. Shows status 503 if the database is unreachable or can't be read. No details are shown publicly, it's never cached or indexed, and checks don't create session files. |
| `config.php` | If the database is down, pages now answer **503 "temporarily unavailable"** instead of 200 OK. Before, the error message went out as a normal page, so a monitor would say "up" and Google could index the error text. Nothing else changed. |

## Step 1: Install (2 minutes)

1. Optional safety copy: in File Manager, download `config.php` (or copy it to `config.php.bak`).
2. Upload `fix-pack-N-uptime-2-files-2026-10-23.zip` to `public_html/batcaveroadfarm/`, choose Extract, and allow it to overwrite. Then delete the zip.
3. On your phone, open https://batcaveroadfarm.com/health.php. It should show only `BCRF-OK`. Then open the homepage to make sure it still loads.

## Step 2: UptimeRobot (5 minutes)

1. Go to https://uptimerobot.com, choose Register for FREE, and sign up with zacjmalin@gmail.com (or whichever inbox you check most). Confirm the email.
2. Add New Monitor, using these settings:
   - Monitor type: **Keyword**
   - Friendly name: `BCRF homepage`
   - URL: `https://batcaveroadfarm.com/`
   - Keyword: `Bat Cave Road Farm`, with "Alert when: keyword **not** exists"
   - Interval: 5 minutes (the free plan's interval)
   - Alert: tick your email
3. Add a second monitor:
   - Monitor type: **Keyword**
   - Friendly name: `BCRF database`
   - URL: `https://batcaveroadfarm.com/health.php`
   - Keyword: `BCRF-OK`, with "Alert when: keyword **not** exists"
   - Interval: 5 minutes; Alert: your email
4. Optional: install the UptimeRobot app on your phone and log in, so alerts also arrive as push notifications.

Keyword monitors catch more than plain "is it up" checks. If the server answers but shows an error page, the keyword is missing and you still get an alert.

## Step 3: Check it's working (after 10 minutes)

Both monitors should be green ("Up").

If either shows **Down** with "403 Forbidden" or "challenge" right after setup, Cloudflare is blocking the monitor:
1. In Cloudflare, go to Security, then Events, and find a recent blocked request from UptimeRobot.
2. Look at the **Service** column:
   - **Bot Fight Mode**: on the free Cloudflare plan this can't be given an exception. Turn Bot Fight Mode off (Security > Settings > Bot traffic). UptimeRobot is a Cloudflare-verified bot, so this usually isn't needed.
   - **Custom rules**: edit that rule and add `and not cf.client.bot` to its expression, so verified bots (UptimeRobot, Googlebot) are skipped.

## What alerts look like

- **"Monitor is DOWN: BCRF homepage"**: visitors can't load the site. Check Bluehost and Cloudflare status, and cPanel.
- **"BCRF database" down, homepage up**: rare (the homepage needs the database too), but it means MySQL is struggling.
- Both down at the same time: usually Bluehost itself. Their status page or support is the next stop.
- You also get an "UP" email when it recovers.

## Undo

Restore your saved `config.php`, and delete `health.php` and the monitors.

## Tested (PHP 8.5 + MariaDB 11.8)

- Healthy: `health.php` shows 200 `BCRF-OK`, with no-cache and noindex headers and no session cookie or file.
- Database stopped: `health.php`, the homepage and /booking/ all return 503 with Retry-After: 300.
- Table unreadable: `health.php` returns 503 `BCRF-DOWN`.
- Database restarted: back to 200 `BCRF-OK` straight away.
