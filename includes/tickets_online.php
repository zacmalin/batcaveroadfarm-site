<?php // includes/tickets_online.php
/*
 * Farm OS Phase 1, slice 4b (Nov 6, 2026): online admission tickets, written to tickets (C-04), not bookings.
 * Needs migrations 0012 (tickets) and 0013 (payment fields).
 *
 *   bcrf_online_ticket_create($pdo, $post)       validate the ticket form and save the order (pending, or waived if free)
 *   bcrf_online_ticket_checkout($pdo, $ticket)   Stripe Checkout session for an unpaid order (returns its URL)
 *   bcrf_online_ticket_mark_paid($pdo, $id, $sessionId, $amountCents)
 *                                               called by the Stripe webhook and the ticket page; idempotent; the first
 *                                               call issues the ticket, emails it, audits it and sends the GA purchase
 *   bcrf_online_ticket_by_code($pdo, $code)
 *   bcrf_online_ticket_url($code)               the buyer's ticket page; also what the QR code holds, so the gate's
 *                                               scanner (includes/admission.php, ?c=CODE) finds it
 * One QR ticket per order with a quantity (07 › Admission). Good any open day, expires ticket_expiry_months after
 * purchase (Q-04). Online tickets don't reserve a time; entry is subject to capacity; parties of 6+ book (Q-06).
 */

if (!function_exists('bcrf_online_ticket_create')) {

require_once __DIR__ . '/admission.php';

class BcrfTicketFormError extends RuntimeException {}   // message is an error key for tickets/index.php

function bcrf_online_tickets_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tickets' AND column_name = 'order_json'");
        return $ready = bcrf_admission_ready($pdo) && (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) { return $ready = false; }
}

/** Ticket types sold online: the active products in the admissions category. */
function bcrf_online_ticket_types(PDO $pdo): array {
    return $pdo->query("SELECT i.id, i.name, i.price FROM inventory i JOIN inventory_categories ic ON ic.id = i.category_id
                        WHERE ic.slug = 'admissions' AND i.is_active = 1 ORDER BY i.price DESC, i.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bcrf_online_ticket_url(string $code, string $extra = ''): string {
    if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';
    return bcrf_farm_url('/tickets/ticket.php?c=' . rawurlencode($code)) . $extra;
}

function bcrf_online_ticket_by_code(PDO $pdo, string $code): ?array {
    $code = bcrf_ticket_code_norm($code);
    if (strlen($code) !== 10) return null;
    $st = $pdo->prepare("SELECT * FROM tickets WHERE public_code = ? LIMIT 1");
    $st->execute([$code]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Validate the ticket form (same rules as before) and save the order. Throws BcrfTicketFormError('error_key'). */
function bcrf_online_ticket_create(PDO $pdo, array $post): array {
    $types = bcrf_online_ticket_types($pdo);
    $order = []; $people = 0; $cents = 0; $bd = [];
    $raw = (array)($post['qty'] ?? []);
    foreach ($types as $t) {
        $q = max(0, min(50, (int)($raw[(int)$t['id']] ?? 0)));
        if (!$q) continue;
        $unit = (int)round((float)$t['price'] * 100);
        $order[] = ['product_id' => (int)$t['id'], 'name' => (string)$t['name'], 'qty' => $q, 'unit_cents' => $unit];
        $bd[(string)$t['name']] = $q;
        $people += $q; $cents += $q * $unit;
    }
    if ($people < 1) throw new BcrfTicketFormError('no_tickets');
    if ($people >= 6) throw new BcrfTicketFormError('too_many');   // parties of 6+ book (Q-06)

    // Optional date: tickets are good any open day (D-04); a picked date is kept for our planning
    $date = trim((string)($post['visit_date'] ?? ''));
    if ($date !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) < strtotime('today')) throw new BcrfTicketFormError('invalid_date');
        if (!function_exists('site_hours')) require_once __DIR__ . '/site_info.php';
        $closed = [];
        foreach (range(0, 6) as $d) if (!empty(site_hours('general')[$d]['closed'])) $closed[] = $d;
        if (!$closed) $closed = [1];
        if (in_array((int)date('w', strtotime($date)), $closed, true)) throw new BcrfTicketFormError('closed_monday');
    }

    $name  = mb_substr(trim(strip_tags((string)($post['customer_name'] ?? ''))), 0, 120);
    $email = filter_var(trim((string)($post['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $phone = mb_substr(preg_replace('/[^0-9+\-() ]/', '', (string)($post['phone'] ?? '')), 0, 40);
    if ($name === '' || !$email) throw new BcrfTicketFormError('missing_info');

    $months = max(1, bcrf_adm_setting($pdo, 'ticket_expiry_months', 12));
    $free = $cents <= 0;
    for ($try = 0; $try < 5; $try++) {
        try {
            $pdo->prepare("INSERT INTO tickets (public_code, source, purchaser_name, purchaser_email, purchaser_phone, breakdown_json, order_json, quantity,
                                                quantity_redeemed, status, payment_status, visit_date, expires_at, amount_cents, created_at, paid_at)
                           VALUES (?, 'online', ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? MONTH, ?, UTC_TIMESTAMP(), ?)")
                ->execute([$code = bcrf_ticket_code(), $name, strtolower($email), $phone, json_encode($bd, JSON_UNESCAPED_UNICODE), json_encode($order, JSON_UNESCAPED_UNICODE),
                           $people, $free ? 'issued' : 'pending_payment', $free ? 'waived' : 'pending', $date !== '' ? $date : null, $months, $cents,
                           $free ? gmdate('Y-m-d H:i:s') : null]);
            break;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000' || $try === 4) throw $e;   // code clash (1 in 10^15): try another
        }
    }
    $t = bcrf_online_ticket_by_code($pdo, $code);
    if (function_exists('bcrf_event')) bcrf_event('ticket.ordered', 'tickets', (int)$t['id'], ['guests' => $people, 'amount_cents' => $cents]);
    if ($free) bcrf_online_ticket_issued($pdo, $t, null);
    return $t;
}

/** Stripe Checkout for an unpaid order. Returns the session URL. */
function bcrf_online_ticket_checkout(PDO $pdo, array $t): string {
    require_once __DIR__ . '/stripe.php';
    if ($t['payment_status'] !== 'pending') throw new RuntimeException('This order is not waiting for payment.');
    $order = json_decode((string)$t['order_json'], true) ?: [];
    $p = ['mode' => 'payment', 'customer_email' => $t['purchaser_email'],
          'success_url' => bcrf_online_ticket_url($t['public_code'], '&paid=1'),
          'cancel_url'  => bcrf_online_ticket_url($t['public_code'], '&cancelled=1'),
          'metadata[ticket_id]' => (string)$t['id'], 'metadata[ticket_code]' => $t['public_code'],
          'payment_intent_data[metadata][ticket_id]' => (string)$t['id'],
          'payment_intent_data[description]' => 'Admission tickets ' . bcrf_ticket_code_fmt($t['public_code'])];
    $i = 0; $sum = 0;
    foreach ($order as $o) {
        if ((int)$o['unit_cents'] <= 0) continue;   // free admissions (e.g. under 2) ride along on the ticket
        $p["line_items[$i][price_data][currency]"] = 'usd';
        $p["line_items[$i][price_data][unit_amount]"] = (int)$o['unit_cents'];
        $p["line_items[$i][price_data][product_data][name]"] = (string)$o['name'];
        $p["line_items[$i][price_data][product_data][description]"] = $t['visit_date'] ? 'Planned for ' . date('l, F j', strtotime($t['visit_date'])) . ' (good any day we\'re open)' : 'Good any day we\'re open';
        $p["line_items[$i][quantity]"] = (int)$o['qty'];
        $sum += (int)$o['unit_cents'] * (int)$o['qty'];
        $i++;
    }
    if ($sum !== (int)$t['amount_cents'] || $sum <= 0) throw new RuntimeException('Order total does not match its items.');
    $s = stripe_api('POST', 'checkout/sessions', $p);
    if (empty($s['url'])) throw new RuntimeException('Stripe did not return a checkout link.');
    $pdo->prepare("UPDATE tickets SET stripe_session_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$s['id'], $t['id']]);
    return (string)$s['url'];
}

/** Payment confirmed (webhook or ticket page). Returns true only for the call that changed it. */
function bcrf_online_ticket_mark_paid(PDO $pdo, int $id, string $sessionId, ?int $amountCents = null): bool {
    $up = $pdo->prepare("UPDATE tickets SET payment_status = 'paid', status = IF(status = 'pending_payment', 'issued', status), paid_at = UTC_TIMESTAMP(),
                                stripe_session_id = COALESCE(NULLIF(?, ''), stripe_session_id), updated_at = UTC_TIMESTAMP()
                         WHERE id = ? AND payment_status = 'pending'");
    $up->execute([$sessionId, $id]);
    if ($up->rowCount() === 0) return false;
    $st = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (function_exists('bcrf_event')) bcrf_event('ticket.paid', 'tickets', $id, ['amount_cents' => $amountCents ?? (int)$t['amount_cents']]);
    bcrf_online_ticket_issued($pdo, $t, $amountCents);
    return true;
}

/** Email the ticket, audit, GA. Runs once per order (free orders at creation, paid ones when payment lands). */
function bcrf_online_ticket_issued(PDO $pdo, array $t, ?int $amountCents): void {
    $claim = $pdo->prepare("UPDATE tickets SET confirmation_sent_at = UTC_TIMESTAMP() WHERE id = ? AND confirmation_sent_at IS NULL");
    $claim->execute([$t['id']]);
    if ($claim->rowCount() === 1) {
        try { bcrf_online_ticket_email($t); }
        catch (Throwable $e) {
            error_log('[tickets] email ' . $t['id'] . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE tickets SET confirmation_sent_at = NULL WHERE id = ?")->execute([$t['id']]);
        }
    }
    if (function_exists('erp_audit')) {
        erp_audit($pdo, 'update', 'tickets', (int)$t['id'], sprintf('Online tickets %s - %s, %d guest%s, $%s %s', bcrf_ticket_code_fmt($t['public_code']),
            $t['purchaser_name'], (int)$t['quantity'], (int)$t['quantity'] === 1 ? '' : 's', number_format(($amountCents ?? (int)$t['amount_cents']) / 100, 2),
            (int)$t['amount_cents'] > 0 ? 'paid online' : '(free)'), 'Stripe (automatic)');
    }
    if ((int)$t['amount_cents'] > 0) {
        require_once __DIR__ . '/ga.php';
        bcrf_ga_purchase($pdo, 'tickets', (int)$t['id'], $amountCents);
    }
}

function bcrf_online_ticket_email(array $t): void {
    if (!function_exists('bcf_send_mail')) require_once dirname(__DIR__) . '/admin/includes/email_template.php';
    $url = bcrf_online_ticket_url($t['public_code']);
    $lines = '';
    foreach ((array)json_decode((string)$t['breakdown_json'], true) as $name => $q) $lines .= (int)$q . ' × ' . htmlspecialchars((string)$name) . '<br>';
    $exp = $t['expires_at'] ? bcrf_adm_local($t['expires_at'], 'F j, Y') : '';
    $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.3rem;margin:0 0 14px">Your tickets are ready 🐐</h1>'
        . '<p style="margin:0 0 12px">Hi ' . htmlspecialchars($t['purchaser_name']) . ', thanks for your order.</p>'
        . '<p style="margin:0 0 12px">' . $lines . '<strong>Ticket code: ' . bcrf_ticket_code_fmt($t['public_code']) . '</strong></p>'
        . '<p style="margin:0 0 18px;text-align:center"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px">Show my ticket (QR code) →</a></p>'
        . '<p style="font-size:.9rem;margin:0 0 8px">Show the QR code at the gate. Your ticket is good for <strong>any day we\'re open</strong>' . ($exp ? ' until ' . $exp : '') . ', and your party can come in together or on different days.</p>'
        . '<p style="font-size:.85rem;color:rgba(245,234,214,.8);margin:0">Online tickets don\'t reserve a time. We limit how many guests are on the farm at once, so on a busy day you may wait a little. Groups of 6 or more, please book ahead. Tickets are non-refundable.</p>';
    require_once __DIR__ . '/nc_warning.php';
    $inner .= bcrf_nc_warning_email_html();   // G.S. 99E-32 / 99E-8: warnings in every written contract
    if (!bcf_send_mail($t['purchaser_email'], 'Your Bat Cave Road Farm tickets', $inner, ['subtitle' => 'Admission Tickets'])) {
        if (function_exists('bcrf_queue_push') && bcrf_queue_push('email.send', ['to' => $t['purchaser_email'], 'subject' => 'Your Bat Cave Road Farm tickets', 'html' => $inner, 'opts' => ['subtitle' => 'Admission Tickets']])) return;
        throw new RuntimeException('The mail server did not accept the ticket email.');
    }
}

/** Ticket page: if still unpaid but a Stripe session exists, ask Stripe (the webhook may be late). */
function bcrf_online_ticket_check_stripe(PDO $pdo, array $t): array {
    if ($t['payment_status'] !== 'pending' || empty($t['stripe_session_id'])) return $t;
    try {
        require_once __DIR__ . '/stripe.php';
        $s = stripe_retrieve_session((string)$t['stripe_session_id']);
        if (in_array($s['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
            bcrf_online_ticket_mark_paid($pdo, (int)$t['id'], (string)$s['id'], isset($s['amount_total']) ? (int)$s['amount_total'] : null);
            return bcrf_online_ticket_by_code($pdo, $t['public_code']) ?? $t;
        }
    } catch (Throwable $e) { error_log('[tickets] stripe check ' . $t['id'] . ': ' . $e->getMessage()); }
    return $t;
}

}
