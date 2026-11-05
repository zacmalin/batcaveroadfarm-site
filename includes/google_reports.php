<?php
// includes/google_reports.php
// Search Console + Google Analytics 4 numbers for the admin "Search & Analytics" screen (Nov 2026).
//
// Signs in with a Google Cloud SERVICE ACCOUNT (server-to-server, nothing expires, no consent screen).
// The Drive-backup sign-in can't be reused: Google's device-code flow doesn't allow these scopes.
//
// Setup (one time, see docs/GOOGLE-REPORTS.md):
//   1. Google Cloud: enable "Google Search Console API" and "Google Analytics Data API",
//      create a service account, add a JSON key.
//   2. Upload the key file OUTSIDE the web root: /home2/<user>/bcrf-google-sa.json (chmod 600).
//   3. Search Console → Settings → Users and permissions → add the service account email (Restricted).
//   4. GA → Admin → Property access management → add the service account email (Viewer).
//   5. bcrf-secrets.php: 'GA4_PROPERTY_ID' => '123456789' (GA → Admin → Property details).
//      Optional: 'GOOGLE_SA_KEY_FILE' (other key path), 'GSC_SITE' (default sc-domain:batcaveroadfarm.com).
//
// Data is fetched by admin/cron/fetch_google_reports.php (daily) or the screen's Refresh button, and
// cached in google_report_cache, so the admin screen never waits on Google.

if (!function_exists('bcrf_greports_config')) {

function bcrf_greports_config(): array {
    $s = $GLOBALS['bcrf'] ?? [];
    return [
        // Default: next to bcrf-secrets.php in the home folder (/home2/<user>/), one level above public_html
        'key_file'    => (string)($s['GOOGLE_SA_KEY_FILE'] ?? ((defined('BCRF_SECRETS_PATH') && BCRF_SECRETS_PATH !== '' ? dirname(BCRF_SECRETS_PATH) : dirname(__DIR__, 3)) . '/bcrf-google-sa.json')),
        'property_id' => preg_replace('/\D/', '', (string)($s['GA4_PROPERTY_ID'] ?? '')),
        'gsc_site'    => (string)($s['GSC_SITE'] ?? 'sc-domain:batcaveroadfarm.com'),
    ];
}

/** What's missing from the setup, in plain words (empty = ready). */
function bcrf_greports_missing(): array {
    $c = bcrf_greports_config();
    $m = [];
    if (!is_file($c['key_file']) || !is_readable($c['key_file'])) $m[] = 'Service account key file not found at ' . str_replace(dirname(__DIR__, 3) . '/', '~/', $c['key_file']);
    if ($c['property_id'] === '') $m[] = "GA4_PROPERTY_ID isn't set in bcrf-secrets.php";
    if (!function_exists('openssl_sign')) $m[] = 'PHP OpenSSL extension is missing';
    return $m;
}

function bcrf_greports_http(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 45,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('network error: ' . $err);
    $json = json_decode((string)$resp, true);
    return [$code, is_array($json) ? $json : []];
}

function bcrf_greports_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Access token for the service account (cached for the request). */
function bcrf_greports_token(): string {
    static $tok = null, $exp = 0;
    if ($tok && time() < $exp - 60) return $tok;
    $c = bcrf_greports_config();
    $key = json_decode((string)@file_get_contents($c['key_file']), true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) throw new RuntimeException('Service account key file is not a valid JSON key');
    $now = time();
    $head   = bcrf_greports_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = bcrf_greports_b64url(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
        'aud'   => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'iat'   => $now, 'exp' => $now + 3600,
    ]));
    $sig = '';
    if (!openssl_sign($head . '.' . $claims, $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign with the service account key');
    [$code, $j] = bcrf_greports_http('POST', $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $head . '.' . $claims . '.' . bcrf_greports_b64url($sig)]));
    if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('Google sign-in failed: ' . ($j['error_description'] ?? $j['error'] ?? ('HTTP ' . $code)));
    $tok = $j['access_token']; $exp = $now + (int)($j['expires_in'] ?? 3600);
    return $tok;
}

function bcrf_greports_api(string $method, string $url, ?array $json = null): array {
    [$code, $j] = bcrf_greports_http($method, $url,
        ['Authorization: Bearer ' . bcrf_greports_token(), 'Content-Type: application/json'],
        $json !== null ? json_encode($json) : null);
    if ($code < 200 || $code >= 300) throw new RuntimeException(($j['error']['message'] ?? ('HTTP ' . $code)));
    return $j;
}

/** The two 28-day windows. Search Console data lags ~2-3 days, so it ends 3 days ago; GA ends yesterday. */
function bcrf_greports_ranges(int $lagDays): array {
    $end   = new DateTime('today -' . $lagDays . ' days', new DateTimeZone('America/New_York'));
    $start = (clone $end)->modify('-27 days');
    $pEnd  = (clone $start)->modify('-1 day');
    $pStart= (clone $pEnd)->modify('-27 days');
    return ['cur' => [$start->format('Y-m-d'), $end->format('Y-m-d')], 'prev' => [$pStart->format('Y-m-d'), $pEnd->format('Y-m-d')]];
}

/** Search Console: totals (this vs previous 28 days), daily clicks, top queries, top pages, sitemaps. */
function bcrf_greports_fetch_gsc(): array {
    $c = bcrf_greports_config();
    $base = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($c['gsc_site']);
    $r = bcrf_greports_ranges(3);
    $q = fn(array $range, array $dims, int $limit = 25) => bcrf_greports_api('POST', $base . '/searchAnalytics/query', [
        'startDate' => $range[0], 'endDate' => $range[1], 'dimensions' => $dims, 'rowLimit' => $limit, 'dataState' => 'final',
    ])['rows'] ?? [];
    $tot = function (array $rows): array {
        $x = $rows[0] ?? [];
        return ['clicks' => (int)($x['clicks'] ?? 0), 'impressions' => (int)($x['impressions'] ?? 0),
                'ctr' => (float)($x['ctr'] ?? 0), 'position' => (float)($x['position'] ?? 0)];
    };
    $sitemaps = [];
    try {
        foreach (bcrf_greports_api('GET', $base . '/sitemaps')['sitemap'] ?? [] as $s) {
            $sitemaps[] = ['path' => $s['path'] ?? '', 'last_downloaded' => $s['lastDownloaded'] ?? '', 'errors' => (int)($s['errors'] ?? 0),
                           'warnings' => (int)($s['warnings'] ?? 0), 'pending' => !empty($s['isPending'])];
        }
    } catch (Throwable $e) { /* sitemaps are optional */ }
    return [
        'range'   => $r,
        'total'   => $tot($q($r['cur'], [], 1)),
        'prev'    => $tot($q($r['prev'], [], 1)),
        'daily'   => array_map(fn($x) => ['date' => $x['keys'][0], 'clicks' => (int)$x['clicks'], 'impressions' => (int)$x['impressions']], $q($r['cur'], ['date'], 40)),
        'queries' => array_map(fn($x) => ['query' => $x['keys'][0], 'clicks' => (int)$x['clicks'], 'impressions' => (int)$x['impressions'], 'ctr' => (float)$x['ctr'], 'position' => (float)$x['position']], $q($r['cur'], ['query'], 25)),
        'pages'   => array_map(fn($x) => ['page' => $x['keys'][0], 'clicks' => (int)$x['clicks'], 'impressions' => (int)$x['impressions'], 'ctr' => (float)$x['ctr'], 'position' => (float)$x['position']], $q($r['cur'], ['page'], 25)),
        'sitemaps'=> $sitemaps,
    ];
}

/** GA4: totals (this vs previous 28 days), channels, sources, landing pages, our tracked events. */
function bcrf_greports_fetch_ga(): array {
    $c = bcrf_greports_config();
    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $c['property_id'] . ':runReport';
    $r = bcrf_greports_ranges(1);
    $run = fn(array $body) => bcrf_greports_api('POST', $url, $body);
    $metrics = [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'keyEvents'], ['name' => 'purchaseRevenue']];

    $tot = $run(['dateRanges' => [['startDate' => $r['cur'][0], 'endDate' => $r['cur'][1]], ['startDate' => $r['prev'][0], 'endDate' => $r['prev'][1]]],
                 'metrics' => $metrics]);
    $totals = ['total' => [], 'prev' => []];
    foreach ($tot['rows'] ?? [] as $i => $row) {
        // With two date ranges and no dimensions, GA adds a dateRange dimension: date_range_0 / date_range_1
        $which = ($row['dimensionValues'][0]['value'] ?? ('date_range_' . $i)) === 'date_range_1' ? 'prev' : 'total';
        foreach ($metrics as $k => $m) $totals[$which][$m['name']] = (float)($row['metricValues'][$k]['value'] ?? 0);
    }
    $table = function (string $dim, array $mets, int $limit, ?array $filter = null) use ($run, $r) {
        $body = ['dateRanges' => [['startDate' => $r['cur'][0], 'endDate' => $r['cur'][1]]],
                 'dimensions' => [['name' => $dim]], 'metrics' => array_map(fn($m) => ['name' => $m], $mets),
                 'orderBys' => [['metric' => ['metricName' => $mets[0]], 'desc' => true]], 'limit' => $limit];
        if ($filter) $body['dimensionFilter'] = $filter;
        $out = [];
        foreach ($run($body)['rows'] ?? [] as $row) {
            $o = [$dim => $row['dimensionValues'][0]['value'] ?? ''];
            foreach ($mets as $k => $m) $o[$m] = (float)($row['metricValues'][$k]['value'] ?? 0);
            $out[] = $o;
        }
        return $out;
    };
    $ours = ['purchase', 'begin_checkout', 'generate_lead', 'sign_up', 'job_application', 'newsletter_signup',
             'click_to_call', 'get_directions', 'recipe_to_stand', 'recipe_to_subrecipe', 'share'];
    return [
        'range'    => $r,
        'total'    => $totals['total'],
        'prev'     => $totals['prev'],
        'channels' => $table('sessionDefaultChannelGroup', ['sessions', 'keyEvents'], 12),
        'sources'  => $table('sessionSourceMedium', ['sessions', 'keyEvents'], 15),
        'landing'  => $table('landingPage', ['sessions', 'keyEvents'], 20),
        'events'   => $table('eventName', ['eventCount'], 20, ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => $ours]]]),
    ];
}

function bcrf_greports_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS google_report_cache (
        report_key VARCHAR(20) NOT NULL PRIMARY KEY,
        data       LONGTEXT NULL,
        fetched_at DATETIME NULL,       -- UTC
        last_error VARCHAR(500) NULL,
        error_at   DATETIME NULL        -- UTC
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Fetch both reports and cache them. Returns ['gsc' => 'ok'|error, 'ga' => 'ok'|error]. */
function bcrf_greports_refresh(PDO $pdo): array {
    bcrf_greports_schema($pdo);
    $res = [];
    foreach (['gsc' => 'bcrf_greports_fetch_gsc', 'ga' => 'bcrf_greports_fetch_ga'] as $key => $fn) {
        try {
            if ($m = bcrf_greports_missing()) throw new RuntimeException(implode('; ', $m));
            $data = $fn();
            $pdo->prepare("INSERT INTO google_report_cache (report_key, data, fetched_at, last_error, error_at) VALUES (?, ?, UTC_TIMESTAMP(), NULL, NULL)
                           ON DUPLICATE KEY UPDATE data = VALUES(data), fetched_at = VALUES(fetched_at), last_error = NULL, error_at = NULL")
                ->execute([$key, json_encode($data)]);
            $res[$key] = 'ok';
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 480);
            $pdo->prepare("INSERT INTO google_report_cache (report_key, last_error, error_at) VALUES (?, ?, UTC_TIMESTAMP())
                           ON DUPLICATE KEY UPDATE last_error = VALUES(last_error), error_at = VALUES(error_at)")
                ->execute([$key, $msg]);
            error_log('[google_reports] ' . $key . ': ' . $msg);
            $res[$key] = $msg;
        }
    }
    return $res;
}

/** Cached reports: ['gsc' => ['data' => array|null, 'fetched_at', 'last_error', 'error_at'], 'ga' => …]. */
function bcrf_greports_cached(PDO $pdo): array {
    $out = ['gsc' => ['data' => null, 'fetched_at' => null, 'last_error' => null, 'error_at' => null], 'ga' => ['data' => null, 'fetched_at' => null, 'last_error' => null, 'error_at' => null]];
    try {
        bcrf_greports_schema($pdo);
        foreach ($pdo->query("SELECT * FROM google_report_cache")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($out[$r['report_key']])) continue;
            $out[$r['report_key']] = ['data' => $r['data'] ? json_decode($r['data'], true) : null,
                                      'fetched_at' => $r['fetched_at'], 'last_error' => $r['last_error'], 'error_at' => $r['error_at']];
        }
    } catch (Throwable $e) { error_log('[google_reports] cache read: ' . $e->getMessage()); }
    return $out;
}

}
