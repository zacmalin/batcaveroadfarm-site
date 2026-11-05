<?php
/**
 * admin/cron/send_social.php
 * Drains social_queue: posts the admin-approved caption to the Facebook Page and,
 * if configured, to Instagram (container -> poll -> publish) and Google Business Profile
 * (rows whose platforms include "google"; connection at admin/gbp_connect.php, code in includes/gbp.php). Each row is attempted
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
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('send_social');   // heartbeat for the admin System Health page

// ── Access guard: CLI always allowed; web requires the secret key ──
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = $_SERVER['HTTP_X_CRON_KEY'] ?? ($_GET['key'] ?? '');   // header preferred: keeps the key out of server logs
    if (!defined('BCRF_CRON_KEY') || strlen(BCRF_CRON_KEY) < 24 || !hash_equals(BCRF_CRON_KEY, (string)$key)) {
        http_response_code(403); exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

function social_log($m) { echo $m . "\n"; }

// ── Config / credentials ──
require_once dirname($bcrf_cfg) . '/includes/gbp.php';
require_once dirname($bcrf_cfg) . '/includes/adapter_meta.php';   // Meta adapter (Farm OS Phase 0)
require_once dirname($bcrf_cfg) . '/includes/farm.php';
$FB_OK  = bcrf_meta_ready();
gbp_schema($pdo);            // adds social_queue.gbp_caption / gbp_post_id if missing (the UPDATE below uses them)
$GBP_OK = gbp_ready($pdo);
if (!$FB_OK && !$GBP_OK) {
    social_log('send_social: neither Facebook (FB_PAGE_ID / FB_PAGE_TOKEN) nor Google Business Profile is configured. Nothing sent.');
    exit(0);
}
$GRAPH   = bcrf_meta_graph_base();
$IG_ID   = bcrf_meta_ig_user();
$BASEURL = bcrf_farm_url();

// ── HTTP: the Meta adapter (includes/adapter_meta.php → adapter_http.php, which has the Bluehost fallback) ──
function social_http(string $method, string $url, array $fields = []): array {
    return bcrf_meta_request($method, $url, $fields, 45);
}
function social_err(array $r): string {
    return bcrf_meta_error($r);
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
        SET status = ?, fb_post_id = ?, ig_media_id = ?, gbp_post_id = ?, last_error = ?, attempts = attempts + 1, processed_at = NOW()
      WHERE id = ?"
);

foreach ($pending as $q) {
    $type = in_array($q['content_type'], ['recipe', 'animal'], true) ? $q['content_type'] : 'blog';
    $row  = social_record($pdo, $type, (int)$q['content_id']);
    if (!$row) {
        $upd->execute(['failed', null, null, null, 'Source record no longer exists', $q['id']]);
        social_log("#{$q['id']}: FAILED — source {$type} #{$q['content_id']} missing");
        continue;
    }

    $img       = social_image_url($row, $BASEURL);
    $permalink = social_permalink($type, $row, $BASEURL);
    $fbCaption = (string)$q['fb_caption'];
    // GA campaign tags on the Facebook link (Instagram captions can't hold clickable links)
    if (!function_exists('bcrf_utm_url')) require_once __DIR__ . '/../../includes/ga.php';
    $fbLink    = bcrf_utm_url($permalink, 'facebook', 'social', 'autoshare-' . $type);
    $fbCaption = str_replace($permalink, $fbLink, $fbCaption);
    $igCaption = trim((string)$q['ig_caption'] . (trim((string)$q['ig_hashtags']) !== '' ? "\n\n" . $q['ig_hashtags'] : ''));

    $fbId = null; $igId = null; $gbpId = null; $errors = [];
    $wantGbp = str_contains((string)$q['platforms'], 'google') && trim((string)($q['gbp_caption'] ?? '')) !== '';

    // ── FACEBOOK ──
    if (!$FB_OK) {
        $errors[] = 'FB: not configured';
    } elseif ($img) {
        $r = social_http('POST', "{$GRAPH}/" . FB_PAGE_ID . "/photos", [
            'url' => $img, 'caption' => $fbCaption, 'access_token' => FB_PAGE_TOKEN,
        ]);
        $fbId = $r['json']['post_id'] ?? $r['json']['id'] ?? null;
    } else {
        $r = social_http('POST', "{$GRAPH}/" . FB_PAGE_ID . "/feed", [
            'message' => $fbCaption, 'link' => $fbLink, 'access_token' => FB_PAGE_TOKEN,
        ]);
        $fbId = $r['json']['id'] ?? null;
    }
    if ($FB_OK && !$fbId) { $errors[] = 'FB: ' . social_err($r); }

    // ── INSTAGRAM (only if configured and we have a public image) ──
    if ($FB_OK && $IG_ID && $img) {
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
    } elseif ($FB_OK && $IG_ID && !$img) {
        $errors[] = 'IG skipped: no public image on this post';
    }

    // ── GOOGLE BUSINESS PROFILE ──
    if ($wantGbp && !$GBP_OK) {
        $errors[] = 'Google: not connected';
    } elseif ($wantGbp) {
        try {
            $gbpLink = bcrf_utm_url($permalink, 'google', 'organic', 'gbp-' . $type);
            $gbpId   = gbp_post($pdo, (string)$q['gbp_caption'], $gbpLink, $img);
        } catch (Throwable $e) { $errors[] = 'Google: ' . $e->getMessage(); }
    }

    // ── Final status (terminal — never re-posts) ──
    $wanted = 1 + ($IG_ID ? 1 : 0) + ($wantGbp ? 1 : 0);
    $got    = ($fbId ? 1 : 0) + ($igId ? 1 : 0) + ($gbpId ? 1 : 0);
    $status = $got === 0 ? 'failed' : ($got >= $wanted ? 'sent' : 'partial');
    $upd->execute([$status, $fbId, $igId, $gbpId, ($errors ? implode(' | ', $errors) : null), $q['id']]);
    social_log("#{$q['id']}: {$status}" . ($fbId ? " fb={$fbId}" : '') . ($igId ? " ig={$igId}" : '') . ($gbpId ? " google={$gbpId}" : '') . ($errors ? ' — ' . implode(' | ', $errors) : ''));
}

social_log('send_social: done.');
