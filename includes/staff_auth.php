<?php // includes/staff_auth.php
/*
 * Two-step sign-in for staff (Farm OS Phase 0, Nov 2026). Authenticator-app codes (TOTP, RFC 6238:
 * 6 digits, 30 seconds, SHA-1, works with Google Authenticator, 1Password, Authy, Microsoft
 * Authenticator) plus 10 single-use backup codes.
 *
 * Who is asked for a code:
 *   - any staff member who has turned it on, and
 *   - every administrator once "Require two-step sign-in for administrators" is on (site_settings
 *     mfa_required_admin = 1). An administrator without it is walked through setup right after the password.
 *
 * Where it's checked:
 *   - admin/login.php: after a correct password, staff who need a code are sent to admin/two_step.php in a
 *     "pending" state; nothing in the session says they're signed in until the code is right.
 *   - check_admin() in config.php calls bcrf_staff_mfa_gate(): a session from before this existed (or from
 *     the public account login) is moved to pending the same way.
 *   - includes/auth.php: the public account login gives such staff a customer session only.
 *
 * Secrets are stored encrypted. The key is MFA_KEY in bcrf-secrets.php if set, otherwise a key file made on
 * first use in the home folder, outside the website: ~/bcrf-mfa.key.
 *
 * Locked out (lost phone and backup codes): in cPanel → File Manager, create an empty file named
 * bcrf-mfa-off.txt in the home folder (next to bcrf-secrets.php). Two-step sign-in is skipped while it
 * exists; sign in, reset the code on admin/two_step.php, then delete the file.
 *
 * Columns come from admin/migrations/0005_staff_two_step.php (users.totp_*, users.mfa_recovery_json).
 */

if (!function_exists('bcrf_totp_code')) {

// ── TOTP ─────────────────────────────────────────────────────────────────────────────────────────

function bcrf_base32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function bcrf_base32_decode(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

/** The 6-digit code for a time step (floor(unix time / 30)). */
function bcrf_totp_code(string $secretB32, int $step): string {
    $mac = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF), bcrf_base32_decode($secretB32), true);
    $o = ord($mac[19]) & 0x0F;
    $n = ((ord($mac[$o]) & 0x7F) << 24) | (ord($mac[$o + 1]) << 16) | (ord($mac[$o + 2]) << 8) | ord($mac[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Check a code, allowing one step either side for a phone clock that's a little off. Returns the step that
 * matched, or null. Pass $lastStep so a code that was already used can't be used again.
 */
function bcrf_totp_verify(string $secretB32, string $code, ?int $lastStep = null, ?int $now = null): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $t = intdiv($now ?? time(), 30);
    foreach ([0, -1, 1] as $d) {
        $s = $t + $d;
        if ($lastStep !== null && $s <= $lastStep) continue;
        if (hash_equals(bcrf_totp_code($secretB32, $s), $code)) return $s;
    }
    return null;
}

function bcrf_totp_new_secret(): string {
    return bcrf_base32_encode(random_bytes(20));   // 32 characters
}

function bcrf_totp_uri(string $secretB32, string $account): string {
    $issuer = function_exists('bcrf_farm_name') ? bcrf_farm_name() : 'Farm OS';
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query([
        'secret' => $secretB32, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
    ], '', '&', PHP_QUERY_RFC3986);
}

// ── Secret at rest ───────────────────────────────────────────────────────────────────────────────

function bcrf_mfa_home(): string {
    return dirname(__DIR__, 2);   // /home2/<user>: where bcrf-secrets.php lives, outside public_html
}

/** 32-byte key, or '' if none can be found or made (then setup refuses rather than store a plain secret). */
function bcrf_mfa_key(): string {
    static $k = null;
    if ($k !== null) return $k;
    $fromSecrets = defined('BCRF_MFA_KEY') ? (string)BCRF_MFA_KEY : (string)($GLOBALS['bcrf']['MFA_KEY'] ?? '');
    if ($fromSecrets !== '') return $k = hash('sha256', 'bcrf-mfa|' . $fromSecrets, true);
    $file = bcrf_mfa_home() . '/bcrf-mfa.key';
    if (is_file($file)) {
        $raw = base64_decode(trim((string)@file_get_contents($file)), true);
        return $k = (is_string($raw) && strlen($raw) === 32) ? $raw : '';
    }
    $raw = random_bytes(32);
    if (@file_put_contents($file, base64_encode($raw) . "\n", LOCK_EX) === false) return $k = '';
    @chmod($file, 0600);
    return $k = $raw;
}

function bcrf_mfa_encrypt(string $plain): string {
    $key = bcrf_mfa_key();
    if ($key === '') throw new RuntimeException('No key for two-step secrets: the home folder is not writable. Add MFA_KEY to bcrf-secrets.php.');
    if (function_exists('sodium_crypto_secretbox')) {
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's:' . base64_encode($n . sodium_crypto_secretbox($plain, $n, $key));
    }
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'o:' . base64_encode($iv . $tag . $ct);
}

function bcrf_mfa_decrypt(string $enc): ?string {
    $key = bcrf_mfa_key();
    if ($key === '') return null;
    $raw = base64_decode(substr($enc, 2), true);
    if ($raw === false) return null;
    if (str_starts_with($enc, 's:') && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $n) return null;
        $out = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
        return $out === false ? null : $out;
    }
    if (str_starts_with($enc, 'o:') && strlen($raw) > 28) {
        $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $out === false ? null : $out;
    }
    return null;
}

// ── Backup codes ─────────────────────────────────────────────────────────────────────────────────

/** 10 new codes like "K7QD-M2XF" (shown once) and the hashes to store. */
function bcrf_mfa_new_recovery(): array {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O or 1/I
    $plain = [];
    for ($i = 0; $i < 10; $i++) {
        $c = '';
        for ($j = 0; $j < 8; $j++) $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        $plain[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return [$plain, array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $plain)];
}

/** If $code is one of the stored backup codes, returns the hashes left after using it; else null. */
function bcrf_mfa_use_recovery(string $code, array $hashes): ?array {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (strlen($code) !== 8) return null;
    foreach ($hashes as $i => $h) {
        if (password_verify($code, (string)$h)) { unset($hashes[$i]); return array_values($hashes); }
    }
    return null;
}

// ── Policy ───────────────────────────────────────────────────────────────────────────────────────

function bcrf_staff_roles(): array {
    return ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'];
}

/** Break-glass: ~/bcrf-mfa-off.txt exists. */
function bcrf_mfa_off(): bool {
    return is_file(bcrf_mfa_home() . '/bcrf-mfa-off.txt');
}

function bcrf_mfa_required_admin(PDO $pdo): bool {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'mfa_required_admin'");
        $st->execute();
        return (string)$st->fetchColumn() === '1';
    } catch (Throwable $e) { return false; }
}

/** The two-step columns for a user, or null if the user doesn't exist. Throws if the columns are missing. */
function bcrf_mfa_row(PDO $pdo, int $uid): ?array {
    $st = $pdo->prepare("SELECT id, email, first_name, last_name, role, account_status, totp_secret_enc, totp_enabled_at, totp_last_step, mfa_recovery_json
                         FROM users WHERE id = ?");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** True when the 0005 columns exist (cached per request). */
function bcrf_mfa_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        return $ready = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_enabled_at'")->fetchColumn() > 0;
    } catch (Throwable $e) { return $ready = false; }
}

/** 'code' (has it on), 'setup' (must set it up now) or '' (no code needed) for a users row. */
function bcrf_mfa_needed(PDO $pdo, array $u): string {
    if (!bcrf_mfa_ready($pdo) || bcrf_mfa_off()) return '';
    if (!in_array((string)($u['role'] ?? ''), bcrf_staff_roles(), true)) return '';
    $row = isset($u['totp_enabled_at']) ? $u : (bcrf_mfa_row($pdo, (int)$u['id']) ?? []);
    if (!empty($row['totp_enabled_at'])) return 'code';
    if (($row['role'] ?? '') === 'administrator' && bcrf_mfa_required_admin($pdo)) return 'setup';
    return '';
}

// ── Session ──────────────────────────────────────────────────────────────────────────────────────

/** Sign a staff member in (same session values admin/login.php always set). */
function bcrf_staff_session_start(array $u): void {
    session_regenerate_id(true);
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_setup_secret']);
    $_SESSION['user_id']    = (int)$u['id'];
    $_SESSION['user_role']  = (string)$u['role'];
    $_SESSION['user_name']  = (string)($u['first_name'] ?? '');
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['mfa_ok']     = true;
}

/** Hold a sign-in until the code is entered (or two-step is set up). Nothing here counts as signed in. */
function bcrf_staff_session_pend(array $u, string $mode, string $dest): void {
    session_regenerate_id(true);
    unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'], $_SESSION['mfa_ok'], $_SESSION['mfa_setup_secret']);
    $_SESSION['mfa_pending'] = ['uid' => (int)$u['id'], 'mode' => $mode, 'dest' => $dest, 'exp' => time() + 600];
}

/** The pending sign-in, if it's still fresh. */
function bcrf_staff_pending(): ?array {
    $p = $_SESSION['mfa_pending'] ?? null;
    if (!is_array($p) || (int)($p['exp'] ?? 0) < time()) { unset($_SESSION['mfa_pending']); return null; }
    return $p;
}

/** Only same-site paths are allowed as the place to go after signing in. */
function bcrf_staff_safe_dest(string $d, string $fallback = '/admin/admin.php'): string {
    return (preg_match('#^/(?!/)[^\s]*$#', $d) && !str_contains($d, '\\')) ? $d : $fallback;
}

/**
 * Called by check_admin() (config.php) once the role is a staff role. A session that hasn't passed two-step
 * sign-in but needs it is moved to pending and sent to the code page. A PIN session on a shared device follows
 * bcrf_pin_gate() instead (register, gate and waivers only; locks after 5 minutes without use).
 */
function bcrf_staff_mfa_gate(): void {
    global $pdo;
    // PIN sign-in on a shared device (includes/pin_auth.php, Nov 13, 2026): its own rules instead of two-step
    if (!empty($_SESSION['pin'])) {
        require_once __DIR__ . '/pin_auth.php';
        bcrf_pin_gate();
        return;
    }
    if (!empty($_SESSION['mfa_ok']) || !($pdo instanceof PDO)) return;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    try {
        $u = $uid ? bcrf_mfa_row($pdo, $uid) : null;
        $need = $u ? bcrf_mfa_needed($pdo, $u) : '';
    } catch (Throwable $e) {
        if (!bcrf_mfa_ready($pdo)) { $_SESSION['mfa_ok'] = true; return; }   // not set up yet
        error_log('[staff_auth] gate: ' . $e->getMessage());
        $need = 'code';                                                        // can't check: ask, don't wave through
        $u = ['id' => $uid, 'role' => (string)($_SESSION['user_role'] ?? '')];
    }
    if ($need === '') { $_SESSION['mfa_ok'] = true; return; }
    $dest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? (string)($_SERVER['REQUEST_URI'] ?? '') : '';
    bcrf_staff_session_pend($u, $need, bcrf_staff_safe_dest($dest));
    if (!headers_sent()) header('Location: /admin/two_step.php');
    exit;
}

/** Audit a two-step event (audit_events). */
function bcrf_mfa_audit(PDO $pdo, string $action, array $u, string $label, ?int $actorId = null): void {
    if (!function_exists('bcrf_audit_event')) {
        $f = dirname(__DIR__) . '/admin/includes/audit.php';
        if (is_file($f)) require_once $f;
    }
    if (!function_exists('bcrf_audit_event')) return;
    $who = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
    bcrf_audit_event($pdo, [
        'actor_type' => 'staff', 'actor_id' => $actorId ?? (int)($u['id'] ?? 0) ?: null, 'actor_label' => $who,
        'ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
        'action' => $action, 'object_type' => 'users', 'object_id' => (string)($u['id'] ?? ''), 'object_label' => $label,
    ]);
}

}
