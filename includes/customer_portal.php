<?php
// includes/customer_portal.php — the customer portal, "My farm" (Farm OS Phase 3 slice 6, Nov 12, 2026; doc 07 › Customer
// portal, CRM extension decision 5: verified sign-in, profile, linked bookings/tickets, passes, receipts, preferences).
//
//   bcrf_portal_signin_request($pdo, $email, $send)   email a one-time sign-in link (20 minutes). If the address has no
//                                                      account but has bookings, a party, tickets or a pass, a password-free
//                                                      account is made for it first. Never says whether an address is known.
//   bcrf_portal_signin_use($pdo, $token)               spend the link: returns the customer (and marks the email verified,
//                                                      since only the inbox owner could have the link), or null
//   bcrf_portal_items($pdo, $customer)                 what this customer owns: bookings, parties, tickets, passes, receipts
//   bcrf_portal_newsletter / _set                      the farm newsletter switch for the account's email
//
// Ownership (doc 07, Portal and API security): records are matched by the account's email only once that email is verified
// (by the account's verification email or by a sign-in link). Register receipts are matched by the customer the cashier
// attached. Typing an email at checkout is never proof on its own. Nothing here changes a booking, takes a payment or
// cancels anything; paying goes through the existing quote page (booking/quote.php).
if (!function_exists('bcrf_portal_signin_request')) {

if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';
if (!function_exists('bcrf_customer_find')) require_once __DIR__ . '/customer_auth.php';

define('BCRF_PORTAL_LINK_MINUTES', 20);

function bcrf_portal_norm_email(string $e): string { return strtolower(trim($e)); }

/** Does anything on the farm's books belong to this email? (decides whether a sign-in link may make an account) */
function bcrf_portal_email_has_records(PDO $pdo, string $email): bool {
    $email = bcrf_portal_norm_email($email);
    if ($email === '') return false;
    foreach (["SELECT 1 FROM bookings WHERE LOWER(email) = ? LIMIT 1",
              "SELECT 1 FROM event_inquiries WHERE LOWER(email) = ? LIMIT 1",
              "SELECT 1 FROM tickets WHERE LOWER(purchaser_email) = ? AND archived_at IS NULL LIMIT 1",
              "SELECT 1 FROM passes WHERE LOWER(email) = ? LIMIT 1"] as $sql) {
        try { $st = $pdo->prepare($sql); $st->execute([$email]); if ($st->fetchColumn()) return true; }
        catch (Throwable $e) {}   // a table this farm doesn't have yet
    }
    return false;
}

/** First and last name from the newest record for this email (for an account the link makes). */
function bcrf_portal_name_for(PDO $pdo, string $email): array {
    $email = bcrf_portal_norm_email($email);
    foreach (["SELECT customer_name FROM event_inquiries WHERE LOWER(email) = ? ORDER BY status = 'archived', id DESC LIMIT 1",
              "SELECT customer_name FROM bookings WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 1",
              "SELECT holder_name FROM passes WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 1",
              "SELECT purchaser_name FROM tickets WHERE LOWER(purchaser_email) = ? ORDER BY id DESC LIMIT 1"] as $sql) {
        try {
            $st = $pdo->prepare($sql); $st->execute([$email]);
            $n = trim((string)$st->fetchColumn());
            if ($n !== '') {
                $parts = preg_split('/\s+/', $n, 2);
                return [mb_substr($parts[0], 0, 50), mb_substr($parts[1] ?? '', 0, 50)];
            }
        } catch (Throwable $e) {}
    }
    return ['Friend', ''];
}

/**
 * Email a sign-in link. Returns true when a link went out (callers show the same message either way).
 * $send($to, $subject, $html): bool — the mailer; defaults to bcf_send_mail.
 */
function bcrf_portal_signin_request(PDO $pdo, string $email, ?callable $send = null): bool {
    $email = bcrf_portal_norm_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) return false;
    $c = bcrf_customer_find_by_email($pdo, $email);
    if ($c && !bcrf_customer_active($c)) return false;
    if (!$c) {
        if (!bcrf_customers_ready($pdo) || !bcrf_portal_email_has_records($pdo, $email)) return false;
        [$first, $last] = bcrf_portal_name_for($pdo, $email);
        $id = bcrf_customer_create($pdo, $first, $last, $email, null, '');   // no password: they sign in by link (or set one via Forgot password)
        $c = bcrf_customer_find($pdo, $id);
        if (!$c) return false;
    }
    // One live link at a time.
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND purpose = 'signin' AND used_at IS NULL"
                  . (bcrf_resets_have_realm($pdo) ? " AND realm = 'customer'" : ''))->execute([(int)$c['id']]);
    $token = bin2hex(random_bytes(32));
    if (bcrf_resets_have_realm($pdo)) {
        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, purpose, realm) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . BCRF_PORTAL_LINK_MINUTES . " MINUTE), 'signin', 'customer')")
            ->execute([(int)$c['id'], hash('sha256', $token)]);
    } else {
        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, purpose) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . BCRF_PORTAL_LINK_MINUTES . " MINUTE), 'signin')")
            ->execute([(int)$c['id'], hash('sha256', $token)]);
    }
    $link = bcrf_farm_url('/account/link.php?token=' . $token);
    $first = trim((string)($c['first_name'] ?? '')) ?: 'there';
    $farm = bcrf_farm_name();
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $html = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">Your sign-in link</h1>'
        . '<p style="margin:0 0 16px;">Hi ' . $h($first) . ', here\'s your link to sign in to ' . $h($farm) . ' and see your bookings, tickets and passes.</p>'
        . '<p style="margin:0 0 24px;"><a href="' . $h($link) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px;">Sign in</a></p>'
        . '<p style="margin:0;font-size:14px;color:rgba(245,234,214,0.8);">The link works once, for ' . BCRF_PORTAL_LINK_MINUTES . ' minutes. If you didn\'t ask for it, you can ignore this email.</p>';
    $send = $send ?? function (string $to, string $subject, string $html): bool {
        if (!function_exists('bcf_send_mail')) require_once dirname(__DIR__) . '/admin/includes/email_template.php';
        return bcf_send_mail($to, $subject, $html, ['subtitle' => 'Sign in']);
    };
    try { $ok = (bool)$send((string)$c['email'], 'Your sign-in link for ' . $farm, $html); }
    catch (Throwable $e) { $ok = false; error_log('[portal] sign-in link: ' . $e->getMessage()); }
    if (!$ok) error_log('[portal] sign-in link email did not send');
    return $ok;
}

/** The link's account (without spending it), or null when it's unknown, used or expired. */
function bcrf_portal_signin_peek(PDO $pdo, string $token): ?array {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
    if (strlen($token) !== 64) return null;
    $st = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND purpose = 'signin' AND used_at IS NULL AND expires_at > NOW()"
                        . (bcrf_resets_have_realm($pdo) ? " AND realm = 'customer'" : '') . " LIMIT 1");
    $st->execute([hash('sha256', $token)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $c = bcrf_customer_find($pdo, (int)$r['user_id']);
    if (!$c || !bcrf_customer_active($c)) return null;
    $c['_reset_id'] = (int)$r['id'];
    return $c;
}

/** Spend the link. Returns the signed-in customer (email now verified), or null. The caller calls auth_login_user(). */
function bcrf_portal_signin_use(PDO $pdo, string $token): ?array {
    $c = bcrf_portal_signin_peek($pdo, $token);
    if (!$c) return null;
    $st = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    $st->execute([$c['_reset_id']]);
    if ($st->rowCount() !== 1) return null;   // spent by a second click at the same moment
    if (empty($c['email_verified_at'])) {
        bcrf_customer_update($pdo, (int)$c['id'], ['email_verified_at' => 'NOW()']);
        $c = bcrf_customer_find($pdo, (int)$c['id']) ?? $c;
    }
    unset($c['_reset_id']);
    return $c;
}

/** Everything this customer owns, newest first within each group. Empty groups when the email isn't verified. */
function bcrf_portal_items(PDO $pdo, array $c): array {
    $out = ['verified' => !empty($c['email_verified_at']), 'upcoming' => [], 'past' => [], 'tickets' => [], 'passes' => [], 'receipts' => []];
    $email = bcrf_portal_norm_email((string)($c['email'] ?? ''));
    $cid = (int)($c['id'] ?? 0);
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

    if ($out['verified'] && $email !== '') {
        // Bookings and parties, with their quote (what's owed, and the pay page) when there is one.
        $quotes = function_exists('bcrf_quotes_ready') && bcrf_quotes_ready($pdo);
        $subjects = [];
        try {
            $st = $pdo->prepare("SELECT b.id FROM bookings b JOIN booking_types t ON t.id = b.type_id
                                 WHERE LOWER(b.email) = ? AND t.type_key <> 'day_admission' AND b.status <> 'cancelled' ORDER BY b.booking_date DESC, b.id DESC LIMIT 50");
            $st->execute([$email]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $subjects[] = ['booking', (int)$id];
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT id FROM event_inquiries WHERE LOWER(email) = ? AND status NOT IN ('archived') ORDER BY preferred_date DESC, id DESC LIMIT 50");
            $st->execute([$email]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $subjects[] = ['event_inquiry', (int)$id];
        } catch (Throwable $e) {}
        foreach ($subjects as [$type, $id]) {
            if (!function_exists('bcrf_quote_subject') || !($s = bcrf_quote_subject($pdo, $type, $id))) continue;
            $row = ['type' => $type, 'id' => $id, 'what' => $s['what'], 'name' => $s['name'], 'date' => $s['date'],
                    'when' => bcrf_quote_when($s), 'guests' => $s['guests'], 'status' => bcrf_portal_status_label($s),
                    'live' => $s['live'], 'quote_url' => null, 'total' => null, 'paid' => 0, 'balance' => null, 'balance_when' => null];
            if ($quotes && ($q = bcrf_quote_get($pdo, $type, $id)) && !empty($q['sent_at'])) {   // a draft quote stays private until the farm sends it
                $qs = bcrf_quote_status($pdo, $q);
                $row['quote_url'] = bcrf_quote_url($q);
                $row['total'] = (int)$q['total_cents']; $row['paid'] = (int)$qs['paid']; $row['balance'] = (int)$qs['balance'];
                $row['balance_when'] = $q['balance_when'] ?? 'arrival';
            }
            $upcoming = $s['live'] && ($s['date'] === '' || $s['date'] >= $today) && $s['status'] !== 'completed';
            $out[$upcoming ? 'upcoming' : 'past'][] = $row;
        }
        usort($out['upcoming'], fn($a, $b) => [$a['date'] ?: '9999', $a['id']] <=> [$b['date'] ?: '9999', $b['id']]);

        try {
            $st = $pdo->prepare("SELECT public_code, quantity, quantity_redeemed, status, expires_at, created_at, breakdown_json FROM tickets
                                 WHERE (LOWER(purchaser_email) = ? OR customer_id = ?) AND archived_at IS NULL AND payment_status = 'paid'
                                 ORDER BY id DESC LIMIT 50");
            $st->execute([$email, $cid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $left = max(0, (int)$t['quantity'] - (int)$t['quantity_redeemed']);
                $expired = $t['expires_at'] && substr((string)$t['expires_at'], 0, 10) < $today;
                $out['tickets'][] = ['code' => $t['public_code'], 'quantity' => (int)$t['quantity'], 'left' => $left,
                    'usable' => $left > 0 && !$expired && !in_array($t['status'], ['void', 'voided', 'refunded', 'cancelled'], true),
                    'expires' => $t['expires_at'] ? substr((string)$t['expires_at'], 0, 10) : '', 'bought' => substr((string)$t['created_at'], 0, 10),
                    'url' => function_exists('bcrf_online_ticket_url') ? bcrf_online_ticket_url($t['public_code']) : bcrf_farm_url('/tickets/ticket.php?c=' . rawurlencode($t['public_code']))];
            }
        } catch (Throwable $e) {}

        try {
            $st = $pdo->prepare("SELECT p.token, p.holder_name, p.valid_from, p.valid_until, p.status, pt.label AS type_name
                                 FROM passes p LEFT JOIN pass_types pt ON pt.id = p.pass_type_id
                                 WHERE LOWER(p.email) = ? AND p.status <> 'pending_payment' ORDER BY p.valid_until DESC, p.id DESC LIMIT 20");
            $st->execute([$email]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $active = $p['status'] === 'active' && (!$p['valid_until'] || $p['valid_until'] >= $today);
                $out['passes'][] = ['holder' => $p['holder_name'], 'type' => $p['type_name'] ?: 'Annual pass', 'until' => (string)$p['valid_until'],
                                    'active' => $active, 'status' => $active ? 'Active' : ucfirst($p['status'] === 'active' ? 'expired' : (string)$p['status']),
                                    'url' => bcrf_farm_url('/passes/verify.php?token=' . rawurlencode((string)$p['token']))];
            }
        } catch (Throwable $e) {}
    }

    // Register receipts: the cashier attached this account to the sale (that's the proof, so no email check needed).
    if ($cid > 0) {
        try {
            $st = $pdo->prepare("SELECT id, sale_number, status, total_cents, refunded_cents, business_date FROM sales
                                 WHERE customer_id = ? AND status IN ('completed', 'partially_refunded', 'refunded', 'voided') ORDER BY id DESC LIMIT 30");
            $st->execute([$cid]);
            $lines = $pdo->prepare("SELECT description, quantity, unit, line_total_cents FROM sale_lines WHERE sale_id = ? ORDER BY line_no");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lines->execute([$r['id']]);
                $out['receipts'][] = ['number' => $r['sale_number'], 'date' => (string)$r['business_date'], 'total' => (int)$r['total_cents'],
                    'refunded' => (int)$r['refunded_cents'], 'voided' => $r['status'] === 'voided', 'lines' => $lines->fetchAll(PDO::FETCH_ASSOC)];
            }
        } catch (Throwable $e) {}
    }
    return $out;
}

/** A customer-friendly status for a booking or party. */
function bcrf_portal_status_label(array $s): string {
    $map = $s['is_party']
        ? ['new' => 'Request received', 'contacted' => 'We\'re in touch', 'quoted' => 'Quote sent', 'confirmed' => 'Confirmed', 'declined' => 'Not going ahead']
        : ['pending' => 'Request received', 'confirmed' => 'Confirmed', 'completed' => 'Done', 'cancelled' => 'Cancelled', 'no_show' => 'Missed'];
    return $map[$s['status']] ?? ucfirst(str_replace('_', ' ', (string)$s['status']));
}

/** Is the account's email on the newsletter? null when the farm has no mailing list table. */
function bcrf_portal_newsletter(PDO $pdo, string $email): ?bool {
    try {
        $st = $pdo->prepare("SELECT is_subscribed FROM mailing_list WHERE email = ? LIMIT 1");
        $st->execute([bcrf_portal_norm_email($email)]);
        $v = $st->fetchColumn();
        return $v === false ? false : (bool)$v;
    } catch (Throwable $e) { return null; }
}

/** Turn the newsletter on or off. On needs a verified email (that stands in for the double opt-in click). */
function bcrf_portal_newsletter_set(PDO $pdo, array $c, bool $on): bool {
    $email = bcrf_portal_norm_email((string)($c['email'] ?? ''));
    if ($email === '' || ($on && empty($c['email_verified_at']))) return false;
    if ($on) {
        $pdo->prepare("INSERT INTO mailing_list (email, is_subscribed) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_subscribed = 1")->execute([$email]);
    } else {
        $pdo->prepare("UPDATE mailing_list SET is_subscribed = 0 WHERE email = ?")->execute([$email]);
    }
    if (function_exists('bcrf_audit_event')) {
        try { bcrf_audit_event($pdo, ['actor_type' => 'customer', 'actor_id' => (int)$c['id'], 'actor_label' => trim($c['first_name'] . ' ' . $c['last_name']),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'action' => 'update', 'object_type' => 'mailing_list', 'object_id' => (string)$c['id'],
                'object_label' => 'Newsletter turned ' . ($on ? 'on' : 'off') . ' in My farm']); }
        catch (Throwable $e) {}
    }
    return true;
}

}
