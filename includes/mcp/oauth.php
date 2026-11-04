<?php
// includes/mcp/oauth.php
//
// OAuth 2.1 for the farm MCP server (Nov 2026). Lets Claude and Perplexity connect
// as "custom connectors": the app registers itself (Dynamic Client Registration),
// sends you to a consent page that only an ADMINISTRATOR account can approve, then
// trades a one-time code (PKCE S256) for tokens.
//
//   Everything lives on https://api.batcaveroadfarm.com (MCP endpoint /mcp, /oauth/*, /.well-known/*).
//   The consent page asks for your admin email + password itself (no cookies), so if
//   Bluehost's ModSecurity blocks these requests it only needs turning off for the api.
//   subdomain, never the main site. Wrong passwords count toward the same lockout as
//   the admin login page.
//
// Tokens are random and stored only as SHA-256 hashes. Access tokens last 1 hour,
// refresh tokens 30 days and rotate on every use. Every MCP call re-checks that the
// approving user is still an active administrator. Revoke at /admin/mcp_connections.php.

if (!function_exists('mcp_oauth_schema')) {

define('MCP_ISSUER', 'https://api.batcaveroadfarm.com');
define('MCP_RESOURCE', 'https://api.batcaveroadfarm.com/mcp');
define('MCP_AUTHORIZE_URL', 'https://api.batcaveroadfarm.com/oauth/authorize');
define('MCP_SCOPE', 'farm');
define('MCP_ACCESS_TTL', 3600);          // 1 hour
define('MCP_REFRESH_TTL', 30 * 86400);    // 30 days
define('MCP_CODE_TTL', 600);           // 10 minutes

function mcp_oauth_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_clients (
        client_id      VARCHAR(64)  NOT NULL PRIMARY KEY,
        secret_hash    CHAR(64)     NULL,
        client_name    VARCHAR(120) NOT NULL DEFAULT '',
        redirect_uris  TEXT         NOT NULL,
        auth_method    VARCHAR(32)  NOT NULL DEFAULT 'client_secret_post',
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
        code_hash      CHAR(64)     NOT NULL PRIMARY KEY,
        client_id      VARCHAR(64)  NOT NULL,
        user_id        INT          NOT NULL,
        redirect_uri   TEXT         NOT NULL,
        code_challenge VARCHAR(128) NOT NULL,
        expires_at     DATETIME     NOT NULL,
        used_at        DATETIME     NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_tokens (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        client_id       VARCHAR(64)  NOT NULL,
        user_id         INT          NOT NULL,
        access_hash     CHAR(64)     NOT NULL,
        refresh_hash    CHAR(64)     NOT NULL,
        access_expires  DATETIME     NOT NULL,
        refresh_expires DATETIME     NOT NULL,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at    DATETIME     NULL,
        revoked_at      DATETIME     NULL,
        UNIQUE KEY uq_access (access_hash),
        UNIQUE KEY uq_refresh (refresh_hash),
        KEY k_client (client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function mcp_now(int $plus = 0): string { return gmdate('Y-m-d H:i:s', time() + $plus); }   // stored in UTC
function mcp_hash(string $s): string { return hash('sha256', $s); }
function mcp_random(string $prefix): string { return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }

/** JSON reply for the OAuth endpoints (RFC 6749 error shape). */
function mcp_oauth_json(int $status, array $body, array $headers = []): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
        foreach ($headers as $h) header($h);
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function mcp_oauth_error(int $status, string $error, string $desc = ''): void {
    mcp_oauth_json($status, array_filter(['error' => $error, 'error_description' => $desc]));
}

function mcp_protected_resource_metadata(): array {
    return [
        'resource'                 => MCP_RESOURCE,
        'authorization_servers'    => [MCP_ISSUER],
        'scopes_supported'         => [MCP_SCOPE],
        'bearer_methods_supported' => ['header'],
        'resource_name'            => 'Bat Cave Road Farm',
    ];
}

function mcp_authorization_server_metadata(): array {
    return [
        'issuer'                                => MCP_ISSUER,
        'authorization_endpoint'                => MCP_AUTHORIZE_URL,
        'token_endpoint'                        => MCP_ISSUER . '/oauth/token',
        'registration_endpoint'                 => MCP_ISSUER . '/oauth/register',
        'revocation_endpoint'                   => MCP_ISSUER . '/oauth/revoke',
        'scopes_supported'                      => [MCP_SCOPE],
        'response_types_supported'              => ['code'],
        'response_modes_supported'              => ['query'],
        'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported'      => ['S256'],
        'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
        'authorization_response_iss_parameter_supported' => true,
    ];
}

/** Redirect URIs we accept: https anywhere, or http on localhost (desktop apps). */
function mcp_redirect_uri_ok(string $u): bool {
    $p = parse_url($u);
    if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment'])) return false;
    if ($p['scheme'] === 'https') return true;
    return $p['scheme'] === 'http' && in_array($p['host'], ['localhost', '127.0.0.1', '[::1]'], true);
}

/** POST /oauth/register — RFC 7591. Always issues a secret (Perplexity requires one). */
function mcp_oauth_register(PDO $pdo): void {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) mcp_oauth_error(400, 'invalid_client_metadata', 'Body must be JSON.');
    $uris = $in['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 10) mcp_oauth_error(400, 'invalid_redirect_uri', 'redirect_uris is required.');
    foreach ($uris as $u) {
        if (!is_string($u) || strlen($u) > 500 || !mcp_redirect_uri_ok($u)) mcp_oauth_error(400, 'invalid_redirect_uri', 'Redirect URIs must be https (or http on localhost).');
    }
    $method = (string)($in['token_endpoint_auth_method'] ?? 'client_secret_post');
    if (!in_array($method, ['client_secret_post', 'client_secret_basic', 'none'], true)) $method = 'client_secret_post';
    $name   = mb_substr(trim(strip_tags((string)($in['client_name'] ?? 'AI app'))), 0, 120) ?: 'AI app';

    // Basic abuse limit: at most 30 registrations per hour in total.
    $n = (int)$pdo->query("SELECT COUNT(*) FROM mcp_oauth_clients WHERE created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR")->fetchColumn();
    if ($n >= 30) mcp_oauth_error(429, 'temporarily_unavailable', 'Too many registrations. Try again later.');

    $id     = 'bcrf_' . bin2hex(random_bytes(12));
    $secret = mcp_random('bcrf_cs_');
    $pdo->prepare("INSERT INTO mcp_oauth_clients (client_id, secret_hash, client_name, redirect_uris, auth_method, created_at) VALUES (?,?,?,?,?,?)")
        ->execute([$id, mcp_hash($secret), $name, json_encode(array_values($uris), JSON_UNESCAPED_SLASHES), $method, mcp_now()]);

    mcp_oauth_json(201, [
        'client_id'                  => $id,
        'client_secret'              => $secret,
        'client_id_issued_at'        => time(),
        'client_secret_expires_at'   => 0,
        'client_name'                => $name,
        'redirect_uris'              => array_values($uris),
        'grant_types'                => ['authorization_code', 'refresh_token'],
        'response_types'             => ['code'],
        'token_endpoint_auth_method' => $method,
        'scope'                      => MCP_SCOPE,
    ]);
}

function mcp_client_find(PDO $pdo, string $id): ?array {
    if ($id === '' || strlen($id) > 64) return null;
    $st = $pdo->prepare("SELECT * FROM mcp_oauth_clients WHERE client_id = ?");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;
    $c['redirect_uris'] = json_decode((string)$c['redirect_uris'], true) ?: [];
    return $c;
}

/** Client authentication at the token endpoint. Returns the client row or exits with 401. */
function mcp_token_client(PDO $pdo): array {
    $id = (string)($_POST['client_id'] ?? '');
    $secret = isset($_POST['client_secret']) ? (string)$_POST['client_secret'] : null;
    $auth = mcp_auth_header();
    if (stripos($auth, 'Basic ') === 0) {
        $pair = base64_decode(substr($auth, 6), true);
        if ($pair !== false && str_contains($pair, ':')) {
            [$bid, $bsec] = explode(':', $pair, 2);
            $id = urldecode($bid); $secret = urldecode($bsec);
        }
    }
    $c = mcp_client_find($pdo, $id);
    if (!$c) mcp_oauth_error(401, 'invalid_client', 'Unknown client.');
    if ($secret !== null && $secret !== '') {
        if (!$c['secret_hash'] || !hash_equals($c['secret_hash'], mcp_hash($secret))) mcp_oauth_error(401, 'invalid_client', 'Wrong client secret.');
    } elseif ($c['auth_method'] !== 'none') {
        mcp_oauth_error(401, 'invalid_client', 'Client secret required.');
    }
    return $c;
}

function mcp_auth_header(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; } }
    }
    return trim((string)$h);
}

function mcp_issue_tokens(PDO $pdo, string $clientId, int $userId): array {
    $at = mcp_random('bcrf_at_');
    $rt = mcp_random('bcrf_rt_');
    $pdo->prepare("INSERT INTO mcp_oauth_tokens (client_id, user_id, access_hash, refresh_hash, access_expires, refresh_expires, created_at)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$clientId, $userId, mcp_hash($at), mcp_hash($rt), mcp_now(MCP_ACCESS_TTL), mcp_now(MCP_REFRESH_TTL), mcp_now()]);
    return ['access_token' => $at, 'token_type' => 'Bearer', 'expires_in' => MCP_ACCESS_TTL, 'refresh_token' => $rt, 'scope' => MCP_SCOPE];
}

/** POST /oauth/token */
function mcp_oauth_token(PDO $pdo): void {
    $c = mcp_token_client($pdo);
    $grant = (string)($_POST['grant_type'] ?? '');

    if ($grant === 'authorization_code') {
        $code = (string)($_POST['code'] ?? '');
        $verifier = (string)($_POST['code_verifier'] ?? '');
        $st = $pdo->prepare("SELECT * FROM mcp_oauth_codes WHERE code_hash = ?");
        $st->execute([mcp_hash($code)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['client_id'] !== $c['client_id'] || $row['used_at'] !== null || $row['expires_at'] < mcp_now()) {
            mcp_oauth_error(400, 'invalid_grant', 'Code is invalid, expired or already used.');
        }
        if (isset($_POST['redirect_uri']) && (string)$_POST['redirect_uri'] !== $row['redirect_uri']) mcp_oauth_error(400, 'invalid_grant', 'redirect_uri mismatch.');
        $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if ($verifier === '' || !hash_equals($row['code_challenge'], $calc)) {
            $pdo->prepare("UPDATE mcp_oauth_codes SET used_at = ? WHERE code_hash = ?")->execute([mcp_now(), $row['code_hash']]);   // burn it
            mcp_oauth_error(400, 'invalid_grant', 'PKCE check failed.');
        }
        // single use
        $u = $pdo->prepare("UPDATE mcp_oauth_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL");
        $u->execute([mcp_now(), $row['code_hash']]);
        if ($u->rowCount() !== 1) mcp_oauth_error(400, 'invalid_grant', 'Code already used.');
        if (!mcp_user_ok($pdo, (int)$row['user_id'])) mcp_oauth_error(400, 'invalid_grant', 'Account is no longer an active administrator.');
        mcp_oauth_json(200, mcp_issue_tokens($pdo, $c['client_id'], (int)$row['user_id']));
    }

    if ($grant === 'refresh_token') {
        $rt = (string)($_POST['refresh_token'] ?? '');
        $st = $pdo->prepare("SELECT * FROM mcp_oauth_tokens WHERE refresh_hash = ?");
        $st->execute([mcp_hash($rt)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['client_id'] !== $c['client_id'] || $row['revoked_at'] !== null || $row['refresh_expires'] < mcp_now()) {
            mcp_oauth_error(400, 'invalid_grant', 'Refresh token is invalid or expired. Reconnect the app.');
        }
        if (!mcp_user_ok($pdo, (int)$row['user_id'])) mcp_oauth_error(400, 'invalid_grant', 'Account is no longer an active administrator.');
        $pdo->prepare("UPDATE mcp_oauth_tokens SET revoked_at = ? WHERE id = ?")->execute([mcp_now(), $row['id']]);   // rotate
        mcp_oauth_json(200, mcp_issue_tokens($pdo, $c['client_id'], (int)$row['user_id']));
    }

    mcp_oauth_error(400, 'unsupported_grant_type');
}

/** POST /oauth/revoke — RFC 7009 (always 200). */
function mcp_oauth_revoke(PDO $pdo): void {
    $c = mcp_token_client($pdo);
    $t = (string)($_POST['token'] ?? '');
    if ($t !== '') {
        $h = mcp_hash($t);
        $pdo->prepare("UPDATE mcp_oauth_tokens SET revoked_at = COALESCE(revoked_at, ?) WHERE client_id = ? AND (access_hash = ? OR refresh_hash = ?)")
            ->execute([mcp_now(), $c['client_id'], $h, $h]);
    }
    mcp_oauth_json(200, []);
}

/** Is this user an active administrator right now? */
function mcp_user_ok(PDO $pdo, int $uid): bool {
    try {
        $st = $pdo->prepare("SELECT role, account_status FROM users WHERE id = ?");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
    }
    return $u && $u['role'] === 'administrator' && (($u['account_status'] ?? 'active') !== 'disabled');
}

/**
 * Validate the bearer token on an MCP request.
 * Returns ['user_id','user_name','client_id','client_name'] or null.
 */
function mcp_bearer(PDO $pdo): ?array {
    $h = mcp_auth_header();
    if (stripos($h, 'Bearer ') !== 0) return null;
    $tok = trim(substr($h, 7));
    if ($tok === '') return null;
    $st = $pdo->prepare("SELECT t.*, c.client_name FROM mcp_oauth_tokens t LEFT JOIN mcp_oauth_clients c ON c.client_id = t.client_id
                          WHERE t.access_hash = ? AND t.revoked_at IS NULL AND t.access_expires > ?");
    $st->execute([mcp_hash($tok), mcp_now()]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !mcp_user_ok($pdo, (int)$row['user_id'])) return null;
    $pdo->prepare("UPDATE mcp_oauth_tokens SET last_used_at = ? WHERE id = ?")->execute([mcp_now(), $row['id']]);
    $n = $pdo->prepare("SELECT first_name FROM users WHERE id = ?");
    $n->execute([(int)$row['user_id']]);
    return [
        'user_id'     => (int)$row['user_id'],
        'user_name'   => (string)($n->fetchColumn() ?: 'Admin'),
        'client_id'   => (string)$row['client_id'],
        'client_name' => (string)($row['client_name'] ?: 'AI app'),
    ];
}

function mcp_unauthorized(): void {
    mcp_oauth_json(401, ['error' => 'invalid_token', 'error_description' => 'Sign in again to reconnect.'], [
        'WWW-Authenticate: Bearer resource_metadata="' . MCP_ISSUER . '/.well-known/oauth-protected-resource", error="invalid_token"',
    ]);
}

// ── Consent page (api subdomain; asks for the admin email + password) ─────────

function mcp_html_page(string $title, string $body): void {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('X-Frame-Options: DENY');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($title) . '</title><style>'
       . 'body{margin:0;background:#3b2010;font-family:Georgia,serif;color:#3f2a14;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}'
       . '.card{background:#fdf4e3;border:2px solid #c9923a;border-radius:14px;max-width:440px;width:100%;padding:26px 24px;box-shadow:0 10px 30px rgba(0,0,0,.4)}'
       . 'h1{font-size:1.3rem;margin:0 0 12px;color:#3b2010}p,li{line-height:1.5;font-size:.96rem}ul{padding-left:20px;margin:6px 0}'
       . 'label{display:block;font-weight:700;font-size:.9rem;margin:12px 0 4px}input[type=email],input[type=password]{width:100%;box-sizing:border-box;font:inherit;padding:10px;border:1px solid #c9a978;border-radius:8px;background:#fff}'
       . '.row{display:flex;gap:10px;margin-top:18px}button{flex:1;font:inherit;font-weight:700;padding:12px;border-radius:8px;border:2px solid #3b2010;cursor:pointer}'
       . '.ok{background:#4a6741;color:#fff}.no{background:#fff;color:#3b2010}.small{font-size:.8rem;color:#6b5030}.err{background:#fbe3dc;border:1px solid #d9937f;border-radius:8px;padding:10px 12px;color:#7a2010}'
       . '</style></head><body><main class="card">' . $body . '</main></body></html>';
    exit;
}

/** GET shows the form; POST checks the admin email + password and issues a code. */
function mcp_oauth_authorize(PDO $pdo): void {
    $src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $q = [];
    foreach (['client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource'] as $k) {
        $q[$k] = mb_substr((string)($src[$k] ?? ''), 0, 1000);
    }

    // Problems with the client or redirect URI are shown here, never redirected (RFC 6749 4.1.2.1).
    $c = mcp_client_find($pdo, $q['client_id']);
    if (!$c) mcp_html_page('Connection error', '<h1>Connection error</h1><p>This app isn\'t registered with the farm. Remove the connector and add it again.</p>');
    if (!in_array($q['redirect_uri'], $c['redirect_uris'], true)) mcp_html_page('Connection error', '<h1>Connection error</h1><p>The return address doesn\'t match what the app registered.</p>');

    $back = function (array $params) use ($q): void {
        $params['state'] = $q['state'];
        $params['iss'] = MCP_ISSUER;
        $sep = str_contains($q['redirect_uri'], '?') ? '&' : '?';
        header('Location: ' . $q['redirect_uri'] . $sep . http_build_query(array_filter($params, fn($v) => $v !== '')), true, 302);
        exit;
    };
    if ($q['response_type'] !== 'code') $back(['error' => 'unsupported_response_type']);
    if ($q['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $q['code_challenge'])) {
        $back(['error' => 'invalid_request', 'error_description' => 'PKCE (S256) is required.']);
    }
    $res = rtrim($q['resource'], '/');
    if ($res !== '' && $res !== MCP_RESOURCE && $res !== MCP_ISSUER) $back(['error' => 'invalid_target']);

    $error = '';
    $email = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['decision'] ?? '') !== 'allow') $back(['error' => 'access_denied']);
        require_once dirname(__DIR__) . '/rate_limit.php';
        require_once dirname(__DIR__, 2) . '/admin/includes/audit.php';
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        $ekey  = strtolower($email);
        $ip    = bcrf_rl_ip();
        // Same buckets and limits as admin/login.php, so lockouts are shared.
        if (bcrf_rl_count('staff_ip', $ip, 900) >= 5) {
            $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
        } elseif ($ekey !== '' && bcrf_rl_count('staff_email', $ekey, 3600) >= 10) {
            $error = 'This account is temporarily locked after too many failed attempts. Try again in an hour.';
        } elseif ($email === '' || trim($pass) === '') {
            $error = 'Enter your email and password.';
        } else {
            $st = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            $valid = $u && password_verify(trim($pass), (string)($u['password'] ?? ''));
            if ($valid && mcp_user_ok($pdo, (int)$u['id'])) {
                bcrf_rl_clear('staff_ip', $ip);
                bcrf_rl_clear('staff_email', $ekey);
                $code = mcp_random('bcrf_code_');
                $pdo->prepare("INSERT INTO mcp_oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, expires_at) VALUES (?,?,?,?,?,?)")
                    ->execute([mcp_hash($code), $c['client_id'], (int)$u['id'], $q['redirect_uri'], $q['code_challenge'], mcp_now(MCP_CODE_TTL)]);
                $pdo->exec("DELETE FROM mcp_oauth_codes WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY");
                erp_audit($pdo, 'create', 'mcp_oauth_tokens', null,
                    'Connected AI app: ' . $c['client_name'] . ' (' . parse_url($q['redirect_uri'], PHP_URL_HOST) . ')',
                    mb_substr((string)($u['first_name'] ?? 'Admin'), 0, 60));
                $back(['code' => $code]);
            } elseif ($valid) {
                $error = 'Only an active administrator account can connect AI apps.';
            } else {
                bcrf_rl_add('staff_ip', $ip, 900);
                bcrf_rl_add('staff_email', $ekey, 3600);
                if (function_exists('erp_audit_security')) erp_audit_security($pdo, 'failed', $email);
                usleep(random_int(250000, 600000));
                $error = 'Wrong email or password.';
            }
        }
    }

    $hidden = '';
    foreach ($q as $k => $v) $hidden .= '<input type="hidden" name="' . $k . '" value="' . htmlspecialchars($v, ENT_QUOTES) . '">';
    $host = (string)parse_url($q['redirect_uri'], PHP_URL_HOST);
    mcp_html_page('Connect to Bat Cave Road Farm', '<h1>Connect ' . htmlspecialchars($c['client_name']) . ' to the farm system?</h1>'
        . ($error !== '' ? '<p class="err">' . htmlspecialchars($error) . '</p>' : '')
        . '<p>This sends you back to <strong>' . htmlspecialchars($host) . '</strong>. Only continue if you just started connecting from that app.</p>'
        . '<p>It will be able to:</p><ul>'
        . '<li>Read bookings, tickets, passes, inquiries, inventory, sales, recipes, blog posts, events and Site Info</li>'
        . '<li>Change stock counts and booking/inquiry statuses (confirming a booking emails the customer)</li>'
        . '<li>Create draft blog posts and events</li>'
        . '<li>Read and edit website files (each edit is backed up first)</li></ul>'
        . '<form method="post" action="" autocomplete="on">' . $hidden
        . '<label for="em">Admin email</label><input id="em" type="email" name="email" autocomplete="username" value="' . htmlspecialchars($email, ENT_QUOTES) . '" required>'
        . '<label for="pw">Password</label><input id="pw" type="password" name="password" autocomplete="current-password" required>'
        . '<div class="row"><button class="no" name="decision" value="deny" formnovalidate>Deny</button><button class="ok" name="decision" value="allow">Allow</button></div></form>'
        . '<p class="small">Administrators only. Everything it changes is recorded in the audit log. Disconnect any time in Admin → MCP connections.</p>');
}

}
