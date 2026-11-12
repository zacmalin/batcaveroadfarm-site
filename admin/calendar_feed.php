<?php
// admin/calendar_feed.php — a staff member's private, read-only phone calendar feed (ICS). Farm OS Phase 3, Nov 12, 2026
// (includes/calendar.php; 12 › Master calendar: "Feed URLs are secret tokens and can be reset").
//   GET calendar_feed.php?t=SECRET   (48 hex). No sign-in: calendar apps can't sign in, so the secret is the key.
// Only active staff accounts work, and only the layers their role may see. Unknown or turned-off links get a 404.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/permission_checks.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';
require_once __DIR__ . '/../includes/tasks.php';
require_once __DIR__ . '/../includes/calendar.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$owner = bcrf_cal_feed_ready($pdo) ? bcrf_cal_feed_owner($pdo, (string)($_GET['t'] ?? '')) : null;
if (!$owner) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Calendar not found.\n"; exit; }

try {
    $ics = bcrf_cal_feed_ics($pdo, $owner);
    if (empty($owner['last_used_at']) || strtotime($owner['last_used_at'] . ' UTC') < time() - 600) {
        $pdo->prepare("UPDATE calendar_feeds SET last_used_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$owner['id']]);
    }
} catch (Throwable $e) {
    error_log('[calendar feed] ' . $e->getMessage());
    http_response_code(503); header('Retry-After: 900'); exit;
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="farm-calendar.ics"');
header('Cache-Control: private, max-age=900');
echo $ics;
