<?php
// includes/adapter_pinterest.php — Pinterest API v5 (Part B, Nov 5, 2026). The only place that knows Pinterest's
// addresses, sign-in and token handling. Used by admin/pinterest_connect.php and includes/pinterest_send.php.
//
// Credentials (bcrf-secrets.php): PINTEREST_APP_ID, PINTEREST_APP_SECRET; optional PINTEREST_REDIRECT_URI.
// Sign-in: authorization code flow (www.pinterest.com/oauth → /v5/oauth/token with HTTP Basic client auth and
// continuous_refresh=true). Access tokens last ~30 days; Pinterest rotates the refresh token on every refresh,
// so the new one is always saved. Tokens are stored encrypted (key derived from the app secret), never logged.
// Environments: sandbox (api-sandbox.pinterest.com, used while the app has Trial access; Pins are visible only
// to you) and production (api.pinterest.com, after Standard access). Tokens belong to one environment.
// Tables: pinterest_auth, pinterest_boards (migration 0008). Times UTC.

if (!function_exists('bcrf_pinapi_configured')) {

require_once __DIR__ . '/adapter_http.php';

define('BCRF_PINAPI_SCOPES', 'boards:read,boards:write,pins:read,pins:write,user_accounts:read');

function bcrf_pinapi_configured(): bool {
    return defined('PINTEREST_APP_ID') && PINTEREST_APP_ID !== '' && defined('PINTEREST_APP_SECRET') && PINTEREST_APP_SECRET !== '';
}

/** True once migration 0008 has run. */
function bcrf_pinapi_tables(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('pinterest_auth', 'pinterest_boards')")->fetchColumn();
        return $ok = ($n === 2);
    } catch (Throwable $e) { return $ok = false; }
}

function bcrf_pinapi_redirect_uri(): string {
    return defined('PINTEREST_REDIRECT_URI') && PINTEREST_REDIRECT_URI !== '' ? PINTEREST_REDIRECT_URI : 'https://admin.batcaveroadfarm.com/pinterest_connect.php';
}

function bcrf_pinapi_base(string $env): string {
    if (($tb = getenv('BCRF_PINTEREST_TEST_BASE')) !== false && $tb !== '') return rtrim($tb, '/');   // test hook only
    return $env === 'production' ? 'https://api.pinterest.com/v5' : 'https://api-sandbox.pinterest.com/v5';
}

/** A site setting (site_settings), or $default. */
function bcrf_pin_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}
function bcrf_pin_setting_save(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}

/** The connection row, or []. */
function bcrf_pinapi_auth(PDO $pdo): array {
    if (!bcrf_pinapi_tables($pdo)) return [];
    try { return $pdo->query("SELECT * FROM pinterest_auth WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}

/** Connected and usable (signed in, not waiting for a reconnect). */
function bcrf_pinapi_ready(PDO $pdo): bool {
    if (!bcrf_pinapi_configured()) return false;
    $a = bcrf_pinapi_auth($pdo);
    return !empty($a['refresh_token_enc']) && !(int)$a['needs_reconnect'];
}

/** Environment for the next sign-in (the connected one once signed in). */
function bcrf_pinapi_env(PDO $pdo): string {
    $a = bcrf_pinapi_auth($pdo);
    if (!empty($a['refresh_token_enc'])) return (string)$a['env'];
    return bcrf_pin_setting($pdo, 'pinterest_env', 'sandbox') === 'production' ? 'production' : 'sandbox';
}

// ── Tokens at rest: libsodium secretbox (or AES-256-GCM), key derived from the app secret ──
function bcrf_pinapi_key(): string { return hash('sha256', PINTEREST_APP_SECRET . '|bcrf-pinterest-tokens', true); }
function bcrf_pinapi_encrypt(string $plain): string {
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, bcrf_pinapi_key()));
    }
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', bcrf_pinapi_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'o:' . base64_encode($iv . $tag . $ct);
}
function bcrf_pinapi_decrypt(?string $enc): ?string {
    if (!$enc) return null;
    $kind = substr($enc, 0, 2);
    $raw  = base64_decode(substr($enc, 2), true);
    if ($raw === false) return null;
    if ($kind === 's:' && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $n) return null;
        $out = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), bcrf_pinapi_key());
        return $out === false ? null : $out;
    }
    if ($kind === 'o:' && strlen($raw) > 28) {
        $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', bcrf_pinapi_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $out === false ? null : $out;
    }
    return null;
}

/** Pinterest's sign-in page for this app. */
function bcrf_pinapi_auth_url(string $state): string {
    return 'https://www.pinterest.com/oauth/?' . http_build_query([
        'client_id' => PINTEREST_APP_ID, 'redirect_uri' => bcrf_pinapi_redirect_uri(),
        'response_type' => 'code', 'scope' => BCRF_PINAPI_SCOPES, 'state' => $state,
    ]);
}

/** Readable error from a Pinterest response. */
function bcrf_pinapi_error(array $r): string {
    $j = $r['json'] ?? [];
    $m = (string)($j['message'] ?? ($j['error_description'] ?? ($j['error'] ?? '')));
    if ($m === '' && ($r['error'] ?? '') !== '') $m = (string)$r['error'];
    if ($m === '') $m = substr((string)($r['body'] ?? ''), 0, 200);
    return 'Pinterest ' . ((int)$r['status'] ?: 'no response') . ($m !== '' ? ': ' . $m : '');
}

/** POST /oauth/token with HTTP Basic client auth. */
function bcrf_pinapi_token_request(string $env, array $form): array {
    return bcrf_http('POST', bcrf_pinapi_base($env) . '/oauth/token', [
        'form' => $form, 'timeout' => 30,
        'headers' => ['Authorization: Basic ' . base64_encode(PINTEREST_APP_ID . ':' . PINTEREST_APP_SECRET),
                      'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
}

/** Save a token response (both tokens: the refresh token rotates). */
function bcrf_pinapi_store_tokens(PDO $pdo, array $t, ?string $env = null, ?int $uid = null, bool $new = false): void {
    $now = time();
    $acc = bcrf_pinapi_encrypt((string)$t['access_token']);
    $accExp = gmdate('Y-m-d H:i:s', $now + max(60, (int)($t['expires_in'] ?? 2592000)));
    $ref = !empty($t['refresh_token']) ? bcrf_pinapi_encrypt((string)$t['refresh_token']) : null;
    $refExp = !empty($t['refresh_token']) ? gmdate('Y-m-d H:i:s', $now + max(60, (int)($t['refresh_token_expires_in'] ?? 5184000))) : null;
    if ($new) {
        $pdo->prepare("REPLACE INTO pinterest_auth (id, env, access_token_enc, access_expires_at, refresh_token_enc, refresh_expires_at, scope,
                           connected_at, connected_by, last_ok_at, last_error, needs_reconnect, updated_at)
                       VALUES (1, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), NULL, 0, UTC_TIMESTAMP())")
            ->execute([$env ?? 'sandbox', $acc, $accExp, $ref, $refExp, mb_substr((string)($t['scope'] ?? ''), 0, 255), $uid]);
        return;
    }
    $pdo->prepare("UPDATE pinterest_auth SET access_token_enc = ?, access_expires_at = ?,
                       refresh_token_enc = COALESCE(?, refresh_token_enc), refresh_expires_at = COALESCE(?, refresh_expires_at),
                       last_ok_at = UTC_TIMESTAMP(), last_error = NULL, needs_reconnect = 0, updated_at = UTC_TIMESTAMP() WHERE id = 1")
        ->execute([$acc, $accExp, $ref, $refExp]);
}

/** Note a problem on the connection (shown on the Connect page and the Dashboard). */
function bcrf_pinapi_note(PDO $pdo, string $err, bool $reconnect = false): void {
    try {
        $pdo->prepare("UPDATE pinterest_auth SET last_error = ?, needs_reconnect = IF(?, 1, needs_reconnect), updated_at = UTC_TIMESTAMP() WHERE id = 1")
            ->execute([mb_substr($err, 0, 500), (int)$reconnect]);
    } catch (Throwable $e) {}
}

/** Swap the code from the sign-in page for tokens, then read the account name. Throws on failure. */
function bcrf_pinapi_exchange_code(PDO $pdo, string $code, string $env, ?int $uid): array {
    $r = bcrf_pinapi_token_request($env, ['grant_type' => 'authorization_code', 'code' => $code,
                                          'redirect_uri' => bcrf_pinapi_redirect_uri(), 'continuous_refresh' => 'true']);
    if ($r['status'] !== 200 || empty($r['json']['access_token'])) throw new RuntimeException(bcrf_pinapi_error($r));
    bcrf_pinapi_store_tokens($pdo, $r['json'], $env, $uid, true);
    $u = bcrf_pinapi_request($pdo, 'GET', '/user_account');
    if ($u['status'] === 200) {
        $pdo->prepare("UPDATE pinterest_auth SET username = ?, account_type = ? WHERE id = 1")
            ->execute([mb_substr((string)($u['json']['username'] ?? ''), 0, 120), mb_substr((string)($u['json']['account_type'] ?? ''), 0, 40)]);
    }
    return bcrf_pinapi_auth($pdo);
}

/**
 * A valid access token, refreshing it when it has under 10 minutes left (or when $force). Only one request
 * refreshes at a time (database lock), because the old refresh token stops working once it is used.
 * Throws when signed out or when Pinterest refuses the refresh (then needs_reconnect is set).
 */
function bcrf_pinapi_access_token(PDO $pdo, bool $force = false): string {
    if (!bcrf_pinapi_configured()) throw new RuntimeException('Pinterest app keys are missing from bcrf-secrets.php.');
    $a = bcrf_pinapi_auth($pdo);
    if (empty($a['refresh_token_enc'])) throw new RuntimeException('Pinterest is not connected.');
    if ((int)$a['needs_reconnect']) throw new RuntimeException('Pinterest needs to be reconnected (Settings › Pinterest).');
    $fresh = fn(array $a) => !$force && !empty($a['access_token_enc']) && $a['access_expires_at'] && strtotime($a['access_expires_at'] . ' UTC') > time() + 600;
    if ($fresh($a) && ($t = bcrf_pinapi_decrypt($a['access_token_enc']))) return $t;

    if ((int)$pdo->query("SELECT GET_LOCK('bcrf_pinterest_token', 20)")->fetchColumn() !== 1) throw new RuntimeException('Pinterest sign-in is busy; try again in a minute.');
    try {
        $a = bcrf_pinapi_auth($pdo);   // someone may have refreshed while we waited
        if ($fresh($a) || ($force && $a['updated_at'] && strtotime($a['updated_at'] . ' UTC') > time() - 30)) {
            if ($t = bcrf_pinapi_decrypt($a['access_token_enc'])) return $t;
        }
        $rt = bcrf_pinapi_decrypt($a['refresh_token_enc']);
        if (!$rt) { bcrf_pinapi_note($pdo, 'Stored sign-in could not be read (app secret changed?). Reconnect.', true); throw new RuntimeException('Pinterest needs to be reconnected.'); }
        $r = bcrf_pinapi_token_request((string)$a['env'], ['grant_type' => 'refresh_token', 'refresh_token' => $rt]);
        if ($r['status'] === 200 && !empty($r['json']['access_token'])) {
            bcrf_pinapi_store_tokens($pdo, $r['json']);
            return (string)$r['json']['access_token'];
        }
        $msg = bcrf_pinapi_error($r);
        bcrf_pinapi_note($pdo, 'Token refresh failed: ' . $msg, in_array($r['status'], [400, 401, 403], true));
        throw new RuntimeException('Pinterest sign-in expired: ' . $msg);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bcrf_pinterest_token')");
    }
}

/**
 * Call the API. $path like '/boards'. $opts: query, json, timeout. On a 401 the token is refreshed once and the
 * call repeated. Returns bcrf_http()'s shape. Never logs tokens.
 */
function bcrf_pinapi_request(PDO $pdo, string $method, string $path, array $opts = []): array {
    $a = bcrf_pinapi_auth($pdo);
    $env = (string)($a['env'] ?? 'sandbox');
    $call = function (string $token) use ($method, $path, $opts, $env): array {
        $o = ['headers' => ['Authorization: Bearer ' . $token, 'Accept: application/json'], 'timeout' => (int)($opts['timeout'] ?? 30)];
        if (isset($opts['query'])) $o['query'] = $opts['query'];
        if (isset($opts['json']))  $o['json']  = $opts['json'];
        return bcrf_http($method, bcrf_pinapi_base($env) . $path, $o);
    };
    $r = $call(bcrf_pinapi_access_token($pdo));
    if ($r['status'] === 401) $r = $call(bcrf_pinapi_access_token($pdo, true));
    if ($r['status'] >= 200 && $r['status'] < 300) {
        try { $pdo->exec("UPDATE pinterest_auth SET last_ok_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = 1"); } catch (Throwable $e) {}
    } elseif ($r['status'] === 401) {
        bcrf_pinapi_note($pdo, bcrf_pinapi_error($r), true);
    }
    return $r;
}

/** Read every board (paged) into pinterest_boards. Returns the active boards. Throws on failure. */
function bcrf_pinapi_sync_boards(PDO $pdo): array {
    $env = (string)(bcrf_pinapi_auth($pdo)['env'] ?? 'sandbox');
    $seen = []; $bookmark = null; $pages = 0;
    $up = $pdo->prepare("INSERT INTO pinterest_boards (board_id, env, name, privacy, owner, is_active, synced_at) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE env = VALUES(env), name = VALUES(name), privacy = VALUES(privacy), owner = VALUES(owner), is_active = 1, synced_at = UTC_TIMESTAMP()");
    do {
        $q = ['page_size' => 100] + ($bookmark ? ['bookmark' => $bookmark] : []);
        $r = bcrf_pinapi_request($pdo, 'GET', '/boards', ['query' => $q]);
        if ($r['status'] !== 200) throw new RuntimeException(bcrf_pinapi_error($r));
        foreach ((array)($r['json']['items'] ?? []) as $b) {
            $id = (string)($b['id'] ?? '');
            if (!preg_match('/^\d{1,64}$/', $id)) continue;
            $up->execute([$id, $env, mb_substr((string)($b['name'] ?? ''), 0, 190), mb_substr((string)($b['privacy'] ?? ''), 0, 20),
                          mb_substr((string)($b['owner']['username'] ?? ''), 0, 120)]);
            $seen[] = $id;
        }
        $bookmark = $r['json']['bookmark'] ?? null;
    } while ($bookmark && ++$pages < 20);
    if ($seen) {
        $in = implode(',', array_fill(0, count($seen), '?'));
        $pdo->prepare("UPDATE pinterest_boards SET is_active = 0 WHERE board_id NOT IN ($in)")->execute($seen);
    } else {
        $pdo->exec("UPDATE pinterest_boards SET is_active = 0");
    }
    return bcrf_pinapi_boards($pdo);
}

/** Active boards from the last sync for the connected environment: [board_id => name]. */
function bcrf_pinapi_boards(PDO $pdo): array {
    if (!bcrf_pinapi_tables($pdo)) return [];
    $env = (string)(bcrf_pinapi_auth($pdo)['env'] ?? 'sandbox');
    $st = $pdo->prepare("SELECT board_id, name FROM pinterest_boards WHERE env = ? AND is_active = 1 ORDER BY name");
    $st->execute([$env]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Create an image Pin from a local JPEG (sent as base64, so hotlink/CDN rules never matter). */
function bcrf_pinapi_create_pin(PDO $pdo, string $boardId, array $f, string $jpegFile): array {
    $data = @file_get_contents($jpegFile);
    if ($data === false || $data === '') throw new RuntimeException('Pin image file is missing.');
    $body = ['board_id' => $boardId, 'title' => (string)$f['title'], 'description' => (string)$f['description'],
             'link' => (string)$f['link'], 'alt_text' => (string)$f['alt_text'],
             'media_source' => ['source_type' => 'image_base64', 'content_type' => 'image/jpeg', 'data' => base64_encode($data)]];
    return bcrf_pinapi_request($pdo, 'POST', '/pins', ['json' => $body, 'timeout' => 60]);
}

}
