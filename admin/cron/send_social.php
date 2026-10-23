<?php
/**
 * admin/cron/send_social.php
 * Drains social_queue: posts the admin-approved caption to the Facebook Page and,
 * if configured, to Instagram (container -> poll -> publish). Each row is attempted
 * once and lands on a terminal status (sent / partial / failed) so a re-run never
 * double-posts. Safe to run every few minutes.
 *
 * Requires in bcrf-secrets.php (above docroot, loaded by config.php):
 *   META_APP_ID, META_APP_SECRET, FB_PAGE_ID, FB_PAGE_TOKEN
 *   IG_USER_ID            (optional — leave undefined/'' to post Facebook only)
 *   BCRF_GRAPH_VERSION    (optional — defaults to v23.0)
 *
 * Cron (ea-php85):
 *   /opt/cpanel/ea-php85/root/usr/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/send_social.php
 * Or via URL with the key:
 *   /admin/cron/send_social.php?key=<BCRF_CRON_KEY>  (only if CRON_KEY is set in bcrf-secrets.php)
 */

ob_start(); // swallow stray pre-output (BOM/whitespace) so header() works on the web trigger

$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "send_social: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;

if (ob_get_level() > 0) { ob_end_clean(); }

// ── Access guard: CLI always allowed; web requires the secret key ──
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = $_SERVER['HTTP_X_CRON_KEY'] ?? ($_GET['key'] ?? '');   // header preferred: keeps the key out of server logs
    if (!defined('BCRF_CRON_KEY') || strlen(BCRF_CRON_KEY) < 24 || !hash_equals(BCRF_CRON_KEY, (string)$key)) {
        http_response_code(403); exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

date_default_timezone_set('America/New_York');

function social_log($m) { echo $m . "\n"; }

// ── Config / credentials ──
if (!defined('FB_PAGE_ID') || !defined('FB_PAGE_TOKEN') || FB_PAGE_ID === '' || FB_PAGE_TOKEN === '') {
    social_log('send_social: Facebook credentials not configured (FB_PAGE_ID / FB_PAGE_TOKEN). Nothing sent.');
    exit(0);
}
$GRAPH   = 'https://graph.facebook.com/' . (defined('BCRF_GRAPH_VERSION') ? BCRF_GRAPH_VERSION : 'v23.0');
$IG_ID   = (defined('IG_USER_ID') && IG_USER_ID !== '') ? IG_USER_ID : null;
$BASEURL = defined('BCRF_SOCIAL_BASE_URL') ? BCRF_SOCIAL_BASE_URL : 'https://batcaveroadfarm.com';

// ── HTTP helpers (cURL first, file_get_contents fallback for Bluehost) ──
function social_http(string $method, string $url, array $fields = []): array {
    $body = '';
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === '' && curl_errno($ch)) { $body = json_encode(['error' => ['message' => curl_error($ch)]]); }
        curl_close($ch);
    } else {
        $ctx = ['http' => [
            'method'  => $method,
            'timeout' => 45,
            'ignore_errors' => true,
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        ]];
        if ($method === 'POST') { $ctx['http']['content'] = http_build_query($fields); }
        $body = (string)@file_get_contents($url, false, stream_context_create($ctx));
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) { $code = (int)$m[1]; }
    }
    $json = json_decode($body, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : [], 'raw' => $body];
}
function social_err(array $r): string {
    if (!empty($r['json']['error']['message'])) return $r['json']['error']['message'];
    return 'HTTP ' . $r['code'] . ' ' . substr($r['raw'], 0, 200);
}

// ── Resolve a record's public image URL + permalink (mirrors recipe_detail logic) ──
function social_record(PDO $pdo, string $type, int $id): ?array {
    $table = $type === 'recipe' ? 'recipes' : ($type === 'animal' ? 'animals' : 'blog_posts');
    try {
        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    return $row ?: null;
}
function social_image_url(array $row, string $base): ?string {
    foreach (['feature_image', 'image_url', 'featured_image', 'image', 'photo_url'] as $k) {
        if (!empty($row[$k])) {
            $u = (string)$row[$k];
            if (!preg_match('#^https?://#', $u)) { $u = $base . ($u[0] === '/' ? '' : '/') . $u; }
            return $u;
        }
    }
    return null;
}
function social_permalink(string $type, array $row, string $base): string {
    if ($type === 'animal') {
        $id   = (int)($row['id'] ?? 0);
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)($row['name'] ?? '')), '-')) ?: 'animal';
        return $base . '/petting-zoo/animals/' . $id . '/' . $slug . '/';
    }
    $slug = (string)($row['slug'] ?? '');
    if ($slug === '') return $base . '/';
    return $base . ($type === 'recipe' ? '/recipes/' : '/blog/') . rawurlencode($slug) . '/';
}

// ── Drain the queue ──
try {
    $pending = $pdo->query("SELECT * FROM social_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 10")
                   ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    social_log('send_social: social_queue table missing — run sql/social_module.sql.');
    exit(0);
}
if (!$pending) { social_log('send_social: nothing pending.'); exit(0); }

$upd = $pdo->prepare(
    "UPDATE social_queue
        SET status = ?, fb_post_id = ?, ig_media_id = ?, last_error = ?, attempts = attempts + 1, processed_at = NOW()
      WHERE id = ?"
);

foreach ($pending as $q) {
    $type = in_array($q['content_type'], ['recipe', 'animal'], true) ? $q['content_type'] : 'blog';
    $row  = social_record($pdo, $type, (int)$q['content_id']);
    if (!$row) {
        $upd->execute(['failed', null, null, 'Source record no longer exists', $q['id']]);
        social_log("#{$q['id']}: FAILED — source {$type} #{$q['content_id']} missing");
        continue;
    }

    $img       = social_image_url($row, $BASEURL);
    $permalink = social_permalink($type, $row, $BASEURL);
    $fbCaption = (string)$q['fb_caption'];
    $igCaption = trim((string)$q['ig_caption'] . (trim((string)$q['ig_hashtags']) !== '' ? "\n\n" . $q['ig_hashtags'] : ''));

    $fbId = null; $igId = null; $errors = [];

    // ── FACEBOOK ──
    if ($img) {
        $r = social_http('POST', "{$GRAPH}/" . FB_PAGE_ID . "/photos", [
            'url' => $img, 'caption' => $fbCaption, 'access_token' => FB_PAGE_TOKEN,
        ]);
        $fbId = $r['json']['post_id'] ?? $r['json']['id'] ?? null;
    } else {
        $r = social_http('POST', "{$GRAPH}/" . FB_PAGE_ID . "/feed", [
            'message' => $fbCaption, 'link' => $permalink, 'access_token' => FB_PAGE_TOKEN,
        ]);
        $fbId = $r['json']['id'] ?? null;
    }
    if (!$fbId) { $errors[] = 'FB: ' . social_err($r); }

    // ── INSTAGRAM (only if configured and we have a public image) ──
    if ($IG_ID && $img) {
        $c = social_http('POST', "{$GRAPH}/{$IG_ID}/media", [
            'image_url' => $img, 'caption' => $igCaption, 'access_token' => FB_PAGE_TOKEN,
        ]);
        $containerId = $c['json']['id'] ?? null;
        if (!$containerId) {
            $errors[] = 'IG container: ' . social_err($c);
        } else {
            // poll for FINISHED (image containers are usually instant; allow a few tries)
            $ready = false;
            for ($i = 0; $i < 5; $i++) {
                $s = social_http('GET', "{$GRAPH}/{$containerId}?fields=status_code&access_token=" . rawurlencode(FB_PAGE_TOKEN));
                $sc = $s['json']['status_code'] ?? '';
                if ($sc === 'FINISHED') { $ready = true; break; }
                if ($sc === 'ERROR' || $sc === 'EXPIRED') { $errors[] = "IG container status: {$sc}"; break; }
                sleep(2);
            }
            if ($ready) {
                $p = social_http('POST', "{$GRAPH}/{$IG_ID}/media_publish", [
                    'creation_id' => $containerId, 'access_token' => FB_PAGE_TOKEN,
                ]);
                $igId = $p['json']['id'] ?? null;
                if (!$igId) { $errors[] = 'IG publish: ' . social_err($p); }
            } elseif (empty($errors)) {
                $errors[] = 'IG container never reached FINISHED';
            }
        }
    } elseif ($IG_ID && !$img) {
        $errors[] = 'IG skipped: no public image on this post';
    }

    // ── Final status (terminal — never re-posts) ──
    if ($fbId && ($igId || !$IG_ID)) {
        $status = 'sent';
    } elseif ($fbId) {
        $status = 'partial';   // FB went out, IG didn't
    } else {
        $status = 'failed';
    }
    $upd->execute([$status, $fbId, $igId, ($errors ? implode(' | ', $errors) : null), $q['id']]);
    social_log("#{$q['id']}: {$status}" . ($fbId ? " fb={$fbId}" : '') . ($igId ? " ig={$igId}" : '') . ($errors ? ' — ' . implode(' | ', $errors) : ''));
}

social_log('send_social: done.');
