<?php
// health.php (uptime monitor) defines BCRF_NO_SESSION so its checks every
// 5 minutes don't create a session file each time.
if (session_status() == PHP_SESSION_NONE && !defined('BCRF_NO_SESSION')) {
    images_session_start_secure();
}

function images_session_start_secure() {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        // Site is HTTPS-only. Behind Cloudflare the origin may see plain HTTP,
        // so also trust the proxy headers; always secure on the live domain.
        'secure' => (($_SERVER['HTTPS'] ?? '') === 'on')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                    || str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"')
                    || str_ends_with(strtolower((string)($_SERVER['HTTP_HOST'] ?? '')), 'batcaveroadfarm.com'),
        'httponly' => true,
        // Lax: the login cookie is sent when visitors come back from Stripe checkout
        // or click a link in an email (Strict dropped it, so they looked logged out).
        // Cross-site POSTs still get no cookie, and every form also checks a CSRF token.
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Credentials are loaded from the environment first, then from a secrets file
// kept OUTSIDE the web root (see bcrf-secrets.php). No live secret is committed
// to a file inside public_html anymore. If neither source is present the PDO
// connection below will fail and be caught.
// Where to look for the secrets file, first match wins. config.php sits in the
// site root (public_html/batcaveroadfarm), so this works the same for web and cron.
//   1. BCRF_SECRETS environment variable (if ever set)
//   2. Home folder, OUTSIDE every web folder:  /home2/<user>/bcrf-secrets.php   <- preferred
//   3. Old location inside public_html:         /home2/<user>/public_html/bcrf-secrets.php
$bcrf_secrets = '';
foreach (array_filter([getenv('BCRF_SECRETS') ?: null, dirname(__DIR__, 2) . '/bcrf-secrets.php', dirname(__DIR__) . '/bcrf-secrets.php']) as $bcrf_try) {
    if (is_readable($bcrf_try)) { $bcrf_secrets = $bcrf_try; break; }
}
$bcrf = $bcrf_secrets !== '' ? (require $bcrf_secrets) : [];
if (!is_array($bcrf)) $bcrf = [];
define('BCRF_SECRETS_PATH', $bcrf_secrets);   // handy for checking which file loaded

define('DB_HOST', getenv('DB_HOST') ?: ($bcrf['DB_HOST'] ?? 'localhost'));
define('DB_USER', getenv('DB_USER') ?: ($bcrf['DB_USER'] ?? ''));
define('DB_PASS', getenv('DB_PASS') ?: ($bcrf['DB_PASS'] ?? ''));
define('DB_NAME', getenv('DB_NAME') ?: ($bcrf['DB_NAME'] ?? ''));

// Secret for signing email unsubscribe tokens. Set 'LIST_SECRET' in bcrf-secrets.php.
// (No hard-coded fallback: secrets never live in files inside public_html.)
define('BCRF_LIST_SECRET', getenv('BCRF_LIST_SECRET') ?: ($bcrf['LIST_SECRET'] ?? ''));

// Key that allows cron scripts in admin/cron/ to be run BY URL (?key=...).
// Leave 'CRON_KEY' out of bcrf-secrets.php to allow command-line cron ONLY (recommended).
// If set, it must be at least 24 characters.
define('BCRF_CRON_KEY', getenv('BCRF_CRON_KEY') ?: ($bcrf['CRON_KEY'] ?? ''));

// ── SMTP credentials for PHPMailer ──────────────────────────────────────────
define('BCRF_SMTP_HOST',      getenv('SMTP_HOST')      ?: ($bcrf['SMTP_HOST']      ?? 'mail.batcaveroadfarm.com'));
define('BCRF_SMTP_USER',      getenv('SMTP_USER')      ?: ($bcrf['SMTP_USER']      ?? 'newsletter@batcaveroadfarm.com'));
define('BCRF_SMTP_PASS',      getenv('SMTP_PASS')      ?: ($bcrf['SMTP_PASS']      ?? ''));
define('BCRF_SMTP_PORT', (int)(getenv('SMTP_PORT')     ?: ($bcrf['SMTP_PORT']      ?? 465)));
define('BCRF_STOCK_ALERT_TO', getenv('STOCK_ALERT_TO') ?: ($bcrf['STOCK_ALERT_TO'] ?? 'zacjmalin@gmail.com'));

// ── Stripe (hosted checkout — no card data ever touches the server) ──────────
// Add STRIPE_PK / STRIPE_SK / STRIPE_WH_SEC to bcrf-secrets.php.
// Use test keys (pk_test_… / sk_test_…) until you're satisfied, then swap to live.
define('BCRF_STRIPE_PK',     getenv('STRIPE_PK')     ?: ($bcrf['STRIPE_PK']     ?? ''));
define('BCRF_STRIPE_SK',     getenv('STRIPE_SK')     ?: ($bcrf['STRIPE_SK']     ?? ''));
define('BCRF_STRIPE_WH_SEC', getenv('STRIPE_WH_SEC') ?: ($bcrf['STRIPE_WH_SEC'] ?? ''));

// ── PayPal ───────────────────────────────────────────────────────────────────
define('BCRF_PAYPAL_CLIENT_ID',  getenv('PAYPAL_CLIENT_ID')  ?: ($bcrf['PAYPAL_CLIENT_ID']  ?? ''));
define('BCRF_PAYPAL_SECRET',     getenv('PAYPAL_SECRET')     ?: ($bcrf['PAYPAL_SECRET']     ?? ''));
define('BCRF_PAYPAL_MODE',       getenv('PAYPAL_MODE')       ?: ($bcrf['PAYPAL_MODE']       ?? 'sandbox'));

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    // Fix pack N: answer "503 temporarily unavailable" (not 200 OK), so uptime
    // monitors see the outage and Google doesn't index this message as a page.
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 300');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    die("A secure system connection interface fault occurred.");
}

function check_admin() {
    // Console access is for staff roles; the per-view permission matrix
    // (admin/includes/permissions.php) governs what each role sees inside.
    // Public 'user' accounts are not staff and are bounced to the login page.
    // 'Staff' = people hired through Jobs: Time Clock only (Jobs pack P1).
    $staff_roles = ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'];
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $staff_roles, true)) {
        header("Location: /admin/login.php");
        exit;
    }
    // Staff can open exactly one screen: admin.php?view=timecard. Every other
    // admin page or handler that calls check_admin() sends them back to it.
    if ($_SESSION['user_role'] === 'Staff') {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== 'admin.php' || ($_GET['view'] ?? 'dashboard') !== 'timecard') {
            header('Location: /admin/admin.php?view=timecard');
            exit;
        }
    }
}

// Add this helper function to the bottom of your config.php file
function createSlug($string) {
    $string = strip_tags($string);
    $string = preg_replace('~[^\pL\d]+~u', '-', $string);
    $string = iconv('utf-8', 'us-ascii//TRANSLIT', $string);
    $string = preg_replace('~[^-\w]+~', '', $string);
    $string = trim($string, '-');
    $string = preg_replace('~-+~', '-', $string);
    $string = strtolower($string);
    return empty($string) ? 'n-a' : $string;
}

// 1. Dynamic Age Calculation Engine Rule
function calculateAge($dob_string) {
    $birthDate = new DateTime($dob_string);
    $currentDate = new DateTime();
    $difference = $currentDate->diff($birthDate);
    return $difference->y; // Outputs integer rounded down to whole completed years
}

// 2. Grammatical Quantity Evaluation Filter
function formatIngredientName($quantity, $singular, $plural) {
    if ((float)$quantity <= 1.00) {
        return htmlspecialchars($singular);
    }
    return htmlspecialchars($plural);
}

// Dynamic RBAC Verification Handler for your admin panel sheets
function verifyTableAccess($tableName, $requireEdit = false) {
    global $pdo;
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        header("Location: /admin/login.php"); exit;
    }
    
    $role = $_SESSION['user_role'];
    
    // Administrators bypass security checks automatically
    if ($role === 'administrator') { return true; }
    
    // Look up the role's permissions for the requested table
    $stmt = $pdo->prepare("SELECT can_view, can_edit FROM role_permissions WHERE role = ? AND table_name = ?");
    $stmt->execute([$role, $tableName]);
    $perms = $stmt->fetch();
    
    if (!$perms) { return false; }
    
    if ($requireEdit) {
        return (int)$perms['can_edit'] === 1;
    }
    
    return (int)$perms['can_view'] === 1;
}

?>