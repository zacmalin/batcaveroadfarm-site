<?php
// includes/social_reports.php — Reports › Social Media numbers (Nov 11, 2026).
// Pulls Facebook Page + Instagram insights with FB_PAGE_TOKEN (includes/adapter_meta.php) and stores them in
// social_metrics_daily / social_posts (migration 0026), so admin/social_reports.php never waits on Meta.
// Filled by admin/cron/fetch_social_reports.php (daily) and the page's Refresh button.
// Token needs: pages_read_engagement, pages_read_user_content, read_insights, instagram_basic,
// instagram_manage_insights (docs/FACEBOOK_TOKEN.md). Pinterest isn't here yet (Trial access only).
//
// Meta's daily numbers end at midnight Pacific; a value with end_time 2026-11-06T08:00:00+0000 is Nov 5.

require_once __DIR__ . '/adapter_meta.php';

if (!function_exists('bcrf_socrep_refresh')) {

/**
 * Metrics on the page: network => metric => [label, how it adds up over days ('sum' | 'last'), definition].
 * Pinterest slots in as a third network here once Standard access is approved (no page changes needed).
 */
function bcrf_socrep_metrics(): array {
    return [
        'facebook' => [
            'page_media_view'           => ['Views', 'sum', 'Times the Page or its posts came on screen (Meta "views": counts repeat viewers).'],
            'page_post_engagements'     => ['Engagements', 'sum', 'Reactions, comments, shares and clicks on the Page\'s posts.'],
            'page_daily_follows_unique' => ['New follows', 'sum', 'People who started following the Page each day (unfollows not subtracted).'],
            'followers'                 => ['Followers', 'last', 'Page followers at the most recent refresh. History starts Nov 11, 2026.'],
        ],
        'instagram' => [
            'reach'              => ['Accounts reached (daily)', 'sum', 'Accounts that saw content each day, added up — someone seen on two days counts twice.'],
            'views'              => ['Views', 'sum', 'Times posts, reels and stories were played or shown (counts repeats).'],
            'total_interactions' => ['Interactions', 'sum', 'Likes, comments, saves, shares and replies.'],
            'follower_count'     => ['New followers (net)', 'sum', 'Follows minus unfollows each day. Instagram only reports the last 30 days.'],
            'followers'          => ['Followers', 'last', 'Account followers at the most recent refresh. History starts Nov 11, 2026.'],
        ],
    ];
}

function bcrf_socrep_tables_ready(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM social_metrics_daily LIMIT 1'); $pdo->query('SELECT 1 FROM social_posts LIMIT 1'); return true; }
    catch (Throwable $e) { return false; }
}

/**
 * GET with the Page token; returns the decoded JSON or throws with Meta's message.
 * Rate limits (HTTP 429, or Meta codes 4, 17, 32, 613, 80001) wait 5 s then 15 s and retry; after that the
 * error is recorded for that part of the refresh and the rest carries on. Posting (send_social) is unaffected.
 */
function bcrf_socrep_get(string $path, array $params = []): array {
    foreach ([0, 5, 15] as $wait) {
        if ($wait) sleep($wait);
        $r = bcrf_meta_request('GET', $path, $params + ['access_token' => FB_PAGE_TOKEN], 30);
        if ($r['code'] >= 200 && $r['code'] < 300 && empty($r['json']['error'])) return $r['json'];
        $limited = $r['code'] === 429 || in_array((int)($r['json']['error']['code'] ?? 0), [4, 17, 32, 613, 80001], true);
        if (!$limited) break;
    }
    throw new RuntimeException(bcrf_meta_error($r));
}

/** Follow a list edge's "after" cursor for up to $pages pages; returns all rows. */
function bcrf_socrep_get_all(string $path, array $params, int $pages = 4): array {
    $rows = [];
    for ($i = 0; $i < $pages; $i++) {
        $j = bcrf_socrep_get($path, $params);
        $rows = array_merge($rows, (array)($j['data'] ?? []));
        $after = $j['paging']['cursors']['after'] ?? null;
        if (!$after || empty($j['paging']['next'])) break;
        $params['after'] = $after;
    }
    return $rows;
}

/** Meta end_time → the day the number is for (Y-m-d). */
function bcrf_socrep_day(string $endTime): string {
    return gmdate('Y-m-d', strtotime($endTime) - 86400);
}

function bcrf_socrep_put(PDO $pdo, string $net, string $metric, string $day, $value): void {
    static $st = null;
    $st ??= $pdo->prepare('INSERT INTO social_metrics_daily (network, metric, day, value, fetched_at) VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE value = VALUES(value), fetched_at = VALUES(fetched_at)');
    $st->execute([$net, $metric, $day, (int)$value, gmdate('Y-m-d H:i:s')]);
}

/** Does social_posts have the clicks column yet (migration 0027)? */
function bcrf_socrep_has_clicks(PDO $pdo): bool {
    static $has = null;
    if ($has === null) {
        try { $pdo->query('SELECT clicks FROM social_posts LIMIT 1'); $has = true; } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

function bcrf_socrep_put_post(PDO $pdo, array $p): void {
    static $st = null;
    $c = bcrf_socrep_has_clicks($pdo);
    $st ??= $pdo->prepare('INSERT INTO social_posts (network, post_id, posted_at, kind, caption, permalink, likes, comments, shares, views, reach, saves'
                           . ($c ? ', clicks' : '') . ', fetched_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . ($c ? ', ?' : '') . ', ?)
                           ON DUPLICATE KEY UPDATE posted_at = VALUES(posted_at), kind = VALUES(kind), caption = VALUES(caption), permalink = VALUES(permalink),
                             likes = VALUES(likes), comments = VALUES(comments), shares = VALUES(shares), views = VALUES(views),
                             reach = VALUES(reach), saves = VALUES(saves)' . ($c ? ', clicks = VALUES(clicks)' : '') . ', fetched_at = VALUES(fetched_at)');
    $cap = trim(preg_replace('/\s+/', ' ', (string)($p['caption'] ?? '')));
    $args = [$p['network'], $p['post_id'], gmdate('Y-m-d H:i:s', strtotime($p['posted_at'])), $p['kind'] ?? null,
             $cap === '' ? null : mb_substr($cap, 0, 300), $p['permalink'] ?? null,
             $p['likes'] ?? null, $p['comments'] ?? null, $p['shares'] ?? null, $p['views'] ?? null,
             $p['reach'] ?? null, $p['saves'] ?? null];
    if ($c) $args[] = $p['clicks'] ?? null;
    $args[] = gmdate('Y-m-d H:i:s');
    $st->execute($args);
}

/** name => value from an insights edge ({data:[{name, values:[{value}]}]} or total_value). */
function bcrf_socrep_insight_values(array $ins): array {
    $out = [];
    foreach ((array)($ins['data'] ?? []) as $m) {
        $v = $m['total_value']['value'] ?? ($m['values'][0]['value'] ?? null);
        if (is_numeric($v)) $out[(string)$m['name']] = (int)$v;
    }
    return $out;
}

/**
 * Fetch everything and store it. Returns ['facebook' => 'ok'|error, 'instagram' => 'ok'|error, 'at' => ISO time].
 * Each part is independent: one failing doesn't stop the others. Also saved to google_report_cache['social'].
 */
function bcrf_socrep_refresh(PDO $pdo): array {
    $res = ['facebook' => 'ok', 'instagram' => 'ok', 'at' => date('c')];
    $errs = ['facebook' => [], 'instagram' => []];
    $today = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');   // Meta's day
    if (!bcrf_meta_ready()) return ['facebook' => 'FB_PAGE_ID / FB_PAGE_TOKEN not set', 'instagram' => 'not set up', 'at' => date('c')];
    $page = (string)FB_PAGE_ID;
    $try = function (string $net, callable $fn) use (&$errs) {
        try { $fn(); } catch (Throwable $e) { $errs[$net][] = $e->getMessage(); }
    };

    // ── Facebook ─────────────────────────────────────────────────────────────
    $try('facebook', function () use ($pdo, $page, $today) {
        $p = bcrf_socrep_get("/$page", ['fields' => 'followers_count']);
        if (isset($p['followers_count'])) bcrf_socrep_put($pdo, 'facebook', 'followers', $today, $p['followers_count']);
    });
    $try('facebook', function () use ($pdo, $page) {
        $j = bcrf_socrep_get("/$page/insights", ['metric' => 'page_media_view,page_post_engagements,page_daily_follows_unique',
                                                 'period' => 'day', 'date_preset' => 'last_28d']);
        foreach ((array)($j['data'] ?? []) as $m) {
            foreach ((array)($m['values'] ?? []) as $v) {
                if (isset($v['end_time']) && is_numeric($v['value'] ?? null)) bcrf_socrep_put($pdo, 'facebook', (string)$m['name'], bcrf_socrep_day($v['end_time']), $v['value']);
            }
        }
    });
    $try('facebook', function () use ($pdo, $page) {
        $base = 'id,created_time,message,permalink_url,status_type,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares';
        try { $list = bcrf_socrep_get_all("/$page/posts", ['fields' => $base . ',insights.metric(post_media_view,post_clicks)', 'limit' => 25]); }
        catch (Throwable $e) { $list = bcrf_socrep_get_all("/$page/posts", ['fields' => $base, 'limit' => 25]); }
        foreach ($list as $p) {
            $ins = bcrf_socrep_insight_values((array)($p['insights'] ?? []));
            bcrf_socrep_put_post($pdo, [
                'network' => 'facebook', 'post_id' => (string)$p['id'], 'posted_at' => (string)$p['created_time'],
                'kind' => $p['status_type'] ?? null, 'caption' => $p['message'] ?? null, 'permalink' => $p['permalink_url'] ?? null,
                'likes' => (int)($p['reactions']['summary']['total_count'] ?? 0), 'comments' => (int)($p['comments']['summary']['total_count'] ?? 0),
                'shares' => (int)($p['shares']['count'] ?? 0), 'views' => $ins['post_media_view'] ?? null, 'clicks' => $ins['post_clicks'] ?? null,
            ]);
        }
    });

    // ── Instagram ────────────────────────────────────────────────────────────
    $ig = bcrf_meta_ig_user();
    if (!$ig) { $errs['instagram'][] = 'IG_USER_ID not set'; }
    else {
        $try('instagram', function () use ($pdo, $ig, $today) {
            $a = bcrf_socrep_get("/$ig", ['fields' => 'followers_count']);
            if (isset($a['followers_count'])) bcrf_socrep_put($pdo, 'instagram', 'followers', $today, $a['followers_count']);
        });
        // Daily series (reach, net new followers): Instagram allows up to 30 days back.
        foreach (['reach', 'follower_count'] as $metric) {
            $try('instagram', function () use ($pdo, $ig, $metric) {
                $j = bcrf_socrep_get("/$ig/insights", ['metric' => $metric, 'period' => 'day',
                                                       'since' => time() - 28 * 86400, 'until' => time()]);
                foreach ((array)($j['data'] ?? []) as $m) foreach ((array)($m['values'] ?? []) as $v) {
                    if (isset($v['end_time']) && is_numeric($v['value'] ?? null)) bcrf_socrep_put($pdo, 'instagram', $metric, bcrf_socrep_day($v['end_time']), $v['value']);
                }
            });
        }
        // views / total_interactions only come as a total per range: ask one finished day at a time.
        // Fills the last 28 days the first time, then only days not stored yet (plus yesterday, which can still change).
        $try('instagram', function () use ($pdo, $ig, $today) {
            $have = $pdo->query("SELECT day FROM social_metrics_daily WHERE network = 'instagram' AND metric = 'views' AND day >= CURDATE() - INTERVAL 30 DAY")
                        ->fetchAll(PDO::FETCH_COLUMN);
            $tz = new DateTimeZone('America/Los_Angeles');
            $todayD = new DateTimeImmutable($today, $tz);
            for ($i = 28; $i >= 1; $i--) {
                $d = $todayD->modify("-$i day");
                $day = $d->format('Y-m-d');
                if ($i > 2 && in_array($day, $have, true)) continue;
                $j = bcrf_socrep_get("/$ig/insights", ['metric' => 'views,total_interactions', 'metric_type' => 'total_value', 'period' => 'day',
                                                       'since' => $d->getTimestamp(), 'until' => $d->modify('+1 day')->getTimestamp()]);
                foreach (bcrf_socrep_insight_values($j) as $name => $val) bcrf_socrep_put($pdo, 'instagram', $name, $day, $val);
            }
        });
        $try('instagram', function () use ($pdo, $ig) {
            $base = 'id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count';
            $withIns = true;
            try { $list = bcrf_socrep_get_all("/$ig/media", ['fields' => $base . ',insights.metric(reach,views,saved,shares)', 'limit' => 25]); }
            catch (Throwable $e) { $withIns = false; $list = bcrf_socrep_get_all("/$ig/media", ['fields' => $base, 'limit' => 25]); }
            foreach ($list as $m) {
                $ins = bcrf_socrep_insight_values((array)($m['insights'] ?? []));
                if (!$withIns) {   // one post type rejected a metric: ask post by post, skipping failures
                    try { $ins = bcrf_socrep_insight_values(bcrf_socrep_get('/' . $m['id'] . '/insights', ['metric' => 'reach,views,saved,shares'])); }
                    catch (Throwable $e) { $ins = []; }
                }
                bcrf_socrep_put_post($pdo, [
                    'network' => 'instagram', 'post_id' => (string)$m['id'], 'posted_at' => (string)$m['timestamp'],
                    'kind' => strtolower((string)($m['media_product_type'] ?? $m['media_type'] ?? '')) ?: null,
                    'caption' => $m['caption'] ?? null, 'permalink' => $m['permalink'] ?? null,
                    'likes' => isset($m['like_count']) ? (int)$m['like_count'] : null, 'comments' => (int)($m['comments_count'] ?? 0),
                    'shares' => $ins['shares'] ?? null, 'views' => $ins['views'] ?? null, 'reach' => $ins['reach'] ?? null, 'saves' => $ins['saved'] ?? null,
                ]);
            }
        });
    }

    foreach (['facebook', 'instagram'] as $n) if ($errs[$n]) $res[$n] = implode(' · ', array_unique($errs[$n]));
    try {
        $pdo->prepare("INSERT INTO google_report_cache (report_key, data, fetched_at, last_error, error_at) VALUES ('social', ?, NOW(), ?, ?)
                       ON DUPLICATE KEY UPDATE data = VALUES(data), fetched_at = VALUES(fetched_at), last_error = VALUES(last_error), error_at = VALUES(error_at)")
            ->execute([json_encode($res), ($res['facebook'] !== 'ok' || $res['instagram'] !== 'ok') ? mb_substr($res['facebook'] . ' | ' . $res['instagram'], 0, 500) : null,
                       ($res['facebook'] !== 'ok' || $res['instagram'] !== 'ok') ? date('Y-m-d H:i:s') : null]);
    } catch (Throwable $e) { /* status is a nicety */ }
    return $res;
}

/** Last refresh: ['at' => 'Y-m-d H:i:s'|null, 'facebook' => .., 'instagram' => ..]. */
function bcrf_socrep_status(PDO $pdo): array {
    try {
        $r = $pdo->query("SELECT data, fetched_at FROM google_report_cache WHERE report_key = 'social'")->fetch(PDO::FETCH_ASSOC);
        if ($r) return (json_decode((string)$r['data'], true) ?: []) + ['fetched_at' => $r['fetched_at']];
    } catch (Throwable $e) {}
    return [];
}

/**
 * Posts since $fromUtc, newest first, each with the Social Queue row that sent it (queue_id, content_type,
 * content_id; null when posted outside Farm OS). Matched on the provider id send_social stored.
 */
function bcrf_socrep_posts(PDO $pdo, string $fromUtc, int $limit = 100): array {
    $st = $pdo->prepare("SELECT p.*, q.id AS queue_id, q.content_type, q.content_id
                         FROM social_posts p
                         LEFT JOIN social_queue q ON (p.network = 'facebook'  AND q.fb_post_id  = p.post_id)
                                                  OR (p.network = 'instagram' AND q.ig_media_id = p.post_id)
                         WHERE p.posted_at >= ? ORDER BY p.posted_at DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute([$fromUtc]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['network'] . ':' . $r['post_id']] ??= $r;   // one row per post even if two queue rows match
    return array_values($out);
}

/** network => metric => [day => value] for days in [$from, $to]. */
function bcrf_socrep_series(PDO $pdo, string $from, string $to): array {
    $st = $pdo->prepare('SELECT network, metric, day, value FROM social_metrics_daily WHERE day BETWEEN ? AND ? ORDER BY day');
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['network']][$r['metric']][$r['day']] = (int)$r['value'];
    return $out;
}

}
