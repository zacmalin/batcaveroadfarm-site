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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $valid = $user && password_verify($password, (string)($user['password'] ?? ''));

            $isStaff = $valid && in_array($user['role'], ['administrator', 'moderator', 'Shopkeep', 'Media'], true);

            if ($isStaff) {
                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);

                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_role']  = $user['role'];
                $_SESSION['user_name']  = $user['first_name'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

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
                $error = 'Invalid credentials.';
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
            <div>
                <label class="block text-xs font-semibold text-stone-600 mb-1">Email</label>
                <input type="email" name="email" required autofocus
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>"
                    class="w-full p-2.5 border border-stone-300 rounded text-sm outline-none bg-stone-50 focus:ring-1 focus:ring-stone-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-600 mb-1">Password</label>
                <input type="password" name="password" required
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
