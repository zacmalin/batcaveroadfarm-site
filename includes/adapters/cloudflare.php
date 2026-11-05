<?php
// includes/adapters/cloudflare.php — Cloudflare cache purge (Farm OS Phase 0, Nov 5, 2026).
// Credentials in the tenant config (bcrf-secrets.php / environment): BCRF_CF_TOKEN, BCRF_CF_ZONE.
// Without them every call is a quiet no-op, so a farm without Cloudflare works unchanged.
require_once __DIR__ . '/http.php';

if (!function_exists('bcrf_cdn_purge')) {

function bcrf_cdn_ready(): bool {
    return bcrf_cdn_creds() !== null;
}

/** [token, zone] or null. */
function bcrf_cdn_creds(): ?array {
    $token = defined('BCRF_CF_TOKEN') ? (string)BCRF_CF_TOKEN : (string)(getenv('BCRF_CF_TOKEN') ?: '');
    $zone  = defined('BCRF_CF_ZONE')  ? (string)BCRF_CF_ZONE  : (string)(getenv('BCRF_CF_ZONE')  ?: '');
    return ($token !== '' && $zone !== '') ? [$token, $zone] : null;
}

/**
 * Purge the CDN cache: everything (no URLs) or just the given full URLs (Cloudflare takes 30 per call).
 * Best effort: returns true on success or when Cloudflare isn't configured; logs failures.
 */
function bcrf_cdn_purge(array $urls = []): bool {
    $c = bcrf_cdn_creds();
    if (!$c) return true;
    [$token, $zone] = $c;
    $batches = $urls ? array_chunk(array_values($urls), 30) : [null];
    $ok = true;
    foreach ($batches as $batch) {
        $r = bcrf_http('POST', 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone) . '/purge_cache', [
            'headers' => ['Authorization: Bearer ' . $token],
            'json'    => $batch === null ? ['purge_everything' => true] : ['files' => $batch],
            'timeout' => 8,
        ]);
        if (empty($r['json']['success'])) {
            $ok = false;
            error_log('[BCRF CF purge] HTTP ' . $r['status'] . ' ' . ($r['error'] ?: substr($r['body'], 0, 200)));
        }
    }
    return $ok;
}

}
