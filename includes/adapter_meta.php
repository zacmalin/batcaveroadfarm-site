<?php
// includes/adapter_meta.php — Facebook / Instagram Graph API (Farm OS Phase 0, Nov 5, 2026).
// The only place that knows the Graph address and version. Credentials stay in the tenant config
// (bcrf-secrets.php): META_APP_ID, META_APP_SECRET, FB_PAGE_ID, FB_PAGE_TOKEN, IG_USER_ID,
// BCRF_GRAPH_VERSION (optional). Used by admin/cron/send_social.php and includes/social_token.php.
require_once __DIR__ . '/adapter_http.php';

if (!function_exists('bcrf_meta_graph_base')) {

/** https://graph.facebook.com/vNN.N — honours the BCRF_GRAPH_TEST_BASE test hook. */
function bcrf_meta_graph_base(): string {
    $host = 'https://graph.facebook.com';
    if (($tb = getenv('BCRF_GRAPH_TEST_BASE')) !== false && $tb !== '') $host = rtrim($tb, '/');   // test hook only
    return $host . '/' . (defined('BCRF_GRAPH_VERSION') && BCRF_GRAPH_VERSION !== '' ? BCRF_GRAPH_VERSION : 'v23.0');
}

/** True when the Facebook Page is configured. */
function bcrf_meta_ready(): bool {
    return defined('FB_PAGE_ID') && defined('FB_PAGE_TOKEN') && FB_PAGE_ID !== '' && FB_PAGE_TOKEN !== '';
}

/** Instagram business account id, or null when Instagram isn't set up. */
function bcrf_meta_ig_user(): ?string {
    return (defined('IG_USER_ID') && IG_USER_ID !== '') ? (string)IG_USER_ID : null;
}

/**
 * Call the Graph API. $path is relative to the versioned base ("/me", "/123/feed") or a full URL.
 * GET params go in the query string, POST params as form fields.
 * Returns ['code' => int, 'json' => array, 'raw' => string] (same shape the posting cron always used).
 */
function bcrf_meta_request(string $method, string $path, array $params = [], int $timeout = 45): array {
    $url = str_starts_with($path, 'http') ? $path : bcrf_meta_graph_base() . '/' . ltrim($path, '/');
    $r = strtoupper($method) === 'GET'
        ? bcrf_http('GET', $url, ['query' => $params, 'timeout' => $timeout])
        : bcrf_http(strtoupper($method), $url, ['form' => $params, 'timeout' => $timeout]);
    $raw = $r['body'];
    if ($raw === '' && $r['error'] !== '') $raw = json_encode(['error' => ['message' => $r['error']]]);
    return ['code' => $r['status'], 'json' => $r['json'] ?? (json_decode($raw, true) ?: []), 'raw' => $raw];
}

/** Readable error from a bcrf_meta_request() result. */
function bcrf_meta_error(array $r): string {
    if (!empty($r['json']['error']['message'])) return (string)$r['json']['error']['message'];
    return 'HTTP ' . $r['code'] . ' ' . substr((string)$r['raw'], 0, 200);
}

}
