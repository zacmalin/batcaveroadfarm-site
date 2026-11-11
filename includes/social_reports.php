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

/** Metric labels for the page: network => metric => [label, how it adds up over days ('sum' | 'last')]. */
function bcrf_socrep_metrics(): array {
    return [
        'facebook' => [
            'page_media_view'           => ['Views', 'sum'],
            'page_post_engagements'     => ['Engagements', 'sum'],
            'page_daily_follows_unique' => ['New follows', 'sum'],
            'followers'                 => ['Followers', 'last'],
        ],
        'instagram' => [
            'reach'              => ['Accounts reached (daily)', 'sum'],
            'views'              => ['Views', 'sum'],
            'total_interactions' => ['Interactions', 'sum'],
            'follower_count'     => ['New followers (net)', 'sum'],
            'followers'          => ['Followers', 'last'],
        ],
    ];
}

function bcrf_socrep_tables_ready(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM social_metrics_daily LIMIT 1'); $pdo->query('SELECT 1 FROM social_posts LIMIT 1'); return true; }
    catch (Throwable $e) { return false; }
}

/** GET with the Page token; returns the decoded JSON or throws with Meta's message. */
function bcrf_socrep_get(string $path, array $params = []): array {
    $r = bcrf_meta_request('GET', $path, $params + ['access_token' => FB_PAGE_TOKEN], 30);
    if ($r['code'] < 200 || $r['code'] >= 300 || !empty($r['json']['error'])) throw new RuntimeException(bcrf_meta_error($r));
    return $r['json'];
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

function bcrf_socrep_put_post(PDO $pdo, array $p): void {
    static $st = null;
    $st ??= $pdo->prepare('INSERT INTO social_posts (network, post_id, posted_at, kind, caption, permalink, likes, comments, shares, views, reach, saves, fetched_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE posted_at = VALUES(posted_at), kind = VALUES(kind), caption = VALUES(caption), permalink = VALUES(permalink),
                             likes = VALUES(likes), comments = VALUES(comments), shares = VALUES(shares), views = VALUES(views),
                             reach = VALUES(reach), saves = VALUES(saves), fetched_at = VALUES(fetched_at)');
    $cap = trim(preg_replace('/\s+/', ' ', (string)($p['caption'] ?? '')));
    $st->execute([$p['network'], $p['post_id'], gmdate('Y-m-d H:i:s', strtotime($p['posted_at'])), $p['kind'] ?? null,
                  $cap === '' ? null : mb_substr($cap, 0, 300), $p['permalink'] ?? null,
                  $p['likes'] ?? null, $p['comments'] ?? null, $p['shares'] ?? null, $p['views'] ?? null,
                  $p['reach'] ?? null, $p['saves'] ?? null, gmdate('Y-m-d H:i:s')]);
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
        try { $j = bcrf_socrep_get("/$page/posts", ['fields' => $base . ',insights.metric(post_media_view)', 'limit' => 25]); }
        catch (Throwable $e) { $j = bcrf_socrep_get("/$page/posts", ['fields' => $base, 'limit' => 25]); }
        foreach ((array)($j['data'] ?? []) as $p) {
            $ins = bcrf_socrep_insight_values((array)($p['insights'] ?? []));
            bcrf_socrep_put_post($pdo, [
                'network' => 'facebook', 'post_id' => (string)$p['id'], 'posted_at' => (string)$p['created_time'],
                'kind' => $p['status_type'] ?? null, 'caption' => $p['message'] ?? null, 'permalink' => $p['permalink_url'] ?? null,
                'likes' => (int)($p['reactions']['summary']['total_count'] ?? 0), 'comments' => (int)($p['comments']['summary']['total_count'] ?? 0),
                'shares' => (int)($p['shares']['count'] ?? 0), 'views' => $ins['post_media_view'] ?? null,
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
            try { $j = bcrf_socrep_get("/$ig/media", ['fields' => $base . ',insights.metric(reach,views,saved,shares)', 'limit' => 25]); }
            catch (Throwable $e) { $withIns = false; $j = bcrf_socrep_get("/$ig/media", ['fields' => $base, 'limit' => 25]); }
            foreach ((array)($j['data'] ?? []) as $m) {
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

/** network => metric => [day => value] for days in [$from, $to]. */
function bcrf_socrep_series(PDO $pdo, string $from, string $to): array {
    $st = $pdo->prepare('SELECT network, metric, day, value FROM social_metrics_daily WHERE day BETWEEN ? AND ? ORDER BY day');
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['network']][$r['metric']][$r['day']] = (int)$r['value'];
    return $out;
}

}
