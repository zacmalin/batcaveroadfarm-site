<?php
/**
 * admin/cron/send_review_requests.php
 * Emails a post-visit thank-you + Google review ask for each CONFIRMED booking
 * whose visit finished ~1 day ago and hasn't been asked yet. Idempotent via
 * bookings.review_request_sent_at, so it's safe to run often (e.g. hourly).
 * A 4-day catch-up window covers a lapsed cron without spamming old bookings.
 *
 * Cron (ea-php85):
 *   /opt/cpanel/ea-php85/root/usr/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/send_review_requests.php
 * Or via URL with the key:
 *   /admin/cron/send_review_requests.php?key=<BCRF_CRON_KEY>  (only if CRON_KEY is set in bcrf-secrets.php)
 */

ob_start(); // swallow stray pre-output so header() works on the web trigger

$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "send_review_requests: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;

$bcrf_bk = dirname($bcrf_cfg) . '/includes/booking.php';
if (!is_file($bcrf_bk)) { fwrite(STDERR, "send_review_requests: includes/booking.php not found\n"); exit(1); }
require_once $bcrf_bk;

if (ob_get_level() > 0) { ob_end_clean(); }

// ── Access guard: CLI always allowed; web requires the secret key ──
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = $_SERVER['HTTP_X_CRON_KEY'] ?? ($_GET['key'] ?? '');   // header preferred: keeps the key out of server logs
    if (!defined('BCRF_CRON_KEY') || strlen(BCRF_CRON_KEY) < 24 || !hash_equals(BCRF_CRON_KEY, (string)$key)) {
        http_response_code(403); exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

date_default_timezone_set('America/New_York');

function rr_log($m) { echo $m . "\n"; }

try {
    if (!function_exists('booking_tables_ready') || !booking_tables_ready($pdo)) {
        rr_log('Booking tables not ready.'); exit;
    }

    // Confirmed, not yet asked, visit finished between 18 hours and 4 days ago.
    $rows = $pdo->query("
        SELECT b.*, t.label
        FROM bookings b
        JOIN booking_types t ON t.id = b.type_id
        WHERE b.status = 'confirmed'
          AND b.review_request_sent_at IS NULL
          AND TIMESTAMP(b.booking_date, b.start_time) <= NOW() - INTERVAL 18 HOUR
          AND TIMESTAMP(b.booking_date, b.start_time) >= NOW() - INTERVAL 4 DAY
        ORDER BY b.booking_date, b.start_time
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) { rr_log('No review requests due.'); exit; }

    $mark = $pdo->prepare("UPDATE bookings SET review_request_sent_at = NOW() WHERE id = ?");
    $sent = 0;

    foreach ($rows as $b) {
        $type = ['label' => $b['label']];
        $ok   = false;
        if (!empty($b['email']) && function_exists('booking_send_review_request')) {
            $ok = @booking_send_review_request($b, $type);
        }
        $mark->execute([(int)$b['id']]); // mark regardless, so a bad address isn't retried every run
        if ($ok) $sent++;
        usleep(120000); // ~0.12s, gentle on the mail queue
    }

    rr_log('Review requests: ' . count($rows) . ' due, ' . $sent . ' sent.');

} catch (Throwable $e) {
    error_log('[BCRF] review requests failed: ' . $e->getMessage());
    rr_log('Error: ' . $e->getMessage());
}
