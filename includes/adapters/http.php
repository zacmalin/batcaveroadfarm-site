<?php
// includes/adapters/http.php — one HTTP client for every outside service (Farm OS Phase 0, Nov 5, 2026).
// Adapters (meta, cloudflare, usda) call this instead of writing their own cURL code, so timeouts,
// error handling and the Bluehost quirks live in one place.

if (!function_exists('bcrf_http')) {

/**
 * Make one HTTP request. Never throws.
 * $opts: query (array, added to the URL), form (array, sent as form fields), json (array, sent as JSON),
 *        headers (list of "Name: value"), timeout (seconds, default 20).
 * Returns ['status' => int (0 = couldn't connect), 'body' => string, 'json' => array|null, 'error' => string].
 */
function bcrf_http(string $method, string $url, array $opts = []): array {
    $method = strtoupper($method);
    if (!empty($opts['query'])) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($opts['query']);
    $headers = (array)($opts['headers'] ?? []);
    $body = null;
    if (isset($opts['json']))     { $body = json_encode($opts['json']); $headers[] = 'Content-Type: application/json'; }
    elseif (isset($opts['form'])) { $body = http_build_query($opts['form']); }

    if (!function_exists('curl_init')) {   // some Bluehost PHP builds: fall back to streams
        $ctx = ['http' => ['method' => $method, 'timeout' => (int)($opts['timeout'] ?? 20), 'ignore_errors' => true,
                           'header' => implode("\r\n", $body !== null && !isset($opts['json'])
                               ? array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']) : $headers)]];
        if ($body !== null) $ctx['http']['content'] = $body;
        $raw = @file_get_contents($url, false, stream_context_create($ctx));
        $rh  = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
        $code = (isset($rh[0]) && preg_match('/\s(\d{3})\s/', $rh[0], $m)) ? (int)$m[1] : 0;
        $raw  = $raw === false ? '' : (string)$raw;
        $json = json_decode($raw, true);
        return ['status' => $code, 'body' => $raw, 'json' => is_array($json) ? $json : null, 'error' => $code ? '' : 'could not connect'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => (int)($opts['timeout'] ?? 20),
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    $raw = $raw === false ? '' : (string)$raw;
    $json = json_decode($raw, true);
    return ['status' => $code, 'body' => $raw, 'json' => is_array($json) ? $json : null, 'error' => $err];
}

}
