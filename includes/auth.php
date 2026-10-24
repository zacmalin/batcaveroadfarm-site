<?php
/**
 * includes/auth.php — public-site auth, CSRF, and abuse helpers.
 * Requires config.php (session already started, $pdo available) to be loaded first.
 * Staff auth stays in /admin; public sign-ups get role 'user' and can never reach /admin.
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
    function is_logged_in(): bool   { return !empty($_SESSION['user_id']); }
    function current_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
    function current_user_name(): string { return (string)($_SESSION['user_name'] ?? ''); }
    function current_user_role(): string { return (string)($_SESSION['user_role'] ?? ''); }

    /** Roles allowed into /admin. Public sign-ups ('user') are deliberately excluded. */
    function is_staff_role(?string $role): bool {
        return in_array((string)$role, ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'], true);
    }

    /** Establish a logged-in session for a fetched users row (mirrors admin/login.php). */
    function auth_login_user(array $user): void {
        session_regenerate_id(true);
        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['user_role']  = $user['role'] ?? 'user';
        $_SESSION['user_name']  = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        $_SESSION['email_verified'] = !empty($user['email_verified_at'] ?? null);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    function auth_logout(): void {
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
            $st = $pdo->prepare("SELECT comment_restricted_until FROM users WHERE id = ?");
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
            $st = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u && password_verify($password, (string)($u['password'] ?? ''))) return ['ok' => true, 'user' => $u];
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
            $c = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $c->execute([$email]);
            if ($c->fetch()) return ['ok' => false, 'error' => 'An account with that email already exists. Try logging in.'];
            $ins = $pdo->prepare("INSERT INTO users (first_name, last_name, email, phone_number, password, role) VALUES (?, ?, ?, ?, ?, 'user')");
            $ins->execute([$first, $last, $email, ($phone !== '' ? $phone : null), password_hash($pw, PASSWORD_DEFAULT)]);
            return ['ok' => true, 'user' => ['id' => (int)$pdo->lastInsertId(), 'role' => 'user', 'first_name' => $first, 'last_name' => $last]];
        } catch (Throwable $e) {
            error_log('[auth_attempt_register] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'A server error occurred. Please try again.'];
        }
    }
}
