<?php
/**
 * admin/cron/send_booking_reminders.php
 * Emails a reminder for each CONFIRMED booking that starts within the next 24h
 * and hasn't been reminded yet. Idempotent via bookings.reminder_sent_at, so it's
 * safe to run often (e.g. hourly). The admin day-view call-list is the backstop —
 * we don't bet no-show prevention on mail() alone.
 *
 * Cron (ea-php85):
 *   /opt/cpanel/ea-php85/root/usr/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/send_booking_reminders.php
 * Or via URL with the key:
 *   /admin/cron/send_booking_reminders.php?key=<BCRF_CRON_KEY>  (only if CRON_KEY is set in bcrf-secrets.php)
 */

ob_start(); // swallow any stray pre-output (BOM/whitespace) so header() works on the web trigger

$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "send_booking_reminders: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;

$bcrf_bk = dirname($bcrf_cfg) . '/includes/booking.php';
if (!is_file($bcrf_bk)) { fwrite(STDERR, "send_booking_reminders: includes/booking.php not found\n"); exit(1); }
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

function br_log($m) { echo $m . "\n"; }

try {
    if (!function_exists('booking_tables_ready') || !booking_tables_ready($pdo)) {
        br_log('Booking tables not ready.'); exit;
    }

    // Confirmed, not yet reminded, starting within the next 24 hours.
    $rows = $pdo->query("
        SELECT b.*, t.label
        FROM bookings b
        JOIN booking_types t ON t.id = b.type_id
        WHERE b.status = 'confirmed'
          AND b.reminder_sent_at IS NULL
          AND TIMESTAMP(b.booking_date, b.start_time) > NOW()
          AND TIMESTAMP(b.booking_date, b.start_time) <= NOW() + INTERVAL 24 HOUR
        ORDER BY b.booking_date, b.start_time
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) { br_log('No reminders due.'); exit; }

    $mark = $pdo->prepare("UPDATE bookings SET reminder_sent_at = NOW() WHERE id = ?");
    $sent = 0;

    foreach ($rows as $b) {
        $type = ['label' => $b['label']];
        $ok   = false;
        if (!empty($b['email']) && function_exists('booking_send_reminder')) {
            $ok = @booking_send_reminder($b, $type);
        }
        $mark->execute([(int)$b['id']]); // mark regardless, so a bad address isn't retried every run
        if ($ok) $sent++;
        usleep(120000); // ~0.12s, gentle on the mail queue
    }

    br_log('Reminders: ' . count($rows) . ' due, ' . $sent . ' sent.');

} catch (Throwable $e) {
    error_log('[BCRF] booking reminders failed: ' . $e->getMessage());
    br_log('Error: ' . $e->getMessage());
}
