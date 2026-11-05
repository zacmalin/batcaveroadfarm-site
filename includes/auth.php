<?php
/**
 * includes/auth.php — public-site auth, CSRF, and abuse helpers.
 * Requires config.php (session already started, $pdo available) to be loaded first.
 * Staff auth stays in /admin (users table); public accounts are in customers (Farm OS Phase 0, C-02)
 * and can never reach /admin.
 */

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
    function csrf_verify(?string $t): bool {
        return is_string($t) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t);
    }
}

if (!function_exists('is_logged_in')) {
    // Customer sign-in (Farm OS Phase 0, C-02): the public site uses only customer_* session values and the
    // customers table (includes/customer_auth.php). Staff sign in at /admin/login.php, which sets
    // user_id / user_role; nothing here reads or writes those, so a public-site bug can't open the console.
    // Someone can be signed in to both at once (staff have a linked customer account).
    require_once __DIR__ . '/customer_auth.php';

    function is_logged_in(): bool   { return !empty($_SESSION['customer_id']); }
    function current_user_id(): int { return (int)($_SESSION['customer_id'] ?? 0); }
    function current_user_name(): string { return (string)($_SESSION['customer_name'] ?? ''); }
    /** The console role of whoever is signed in as staff in this browser ('' if nobody). */
    function current_user_role(): string { return (string)($_SESSION['user_role'] ?? ''); }

    /** Roles allowed into /admin. Public sign-ups ('user') are deliberately excluded. */
    function is_staff_role(?string $role): bool {
        return in_array((string)$role, ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'], true);
    }

    /** Sign a customer account in on the public site (a customers row). Never touches the staff sign-in. */
    function auth_login_user(array $user): void {
        $staffSignedIn = !empty($_SESSION['user_id']) && is_staff_role($_SESSION['user_role'] ?? '');
        session_regenerate_id(true);
        $_SESSION['customer_id']    = (int)$user['id'];
        $_SESSION['customer_name']  = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        $_SESSION['email_verified'] = !empty($user['email_verified_at'] ?? null);
        // New CSRF token on sign-in, unless the console is open in this browser too (its forms would fail).
        if (!$staffSignedIn) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        // Before the split, a public account's session was user_id + role 'user': drop that leftover.
        if (($_SESSION['user_role'] ?? '') === 'user') unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);
    }

    /** Sign the customer out. A staff sign-in in the same browser stays signed in. */
    function auth_logout(): void {
        unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['email_verified'], $_SESSION['evf_nudge_dismissed']);
        if (($_SESSION['user_role'] ?? '') === 'user') unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);
        if (!empty($_SESSION['user_id'])) { session_regenerate_id(true); return; }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    function require_login(string $next = ''): void {
        if (!is_logged_in()) {
            $q = $next !== '' ? ('?next=' . urlencode($next)) : '';
            header('Location: /account/login.php' . $q);
            exit;
        }
    }

    /** Only allow same-site relative redirect targets (blocks open-redirect). */
    function safe_next(string $next, string $default = '/'): string {
        if ($next === '' || $next[0] !== '/') return $default;
        if (isset($next[1]) && $next[1] === '/') return $default; // protocol-relative //evil.com
        return $next;
    }

    /** If the logged-in user is currently barred from commenting, returns the date; else null. */
    function comment_blocked_until(PDO $pdo, int $userId): ?string {
        try {
            $st = $pdo->prepare("SELECT comment_restricted_until FROM " . bcrf_customer_table($pdo) . " WHERE id = ?");
            $st->execute([$userId]);
            $d = $st->fetchColumn();
            if ($d && strtotime((string)$d) >= strtotime(date('Y-m-d'))) return (string)$d;
        } catch (Throwable $e) {}
        return null;
    }
}

if (!function_exists('client_ip_hash')) {
    function client_ip_raw(): string {
        // Behind Cloudflare, the real client IP is in CF-Connecting-IP.
        foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
        }
        return '0.0.0.0';
    }
    /** Privacy: we store only a salted hash of the IP, never the raw address. */
    function client_ip_hash(): string {
        return hash('sha256', client_ip_raw() . '|bcrf-ip-salt-v1');
    }
    function honeypot_tripped(string $field = 'website'): bool {
        return isset($_POST[$field]) && trim((string)$_POST[$field]) !== '';
    }
    /** Session-based sliding-window throttle. Returns true when the limit is exceeded. */
    function throttle_hit(string $key, int $max, int $secs): bool {
        $now = time();
        $bucket = $_SESSION['__throttle'][$key] ?? [];
        $bucket = array_values(array_filter($bucket, fn($t) => $t > $now - $secs));
        $bucket[] = $now;
        $_SESSION['__throttle'][$key] = $bucket;
        return count($bucket) > $max;
    }
}

if (!function_exists('auth_attempt_login')) {
    function auth_attempt_login(PDO $pdo, string $email, string $password): array {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') return ['ok' => false, 'error' => 'Email and password are required.'];
        try {
            $u = bcrf_customer_find_by_email($pdo, $email);   // customer accounts only (C-02)
            if ($u && bcrf_customer_active($u) && password_verify($password, (string)($u['password'] ?? ''))) return ['ok' => true, 'user' => $u];
        } catch (Throwable $e) {
            error_log('[auth_attempt_login] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'A server error occurred. Please try again.'];
        }
        return ['ok' => false, 'error' => 'Email or password is incorrect.'];
    }

    function auth_attempt_register(PDO $pdo, array $f): array {
        $first = trim((string)($f['first_name'] ?? ''));
        $last  = trim((string)($f['last_name'] ?? ''));
        $email = strtolower(trim((string)($f['email'] ?? '')));
        $phone = trim((string)($f['phone_number'] ?? ''));
        $pw    = (string)($f['password'] ?? '');
        $conf  = (string)($f['password_confirm'] ?? '');
        if ($first === '' || $last === '' || $email === '') return ['ok' => false, 'error' => 'Name and email are required.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        if (strlen($pw) < 8) return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        if ($pw !== $conf) return ['ok' => false, 'error' => 'The two passwords do not match.'];
        try {
            if (bcrf_customer_email_taken($pdo, $email)) return ['ok' => false, 'error' => 'An account with that email already exists. Try logging in.'];
            $id = bcrf_customer_create($pdo, $first, $last, $email, ($phone !== '' ? $phone : null), password_hash($pw, PASSWORD_DEFAULT));
            return ['ok' => true, 'user' => ['id' => $id, 'first_name' => $first, 'last_name' => $last, 'email' => $email]];
        } catch (Throwable $e) {
            error_log('[auth_attempt_register] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'A server error occurred. Please try again.'];
        }
    }
}
