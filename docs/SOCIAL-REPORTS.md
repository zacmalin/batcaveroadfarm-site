# Reports › Social Media

<!-- docs/SOCIAL-REPORTS.md — Nov 11, 2026 -->

Facebook (Petting Zoo page) and Instagram (@batcaveroadfarm) numbers in one admin page: `admin/social_reports.php`.

## Where the numbers come from

| Piece | File |
| --- | --- |
| Fetch + store | `includes/social_reports.php` (`bcrf_socrep_refresh`) |
| Daily job | `admin/cron/fetch_social_reports.php`, 6:20 AM (runner task + System Health "Social Media refresh") |
| Tables | `social_metrics_daily`, `social_posts` (migrations 0026, 0027) |
| Last refresh / errors | `google_report_cache` row `social` |
| Token + permissions | `FB_PAGE_TOKEN` in bcrf-secrets.php; how to remake it: `docs/FACEBOOK_TOKEN.md` |

The page never calls Meta while you look at it, except **Refresh now** and the **Meta access check**.

## Sync rules

- Daily numbers: the last 28 days are re-read every run, so late corrections from Meta land. Instagram views and
  interactions are asked one day at a time; days already stored are skipped except the last two.
- Posts: the newest 100 per network (4 pages of 25) are re-read every run; older posts keep their last numbers.
- Every write is an upsert on (network, metric, day) or (network, post_id): repeating a sync never duplicates.
- Rate limits (HTTP 429 or Meta codes 4, 17, 32, 613, 80001): wait 5 s, then 15 s, then give up on that part.
  Facebook and Instagram parts fail independently; the error shows under "Last refresh" and the job is marked
  failing in System Health only when both fail. Posting (`send_social.php`) shares nothing with this job.
- An expired token shows as an error here and in the daily token check (`check_social_token.php`).

## Retention and backfill

- Backfill: Meta allows about 28–30 days back, so history starts on the first refresh (Nov 11, 2026).
  Follower totals only exist from that day on.
- Retention: everything is kept. Both tables grow by a few dozen rows a day (under 20,000 rows a year);
  revisit if that changes. The nightly database backup covers them.

## Social Queue link

`social_posts` rows are matched to `social_queue` by `fb_post_id` / `ig_media_id` (what send_social saved).
Matched posts show a "Farm OS" badge and the CSV's `source` column names the queue row.

## Not covered yet

- Pinterest: add a `pinterest` block to `bcrf_socrep_metrics()` and a fetch section once Standard access is approved.
- Video watch time, and a second Facebook page or Instagram account (one of each today).
