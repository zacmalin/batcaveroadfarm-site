<?php
// Self-contained tokenized booking cancellation for batcaveroadfarm.com.
// GET ?id=&t=  -> verify token, show a confirm button (prevents mail-scanner
//                prefetch from auto-cancelling via a bare link).
// POST id,t    -> verify token again, cancel, notify the farm.
// Renders its OWN page (no redirect), mirroring submit.php / send-message.php.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/auth.php';   // csrf_token()/csrf_verify() (fix pack L)

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$id     = (int)($_REQUEST['id'] ?? 0);
$tok    = (string)($_REQUEST['t'] ?? '');

$state  = 'invalid';   // invalid | confirm | done | already | locked
$retryMsg = false;     // true = token expired, ask them to tap again
$b      = null;
$type   = null;

if ($id > 0 && $tok !== '') {
    try {
        $st = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $st->execute([$id]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $b = null; }
}

// Constant-time token match; only proceed if the row exists and the token fits.
$valid = $b && isset($b['token']) && hash_equals((string)$b['token'], $tok);

if ($valid) {
    if (function_exists('booking_type')) {
        // resolve label without requiring is_active
        try {
            $ts = $pdo->prepare("SELECT * FROM booking_types WHERE id = ?");
            $ts->execute([(int)$b['type_id']]);
            $type = $ts->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { $type = null; }
    }
    if (in_array($b['status'], ['cancelled', 'completed', 'no_show'], true)) {
        $state = ($b['status'] === 'cancelled') ? 'already' : 'locked';
    } elseif ($method === 'POST' && !csrf_verify($_POST['csrf_token'] ?? null)) {
        // Fix pack L: the button must come from this page in this browser. If the
        // session timed out (page left open a long time), just show the button
        // again with a fresh token instead of an error.
        $state   = 'confirm';
        $retryMsg = true;
    } elseif ($method === 'POST') {
        try {
            $up = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status IN ('pending','confirmed')");
            $up->execute([$id]);
            $state = 'done';
            // Fix pack O: record the guest cancellation in Admin > Audit Log.
            if ($up->rowCount() > 0) {
                $aud = __DIR__ . '/admin/includes/audit.php';
                if (is_file($aud)) {
                    require_once $aud;
                    erp_audit($pdo, 'update', 'bookings', (int)$id,
                        'Cancelled by guest (email link): ' . $b['customer_name'] . (!empty($b['booking_date']) ? ' (' . $b['booking_date'] . ')' : ''),
                        'Guest');
                }
            }
            // let the farm know a (possibly confirmed) slot just freed up
            if (function_exists('booking_send_admin_alert')) {
                $note = $b;
                $note['customer_name'] = $b['customer_name'] . ' (CANCELLED by guest)';
                $note['notes'] = 'This booking was cancelled via the guest link.';
                @booking_send_admin_alert($note, $type);
            }
        } catch (Throwable $e) {
            error_log('[BCRF cancel] ' . $e->getMessage());
            $state = 'confirm'; // fall back to showing the button again
        }
    } else {
        $state = 'confirm';
    }
}

$titleMap = [
    'invalid' => "Link not found",
    'confirm' => "Cancel your booking?",
    'done'    => "Booking cancelled",
    'already' => "Already cancelled",
    'locked'  => "Can't cancel online",
];
$pageTitle = $titleMap[$state] ?? 'Booking';
$phone     = function_exists('site') ? site('phone.general') : '(828) 216-5488';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle); ?> &mdash; Bat Cave Road Farm</title>
<link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:wght@400;600&display=swap" rel="stylesheet">
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:#f5ead6;font-family:Lora,Georgia,serif;color:#3b2010;padding:24px}
  .card{max-width:480px;background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;
    border-radius:14px;padding:36px 28px;text-align:center;box-shadow:0 10px 30px rgba(59,32,16,.18)}
  .emoji{font-size:3rem;line-height:1}
  h1{font-family:Rye,Georgia,serif;font-size:1.5rem;margin:14px 0 8px;color:#5c3317}
  p{font-size:1rem;line-height:1.6;margin:0 0 22px}
  .summary{background:#fffdf8;border:1px solid #e8d5b0;border-radius:8px;padding:12px 16px;margin:0 0 22px;text-align:left;font-size:.95rem}
  .summary span{color:#8b5c2a;font-weight:600}
  .btn{display:inline-block;background:#5c3317;color:#f5ead6;text-decoration:none;font-weight:600;
    padding:12px 26px;border-radius:8px;border:2px solid #c9923a;cursor:pointer;font-family:Lora,serif;font-size:1rem}
  .btn:hover{background:#3b2010}
  .btn.danger{background:#8a2f1d;border-color:#8a2f1d}
  .btn.danger:hover{background:#6f2415}
  .btn-row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
</style></head>
<body>
  <div class="card">
  <?php if ($state === 'confirm'): ?>
    <div class="emoji">🐐</div>
    <h1>Cancel your booking?</h1>
    <?php if ($retryMsg): ?>
    <p style="background:#fff3cd;border:1px solid #e8b84b;border-radius:8px;padding:10px 14px;font-size:.95rem">This page was open a while, so we need you to tap the button once more.</p>
    <?php endif; ?>
    <div class="summary">
      <p style="margin:0 0 6px"><span>What:</span> <?php echo htmlspecialchars($type['label'] ?? 'Booking'); ?></p>
      <p style="margin:0 0 6px"><span>When:</span> <?php echo htmlspecialchars(booking_fmt_date($b['booking_date']) . ' · ' . booking_fmt_time($b['start_time'])); ?></p>
      <p style="margin:0"><span>Party:</span> <?php echo (int)$b['party_size']; ?></p>
    </div>
    <div class="btn-row">
      <form method="POST" action="https://batcaveroadfarm.com/cancel-booking.php" style="margin:0">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <input type="hidden" name="t" value="<?php echo htmlspecialchars($tok, ENT_QUOTES); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
        <button class="btn danger" type="submit">Yes, cancel it</button>
      </form>
      <a class="btn" href="https://batcaveroadfarm.com/">Keep my booking</a>
    </div>
  <?php elseif ($state === 'done'): ?>
    <div class="emoji">🌾</div>
    <h1>Booking cancelled</h1>
    <p>Your booking has been cancelled and the spot is freed up. We hope to see you another time! Questions? Call us at <?php echo htmlspecialchars($phone); ?>.</p>
    <a class="btn" href="https://batcaveroadfarm.com/booking/">Book another visit</a>
  <?php elseif ($state === 'already'): ?>
    <div class="emoji">🌿</div>
    <h1>Already cancelled</h1>
    <p>This booking was already cancelled — nothing more to do. Want to come another day? We'd love to have you.</p>
    <a class="btn" href="https://batcaveroadfarm.com/booking/">Book a visit</a>
  <?php elseif ($state === 'locked'): ?>
    <div class="emoji">🌿</div>
    <h1>Can't cancel online</h1>
    <p>This booking can no longer be cancelled through this link. Please give us a call at <?php echo htmlspecialchars($phone); ?> and we'll help.</p>
    <a class="btn" href="https://batcaveroadfarm.com/">Back to the farm</a>
  <?php else: ?>
    <div class="emoji">🌾</div>
    <h1>Link not found</h1>
    <p>We couldn't match this cancellation link to a booking. It may have expired or been mistyped. Call us at <?php echo htmlspecialchars($phone); ?> and we'll sort it out.</p>
    <a class="btn" href="https://batcaveroadfarm.com/">Back to the farm</a>
  <?php endif; ?>
  </div>
</body></html>
