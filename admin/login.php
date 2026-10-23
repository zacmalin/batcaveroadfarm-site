<?php
ob_start(); // Buffer any accidental output — prevents "headers already sent" if config.php has whitespace

require_once '../config.php';

// Already logged in? Skip the form — staff to the console, anyone else to the site.
if (!empty($_SESSION['user_id'])) {
    $r = $_SESSION['user_role'] ?? 'user';
    $staff = in_array($r, ['administrator', 'moderator', 'Shopkeep', 'Media'], true);
    header('Location: ' . ($staff ? '/admin/admin.php' : '/'));
    exit;
}

$error = '';

// ── Fix pack K: brute-force protection ─────────────────────────────────────
// Failed attempts are counted on the server (not in the session), per IP
// address and per email. Limits:
//   - 5 failed tries from one IP in 15 minutes  -> that IP waits 15 minutes
//   - 10 failed tries on one email in 1 hour    -> that email waits up to 1 hour
//     (catches an attacker switching IPs; a real owner can still reset via email)
// Every failure, lockout and successful staff sign-in goes to the Audit Log.
require_once __DIR__ . '/../includes/auth.php';        // csrf_token(), csrf_verify()
require_once __DIR__ . '/../includes/rate_limit.php';  // bcrf_rl_*()
require_once __DIR__ . '/includes/audit.php';          // erp_audit_security()

const BCRF_STAFF_IP_MAX     = 5;
const BCRF_STAFF_IP_WIN     = 900;    // 15 min
const BCRF_STAFF_EMAIL_MAX  = 10;
const BCRF_STAFF_EMAIL_WIN  = 3600;   // 1 hour

$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim((string)($_POST['email']    ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $emailKey = strtolower($email);
    $ip       = bcrf_rl_ip();

    $ipFails    = bcrf_rl_count('staff_ip', $ip, BCRF_STAFF_IP_WIN);
    $emailFails = $emailKey !== '' ? bcrf_rl_count('staff_email', $emailKey, BCRF_STAFF_EMAIL_WIN) : 0;

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($ipFails >= BCRF_STAFF_IP_MAX) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } elseif ($emailFails >= BCRF_STAFF_EMAIL_MAX) {
        $error = 'This account is temporarily locked after too many failed attempts. Please try again in an hour.';
    } elseif ($email === '' || trim($password) === '') {
        $error = 'Email and password are required.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // trim() kept for compatibility with how passwords were checked before
            $valid = $user && password_verify(trim($password), (string)($user['password'] ?? ''));

            $isStaff = $valid && in_array($user['role'], ['administrator', 'moderator', 'Shopkeep', 'Media'], true);

            if ($isStaff) {
                // Clean slate for this IP + email
                bcrf_rl_clear('staff_ip', $ip);
                bcrf_rl_clear('staff_email', $emailKey);

                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);

                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_role']  = $user['role'];
                $_SESSION['user_name']  = $user['first_name'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                erp_audit_security($pdo, 'login', $email, (int)$user['id'], (string)$user['first_name']);

                ob_end_clean(); // Discard buffer before redirect

                // All staff roles use the console; the permission matrix
                // governs which modules each role can open inside it.
                $dest = '/admin/admin.php';

                header('Location: ' . $dest);
                exit;

            } elseif ($valid) {
                // Authenticated, but a public ('user') account — keep them out of the console.
                $error = 'This console is for staff only. Please use the main site.';
            } else {
                $ipNow    = bcrf_rl_add('staff_ip', $ip, BCRF_STAFF_IP_WIN);
                $emailNow = bcrf_rl_add('staff_email', $emailKey, BCRF_STAFF_EMAIL_WIN);
                $locked   = ($ipNow >= BCRF_STAFF_IP_MAX || $emailNow >= BCRF_STAFF_EMAIL_MAX);

                erp_audit_security($pdo, 'failed', $email);
                if ($locked) erp_audit_security($pdo, 'blocked', $email);

                usleep(random_int(250000, 600000));   // slow down guessing a little
                $error = !$locked ? 'Invalid credentials.'
                    : ($ipNow >= BCRF_STAFF_IP_MAX
                        ? 'Too many failed attempts. Please wait 15 minutes and try again.'
                        : 'This account is temporarily locked after too many failed attempts. Please try again in an hour.');
            }

        } catch (Throwable $e) {
            error_log('[Login] ' . $e->getMessage());
            $error = 'A server error occurred. Please try again.';
        }
    }
}

ob_end_clean(); // Done with buffering — render the page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Farm Control Console Login</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-stone-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-8 rounded shadow-md max-w-sm w-full border border-stone-200">
        <h2 class="text-xl font-serif font-bold text-stone-800 mb-6 text-center">Staff Console Login</h2>

        <?php if ($error): ?>
            <div class="p-2 mb-4 text-xs bg-red-50 text-red-800 border border-red-200 rounded font-mono">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <!-- Hotfix K1: no hidden bot-trap field here. Phone password managers autofilled it with the email, which blocked real logins. CSRF + lockouts protect this form. -->
            <div>
                <label class="block text-xs font-semibold text-stone-600 mb-1">Email</label>
                <input type="email" name="email" autocomplete="username" required autofocus
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>"
                    class="w-full p-2.5 border border-stone-300 rounded text-sm outline-none bg-stone-50 focus:ring-1 focus:ring-stone-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-600 mb-1">Password</label>
                <input type="password" name="password" autocomplete="current-password" required
                    class="w-full p-2.5 border border-stone-300 rounded text-sm outline-none bg-stone-50 focus:ring-1 focus:ring-stone-500">
            </div>
            <button type="submit"
                class="w-full bg-stone-950 text-white p-2.5 rounded hover:bg-stone-800 transition font-medium text-sm shadow-xs">
                Sign In
            </button>
        </form>
    </div>
</body>
</html>
