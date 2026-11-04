<?php
// health.php (uptime monitor) defines BCRF_NO_SESSION so its checks every
// 5 minutes don't create a session file each time.
// Perf fix (Nov 2026): only start a session when this request needs one.
// Starting one on every page sent no-cache headers + a cookie, so NO page could
// ever be cached and every visit ran PHP + MySQL. Anonymous visitors browsing
// public pages now get no session, and those pages can be cached briefly.
// A session still starts when:
//   - the visitor already has a session cookie (logged in, mid-checkout, ...)
//   - the request is not GET/HEAD (every form post, AJAX save, login)
//   - the path is one that keeps state (bcrf_session_path() below)
//   - a page defines BCRF_NEEDS_SESSION before requiring config.php
//   - CLI (cron scripts), same as before
function bcrf_session_path(string $path): bool {
    return (bool)preg_match('#^/(admin|account|booking|cancel-booking\.php|passes|tickets|private-events|contact-us|send-message\.php|jobs/(apply|thanks|alerts|[a-z0-9-]+/apply))(/|\.php|$)#i', $path);
}
$__bcrfPath   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$__bcrfMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$__bcrfHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$__bcrfNeedsSession = PHP_SAPI === 'cli'
    || defined('BCRF_NEEDS_SESSION')
    || str_starts_with($__bcrfHost, 'admin.')            // admin.batcaveroadfarm.com: every page is admin
    || !in_array($__bcrfMethod, ['GET', 'HEAD'], true)
    || isset($_COOKIE[session_name()])
    || bcrf_session_path($__bcrfPath);

// Admin subdomain switch (Nov 2026): once admin/use_subdomain.txt exists, plain page loads of
// batcaveroadfarm.com/admin/... go to admin.batcaveroadfarm.com/... (GET only, so a form that was
// already open still saves). The root .htaccess maps the short URLs back into /admin/.
if (PHP_SAPI !== 'cli' && $__bcrfMethod === 'GET' && !str_starts_with($__bcrfHost, 'admin.')
    && preg_match('#^/admin/(.*)$#', $__bcrfPath, $__m) && is_file(__DIR__ . '/admin/use_subdomain.txt')) {
    $__qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: https://admin.batcaveroadfarm.com/' . $__m[1] . ($__qs !== '' ? '?' . $__qs : ''), true, 301);
    exit;
}

if (session_status() == PHP_SESSION_NONE && !defined('BCRF_NO_SESSION') && $__bcrfNeedsSession) {
    images_session_start_secure();
}
if (session_status() !== PHP_SESSION_ACTIVE && !isset($_SESSION)) {
    $_SESSION = [];   // reads like $_SESSION['user_id'] stay warning-free; visitor is simply logged out
}

// Anonymous public page with no session: let LiteSpeed cache it for 5 minutes.
// (.htaccess skips the cache for anyone sending a session cookie.)
// Site Info saves already purge the cache, so hours/contacts update right away.
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE && !defined('BCRF_NO_SESSION')
    && in_array($__bcrfMethod, ['GET', 'HEAD'], true)
    && !preg_match('#^/(subscribe|unsubscribe|subscribe-confirm|review|health|ping)(/|\.php|$)#i', $__bcrfPath)
    && !headers_sent()) {
    header('X-LiteSpeed-Cache-Control: public,max-age=300');
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

// PHP error log (Nov 2026): Bluehost didn't give the site a readable error log, so errors and
// error_log() messages vanished into the web server's own log. Write them to a file in the home
// folder (outside the website) instead; Admin → System Health reads it. Rotated at 5 MB by cron
// runs (one old copy kept as .1).
if (!defined('BCRF_PHP_ERROR_LOG')) {
    define('BCRF_PHP_ERROR_LOG', getenv('BCRF_PHP_ERROR_LOG') ?: (dirname(__DIR__, 2) . '/logs/php_errors.log'));
}
if (is_dir(dirname(BCRF_PHP_ERROR_LOG)) && is_writable(dirname(BCRF_PHP_ERROR_LOG))) {
    @ini_set('log_errors', '1');
    @ini_set('display_errors', '0');
    @ini_set('error_log', BCRF_PHP_ERROR_LOG);
    if (PHP_SAPI === 'cli' && @filesize(BCRF_PHP_ERROR_LOG) > 5 * 1048576) {
        @rename(BCRF_PHP_ERROR_LOG, BCRF_PHP_ERROR_LOG . '.1');
    }
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

// Backups (Nov 2026): password for the encrypted backup zips + Google Drive OAuth client for off-site copies.
// See includes/backup_offsite.php and admin/backups.php. Keep BACKUP_PASSWORD in your password manager too.
define('BCRF_BACKUP_PASSWORD',      getenv('BACKUP_PASSWORD')      ?: ($bcrf['BACKUP_PASSWORD']      ?? ''));
define('BCRF_GDRIVE_CLIENT_ID',     getenv('GDRIVE_CLIENT_ID')     ?: ($bcrf['GDRIVE_CLIENT_ID']     ?? ''));
define('BCRF_GDRIVE_CLIENT_SECRET', getenv('GDRIVE_CLIENT_SECRET') ?: ($bcrf['GDRIVE_CLIENT_SECRET'] ?? ''));

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

// Automatic asset versions (audit housekeeping, Nov 2026).
// Returns the file's last-modified time for a site CSS/JS/image URL, so uploading a
// changed file changes its ?v= and browsers/Cloudflare fetch the new copy — no manual
// BCRF_ASSET_V bump. Falls back to BCRF_ASSET_V (or '1') if the file isn't found.
function bcrf_asset_v(string $url): string {
    static $cache = [];
    if (isset($cache[$url])) return $cache[$url];
    $path = (string)parse_url($url, PHP_URL_PATH);
    $file = __DIR__ . '/' . ltrim($path, '/');
    $v = ($path !== '' && strpos($path, '..') === false && is_file($file)) ? (string)filemtime($file) : '';
    if ($v === '') $v = defined('BCRF_ASSET_V') ? (string)BCRF_ASSET_V : '1';
    return $cache[$url] = $v;
}

// 2. Grammatical Quantity Evaluation Filter
function formatIngredientName($quantity, $singular, $plural) {
    if ((float)$quantity <= 1.00) {
        return htmlspecialchars($singular);
    }
    return htmlspecialchars($plural);
}

?>