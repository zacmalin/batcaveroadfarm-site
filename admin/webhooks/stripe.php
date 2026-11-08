<?php
// admin/webhooks/stripe.php — receives Stripe webhook events.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
// Publicly reachable. Security = Stripe-Signature header verification.
// Register this URL in Stripe Dashboard → Developers → Webhooks.
// Event to listen for: checkout.session.completed
// URL: https://batcaveroadfarm.com/admin/webhooks/stripe.php

// Load config (gives $pdo + BCRF_STRIPE_WH_SEC). No session, no admin check.
$cfgPath = __DIR__ . '/../../config.php';
if (!is_file($cfgPath)) { http_response_code(500); echo 'Config not found'; exit; }
require_once $cfgPath;
require_once __DIR__ . '/../../includes/stripe.php';
require_once __DIR__ . '/../../includes/booking.php';
require_once __DIR__ . '/../includes/audit.php';
if (is_file(__DIR__ . '/../../admin/includes/email_template.php')) {
    require_once __DIR__ . '/../../admin/includes/email_template.php';
}

// Only POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo 'Method not allowed'; exit;
}

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret    = defined('BCRF_STRIPE_WH_SEC') ? BCRF_STRIPE_WH_SEC : '';

if ($secret === '') {
    error_log('[BCRF stripe webhook] BCRF_STRIPE_WH_SEC not set — add it to bcrf-secrets.php');
    http_response_code(500); echo 'Webhook secret not configured'; exit;
}

// Verify signature + decode event
try {
    $event = stripe_verify_webhook($payload, $sigHeader, $secret);
} catch (Throwable $e) {
    error_log('[BCRF stripe webhook] Verification failed: ' . $e->getMessage());
    http_response_code(400); echo 'Webhook verification failed'; exit;
}

// We only care about checkout.session.completed
if (($event['type'] ?? '') !== 'checkout.session.completed') {
    http_response_code(200); echo 'Ignored event type'; exit;
}

$session   = $event['data']['object'] ?? [];

// Only fulfil when Stripe says the money actually arrived
// ('no_payment_required' = 100% coupon, which is fine to fulfil)
if (!in_array($event['data']['object']['payment_status'] ?? 'paid', ['paid', 'no_payment_required'], true)) {
    http_response_code(200); echo 'Payment not completed yet'; exit;
}
$bookingId = (int)($session['metadata']['booking_id'] ?? 0);
$passId    = (int)($session['metadata']['pass_id']    ?? 0);
$ticketId  = (int)($session['metadata']['ticket_id']  ?? 0);   // online admission tickets (Farm OS 4b)
$sessionId = (string)($session['id'] ?? '');

// ── ONLINE TICKETS (Farm OS Phase 1, slice 4b): includes/tickets_online.php issues, emails, audits, GA ──
if ($ticketId > 0) {
    try {
        require_once __DIR__ . '/../../includes/queue.php';
        require_once __DIR__ . '/../../includes/tickets_online.php';
        $done = bcrf_online_ticket_mark_paid($pdo, $ticketId, $sessionId, isset($session['amount_total']) ? (int)$session['amount_total'] : null);
        error_log('[BCRF stripe webhook] Ticket #' . $ticketId . ($done ? ' paid and emailed.' : ' already processed.'));
        http_response_code(200); echo $done ? 'ok' : 'Ticket already processed'; exit;
    } catch (Throwable $e) {
        error_log('[BCRF stripe webhook] Ticket error: ' . $e->getMessage());
        http_response_code(500); echo 'Server error'; exit;
    }
}

if ($bookingId <= 0 && $passId <= 0) {
    error_log('[BCRF stripe webhook] No booking_id or pass_id in session metadata');
    http_response_code(200); echo 'No known entity id'; exit;
}

// ── PASS PAYMENT ─────────────────────────────────────────────────────────────
if ($passId > 0) {
    try {
        require_once __DIR__ . '/../../includes/pass_reservations.php';
        $outcome = bcrf_pass_confirm_payment($pdo, $passId, $sessionId);
        if ($outcome === 'already') { http_response_code(200); echo 'Pass already processed'; exit; }
        if ($outcome === 'late') {
            // Paid, but hold expired: leave inactive and flag for manual reconciliation.
            // Do not automatically refund or consume another customer's slot.
            error_log('[BCRF stripe webhook] ACTION REQUIRED: paid late founding pass #' . $passId);
            http_response_code(200); echo 'Paid pass requires manual review'; exit;
        }

        // Re-read AFTER the update so the email shows the correct expiry date
        $pst = $pdo->prepare("SELECT p.*, t.label, t.duration_months FROM passes p JOIN pass_types t ON t.id=p.pass_type_id WHERE p.id=?");
        $pst->execute([$passId]);
        $pass = $pst->fetch(PDO::FETCH_ASSOC);

        if ($pass && function_exists('bcf_send_mail')) {
            $verifyUrl = (bcrf_farm_url() . '/passes/verify.php?token=') . rawurlencode($pass['token']);
            $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.3rem;margin:0 0 14px">Your annual pass is active! 🎟</h1>'
                . '<p style="margin:0 0 12px">Hi ' . htmlspecialchars($pass['holder_name']) . ', your <strong>' . htmlspecialchars($pass['label']) . '</strong> is confirmed and ready to use.</p>'
                . '<p style="margin:0 0 12px">Valid until <strong>' . date('F j, Y', strtotime($pass['valid_until'])) . '</strong></p>'
                . '<p style="margin:0 0 18px;text-align:center"><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px">View My Pass →</a></p>'
                . '<p style="font-size:.85rem;color:rgba(245,234,214,.8)">Bookmark this link or screenshot the QR code. Show it to staff at the gate. We can\'t wait to see you at the farm!</p>';
            require_once __DIR__ . '/../../includes/nc_warning.php';
            $inner .= bcrf_nc_warning_email_html();   // G.S. 99E-32 / 99E-8
            @bcf_send_mail($pass['email'], 'Your Bat Cave Road Farm annual pass is active!', $inner, ['subtitle' => 'Annual Pass']);
        }

        $amt = isset($session['amount_total']) ? ' $' . number_format(((int)$session['amount_total']) / 100, 2) : '';
        erp_audit($pdo, 'update', 'passes', $passId, ($pass['holder_name'] ?? 'Pass') . ' - ' . ($pass['label'] ?? '') . ' paid online' . $amt . ', active until ' . ($pass['valid_until'] ?? '?'), 'Stripe (automatic)');
        error_log('[BCRF stripe webhook] Pass #' . $passId . ' activated.');
        require_once __DIR__ . '/../../includes/ga.php';
        bcrf_ga_purchase($pdo, 'passes', $passId, isset($session['amount_total']) ? (int)$session['amount_total'] : null);
        http_response_code(200); echo 'ok'; exit;
    } catch (Throwable $e) {
        error_log('[BCRF stripe webhook] Pass error: ' . $e->getMessage());
        http_response_code(500); echo 'Server error'; exit;
    }
}

try {
    // Mark payment as paid (idempotent — WHERE payment_status = 'pending' guards double-fire)
    $upd = $pdo->prepare(
        "UPDATE bookings SET
            payment_status   = 'paid',
            paid_at          = COALESCE(paid_at, NOW()),
            payment_provider = 'stripe',
            payment_reference = ?
         WHERE id = ? AND payment_status = 'pending'"
    );
    $upd->execute([$sessionId, $bookingId]);

    if ($upd->rowCount() === 0) {
        // Already processed or not pending — still return 200 so Stripe doesn't retry
        http_response_code(200); echo 'Already processed'; exit;
    }

    // Load the booking + type for the confirmation email
    $st = $pdo->prepare(
        "SELECT b.*, t.label, t.pricing_model, t.unit_price
         FROM bookings b JOIN booking_types t ON t.id = b.type_id WHERE b.id = ?"
    );
    $st->execute([$bookingId]);
    $booking = $st->fetch(PDO::FETCH_ASSOC);

    if ($booking && function_exists('booking_send_confirmation')) {
        // Enrich the booking array with payment info for the email
        $booking['payment_status']    = 'paid';
        $booking['payment_provider']  = 'stripe';
        $booking['payment_reference'] = $sessionId;
        @booking_send_confirmation($booking, $booking);
    }

    $amt = isset($session['amount_total']) ? ' $' . number_format(((int)$session['amount_total']) / 100, 2) : '';
    erp_audit($pdo, 'update', 'bookings', $bookingId, trim(($booking['customer_name'] ?? 'Booking') . ' (' . ($booking['booking_date'] ?? '') . ')') . ' - paid online' . $amt, 'Stripe (automatic)');
    error_log('[BCRF stripe webhook] Booking #' . $bookingId . ' marked paid, confirmation sent.');
    require_once __DIR__ . '/../../includes/ga.php';
    bcrf_ga_purchase($pdo, 'bookings', $bookingId, isset($session['amount_total']) ? (int)$session['amount_total'] : null);
    http_response_code(200); echo 'ok'; exit;

} catch (Throwable $e) {
    error_log('[BCRF stripe webhook] DB/email error: ' . $e->getMessage());
    http_response_code(500); echo 'Server error'; exit;
}
