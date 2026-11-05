<?php // includes/gbp.php
/*
 * Google Business Profile posting (Nov 2026; API access approved 2026-11-05, case 2-8077000042009).
 *
 * - OAuth: admin/gbp_connect.php signs in once with the Google account that manages the
 *   profile; the refresh token is stored encrypted in the gbp_auth table (one row).
 * - Posting: admin/cron/send_social.php calls gbp_post() for queue rows whose platforms
 *   include "google". Uses the v4 localPosts endpoint (still the only way to post).
 *
 * Needs in bcrf-secrets.php (above docroot):  GBP_CLIENT_ID, GBP_CLIENT_SECRET
 * Google Cloud: enable Google My Business API, My Business Account Management API and
 * My Business Business Information API; OAuth client redirect URI
 *   https://api.batcaveroadfarm.com/gbp-callback.php  (relay: ModSecurity blocks Google's reply on
 *   the admin host; api/gbp-callback.php forwards code + state to admin/gbp_connect.php)
 */

const GBP_SCOPE       = 'https://www.googleapis.com/auth/business.manage';
const GBP_REDIRECT    = 'https://api.batcaveroadfarm.com/gbp-callback.php';
const GBP_SUMMARY_MAX = 1500;   // Google's limit for a post's text

function gbp_configured(): bool {
    return defined('GBP_CLIENT_ID') && defined('GBP_CLIENT_SECRET') && GBP_CLIENT_ID !== '' && GBP_CLIENT_SECRET !== '';
}

/** One-row table for the connection, plus the Google columns on social_queue. Safe to call often. */
function gbp_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gbp_auth (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            refresh_token_enc TEXT NULL,
            google_email VARCHAR(190) NULL,
            account_name VARCHAR(120) NULL,
            location_name VARCHAR(120) NULL,
            location_title VARCHAR(190) NULL,
            connected_at DATETIME NULL,
            last_ok_at DATETIME NULL,
            last_error VARCHAR(500) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['gbp_caption' => 'TEXT NULL', 'gbp_post_id' => 'VARCHAR(190) NULL'] as $col => $def) {
            if (!$pdo->query("SHOW COLUMNS FROM social_queue LIKE '{$col}'")->fetch()) {
                $pdo->exec("ALTER TABLE social_queue ADD COLUMN {$col} {$def}");
            }
        }
    } catch (Throwable $e) { error_log('[GBP] schema: ' . $e->getMessage()); }
}

function gbp_auth_row(PDO $pdo): array {
    gbp_schema($pdo);
    try { $r = $pdo->query("SELECT * FROM gbp_auth WHERE id = 1")->fetch(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { $r = false; }
    return $r ?: [];
}

/** Connected = signed in AND a location picked. */
function gbp_ready(PDO $pdo): bool {
    if (!gbp_configured()) return false;
    $a = gbp_auth_row($pdo);
    return !empty($a['refresh_token_enc']) && !empty($a['account_name']) && !empty($a['location_name']);
}

// ── Refresh token at rest: libsodium secretbox (or AES-256-GCM), key derived from the client secret ──
function gbp_key(): string { return hash('sha256', GBP_CLIENT_SECRET . '|bcrf-gbp-refresh', true); }
function gbp_encrypt(string $plain): string {
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, gbp_key()));
    }
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', gbp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'o:' . base64_encode($iv . $tag . $ct);
}
function gbp_decrypt(string $enc): ?string {
    $kind = substr($enc, 0, 2);
    $raw  = base64_decode(substr($enc, 2), true);
    if ($raw === false) return null;
    if ($kind === 's:' && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $n) return null;
        $out = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), gbp_key());
        return $out === false ? null : $out;
    }
    if ($kind === 'o:' && strlen($raw) > 28) {
        $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', gbp_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $out === false ? null : $out;
    }
    return null;
}

/** JSON-or-form HTTP call. Returns ['code', 'json', 'raw']. */
function gbp_http(string $method, string $url, ?array $body = null, ?string $bearer = null, bool $form = false): array {
    $headers = ['Accept: application/json'];
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
    $payload = null;
    if ($body !== null) {
        $payload   = $form ? http_build_query($body) : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === '' && curl_errno($ch)) $raw = json_encode(['error' => ['message' => curl_error($ch)]]);
    curl_close($ch);
    $json = json_decode($raw, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : [], 'raw' => $raw];
}

function gbp_err(array $r): string {
    $e = $r['json']['error'] ?? null;
    if (is_array($e)) return (string)($e['message'] ?? $e['status'] ?? 'HTTP ' . $r['code']);
    if (is_string($e)) return $e . (!empty($r['json']['error_description']) ? ': ' . $r['json']['error_description'] : '');
    return 'HTTP ' . $r['code'] . ' ' . substr($r['raw'], 0, 200);
}

function gbp_auth_url(string $state): string {
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => GBP_CLIENT_ID,
        'redirect_uri'  => GBP_REDIRECT,
        'response_type' => 'code',
        'scope'         => GBP_SCOPE . ' openid email',
        'access_type'   => 'offline',
        'prompt'        => 'consent',       // always hand back a refresh token
        'state'         => $state,
    ]);
}

/** Swap the sign-in code for tokens. Returns the token response or throws. */
function gbp_exchange_code(string $code): array {
    $r = gbp_http('POST', 'https://oauth2.googleapis.com/token', [
        'code' => $code, 'client_id' => GBP_CLIENT_ID, 'client_secret' => GBP_CLIENT_SECRET,
        'redirect_uri' => GBP_REDIRECT, 'grant_type' => 'authorization_code',
    ], null, true);
    if (empty($r['json']['access_token'])) throw new RuntimeException('Google sign-in failed: ' . gbp_err($r));
    return $r['json'];
}

/** Fresh access token from the stored refresh token (cached for this request). */
function gbp_access_token(PDO $pdo): string {
    static $tok = null, $exp = 0;
    if ($tok && time() < $exp - 60) return $tok;
    $a = gbp_auth_row($pdo);
    $refresh = !empty($a['refresh_token_enc']) ? gbp_decrypt((string)$a['refresh_token_enc']) : null;
    if (!$refresh) throw new RuntimeException('Google Business Profile is not connected — open Social Queue → Connect Google.');
    $r = gbp_http('POST', 'https://oauth2.googleapis.com/token', [
        'refresh_token' => $refresh, 'client_id' => GBP_CLIENT_ID, 'client_secret' => GBP_CLIENT_SECRET,
        'grant_type' => 'refresh_token',
    ], null, true);
    if (empty($r['json']['access_token'])) {
        $msg = gbp_err($r);
        if (($r['json']['error'] ?? '') === 'invalid_grant') $msg = 'Google sign-in expired or was revoked — reconnect Google in the Social Queue.';
        gbp_note($pdo, false, $msg);
        throw new RuntimeException($msg);
    }
    $tok = (string)$r['json']['access_token'];
    $exp = time() + (int)($r['json']['expires_in'] ?? 3600);
    return $tok;
}

function gbp_note(PDO $pdo, bool $ok, string $err = ''): void {
    try {
        if ($ok) $pdo->exec("UPDATE gbp_auth SET last_ok_at = NOW(), last_error = NULL WHERE id = 1");
        else $pdo->prepare("UPDATE gbp_auth SET last_error = ? WHERE id = 1")->execute([mb_substr($err, 0, 500)]);
    } catch (Throwable $e) {}
}

/**
 * Every location the signed-in Google account can manage: [['account','location','title','address'], ...].
 * Tries the current APIs (Account Management + Business Information) first. If those fail (e.g. their
 * quota is 0 — Nov 2026, project 166156148942), falls back to the older v4 lookups, which run on the
 * Google My Business API quota that posting uses. If both fail, the connect page offers manual IDs.
 */
function gbp_list_locations(PDO $pdo): array {
    $t = gbp_access_token($pdo);
    try {
        return gbp_list_locations_v1($t);
    } catch (RuntimeException $e) {
        try { return gbp_list_locations_v4($t); }
        catch (RuntimeException $e4) { throw new RuntimeException($e->getMessage() . ' — older lookup also failed: ' . $e4->getMessage()); }
    }
}

function gbp_list_locations_v1(string $t): array {
    $r = gbp_http('GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20', null, $t);
    if ($r['code'] !== 200) throw new RuntimeException('Could not list Google accounts: ' . gbp_err($r));
    $out = [];
    foreach ($r['json']['accounts'] ?? [] as $acc) {
        $l = gbp_http('GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $acc['name']
            . '/locations?pageSize=100&readMask=name,title,storefrontAddress', null, $t);
        if ($l['code'] !== 200) throw new RuntimeException('Could not list locations: ' . gbp_err($l));
        foreach ($l['json']['locations'] ?? [] as $loc) {
            $addr = $loc['storefrontAddress'] ?? [];
            $out[] = [
                'account'  => (string)$acc['name'],
                'location' => (string)$loc['name'],
                'title'    => (string)($loc['title'] ?? ''),
                'address'  => trim(implode(', ', array_filter([implode(' ', $addr['addressLines'] ?? []), $addr['locality'] ?? '', $addr['administrativeArea'] ?? '']))),
            ];
        }
    }
    return $out;
}

/** Older v4 lookups (same API as posting). Location names come back as accounts/X/locations/Y. */
function gbp_list_locations_v4(string $t): array {
    $r = gbp_http('GET', 'https://mybusiness.googleapis.com/v4/accounts?pageSize=20', null, $t);
    if ($r['code'] !== 200) throw new RuntimeException(gbp_err($r));
    $out = [];
    foreach ($r['json']['accounts'] ?? [] as $acc) {
        $l = gbp_http('GET', 'https://mybusiness.googleapis.com/v4/' . $acc['name'] . '/locations?pageSize=100', null, $t);
        if ($l['code'] !== 200) throw new RuntimeException(gbp_err($l));
        foreach ($l['json']['locations'] ?? [] as $loc) {
            $addr = $loc['address'] ?? [];
            $out[] = [
                'account'  => (string)$acc['name'],
                'location' => 'locations/' . basename((string)$loc['name']),
                'title'    => (string)($loc['locationName'] ?? ''),
                'address'  => trim(implode(', ', array_filter([implode(' ', $addr['addressLines'] ?? []), $addr['locality'] ?? '', $addr['administrativeArea'] ?? '']))),
            ];
        }
    }
    return $out;
}

/** Account names visible through v4 only (for the manual-ID fallback when no account ID is given). */
function gbp_v4_accounts(PDO $pdo): array {
    $r = gbp_http('GET', 'https://mybusiness.googleapis.com/v4/accounts?pageSize=20', null, gbp_access_token($pdo));
    if ($r['code'] !== 200) throw new RuntimeException('Could not look up the account ID: ' . gbp_err($r));
    return array_map(fn($a) => (string)$a['name'], $r['json']['accounts'] ?? []);
}

/**
 * Manual IDs: confirm Google accepts accounts/{a}/locations/{l} by listing its posts (posting API quota).
 * Returns true or throws with Google's message.
 */
function gbp_verify_ids(PDO $pdo, string $acctId, string $locId): bool {
    $r = gbp_http('GET', "https://mybusiness.googleapis.com/v4/accounts/{$acctId}/locations/{$locId}/localPosts?pageSize=1", null, gbp_access_token($pdo));
    if ($r['code'] !== 200) throw new RuntimeException('Google did not accept those IDs: ' . gbp_err($r));
    return true;
}

/** Google post text from a caption: links don't work in post text (the button carries the link). */
function gbp_caption_from(string $caption): string {
    $s = preg_replace('#\s*https?://\S+#u', '', $caption);
    $s = preg_replace("/\n{3,}/", "\n\n", trim((string)$s));
    return mb_substr($s, 0, GBP_SUMMARY_MAX);
}

/**
 * Google posts take JPG/PNG photos only; most site images are WebP (Google answers those with a bare
 * "Internal error encountered"). Makes a cached JPG copy under /img/social-cache/ and returns its public
 * URL, or null if the image can't be read (the post then goes out without a photo).
 */
function gbp_photo_url(?string $url): ?string {
    if (!$url) return null;
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (preg_match('#\.(jpe?g|png)$#i', $path)) return $url;
    $docroot = dirname(__DIR__);
    $src = realpath($docroot . rawurldecode($path));
    if (!$src || !str_starts_with($src, realpath($docroot) . '/') || !is_file($src)) return null;
    $rel = '/img/social-cache/gbp-' . substr(sha1($src . '|' . filemtime($src)), 0, 16) . '.jpg';
    $dst = $docroot . $rel;
    if (!is_file($dst)) {
        $im = @imagecreatefromstring((string)file_get_contents($src));
        if (!$im) return null;
        // Flatten transparency onto white; Google wants at least 250px on each side.
        $w = imagesx($im); $h = imagesy($im);
        $scale = max(1, 250 / max(1, min($w, $h)));
        $nw = (int)ceil($w * $scale); $nh = (int)ceil($h * $scale);
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
        $ok = imagejpeg($out, $dst, 88);
        imagedestroy($im); imagedestroy($out);
        if (!$ok) return null;
    }
    $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
    $host   = parse_url($url, PHP_URL_HOST) ?: 'batcaveroadfarm.com';
    return "{$scheme}://{$host}{$rel}";
}

/**
 * Publish one "What's new" post. $link becomes the "Learn more" button; $image must be a public URL.
 * Photos are converted to JPG for Google. If Google fails with a server error while a photo is attached,
 * the post is retried once without the photo (text + button still go out).
 * Returns the post's resource name; throws with Google's message on failure.
 */
function gbp_post(PDO $pdo, string $summary, ?string $link, ?string $image): string {
    $a = gbp_auth_row($pdo);
    $acctId = preg_replace('#^accounts/#', '', (string)($a['account_name'] ?? ''));
    $locId  = preg_replace('#^locations/#', '', (string)($a['location_name'] ?? ''));
    if ($acctId === '' || $locId === '') throw new RuntimeException('No Google location picked — open Social Queue → Connect Google.');

    $body = ['languageCode' => 'en-US', 'topicType' => 'STANDARD', 'summary' => mb_substr(trim($summary), 0, GBP_SUMMARY_MAX)];
    if ($link) $body['callToAction'] = ['actionType' => 'LEARN_MORE', 'url' => $link];
    $photo = gbp_photo_url($image);
    if ($photo) $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $photo]];

    $endpoint = "https://mybusiness.googleapis.com/v4/accounts/{$acctId}/locations/{$locId}/localPosts";
    $r = gbp_http('POST', $endpoint, $body, gbp_access_token($pdo));
    if (empty($r['json']['name']) && $photo && ($r['code'] >= 500 || $r['code'] === 400)) {
        error_log('[GBP] post with photo failed (' . gbp_err($r) . ') — retrying without photo: ' . $photo);
        unset($body['media']);
        $r = gbp_http('POST', $endpoint, $body, gbp_access_token($pdo));
    }
    if (empty($r['json']['name'])) {
        $msg = gbp_err($r);
        error_log('[GBP] post failed: HTTP ' . $r['code'] . ' ' . substr($r['raw'], 0, 500));
        gbp_note($pdo, false, $msg);
        throw new RuntimeException($msg);
    }
    gbp_note($pdo, true);
    return (string)$r['json']['name'];
}

/** Queue rows that asked for Google but didn't get there (for the retry list on the connect page). */
function gbp_failed_rows(PDO $pdo): array {
    gbp_schema($pdo);
    try {
        return $pdo->query("SELECT id, content_type, content_id, gbp_caption, last_error, processed_at FROM social_queue
            WHERE platforms LIKE '%google%' AND gbp_post_id IS NULL AND status IN ('partial','failed')
              AND gbp_caption IS NOT NULL AND gbp_caption <> '' AND processed_at >= NOW() - INTERVAL 30 DAY
            ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Re-send one queue row to Google only (Facebook/Instagram untouched). Returns the post name or throws. */
function gbp_retry_row(PDO $pdo, int $id): string {
    $st = $pdo->prepare("SELECT * FROM social_queue WHERE id = ?");
    $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q || trim((string)($q['gbp_caption'] ?? '')) === '') throw new RuntimeException('That queued post has no Google text.');
    if (!empty($q['gbp_post_id'])) throw new RuntimeException('That post is already on Google.');
    $type  = in_array($q['content_type'], ['recipe', 'animal'], true) ? $q['content_type'] : 'blog';
    $table = $type === 'recipe' ? 'recipes' : ($type === 'animal' ? 'animals' : 'blog_posts');
    $rs = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
    $rs->execute([(int)$q['content_id']]);
    $rec = $rs->fetch(PDO::FETCH_ASSOC);
    if (!$rec) throw new RuntimeException('The recipe/post/animal no longer exists.');
    require_once __DIR__ . '/social.php';
    if (!function_exists('bcrf_utm_url')) require_once __DIR__ . '/ga.php';
    $base = defined('BCRF_SOCIAL_BASE_URL') ? BCRF_SOCIAL_BASE_URL : 'https://batcaveroadfarm.com';
    $img  = null;
    foreach (['feature_image', 'image_url', 'featured_image', 'image', 'photo_url'] as $k) {
        if (!empty($rec[$k])) { $u = (string)$rec[$k]; $img = preg_match('#^https?://#', $u) ? $u : $base . ($u[0] === '/' ? '' : '/') . $u; break; }
    }
    $link = bcrf_utm_url(social_permalink($type, $rec), 'google', 'organic', 'gbp-' . $type);
    $name = gbp_post($pdo, (string)$q['gbp_caption'], $link, $img);
    $done = !empty($q['fb_post_id']) && (empty($q['ig_media_id']) === false || !defined('IG_USER_ID') || IG_USER_ID === '');
    $pdo->prepare("UPDATE social_queue SET gbp_post_id = ?, status = ?, last_error = NULL WHERE id = ?")
        ->execute([$name, $done ? 'sent' : $q['status'], $id]);
    return $name;
}
