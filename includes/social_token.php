<?php // includes/social_token.php
/*
 * Facebook Page token health (Nov 2026).
 *
 * Asks Facebook's debug_token endpoint whether FB_PAGE_TOKEN still works and when it — and the
 * app's "data access" for it — expire, saves the answer to /home2/<user>/bcrf-social-token.json,
 * and decides when to email a warning. Used by:
 *   admin/cron/check_social_token.php  (daily, emails)
 *   admin/social_token_check.php       ("Check now" link in the admin)
 *   admin/views/layout/social_health_banner.php (Dashboard + Social Queue banner)
 * How to make a new token: docs/FACEBOOK_TOKEN.md.
 */

function bcrf_social_token_file(): string {
    if (($f = getenv('BCRF_SOCIAL_TOKEN_FILE')) !== false && $f !== '') return $f;
    $docroot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    return dirname($docroot, 2) . '/bcrf-social-token.json';
}
function bcrf_social_token_status(): array {
    $f = bcrf_social_token_file();
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) ? $j : [];
}
function bcrf_social_token_save(array $s): void {
    $f = bcrf_social_token_file();
    @file_put_contents($f, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($f, 0600);
}

/** GET a Graph URL and return [httpCode, decodedJson]. */
function bcrf_social_graph_get(string $url): array {
    // Meta adapter (Farm OS Phase 0): includes/adapter_meta.php
    require_once __DIR__ . '/adapter_meta.php';
    $r = bcrf_meta_request('GET', $url, [], 20);
    if ($r['code'] === 0) throw new RuntimeException('could not reach Facebook: ' . bcrf_meta_error($r));
    return [$r['code'], $r['json']];
}

/**
 * Check the token now and save the result. Returns the saved status:
 *   ok (bool), checked_at, expires_at (0 = never), data_access_expires_at, error, page_id, page_name
 * Network trouble is recorded as 'unknown' (not as a broken token), so a Facebook outage doesn't alarm you.
 */
function bcrf_social_token_check(): array {
    $prev = bcrf_social_token_status();
    $s = ['checked_at' => date('c'), 'alerts' => $prev['alerts'] ?? []];
    require_once __DIR__ . '/adapter_meta.php';
    $graph = bcrf_meta_graph_base();

    if (!defined('FB_PAGE_TOKEN') || FB_PAGE_TOKEN === '' || !defined('FB_PAGE_ID') || FB_PAGE_ID === '') {
        $s += ['state' => 'missing', 'ok' => false, 'error' => 'FB_PAGE_ID / FB_PAGE_TOKEN are not set in bcrf-secrets.php'];
        bcrf_social_token_save($s); return $s;
    }
    try {
        // 1. Does the token work for the Page? (needs nothing but the token itself)
        [$c1, $me] = bcrf_social_graph_get($graph . '/me?fields=id,name&access_token=' . rawurlencode(FB_PAGE_TOKEN));
        if ($c1 >= 500) throw new RuntimeException('Facebook returned HTTP ' . $c1);
        if (!empty($me['error'])) {
            $s += ['state' => 'invalid', 'ok' => false,
                   'error' => ($me['error']['message'] ?? 'token rejected') . (isset($me['error']['code']) ? ' (code ' . $me['error']['code'] . ')' : '')];
            bcrf_social_token_save($s); return $s;
        }
        $s['page_id'] = (string)($me['id'] ?? ''); $s['page_name'] = (string)($me['name'] ?? '');
        if ($s['page_id'] !== (string)FB_PAGE_ID) {
            $s += ['state' => 'invalid', 'ok' => false,
                   'error' => "token belongs to '{$s['page_name']}' ({$s['page_id']}), not FB_PAGE_ID " . FB_PAGE_ID . ' — use the Petting Zoo page token'];
            bcrf_social_token_save($s); return $s;
        }
        // 2. Expiry dates (needs the app id + secret)
        $s['expires_at'] = null; $s['data_access_expires_at'] = null;
        if (defined('META_APP_ID') && META_APP_ID !== '' && defined('META_APP_SECRET') && META_APP_SECRET !== '') {
            [, $dbg] = bcrf_social_graph_get($graph . '/debug_token?input_token=' . rawurlencode(FB_PAGE_TOKEN)
                . '&access_token=' . rawurlencode(META_APP_ID . '|' . META_APP_SECRET));
            $d = $dbg['data'] ?? [];
            if (isset($d['is_valid']) && !$d['is_valid']) {
                $s += ['state' => 'invalid', 'ok' => false, 'error' => $d['error']['message'] ?? 'Facebook says the token is no longer valid'];
                bcrf_social_token_save($s); return $s;
            }
            $s['expires_at']             = isset($d['expires_at']) ? (int)$d['expires_at'] : null;              // 0 = never
            $s['data_access_expires_at'] = isset($d['data_access_expires_at']) ? (int)$d['data_access_expires_at'] : null;
            $s['scopes']                 = $d['scopes'] ?? [];
        }
        $s += ['state' => 'ok', 'ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        // Couldn't ask Facebook — keep the last known dates, don't call the token broken.
        $s += ['state' => 'unknown', 'ok' => $prev['ok'] ?? null, 'error' => $e->getMessage(),
               'expires_at' => $prev['expires_at'] ?? null, 'data_access_expires_at' => $prev['data_access_expires_at'] ?? null];
    }
    bcrf_social_token_save($s);
    return $s;
}

/** Soonest real expiry (token or data access) as [timestamp, label], or [null, ''] if none. */
function bcrf_social_token_next_expiry(array $s): array {
    $c = [];
    if (!empty($s['expires_at']))             $c[(int)$s['expires_at']] = 'Page token';
    if (!empty($s['data_access_expires_at'])) $c[(int)$s['data_access_expires_at']] = 'Facebook data access';
    if (!$c) return [null, ''];
    ksort($c);
    return [array_key_first($c), reset($c)];
}

/**
 * Should today's check email you? Returns [subject, message] or null.
 * Broken token: once a day until fixed. Expiring: at 14, 7, 3, 2, 1 and 0 days left.
 */
function bcrf_social_token_alert(array &$s): ?array {
    $today = date('Y-m-d');
    $sentToday = fn(string $k) => ($s['alerts'][$k] ?? '') === $today;
    if (in_array($s['state'] ?? '', ['invalid', 'missing'], true)) {
        if ($sentToday('broken')) return null;
        $s['alerts']['broken'] = $today;
        return ['Facebook posting is BROKEN', 'The Facebook Page token no longer works, so new recipes/blog posts will not be shared. '
            . 'Facebook said: ' . ($s['error'] ?? '') . '. Make a new token (docs/FACEBOOK_TOKEN.md) and paste it into bcrf-secrets.php as FB_PAGE_TOKEN.'];
    }
    unset($s['alerts']['broken']);
    [$ts, $label] = bcrf_social_token_next_expiry($s);
    if ($ts === null) return null;
    $days = (int)floor(($ts - time()) / 86400);
    // Already expired (days < 0): remind daily. Otherwise only on the countdown days.
    if ($days > 14 || ($days >= 0 && !in_array($days, [14, 7, 3, 2, 1, 0], true))) return null;
    if ($sentToday('expiring')) return null;
    $s['alerts']['expiring'] = $today;
    $when = date('l, M j', $ts);
    if ($days < 0) {
        return ['Facebook token has EXPIRED', "{$label} for the Facebook Page ran out on {$when}, so recipe/blog sharing to Facebook and Instagram has stopped. "
            . 'Make a new token (docs/FACEBOOK_TOKEN.md), paste it into bcrf-secrets.php as FB_PAGE_TOKEN, then tap "Check now" in the admin.'];
    }
    return ["Facebook token expires " . ($days === 0 ? 'TODAY' : "in {$days} day" . ($days === 1 ? '' : 's')),
        "{$label} for the Facebook Page runs out on {$when}. After that, recipe/blog sharing to Facebook and Instagram stops. "
        . 'Make a new token before then (docs/FACEBOOK_TOKEN.md) and paste it into bcrf-secrets.php as FB_PAGE_TOKEN, then tap "Check now" in the admin.'];
}

/** Short summary for the admin banner: [level ('ok'|'warn'|'bad'|'none'), message]. */
function bcrf_social_token_summary(array $s): array {
    if (!$s) return ['none', 'Facebook token not checked yet — tap Check now.'];
    $state = $s['state'] ?? '';
    if ($state === 'invalid' || $state === 'missing') return ['bad', 'Facebook posting is broken: ' . ($s['error'] ?? '')];
    [$ts, $label] = bcrf_social_token_next_expiry($s);
    $age = time() - (int)strtotime((string)($s['checked_at'] ?? 'now'));
    $stale = $age > 3 * 86400 ? ' (last checked ' . floor($age / 86400) . ' days ago — is the daily check cron running?)' : '';
    if ($ts !== null) {
        $days = (int)floor(($ts - time()) / 86400);
        if ($days < 0)   return ['bad', "{$label} expired on " . date('M j', $ts) . '. Make a new token — sharing to Facebook/Instagram has stopped.' . $stale];
        if ($days <= 14) return [$days <= 3 ? 'bad' : 'warn', "{$label} expires " . date('M j', $ts) . " ({$days} day" . ($days === 1 ? '' : 's') . ' left). Make a new token soon.' . $stale];
        return [$stale ? 'warn' : 'ok', "Facebook token OK. {$label} renews by " . date('M j, Y', $ts) . '.' . $stale];
    }
    if ($state === 'unknown') return ['warn', 'Could not reach Facebook on the last check: ' . ($s['error'] ?? '')];
    return [$stale ? 'warn' : 'ok', 'Facebook token OK (no expiry date).' . $stale];
}
