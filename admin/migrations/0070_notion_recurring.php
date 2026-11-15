<?php
// admin/migrations/0070_notion_recurring.php — Moves the Notion "Recurring & renewals" list into Farm OS (Nov 15, 2026).
//   renewals          domain, hosting, SSL (checks it renewed by itself), DMCA agent, Facebook page token. Added only when
//                     a renewal with the same name isn't already there (so it's safe if Zac added one by hand first).
//   task_recurrences  the repeating checks as chores for Owners at 9 AM: backup restore test, accessibility rescan, SSL check,
//                     listing checks, Yelp, DMARC, Google post, dashboard refresh. seed_key keeps them from being added twice.
// Needs 0069 (renewals) and the quarterly/yearly chore repeats. Adds only; edit or delete any of them in Farm OS afterwards.
// Stays in Notion: design-doc updates at each phase exit, and holiday hours.
return function (PDO $pdo): void {
    $ren = $pdo->prepare("INSERT INTO renewals (title, category, renews_on, repeat_months, remind_days, auto_renews, provider, account_ref,
                              link_url, assignee_role, notes, created_at)
                          SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, 'administrator', ?, UTC_TIMESTAMP() FROM DUAL
                          WHERE NOT EXISTS (SELECT 1 FROM renewals WHERE title = ? AND deleted_at IS NULL)");
    $renewals = [
        ['Domain: batcaveroadfarm.com', 'website', '2027-04-29', 12, 60, 0, 'Bluehost', null, 'https://my.bluehost.com',
         'Registrar is Bluehost. Check the card on file and the contact email too.'],
        ['Hosting: Bluehost', 'website', '2027-04-29', 12, 30, 0, 'Bluehost', 'cPanel ddailnmy', 'https://my.bluehost.com',
         'Check the card on file and the contact email; they matter more than the date.'],
        ['SSL certificate (Bluehost AutoSSL)', 'website', '2027-04-29', 12, 30, 1, 'Bluehost', null, null,
         "Covers batcaveroadfarm.com, admin. and api. It renews by itself; check that it did (padlock and expiry date)."],
        ['DMCA designated agent', 'license', '2029-10-20', 36, 60, 0, 'U.S. Copyright Office', null, 'https://dmca.copyright.gov',
         'Renew the designated agent registration every 3 years.'],
        ['Facebook page token', 'account', '2027-01-24', 2, 14, 0, 'Meta', null, null,
         'Refresh about every 60 days. Steps: docs/FACEBOOK_TOKEN.md; admin/cron/check_social_token.php also warns.'],
    ];
    foreach ($renewals as $r) {
        $ren->execute([...$r, $r[0]]);
    }

    $rec = $pdo->prepare("INSERT IGNORE INTO task_recurrences (seed_key, title, description, checklist_json, priority, assignee_role, freq, interval_n,
                              weekdays, month_day, open_days_only, due_anchor, due_time, start_date, end_date, horizon_days, active, created_at)
                          VALUES (?, ?, ?, '[]', 'normal', 'administrator', ?, 1, ?, ?, 0, 'time', ?, ?, ?, 7, 1, UTC_TIMESTAMP())");
    // [seed_key, title, description, freq, weekdays, month_day, due_time, start_date, end_date]
    $chores = [
        ['notion_backup_restore', 'Backup restore test', 'Restore last night\'s backup to a test spot and check it opens. Steps: docs/RESTORE.md.',
         'quarterly', null, 8, '09:00:00', '2027-02-08', null],
        ['notion_a11y_rescan', 'Accessibility rescan (AudioEye)', 'Run the AudioEye rescan and fix anything new.',
         'quarterly', null, 1, '09:00:00', '2027-02-01', null],
        ['notion_ssl_check', 'SSL certificate check', "Check the padlock and expiry date on batcaveroadfarm.com, admin. and api. An issue date older than 90 days means AutoSSL has stopped renewing (usually the domain stopped pointing at the hosting).",
         'monthly', null, 1, '09:00:00', '2026-12-01', null],
        ['notion_listing_check', 'Listing check: Apple Maps, Bing, Yelp, OpenStreetMap, Nextdoor', 'Name, address, phone, hours, pin and photos match Google. Confirm no holiday hours are left attached.',
         'monthly', null, 1, '09:00:00', '2026-12-01', null],
        ['notion_yelp_photos', 'Yelp: new photos and refresh the business info', 'Add 3 to 5 real, current photos; refresh description, hours, attributes and services (Yelp for Business).',
         'monthly', null, 1, '09:30:00', '2026-12-01', null],
        ['notion_yelp_access', 'Yelp: check page access and ad spend', 'Yelp for Business > Settings / Users and Ads: who has access, and ad spend against leads.',
         'quarterly', null, 1, '09:30:00', '2027-02-01', null],
        ['notion_dmarc_weekly', 'Check DMARC reports (weekly until reject)', 'Cloudflare DMARC Management: Bluehost and Google 100% pass, no real service failing. Also check the day after adding any new email-sending service.',
         'weekly', '1', null, '09:30:00', '2026-11-23', '2026-12-07'],
        ['notion_dmarc_monthly', 'Check DMARC reports', 'Cloudflare DMARC Management: Bluehost and Google 100% pass, no real service failing. Also check the day after adding any new email-sending service.',
         'monthly', null, 1, '09:30:00', '2027-01-01', null],
        ['notion_gbp_post', 'Google Business Profile post', 'Post this week\'s update (Admin > Social Queue; GBP is connected).',
         'weekly', '1', null, '09:00:00', '2026-11-16', null],
        ['notion_yelp_reviews', 'Reply to new Yelp reviews', 'Yelp for Business > Reviews: a public reply to every new review; anything personal goes to a direct message.',
         'weekly', '1', null, '09:00:00', '2026-11-16', null],
        ['notion_dashboard_refresh', 'Refresh the BCRF dashboard in Notion', 'Recount the status, due and completion numbers at the top of the Notion checklist page.',
         'weekly', '1', null, '09:15:00', '2026-11-16', null],
    ];
    foreach ($chores as $c) {
        $rec->execute($c);
    }
};
