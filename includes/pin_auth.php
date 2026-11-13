<?php // includes/pin_auth.php
/*
 * PIN sign-in on shared devices, and manager approval by PIN (Farm OS spec 03; Nov 13, 2026; migration 0058).
 *
 * Shared devices
 *   An Owner opens admin/pin_settings.php on the register or gate tablet and taps "Set up this device". The device gets a
 *   long random cookie (bcrf_device, ~13 months, httpOnly); only its SHA-256 is stored in staff_devices. Revoking the row
 *   stops the device at once.
 *   On a set-up device, admin/pin.php lists the people with a PIN: tap your name, type your PIN, and you are on the register
 *   or the gate. A PIN session:
 *     - can open only the register, the gate, waiver signing and gift card printing (bcrf_pin_gate(), called for every
 *       admin page by check_admin() through bcrf_staff_mfa_gate() in includes/staff_auth.php);
 *     - locks after 5 minutes without anyone using the screen (server side by time since the last request the person made,
 *       and on the screen by admin/assets/pin-lock.js), or when someone taps Lock;
 *     - never has Owner powers: an Owner who signs in by PIN acts as a Manager.
 *   It stands in for two-step sign-in, because the device itself had to be set up by an Owner who passed it.
 *
 * PINs
 *   4 to 8 digits, not all the same digit and not a straight run (1234, 9876). Five wrong PINs in a row lock that PIN for
 *   15 minutes. Each person sets their own on admin/pin_settings.php after signing in with their password.
 *
 * Manager approval
 *   On the register and the gate, when something needs an Owner or Manager (refund over the limit or of used admission,
 *   a discount that isn't a preset, letting a party in over capacity or without a waiver, closing a drawer that is off by
 *   more than the limit), the server answers need_manager with the managers who have a PIN. admin/assets/manager-pin.js
 *   asks for one of them to pick their name and type their PIN, and sends the same request again with
 *   approver: {id, pin}. bcrf_pin_take_approver() checks it; for that one request the manager checks pass, the approver is
 *   recorded where the record has an "approved by", and the approval is written to the audit log.
 */

if (!function_exists('bcrf_pin_ready')) {

if (!defined('BCRF_PIN_IDLE'))      define('BCRF_PIN_IDLE', 300);       // seconds without use before a PIN session locks
if (!defined('BCRF_PIN_TRIES'))     define('BCRF_PIN_TRIES', 5);        // wrong PINs in a row before the PIN locks
if (!defined('BCRF_PIN_LOCK_MIN'))  define('BCRF_PIN_LOCK_MIN', 15);    // minutes a PIN stays locked
if (!defined('BCRF_PIN_COOKIE'))    define('BCRF_PIN_COOKIE', 'bcrf_device');

/** Roles that may sign in by PIN on a shared device (they can use the register or the gate). */
function bcrf_pin_roles(): array { return ['administrator', 'moderator', 'Shopkeep']; }
/** Roles that can approve by PIN. */
function bcrf_pin_manager_roles(): array { return ['administrator', 'moderator']; }
/** Pages a PIN session may open (all in admin/). */
function bcrf_pin_pages(): array { return ['register.php', 'gate.php', 'waiver_sign.php', 'gift_card_print.php']; }

/** True once migration 0058 has run (cached per request). */
function bcrf_pin_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query("SELECT pin_hash FROM users LIMIT 0");
        $pdo->query("SELECT id FROM staff_devices LIMIT 0");
        return $ready = true;
    } catch (Throwable $e) { return $ready = false; }
}

// ── PINs ─────────────────────────────────────────────────────────────────────────────────────────

/** Why this PIN can't be used, or null when it's fine. */
function bcrf_pin_problem(string $pin): ?string {
    if (!preg_match('/^\d{4,8}$/', $pin)) return 'A PIN is 4 to 8 digits.';
    if (preg_match('/^(\d)\1+$/', $pin)) return 'Pick a PIN that isn\'t one digit repeated.';
    $up = $down = true;
    for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
        $d = (int)$pin[$i] - (int)$pin[$i - 1];
        if ($d !== 1) $up = false;
        if ($d !== -1) $down = false;
    }
    if ($up || $down) return 'Pick a PIN that isn\'t a straight run like 1234.';
    return null;
}

function bcrf_pin_set(PDO $pdo, int $uid, string $pin): void {
    if ($why = bcrf_pin_problem($pin)) throw new InvalidArgumentException($why);
    $pdo->prepare("UPDATE users SET pin_hash = ?, pin_set_at = UTC_TIMESTAMP(), pin_failed = 0, pin_locked_until = NULL WHERE id = ?")
        ->execute([password_hash($pin, PASSWORD_DEFAULT), $uid]);
}

function bcrf_pin_clear(PDO $pdo, int $uid): void {
    $pdo->prepare("UPDATE users SET pin_hash = NULL, pin_set_at = NULL, pin_failed = 0, pin_locked_until = NULL WHERE id = ?")->execute([$uid]);
}

/** Active staff with a PIN who may sign in by PIN, by first name. $managers = only those who can approve. */
function bcrf_pin_people(PDO $pdo, bool $managers = false): array {
    if (!bcrf_pin_ready($pdo)) return [];
    $roles = $managers ? bcrf_pin_manager_roles() : bcrf_pin_roles();
    $in = implode(',', array_fill(0, count($roles), '?'));
    $st = $pdo->prepare("SELECT id, first_name, last_name, role FROM users
                         WHERE pin_hash IS NOT NULL AND role IN ($in) AND account_status = 'active' AND date_terminated IS NULL
                         ORDER BY first_name, last_name, id");
    $st->execute($roles);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // Two people with the same first name get their last initial
    $firsts = array_count_values(array_map(fn($r) => mb_strtolower((string)$r['first_name']), $rows));
    return array_map(function ($r) use ($firsts) {
        $name = (string)$r['first_name'];
        if (($firsts[mb_strtolower($name)] ?? 0) > 1 && (string)$r['last_name'] !== '') $name .= ' ' . mb_substr((string)$r['last_name'], 0, 1) . '.';
        return ['id' => (int)$r['id'], 'name' => $name, 'role' => (string)$r['role']];
    }, $rows);
}

/**
 * Check a person's PIN. Returns ['ok' => true, 'user' => row] or ['ok' => false, 'message' => …]. Wrong PINs count toward
 * the lockout; a right one clears the count. $roles limits who can use it (sign-in roles by default).
 */
function bcrf_pin_check(PDO $pdo, int $uid, string $pin, ?array $roles = null): array {
    $no = ['ok' => false, 'message' => 'That PIN is not right.'];
    if (!bcrf_pin_ready($pdo) || $uid <= 0) return $no;
    $st = $pdo->prepare("SELECT id, first_name, last_name, email, role, account_status, date_terminated, pin_hash, pin_failed, pin_locked_until,
                                (pin_locked_until IS NOT NULL AND pin_locked_until > UTC_TIMESTAMP()) AS locked,
                                GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), pin_locked_until)) AS lock_left
                         FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || empty($u['pin_hash']) || $u['account_status'] !== 'active' || $u['date_terminated'] !== null
        || !in_array((string)$u['role'], $roles ?? bcrf_pin_roles(), true)) return $no;
    if ((int)$u['locked']) {
        $m = max(1, (int)ceil((int)$u['lock_left'] / 60));
        return ['ok' => false, 'message' => sprintf('Too many wrong PINs. %s can try again in %d minute%s, or sign in with a password.', $u['first_name'], $m, $m === 1 ? '' : 's')];
    }
    if (!password_verify(preg_replace('/\D/', '', $pin), (string)$u['pin_hash'])) {
        $fails = (int)$u['pin_failed'] + 1;
        if ($fails >= BCRF_PIN_TRIES) {
            $pdo->prepare("UPDATE users SET pin_failed = 0, pin_locked_until = UTC_TIMESTAMP() + INTERVAL " . (int)BCRF_PIN_LOCK_MIN . " MINUTE WHERE id = ?")->execute([$uid]);
            bcrf_pin_audit($pdo, 'pin_locked', $u, 'PIN locked after ' . BCRF_PIN_TRIES . ' wrong tries');
            return ['ok' => false, 'message' => sprintf('Too many wrong PINs. %s\'s PIN is locked for %d minutes.', $u['first_name'], BCRF_PIN_LOCK_MIN)];
        }
        $pdo->prepare("UPDATE users SET pin_failed = ? WHERE id = ?")->execute([$fails, $uid]);
        $left = BCRF_PIN_TRIES - $fails;
        return ['ok' => false, 'message' => 'That PIN is not right.' . ($left <= 2 ? sprintf(' %d more tr%s before it locks.', $left, $left === 1 ? 'y' : 'ies') : '')];
    }
    if ((int)$u['pin_failed'] || $u['pin_locked_until'] !== null) $pdo->prepare("UPDATE users SET pin_failed = 0, pin_locked_until = NULL WHERE id = ?")->execute([$uid]);
    unset($u['pin_hash'], $u['locked'], $u['lock_left']);
    return ['ok' => true, 'user' => $u];
}

// ── Shared devices ───────────────────────────────────────────────────────────────────────────────

function bcrf_pin_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** The set-up device this request comes from, or null. */
function bcrf_pin_device(PDO $pdo): ?array {
    static $cache = [];
    $tok = (string)($_COOKIE[BCRF_PIN_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $tok) || !bcrf_pin_ready($pdo)) return null;
    $hash = hash('sha256', $tok);
    if (array_key_exists($hash, $cache)) return $cache[$hash];
    $st = $pdo->prepare("SELECT id, name, last_seen_at, (last_seen_at IS NULL OR last_seen_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE) AS stale
                         FROM staff_devices WHERE token_hash = ? AND revoked_at IS NULL");
    $st->execute([$hash]);
    $d = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($d && (int)$d['stale']) $pdo->prepare("UPDATE staff_devices SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?")->execute([(int)$d['id']]);
    return $cache[$hash] = $d ? ['id' => (int)$d['id'], 'name' => (string)$d['name']] : null;
}

/** Set up the device making this request. Returns the new device id. */
function bcrf_pin_device_create(PDO $pdo, string $name, int $byUid): int {
    $name = mb_substr(trim(strip_tags($name)), 0, 80);
    if ($name === '') throw new InvalidArgumentException('Give the device a name, like "Gate tablet".');
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO staff_devices (name, token_hash, created_by, created_at, last_seen_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute([$name, hash('sha256', $tok), $byUid ?: null]);
    $id = (int)$pdo->lastInsertId();
    if (!headers_sent()) setcookie(BCRF_PIN_COOKIE, $tok, ['expires' => time() + 400 * 86400, 'path' => '/', 'secure' => bcrf_pin_https(), 'httponly' => true, 'samesite' => 'Strict']);
    $_COOKIE[BCRF_PIN_COOKIE] = $tok;
    return $id;
}

function bcrf_pin_device_revoke(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE staff_devices SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL")->execute([$id]);
}

/** Devices for the settings page, newest first (revoked ones from the last 30 days too). */
function bcrf_pin_devices(PDO $pdo): array {
    if (!bcrf_pin_ready($pdo)) return [];
    return $pdo->query("SELECT d.id, d.name, d.created_at, d.last_seen_at, d.revoked_at, u.first_name AS by_name
                        FROM staff_devices d LEFT JOIN users u ON u.id = d.created_by
                        WHERE d.revoked_at IS NULL OR d.revoked_at > UTC_TIMESTAMP() - INTERVAL 30 DAY
                        ORDER BY d.revoked_at IS NOT NULL, d.id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// ── PIN sessions ─────────────────────────────────────────────────────────────────────────────────

function bcrf_pin_session(): ?array {
    $p = $_SESSION['pin'] ?? null;
    return is_array($p) ? $p : null;
}

/** Sign someone in by PIN on a set-up device. An Owner acts as a Manager here. */
function bcrf_pin_session_start(array $u, array $device): void {
    session_regenerate_id(true);
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_setup_secret']);
    $role = (string)$u['role'] === 'administrator' ? 'moderator' : (string)$u['role'];
    $_SESSION['user_id']    = (int)$u['id'];
    $_SESSION['user_role']  = $role;
    $_SESSION['user_name']  = (string)($u['first_name'] ?? '');
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['mfa_ok']     = true;   // the device was set up by an Owner who passed two-step sign-in
    $_SESSION['pin']        = ['device' => (int)$device['id'], 'device_name' => (string)$device['name'], 'last' => time(),
                               'started' => time(), 'real_role' => (string)$u['role']];
}

/** End the session (Lock, time out, or the device was revoked). Keeps the PHP session for the next sign-in. */
function bcrf_pin_session_end(): void {
    foreach (['user_id', 'user_role', 'user_name', 'csrf_token', 'mfa_ok', 'pin', 'mfa_pending', 'first_name', 'last_name'] as $k) unset($_SESSION[$k]);
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

/** True for requests the page makes on its own (a timer refreshing a total), which don't count as someone using it. */
function bcrf_pin_background_request(): bool {
    return ($_SERVER['HTTP_X_BCRF_BACKGROUND'] ?? '') === '1';
}

function bcrf_pin_wants_json(): bool {
    return isset($_GET['api']) || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
}

/** Stop this request: JSON for the screens' background calls, else back to the PIN screen. */
function bcrf_pin_bounce(string $why, int $code = 401): void {
    if (bcrf_pin_wants_json()) {
        if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json'); header('Cache-Control: no-store'); }
        echo json_encode(['ok' => false, 'pin_locked' => $code === 401, 'message' => $why, 'lock_url' => '/admin/pin.php']);
        exit;
    }
    if (!headers_sent()) header('Location: /admin/pin.php?m=' . rawurlencode($why));
    exit;
}

/**
 * The PIN-session rules, for every admin page that calls check_admin() (through bcrf_staff_mfa_gate()). Ends a session
 * that went 5 minutes without use or whose device was revoked, and keeps it to the register, gate and waiver pages.
 */
function bcrf_pin_gate(): void {
    global $pdo;
    $p = bcrf_pin_session();
    if (!$p) return;
    $GLOBALS['bcrf_pin_gate_ran'] = true;
    if (time() - (int)($p['last'] ?? 0) > BCRF_PIN_IDLE) {
        bcrf_pin_session_end();
        bcrf_pin_bounce('Locked after 5 minutes without use. Sign in with your PIN.');
    }
    $dev = ($pdo instanceof PDO) ? bcrf_pin_device($pdo) : null;
    if (!$dev || $dev['id'] !== (int)($p['device'] ?? 0)) {
        bcrf_pin_session_end();
        bcrf_pin_bounce('This device is no longer set up for PIN sign-in.');
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir    = basename(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($dir !== 'admin' || !in_array($script, bcrf_pin_pages(), true)) {
        if (bcrf_pin_wants_json()) bcrf_pin_bounce('With PIN sign-in this device can use the register, gate and waivers only.', 403);
        if (!headers_sent()) header('Location: /admin/pin.php?m=' . rawurlencode('With PIN sign-in this device can use the register, gate and waivers only. For anything else, sign in with your password.'));
        exit;
    }
    if (!bcrf_pin_background_request()) $_SESSION['pin']['last'] = time();
}

/**
 * For the PIN pages themselves: a PIN session only counts if check_admin() ran bcrf_pin_gate() (it does when config.php
 * calls bcrf_staff_mfa_gate()). If it didn't, the PIN rules aren't being enforced, so the session is ended.
 */
function bcrf_pin_page_check(): void {
    if (bcrf_pin_session() && empty($GLOBALS['bcrf_pin_gate_ran'])) {
        bcrf_pin_session_end();
        bcrf_pin_bounce('PIN sign-in is switched off on this server. Sign in with your password.', 403);
    }
}

/** <link>/<script> for the idle lock on a page a PIN session may open ('' when not a PIN session). */
function bcrf_pin_head_tags(): string {
    $p = bcrf_pin_session();
    if (!$p) return '';
    $v = function_exists('bcrf_asset_v') ? bcrf_asset_v('/admin/assets/pin-lock.js') : '1';
    $name = htmlspecialchars((string)($_SESSION['user_name'] ?? ''), ENT_QUOTES);
    return '<script src="/admin/assets/pin-lock.js?v=' . $v . '" defer data-idle="' . (int)BCRF_PIN_IDLE . '" data-name="' . $name . '"></script>';
}

/** For the top bar of the PIN pages: a "Lock" link in a PIN session, else the usual back-to-console link. */
function bcrf_pin_back_link(string $class = 'back drawerbtn'): string {
    if (bcrf_pin_session()) {
        $name = htmlspecialchars((string)($_SESSION['user_name'] ?? ''), ENT_QUOTES);
        return '<a class="' . $class . '" href="/admin/pin.php?lock=1" id="pinLock">🔒&nbsp;Lock' . ($name !== '' ? ' (' . $name . ')' : '') . '</a>';
    }
    return '<a class="' . $class . '" href="/admin/admin.php"><span aria-hidden="true">←</span>&nbsp;Console</a>';
}

// ── Manager approval ─────────────────────────────────────────────────────────────────────────────

/** The manager who approved this request by PIN, or null. */
function bcrf_pin_approver(): ?array {
    $a = $GLOBALS['bcrf_pin_approver'] ?? null;
    return is_array($a) ? $a : null;
}

/** Is the signed-in person an Owner or Manager, or did one approve this request by PIN? */
function bcrf_mgr_ok(): bool {
    return in_array((string)($_SESSION['user_role'] ?? ''), bcrf_pin_manager_roles(), true) || bcrf_pin_approver() !== null;
}

/** Who to record as approving: the PIN approver, else $fallback (the signed-in person). */
function bcrf_mgr_id(?int $fallback): ?int {
    $a = bcrf_pin_approver();
    return $a ? (int)$a['id'] : $fallback;
}

/**
 * Read approver {id, pin} from a JSON request body and check it. Returns null when there's none, true when it's a
 * manager's right PIN (bcrf_pin_approver() then returns them), or the message to show.
 */
function bcrf_pin_take_approver(PDO $pdo, ?array $in = null): bool|string|null {
    if ($in === null) {
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) return null;
    }
    $a = $in['approver'] ?? null;
    if (!is_array($a) || empty($a['id'])) return null;
    $r = bcrf_pin_check($pdo, (int)$a['id'], (string)($a['pin'] ?? ''), bcrf_pin_manager_roles());
    if (!$r['ok']) return $r['message'];
    $u = $r['user'];
    $GLOBALS['bcrf_pin_approver'] = ['id' => (int)$u['id'], 'name' => trim($u['first_name'] . ' ' . $u['last_name']), 'role' => (string)$u['role']];
    return true;
}

/** Note that a refusal was only for want of a manager (so the screen can offer the PIN prompt). */
function bcrf_mgr_needed(): void { $GLOBALS['bcrf_mgr_needed'] = true; }

/** Fields to add to a refusal so the screen can ask a manager for their PIN; [] when that won't help. */
function bcrf_pin_need_fields(PDO $pdo): array {
    if (empty($GLOBALS['bcrf_mgr_needed']) || bcrf_mgr_ok()) return [];
    $m = bcrf_pin_people($pdo, true);
    return $m ? ['need_manager' => true, 'managers' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $m)] : [];
}

/** Can a manager approve here by PIN (at least one has a PIN)? Screens use it to offer overrides to everyone. */
function bcrf_pin_approval_on(PDO $pdo): bool {
    static $on = null;
    return $on ??= (bool)bcrf_pin_people($pdo, true);
}

/** Audit a manager's PIN approval of this request. */
function bcrf_pin_audit_approval(PDO $pdo, string $what): void {
    $a = bcrf_pin_approver();
    if (!$a) return;
    $for = trim((string)($_SESSION['user_name'] ?? ''));
    bcrf_pin_audit($pdo, 'approve', ['id' => $a['id'], 'first_name' => $a['name']], 'Approved by PIN for ' . ($for !== '' ? $for : 'staff') . ': ' . $what);
}

function bcrf_pin_audit(PDO $pdo, string $action, array $u, string $label): void {
    if (!function_exists('bcrf_audit_event')) {
        $f = dirname(__DIR__) . '/admin/includes/audit.php';
        if (is_file($f)) require_once $f;
    }
    if (!function_exists('bcrf_audit_event')) return;
    $who = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
    bcrf_audit_event($pdo, [
        'actor_type' => 'staff', 'actor_id' => (int)($u['id'] ?? 0) ?: null, 'actor_label' => $who,
        'ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
        'action' => $action, 'object_type' => 'users', 'object_id' => (string)($u['id'] ?? ''), 'object_label' => mb_substr($label, 0, 255),
    ]);
}

}
