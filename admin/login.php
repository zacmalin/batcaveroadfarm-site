<?php
ob_start(); // Buffer any accidental output — prevents "headers already sent" if config.php has whitespace

require_once '../config.php';

// Already signed in as staff? Skip the form. (A customer session, including staff who signed in on the
// public site, sees the form: the console needs a staff sign-in, with two-step when it's on.)
require_once __DIR__ . '/../includes/staff_auth.php';   // two-step sign-in (Farm OS Phase 0)
if (!empty($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'], true)) {
    header('Location: ' . (($_SESSION['user_role'] ?? '') === 'Staff' ? '/admin/admin.php?view=timecard' : '/admin/admin.php'));
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

            // 'Staff' = hired through Jobs (Time Clock only). A disabled account can't sign in.
            $isDisabled = $valid && (($user['account_status'] ?? 'active') === 'disabled');
            $isStaff = $valid && !$isDisabled && in_array($user['role'], ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'], true);

            if ($isStaff) {
                // Clean slate for this IP + email
                bcrf_rl_clear('staff_ip', $ip);
                bcrf_rl_clear('staff_email', $emailKey);

                // All staff roles use the console; the permission matrix
                // governs which modules each role can open inside it.
                $dest = $user['role'] === 'Staff' ? '/admin/admin.php?view=timecard' : '/admin/admin.php';

                // Two-step sign-in (includes/staff_auth.php): hold the sign-in until the code is entered,
                // or until it's set up when it's required. Nothing in the session counts as signed in yet.
                $mfa = bcrf_mfa_needed($pdo, $user);
                if ($mfa !== '') {
                    bcrf_staff_session_pend($user, $mfa, $dest);
                    ob_end_clean();
                    header('Location: /admin/two_step.php');
                    exit;
                }

                // Regenerate session ID to prevent session fixation (and mark two-step as not needed)
                bcrf_staff_session_start($user);

                erp_audit_security($pdo, 'login', $email, (int)$user['id'], (string)$user['first_name']);

                ob_end_clean(); // Discard buffer before redirect
                header('Location: ' . $dest);
                exit;

            } elseif ($isDisabled) {
                $error = 'This staff account is turned off. Please contact Zac or Casey.';
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
    <!-- Phone-sized page (Nov 2026): without this, phones drew a desktop page and shrank it. -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Staff Console Login</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-stone-100 min-h-screen flex items-start sm:items-center justify-center px-4 py-10 sm:py-4">
    <div class="bg-white p-6 sm:p-8 rounded-lg shadow-md w-full max-w-sm border border-stone-200">
        <h1 class="text-2xl font-serif font-bold text-stone-800 mb-6 text-center">Staff Console</h1>

        <?php if ($error): ?>
            <div class="p-3 mb-4 text-sm bg-red-50 text-red-800 border border-red-200 rounded" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <!-- Hotfix K1: no hidden bot-trap field here. Phone password managers autofilled it with the email, which blocked real logins. CSRF + lockouts protect this form. -->
            <!-- Fields are 16px text: smaller makes iPhones zoom in when you tap them. -->
            <div>
                <label for="email" class="block text-sm font-semibold text-stone-700 mb-1">Email</label>
                <input id="email" type="email" name="email" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required autofocus
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>"
                    class="w-full px-3 py-3 border border-stone-300 rounded-md text-base outline-none bg-stone-50 focus:ring-2 focus:ring-stone-500">
            </div>
            <div>
                <label for="password" class="block text-sm font-semibold text-stone-700 mb-1">Password</label>
                <div class="relative">
                    <input id="password" type="password" name="password" autocomplete="current-password" required
                        class="w-full px-3 py-3 pr-16 border border-stone-300 rounded-md text-base outline-none bg-stone-50 focus:ring-2 focus:ring-stone-500">
                    <button type="button" class="absolute inset-y-0 right-0 px-3 text-sm font-semibold text-stone-600"
                        aria-controls="password" aria-label="Show password"
                        onclick="var f=document.getElementById('password'),s=f.type==='password';f.type=s?'text':'password';this.textContent=s?'Hide':'Show';this.setAttribute('aria-label',s?'Hide password':'Show password')">Show</button>
                </div>
            </div>
            <button type="submit"
                class="w-full bg-stone-950 text-white py-3 rounded-md hover:bg-stone-800 transition font-semibold text-base shadow-xs">
                Sign In
            </button>
        </form>
        <p class="text-center mt-5"><a href="/account/forgot.php?staff=1" class="text-sm text-stone-600 underline">Forgot password?</a></p>
    </div>
</body>
</html>
