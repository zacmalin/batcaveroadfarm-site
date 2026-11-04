<?php // api/index.php
/*
 * JSON API front controller (Nov 2026) — Farm OS spec 15 "Database & API".
 *
 * Reached two ways, same code:
 *   https://api.batcaveroadfarm.com/v1/...   subdomain (cPanel document root = this api/ folder)
 *   https://batcaveroadfarm.com/api/v1/...   main domain — for admin screens, which need the
 *                                            main-domain login cookie (cookies are per host)
 * Responses follow the spec: {"data": ..., "meta": {...}} or {"errors": [{"code","message"}], "meta": {...}}.
 *
 * Routes so far:
 *   GET /v1/health   — is the API up and can it reach the database? Public, no data exposed.
 *   POST /mcp        — farm MCP server for Claude / Perplexity (OAuth; administrators only).
 *                      OAuth endpoints: /.well-known/*, /oauth/register|token|revoke|authorize.
 *                      Code: includes/mcp/. Setup: docs/MCP.md.
 * Everything else returns a JSON 404. Real endpoints (products, sales, tickets, MCP) come later,
 * each behind the authorization layer described in the spec.
 */

define('BCRF_NO_SESSION', true);   // no cookies/sessions for the API (MCP uses bearer tokens)

$__reqId = bin2hex(random_bytes(8));
$__t0    = microtime(true);

function api_send(int $status, array $body): void {
    global $__reqId, $__t0;
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-LiteSpeed-Cache-Control: no-cache');   // overrides the page-cache header config.php may send
        header('X-Request-Id: ' . $__reqId);
    }
    $body['meta'] = ($body['meta'] ?? []) + ['request_id' => $__reqId, 'ms' => (int)round((microtime(true) - $__t0) * 1000)];
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function api_error(int $status, string $code, string $message): void {
    api_send($status, ['errors' => [['code' => $code, 'message' => $message]]]);
}

// Any PHP error inside the API comes back as JSON, not an HTML page.
set_exception_handler(function (Throwable $e) {
    global $__reqId;
    error_log("[API {$__reqId}] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_error(500, 'server_error', 'Something went wrong. Reference: ' . $__reqId);
});

// Site config at top level (not inside a route), so $pdo and helpers that use `global $pdo`
// work for every endpoint. If the database is down, config.php answers 503 itself.
require_once dirname(__DIR__) . '/config.php';

// ── Route: path relative to the API root, the same on both hosts ─────────────
$path = '/' . ltrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if (!str_starts_with($host, 'api.') && preg_match('#^/api(/|$)#', $path)) {
    $path = substr($path, 4) ?: '/';            // batcaveroadfarm.com/api/v1/x -> /v1/x
}
$path   = rtrim($path, '/') ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ── MCP server + OAuth (Nov 2026) — includes/mcp/*. Connect at https://api.batcaveroadfarm.com/mcp ──
$__mcpPaths = ['/mcp', '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp',
               '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration',
               '/oauth/register', '/oauth/token', '/oauth/revoke', '/oauth/authorize'];
if (in_array($path, $__mcpPaths, true)) {
    require_once dirname(__DIR__) . '/includes/mcp/oauth.php';
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id');
        http_response_code(204); exit;
    }
    mcp_oauth_schema($pdo);
    switch ($path) {
        case '/.well-known/oauth-protected-resource':
        case '/.well-known/oauth-protected-resource/mcp':
            mcp_oauth_json(200, mcp_protected_resource_metadata());
        case '/.well-known/oauth-authorization-server':
        case '/.well-known/openid-configuration':
            mcp_oauth_json(200, mcp_authorization_server_metadata());
        case '/oauth/register':
            if ($method !== 'POST') mcp_oauth_error(405, 'invalid_request', 'Use POST.');
            mcp_oauth_register($pdo);
        case '/oauth/token':
            if ($method !== 'POST') mcp_oauth_error(405, 'invalid_request', 'Use POST.');
            mcp_oauth_token($pdo);
        case '/oauth/revoke':
            if ($method !== 'POST') mcp_oauth_error(405, 'invalid_request', 'Use POST.');
            mcp_oauth_revoke($pdo);
        case '/oauth/authorize':
            mcp_oauth_authorize($pdo);
        case '/mcp':
            require_once dirname(__DIR__) . '/includes/mcp/server.php';
            mcp_handle_request($pdo, $method);
    }
}

// ── Routes ───────────────────────────────────────────────────────────────────
$routes = [
    'GET /v1/health' => function () {
        global $pdo;
        $db = 'ok';
        try { $pdo->query('SELECT 1')->fetchColumn(); }
        catch (Throwable $e) { $db = 'error'; error_log('[API health] ' . $e->getMessage()); }
        api_send($db === 'ok' ? 200 : 503, ['data' => [
            'status'   => $db === 'ok' ? 'ok' : 'degraded',
            'api'      => 'v1',
            'database' => $db,
            'time'     => (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format(DATE_ATOM),
        ]]);
    },
];

if ($path === '/' || $path === '/v1') {
    api_send(200, ['data' => ['name' => 'Bat Cave Road Farm API', 'versions' => ['v1'], 'health' => '/v1/health']]);
}
if (isset($routes["{$method} {$path}"])) { $routes["{$method} {$path}"](); }
if ($method === 'HEAD' && isset($routes["GET {$path}"])) { $routes["GET {$path}"](); }

$allowed = [];
foreach (array_keys($routes) as $k) { [$m, $p] = explode(' ', $k, 2); if ($p === $path) $allowed[] = $m; }
if ($allowed) { header('Allow: ' . implode(', ', $allowed)); api_error(405, 'method_not_allowed', "Use " . implode(' or ', $allowed) . " for {$path}."); }
api_error(404, 'not_found', "No API endpoint at {$path}.");
