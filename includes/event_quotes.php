<?php
// includes/event_quotes.php — Farm OS Phase 3 slice 4 (Nov 12, 2026; 07 › Bookings and Private events):
// quotes, deposits and payments for group bookings (bookings) and parties (event_inquiries).
//
//   Quote     event_quotes: one per booking or party. Price lines (label × qty × unit), total, an optional deposit
//             with a due date, whether the balance is due on arrival or before the day, whether the customer may pay
//             online, and a note. The customer's page is booking/quote.php?q=TOKEN (token-only URL, no session id).
//   Payments  event_payments: + payments and − refunds by method. Stripe ones carry the Checkout session (unique),
//             register ones the receipt (sales.sale_number). Hand-entered mistakes are voided with a reason, never
//             deleted. Paid = sum of rows not voided; balance = total − paid.
//   Stage     derived, nothing stored: no quote → Request; quote not sent → Quote draft; deposit not covered → Deposit
//             due; balance left → Balance due (on arrival / before the day); nothing left → Paid in full.
//   Parties   $10 a guest (site setting party_price_per_guest_cents), paid in full on arrival, no deposit, not online
//             by default (Zac, Nov 12, 2026). Staff can still set a deposit or turn online payment on for one quote.
//   Bookings  start from the booking type's price; a payment taken with the original booking request (bookings.*
//             payment_status 'paid' via Stripe) is copied into the ledger when the quote is first made.
// Emails: the quote (staff press Send; to the customer's own email), and after an online payment a receipt to the
// customer plus a note to the farm. Nothing here confirms a booking or party: staff still do that.
if (!function_exists('bcrf_quotes_ready')) {

if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';

class BcrfQuoteError extends RuntimeException {}

define('BCRF_QUOTE_METHODS', ['cash' => 'Cash', 'check' => 'Check', 'paypal' => 'PayPal', 'venmo' => 'Venmo', 'cashapp' => 'Cash App',
                               'register' => 'Rung up on the register', 'other' => 'Other']);
define('BCRF_QUOTE_MAX_CENTS', 10000000);   // $100,000: a typo guard, not a policy

function bcrf_quotes_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM event_quotes LIMIT 0"); $pdo->query("SELECT 1 FROM event_payments LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function bcrf_quote_money(int $cents): string {
    return ($cents < 0 ? '-' : '') . '$' . number_format(abs($cents) / 100, 2);
}

/** "12.50" / "$12" / "12" → cents; null if it isn't a money amount. */
function bcrf_quote_parse_cents($v): ?int {
    $s = trim(str_replace([',', '$', ' '], '', (string)$v));
    if ($s === '') return null;
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $s)) return null;
    [$d, $c] = array_pad(explode('.', $s, 2), 2, '');
    return (int)$d * 100 + (int)str_pad($c, 2, '0');
}

function bcrf_quote_setting_int(PDO $pdo, string $key, int $default): int {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v !== false && is_numeric($v)) ? max(0, (int)$v) : $default;
    } catch (Throwable $e) { return $default; }
}

function bcrf_quote_party_price(PDO $pdo): int { return bcrf_quote_setting_int($pdo, 'party_price_per_guest_cents', 1000); }

function bcrf_quote_is_manager(): bool { return in_array((string)($_SESSION['user_role'] ?? ''), ['administrator', 'moderator'], true); }

define('BCRF_QUOTE_PARTY_KINDS', ['birthday' => 'Birthday party', 'field_trip' => 'Field trip', 'buyout' => 'Farm buyout',
                                   'corporate' => 'Corporate event', 'other' => 'Private event']);

/**
 * The booking or party a quote is for, in one shape:
 * type, id, name, email, phone, date (Y-m-d or ''), start, end (H:i:s or ''), guests, adults, children, what, status,
 * live (false once cancelled / declined / archived / no-show), is_party, raw (the row).
 */
function bcrf_quote_subject(PDO $pdo, string $type, int $id): ?array {
    if ($id <= 0) return null;
    if ($type === 'booking') {
        $st = $pdo->prepare("SELECT b.*, t.label AS type_label, t.pricing_model, t.unit_price, t.requires_payment
                             FROM bookings b JOIN booking_types t ON t.id = b.type_id WHERE b.id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['type' => 'booking', 'id' => (int)$r['id'], 'name' => (string)$r['customer_name'], 'email' => (string)$r['email'],
                'phone' => (string)$r['phone'], 'date' => !empty($r['date_flexible']) ? '' : (string)$r['booking_date'],
                'start' => (string)$r['start_time'], 'end' => (string)$r['end_time'], 'guests' => (int)$r['party_size'],
                'adults' => null, 'children' => null, 'what' => (string)$r['type_label'], 'status' => (string)$r['status'],
                'live' => in_array($r['status'], ['pending', 'confirmed', 'completed'], true), 'is_party' => false, 'raw' => $r];
    }
    if ($type === 'event_inquiry') {
        $st = $pdo->prepare("SELECT * FROM event_inquiries WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $a = (int)$r['adult_count']; $c = (int)$r['child_count'];
        return ['type' => 'event_inquiry', 'id' => (int)$r['id'], 'name' => (string)$r['customer_name'], 'email' => (string)$r['email'],
                'phone' => (string)($r['phone'] ?? ''), 'date' => (string)($r['preferred_date'] ?? ''), 'start' => (string)($r['start_time'] ?? ''), 'end' => '',
                'guests' => $a + $c, 'adults' => $a, 'children' => $c,
                'what' => BCRF_QUOTE_PARTY_KINDS[$r['event_type']] ?? (BCRF_QUOTE_PARTY_KINDS['other']),
                'status' => (string)$r['status'], 'live' => !in_array($r['status'], ['declined', 'archived'], true), 'is_party' => true, 'raw' => $r];
    }
    return null;
}

/** "Saturday, December 5, 2026 · 2:00 PM" (or "Date to be agreed"). */
function bcrf_quote_when(array $s): string {
    if ($s['date'] === '') return 'Date to be agreed';
    $out = date('l, F j, Y', strtotime($s['date']));
    if ($s['start'] !== '') $out .= ' · ' . date('g:i A', strtotime($s['start']));
    return $out;
}

/** Starting price lines for a new quote. */
function bcrf_quote_default_lines(PDO $pdo, array $s): array {
    if ($s['is_party']) {
        // The party's package sets the price when one is picked (includes/parties.php, Nov 12, 2026).
        if (!empty($s['raw']['package_id']) && function_exists('bcrf_party_package')
            && ($p = bcrf_party_package($pdo, (int)$s['raw']['package_id'])) && ($lines = bcrf_party_price_lines($p, $s['guests']))) return $lines;
        return [['label' => 'Party guests (admission, plus a feed cup for each child)', 'qty' => max(1, $s['guests']),
                 'unit_cents' => bcrf_quote_party_price($pdo)]];
    }
    $unit = (int)round(((float)($s['raw']['unit_price'] ?? 0)) * 100);
    if ($unit <= 0) return [];
    return ($s['raw']['pricing_model'] ?? 'flat') === 'per_person'
        ? [['label' => $s['what'] . ' (per guest)', 'qty' => max(1, $s['guests']), 'unit_cents' => $unit]]
        : [['label' => $s['what'], 'qty' => 1, 'unit_cents' => $unit]];
}

function bcrf_quote_decode(array $q): array {
    $q['id'] = (int)$q['id']; $q['subject_id'] = (int)$q['subject_id'];
    foreach (['total_cents', 'deposit_cents', 'pay_online', 'sent_count'] as $k) $q[$k] = (int)$q[$k];
    $q['lines'] = json_decode((string)$q['lines_json'], true) ?: [];
    return $q;
}

function bcrf_quote_get(PDO $pdo, string $type, int $id, bool $lock = false): ?array {
    $st = $pdo->prepare("SELECT * FROM event_quotes WHERE subject_type = ? AND subject_id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$type, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? bcrf_quote_decode($r) : null;
}

function bcrf_quote_by_id(PDO $pdo, int $id, bool $lock = false): ?array {
    $st = $pdo->prepare("SELECT * FROM event_quotes WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? bcrf_quote_decode($r) : null;
}

function bcrf_quote_by_token(PDO $pdo, string $token): ?array {
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $st = $pdo->prepare("SELECT * FROM event_quotes WHERE token = ?");
    $st->execute([$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? bcrf_quote_decode($r) : null;
}

function bcrf_quote_url(array $q, string $extra = ''): string {
    return bcrf_farm_url() . '/booking/quote.php?q=' . rawurlencode($q['token']) . $extra;
}

/** Ledger rows for a quote, oldest first. */
function bcrf_quote_payments(PDO $pdo, int $quoteId): array {
    $st = $pdo->prepare("SELECT p.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS recorded_name
                         FROM event_payments p LEFT JOIN users u ON u.id = p.recorded_by WHERE p.quote_id = ? ORDER BY p.id");
    $st->execute([$quoteId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['amount_cents'] = (int)$r['amount_cents']; }
    return $rows;
}

/**
 * Where the money stands. paid, balance (total − paid; negative = refund owed), deposit_left, stage and a label.
 * stage: no_price | draft | deposit_due | balance_due | paid | overpaid
 */
function bcrf_quote_status(PDO $pdo, array $q): array {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM event_payments WHERE quote_id = ? AND voided_at IS NULL");
    $st->execute([$q['id']]);
    $paid = (int)$st->fetchColumn();
    $total = $q['total_cents'];
    $bal = $total - $paid;
    $depLeft = max(0, min($q['deposit_cents'], $total) - $paid);
    $m = 'bcrf_quote_money';
    if ($total <= 0 && $paid === 0)  { $stage = 'no_price';    $label = 'No price yet'; }
    elseif ($bal < 0)                { $stage = 'overpaid';    $label = 'Refund owed ' . $m(-$bal); }
    elseif ($bal === 0)              { $stage = 'paid';        $label = 'Paid in full'; }
    elseif ($depLeft > 0)            { $stage = 'deposit_due'; $label = 'Deposit due ' . $m($depLeft); }
    else                             { $stage = 'balance_due'; $label = $m($bal) . ($q['balance_when'] === 'before' ? ' due before the day' : ' due on arrival'); }
    if (!$q['sent_at'] && in_array($stage, ['deposit_due', 'balance_due'], true) && $paid === 0) {
        $label = 'Quote ' . $m($total) . ' · not sent';
        $stage = 'draft';
    }
    return ['paid' => $paid, 'balance' => $bal, 'deposit_left' => $depLeft, 'stage' => $stage, 'label' => $label];
}

/** Quote + status for a list of subjects in one query each: [subject_id => ['quote' => ..., 'status' => ...]]. */
function bcrf_quote_badges(PDO $pdo, string $type, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !bcrf_quotes_ready($pdo)) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM event_quotes WHERE subject_type = ? AND subject_id IN ($in)");
    $st->execute(array_merge([$type], $ids));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $q = bcrf_quote_decode($r);
        $out[$q['subject_id']] = ['quote' => $q, 'status' => bcrf_quote_status($pdo, $q)];
    }
    return $out;
}

/** Clean price lines from the editor: [{label, qty, unit_cents}], blank rows dropped. Throws on a half-filled row. */
function bcrf_quote_clean_lines(array $labels, array $qtys, array $units): array {
    $out = [];
    foreach ($labels as $i => $label) {
        $label = mb_substr(trim(strip_tags((string)$label)), 0, 150);
        $qRaw = trim((string)($qtys[$i] ?? ''));
        $uRaw = trim((string)($units[$i] ?? ''));
        if ($label === '' && $qRaw === '' && $uRaw === '') continue;
        if ($label === '') throw new BcrfQuoteError('Line ' . ($i + 1) . ' needs a description.');
        if (!preg_match('/^\d{1,4}$/', $qRaw) || (int)$qRaw < 1) throw new BcrfQuoteError('Line ' . ($i + 1) . ': quantity must be a whole number, 1 or more.');
        $u = bcrf_quote_parse_cents($uRaw);
        if ($u === null) throw new BcrfQuoteError('Line ' . ($i + 1) . ': price must be an amount like 10 or 12.50.');
        $out[] = ['label' => $label, 'qty' => (int)$qRaw, 'unit_cents' => $u];
    }
    return $out;
}

function bcrf_quote_lines_total(array $lines): int {
    $t = 0;
    foreach ($lines as $l) $t += (int)$l['qty'] * (int)$l['unit_cents'];
    return $t;
}

/** Make the quote for a booking or party with the starting price (or return the one it has). */
function bcrf_quote_create(PDO $pdo, string $type, int $id, ?int $uid): array {
    $s = bcrf_quote_subject($pdo, $type, $id);
    if (!$s) throw new BcrfQuoteError('That booking or party was not found.');
    $have = bcrf_quote_get($pdo, $type, $id);
    if ($have) return $have;
    $lines = bcrf_quote_default_lines($pdo, $s);
    try {
        $pdo->prepare("INSERT INTO event_quotes (subject_type, subject_id, token, lines_json, total_cents, deposit_cents, balance_when, pay_online, created_at, created_by)
                       VALUES (?, ?, ?, ?, ?, 0, 'arrival', ?, UTC_TIMESTAMP(), ?)")
            ->execute([$type, $id, bin2hex(random_bytes(16)), json_encode($lines, JSON_UNESCAPED_UNICODE), bcrf_quote_lines_total($lines),
                       $s['is_party'] ? 0 : 1, $uid]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;   // made by someone else a moment ago: use theirs
    }
    $q = bcrf_quote_get($pdo, $type, $id);
    // A booking paid in full with its original request (booking/checkout.php → Stripe): that money is already in.
    if (!$s['is_party'] && ($s['raw']['payment_status'] ?? '') === 'paid' && ($s['raw']['payment_provider'] ?? '') === 'stripe'
        && (float)($s['raw']['amount_total'] ?? 0) > 0) {
        try {
            $pdo->prepare("INSERT INTO event_payments (quote_id, subject_type, subject_id, kind, method, amount_cents, reference, stripe_session_id, note, recorded_by, created_at)
                           VALUES (?, 'booking', ?, 'payment', 'stripe', ?, NULL, ?, 'Paid online with the booking request', NULL, COALESCE(?, UTC_TIMESTAMP()))")
                ->execute([$q['id'], $id, (int)round((float)$s['raw']['amount_total'] * 100), ($s['raw']['payment_reference'] ?? '') ?: null,
                           bcrf_quote_local_to_utc($s['raw']['paid_at'] ?? null)]);
        } catch (PDOException $e) { if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e; }
    }
    bcrf_quote_audit($pdo, 'create', $q, $s, 'quote started at ' . bcrf_quote_money($q['total_cents']));
    return $q;
}

/** bookings.paid_at is server-local (old code used NOW()); ledger times are UTC. */
function bcrf_quote_local_to_utc(?string $dt): ?string {
    if (!$dt) return null;
    try { return (new DateTimeImmutable($dt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    catch (Throwable $e) { return null; }
}

/**
 * Save the editor. $in: lines (from bcrf_quote_clean_lines), deposit (cents), deposit_due ('' or Y-m-d),
 * balance_when ('arrival'|'before'), pay_online (bool), customer_note.
 */
function bcrf_quote_save(PDO $pdo, int $quoteId, array $in, ?int $uid): array {
    $lines = $in['lines'];
    if (!$lines) throw new BcrfQuoteError('Add at least one price line.');
    $total = bcrf_quote_lines_total($lines);
    if ($total > BCRF_QUOTE_MAX_CENTS) throw new BcrfQuoteError('That total looks too big. Check the quantities and prices.');
    $dep = (int)$in['deposit'];
    if ($dep < 0 || $dep > $total) throw new BcrfQuoteError('The deposit can\'t be more than the total.');
    $due = trim((string)($in['deposit_due'] ?? ''));
    if ($due !== '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) && checkdate((int)substr($due, 5, 2), (int)substr($due, 8, 2), (int)substr($due, 0, 4)))) {
        throw new BcrfQuoteError('Pick a real date for the deposit, or leave it blank.');
    }
    if ($dep === 0) $due = '';
    $when = ($in['balance_when'] ?? '') === 'before' ? 'before' : 'arrival';
    $note = trim(str_replace("\r", '', strip_tags((string)($in['customer_note'] ?? ''))));
    if (mb_strlen($note) > 1000) throw new BcrfQuoteError('Keep the note under 1,000 characters.');
    $old = bcrf_quote_by_id($pdo, $quoteId);
    if (!$old) throw new BcrfQuoteError('That quote was not found.');
    $pdo->prepare("UPDATE event_quotes SET lines_json = ?, total_cents = ?, deposit_cents = ?, deposit_due = ?, balance_when = ?, pay_online = ?,
                   customer_note = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
        ->execute([json_encode($lines, JSON_UNESCAPED_UNICODE), $total, $dep, $due !== '' ? $due : null, $when, !empty($in['pay_online']) ? 1 : 0,
                   $note !== '' ? $note : null, $uid, $quoteId]);
    $q = bcrf_quote_by_id($pdo, $quoteId);
    $chg = [];
    if ($old['total_cents'] !== $q['total_cents']) $chg[] = 'total ' . bcrf_quote_money($old['total_cents']) . ' → ' . bcrf_quote_money($q['total_cents']);
    if ($old['deposit_cents'] !== $q['deposit_cents']) $chg[] = 'deposit ' . bcrf_quote_money($old['deposit_cents']) . ' → ' . bcrf_quote_money($q['deposit_cents']);
    if ($old['pay_online'] !== $q['pay_online']) $chg[] = 'online payment ' . ($q['pay_online'] ? 'on' : 'off');
    if ($old['balance_when'] !== $q['balance_when']) $chg[] = 'balance due ' . ($q['balance_when'] === 'before' ? 'before the day' : 'on arrival');
    bcrf_quote_audit($pdo, 'update', $q, null, 'quote saved' . ($chg ? ': ' . implode(', ', $chg) : ''));
    return $q;
}

/** Record a payment taken by hand (cash, check, P2P, register, other). Returns the new row id. */
function bcrf_quote_record_payment(PDO $pdo, int $quoteId, string $method, int $cents, string $reference, string $note, ?int $uid): int {
    if (!isset(BCRF_QUOTE_METHODS[$method])) throw new BcrfQuoteError('Choose how they paid.');
    if ($cents <= 0) throw new BcrfQuoteError('Enter the amount they paid.');
    $reference = mb_substr(trim(strip_tags($reference)), 0, 120);
    $note = mb_substr(trim(strip_tags($note)), 0, 255);
    $saleId = null;
    $pdo->beginTransaction();
    try {
        $q = bcrf_quote_by_id($pdo, $quoteId, true);
        if (!$q) throw new BcrfQuoteError('That quote was not found.');
        $s = bcrf_quote_status($pdo, $q);
        if ($cents > max(0, $s['balance'])) {
            throw new BcrfQuoteError($s['balance'] > 0 ? 'That is more than the ' . bcrf_quote_money($s['balance']) . ' left to pay. Change the quote first if the price went up.'
                                                       : 'Nothing is left to pay on this quote. Change the quote first if the price went up.');
        }
        if ($method === 'register') {
            $num = strtoupper(preg_replace('/\s+/', '', $reference));
            if ($num === '') throw new BcrfQuoteError('Type the receipt number from the register (like R-2026-000123).');
            $st = $pdo->prepare("SELECT id, status, total_cents FROM sales WHERE sale_number = ?");
            $st->execute([$num]);
            $sale = $st->fetch(PDO::FETCH_ASSOC);
            if (!$sale) throw new BcrfQuoteError('No register sale has receipt number ' . $num . '.');
            if ($sale['status'] === 'voided') throw new BcrfQuoteError('Receipt ' . $num . ' was voided.');
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM event_payments WHERE sale_id = ? AND voided_at IS NULL");
            $st->execute([(int)$sale['id']]);
            if ((int)$st->fetchColumn() + $cents > (int)$sale['total_cents']) {
                throw new BcrfQuoteError('Receipt ' . $num . ' was for ' . bcrf_quote_money((int)$sale['total_cents']) . '; that is already used up by other payments.');
            }
            $saleId = (int)$sale['id']; $reference = $num;
        } elseif (in_array($method, ['paypal', 'venmo', 'cashapp', 'check'], true) && $reference === '' && $note === '') {
            // allowed, but the P2P matching screen works better with a reference
        }
        $pdo->prepare("INSERT INTO event_payments (quote_id, subject_type, subject_id, kind, method, amount_cents, reference, sale_id, note, recorded_by, created_at)
                       VALUES (?, ?, ?, 'payment', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
            ->execute([$q['id'], $q['subject_type'], $q['subject_id'], $method, $cents, $reference !== '' ? $reference : null, $saleId, $note !== '' ? $note : null, $uid]);
        $pid = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    if (function_exists('bcrf_event')) bcrf_event('event_payment.recorded', 'event_payments', $pid, ['quote_id' => $q['id'], 'amount_cents' => $cents, 'method' => $method]);
    bcrf_quote_audit($pdo, 'update', $q, null, 'payment ' . bcrf_quote_money($cents) . ' (' . BCRF_QUOTE_METHODS[$method] . ($reference !== '' ? ' ' . $reference : '') . ')');
    return $pid;
}

/** Void a hand-entered row typed by mistake. Stripe rows can't be voided: refund them instead. Owner or Manager. */
function bcrf_quote_void_payment(PDO $pdo, int $paymentId, string $reason, ?int $uid): void {
    $reason = mb_substr(trim(strip_tags($reason)), 0, 255);
    if ($reason === '') throw new BcrfQuoteError('Say why this payment is being removed.');
    $st = $pdo->prepare("SELECT * FROM event_payments WHERE id = ?");
    $st->execute([$paymentId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new BcrfQuoteError('That payment was not found.');
    if ($p['voided_at']) throw new BcrfQuoteError('That payment was already removed.');
    if ($p['method'] === 'stripe') throw new BcrfQuoteError('Online payments can\'t be removed. Use Refund to send the money back.');
    if ($p['kind'] === 'payment') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM event_payments WHERE refund_of = ? AND voided_at IS NULL");
        $st->execute([$paymentId]);
        if ((int)$st->fetchColumn() > 0) throw new BcrfQuoteError('Remove the refunds on this payment first.');
    }
    $pdo->prepare("UPDATE event_payments SET voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL")
        ->execute([$uid, $reason, $paymentId]);
    $q = bcrf_quote_by_id($pdo, (int)$p['quote_id']);
    bcrf_quote_audit($pdo, 'update', $q, null, ($p['kind'] === 'refund' ? 'refund ' : 'payment ') . bcrf_quote_money(abs((int)$p['amount_cents'])) . ' removed: ' . $reason);
}

/**
 * Give money back from one payment. Stripe payments are refunded through Stripe ($stripeRefund is the API call, so
 * tests can stand in for it); others are recorded as handed back by the same method. Owner or Manager.
 */
function bcrf_quote_refund(PDO $pdo, int $paymentId, int $cents, string $reason, ?int $uid, ?callable $stripeRefund = null): int {
    $reason = mb_substr(trim(strip_tags($reason)), 0, 255);
    if ($reason === '') throw new BcrfQuoteError('Say why you\'re refunding.');
    if ($cents <= 0) throw new BcrfQuoteError('Enter the amount to refund.');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM event_payments WHERE id = ? FOR UPDATE");
        $st->execute([$paymentId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || $p['kind'] !== 'payment' || $p['voided_at']) throw new BcrfQuoteError('Pick a payment to refund.');
        $st = $pdo->prepare("SELECT COALESCE(-SUM(amount_cents), 0) FROM event_payments WHERE refund_of = ? AND voided_at IS NULL");
        $st->execute([$paymentId]);
        $left = (int)$p['amount_cents'] - (int)$st->fetchColumn();
        if ($cents > $left) throw new BcrfQuoteError('Only ' . bcrf_quote_money($left) . ' of that payment can still be refunded.');
        $refundId = null;
        if ($p['method'] === 'stripe') {
            if (empty($p['stripe_session_id'])) throw new BcrfQuoteError('This online payment has no Stripe record here. Refund it in the Stripe dashboard, then record it as Other.');
            $stripeRefund = $stripeRefund ?? 'bcrf_quote_stripe_refund_api';
            $refundId = (string)$stripeRefund((string)$p['stripe_session_id'], $cents);
            if ($refundId === '') throw new BcrfQuoteError('Stripe did not confirm the refund.');
        }
        $pdo->prepare("INSERT INTO event_payments (quote_id, subject_type, subject_id, kind, method, amount_cents, reference, stripe_refund_id, refund_of, note, recorded_by, created_at)
                       VALUES (?, ?, ?, 'refund', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
            ->execute([$p['quote_id'], $p['subject_type'], $p['subject_id'], $p['method'], -$cents, $p['reference'], $refundId, $paymentId, $reason, $uid]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $q = bcrf_quote_by_id($pdo, (int)$p['quote_id']);
    if (function_exists('bcrf_event')) bcrf_event('event_payment.refunded', 'event_payments', $rid, ['quote_id' => $q['id'], 'amount_cents' => $cents]);
    bcrf_quote_audit($pdo, 'update', $q, null, 'refund ' . bcrf_quote_money($cents) . ' (' . ($p['method'] === 'stripe' ? 'Stripe' : (BCRF_QUOTE_METHODS[$p['method']] ?? $p['method'])) . '): ' . $reason);
    return $rid;
}

/** Stripe: refund part or all of a Checkout session's payment. Returns the refund id. */
function bcrf_quote_stripe_refund_api(string $sessionId, int $cents): string {
    require_once __DIR__ . '/stripe.php';
    $s = stripe_retrieve_session($sessionId);
    $pi = (string)($s['payment_intent'] ?? '');
    if ($pi === '') throw new BcrfQuoteError('Stripe has no payment for that checkout.');
    $r = stripe_api('POST', 'refunds', ['payment_intent' => $pi, 'amount' => $cents]);
    return (string)($r['id'] ?? '');
}

// ── The customer's side: online payment ──

/** What the quote page can take online right now: ['deposit' => cents, 'balance' => cents] (0 = not offered). */
function bcrf_quote_payable(PDO $pdo, array $q, ?array $subject = null, ?array $status = null): array {
    $subject = $subject ?? bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
    $status = $status ?? bcrf_quote_status($pdo, $q);
    if (!$q['pay_online'] || !$subject || !$subject['live'] || ($subject['status'] ?? '') === 'completed' || $status['balance'] <= 0) {
        return ['deposit' => 0, 'balance' => 0];
    }
    $dep = $status['deposit_left'];
    return ['deposit' => ($dep > 0 && $dep < $status['balance']) ? $dep : 0, 'balance' => $status['balance']];
}

/** Start Stripe Checkout for the deposit or the balance. Returns the Checkout URL. */
function bcrf_quote_checkout(PDO $pdo, array $q, string $what): string {
    require_once __DIR__ . '/stripe.php';
    $s = bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
    $pay = bcrf_quote_payable($pdo, $q, $s);
    $amt = $what === 'deposit' ? $pay['deposit'] : $pay['balance'];
    if ($amt <= 0) throw new BcrfQuoteError('There is nothing to pay online on this quote right now.');
    $name = ($what === 'deposit' ? 'Deposit' : 'Payment') . ': ' . $s['what'];
    $sess = stripe_api('POST', 'checkout/sessions', [
        'mode' => 'payment',
        'customer_email' => $s['email'] ?: null,
        'success_url' => bcrf_quote_url($q, '&paid=1'),
        'cancel_url'  => bcrf_quote_url($q, '&cancelled=1'),
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => $amt,
        'line_items[0][price_data][product_data][name]' => $name,
        'line_items[0][price_data][product_data][description]' => $s['name'] . ' · ' . bcrf_quote_when($s),
        'line_items[0][quantity]' => 1,
        'metadata[quote_id]' => (string)$q['id'],
        'payment_intent_data[metadata][quote_id]' => (string)$q['id'],
        'payment_intent_data[description]' => $name . ' (' . $s['name'] . ')',
    ]);
    if (empty($sess['url'])) throw new RuntimeException('Stripe did not return a checkout link.');
    $pdo->prepare("UPDATE event_quotes SET stripe_session_id = ? WHERE id = ?")->execute([$sess['id'], $q['id']]);
    return (string)$sess['url'];
}

/**
 * Money arrived through Stripe (webhook, or the quote page asking Stripe). Records it once per Checkout session;
 * returns true only for the call that recorded it, then emails the customer a receipt and tells the farm.
 */
function bcrf_quote_stripe_paid(PDO $pdo, int $quoteId, string $sessionId, int $amountCents, string $paymentIntent = ''): bool {
    if ($sessionId === '' || $amountCents <= 0) return false;
    $q = bcrf_quote_by_id($pdo, $quoteId);
    if (!$q) { error_log('[quotes] ACTION REQUIRED: Stripe payment ' . $sessionId . ' for missing quote ' . $quoteId); return false; }
    try {
        $pdo->prepare("INSERT INTO event_payments (quote_id, subject_type, subject_id, kind, method, amount_cents, reference, stripe_session_id, note, recorded_by, created_at)
                       VALUES (?, ?, ?, 'payment', 'stripe', ?, ?, ?, 'Paid online', NULL, UTC_TIMESTAMP())")
            ->execute([$q['id'], $q['subject_type'], $q['subject_id'], $amountCents, $paymentIntent !== '' ? mb_substr($paymentIntent, 0, 120) : null, $sessionId]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) return false;   // already recorded (webhook retry, or the page got there first)
        throw $e;
    }
    $pid = (int)$pdo->lastInsertId();
    if (function_exists('bcrf_event')) bcrf_event('event_payment.recorded', 'event_payments', $pid, ['quote_id' => $q['id'], 'amount_cents' => $amountCents, 'method' => 'stripe']);
    $subj = bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
    bcrf_quote_audit($pdo, 'update', $q, $subj, 'paid online ' . bcrf_quote_money($amountCents), 'Stripe (automatic)');
    bcrf_quote_paid_emails($pdo, $q, $subj, $amountCents);
    return true;
}

/** Quote page: a checkout was started and nothing is recorded for it yet? Ask Stripe (the webhook may be behind). */
function bcrf_quote_check_stripe(PDO $pdo, array $q): void {
    $sid = (string)($q['stripe_session_id'] ?? '');
    if ($sid === '') return;
    $st = $pdo->prepare("SELECT 1 FROM event_payments WHERE stripe_session_id = ?");
    $st->execute([$sid]);
    if ($st->fetchColumn()) return;
    try {
        require_once __DIR__ . '/stripe.php';
        $s = stripe_retrieve_session($sid);
        if (in_array($s['payment_status'] ?? '', ['paid', 'no_payment_required'], true) && (int)($s['metadata']['quote_id'] ?? 0) === $q['id']) {
            bcrf_quote_stripe_paid($pdo, $q['id'], (string)$s['id'], (int)($s['amount_total'] ?? 0), (string)($s['payment_intent'] ?? ''));
        }
    } catch (Throwable $e) { error_log('[quotes] stripe check ' . $q['id'] . ': ' . $e->getMessage()); }
}

/** First time the customer opens their quote page. */
function bcrf_quote_mark_viewed(PDO $pdo, array $q): void {
    if (!empty($q['viewed_at'])) return;
    try { $pdo->prepare("UPDATE event_quotes SET viewed_at = UTC_TIMESTAMP() WHERE id = ? AND viewed_at IS NULL")->execute([$q['id']]); }
    catch (Throwable $e) { /* not important */ }
}

// ── Emails ──

/** The farm's terms the customer sees on the quote (email and page): plain sentences. */
function bcrf_quote_terms(array $q, array $s): array {
    if ($s['is_party']) {
        return [
            '$10 a guest covers admission for everyone and a souvenir feed cup for each child.',
            $q['deposit_cents'] > 0 ? 'The deposit holds your date; the rest is paid when you arrive.' : 'No deposit: pay in full when you arrive (cash, PayPal, Venmo or Cash App).',
            'Bring your own food, cake and decorations. We have a table for you, but no chairs.',
            'We\'re open rain or shine. If the weather looks bad, you can move your party to another day up to two weeks later.',
            'Every child needs an adult with them around the animals.',
        ];
    }
    $t = [];
    if ($q['deposit_cents'] > 0) $t[] = 'Your date is held once the deposit is paid' . ($q['deposit_due'] ? ' (by ' . date('F j', strtotime($q['deposit_due'])) . ')' : '') . '.';
    $t[] = $q['balance_when'] === 'before' ? 'The balance is due before the day of your visit.' : 'The balance is due when you arrive.';
    $t[] = 'Cancel 30 or more days ahead for a full refund; within 30 days we can\'t refund.';
    $t[] = 'Every child needs an adult with them around the animals.';
    return $t;
}

function bcrf_quote_signoff(): string {
    return function_exists('site_setting') ? site_setting('quote_signoff', 'Zac, BCRF') : 'Zac, BCRF';
}

/** HTML table of the quote lines, total, paid and balance (email). */
function bcrf_quote_lines_html(array $q, array $status): string {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $td = 'padding:4px 10px 4px 0;color:#f5ead6;vertical-align:top';
    $out = '<table style="border-collapse:collapse;margin:8px 0 14px;width:100%">';
    foreach ($q['lines'] as $l) {
        $out .= '<tr><td style="' . $td . '">' . $h($l['label']) . ($l['qty'] > 1 ? ' <span style="color:#e8d5b0">(' . (int)$l['qty'] . ' × ' . $h(bcrf_quote_money((int)$l['unit_cents'])) . ')</span>' : '')
              . '</td><td style="' . $td . ';text-align:right;white-space:nowrap">' . $h(bcrf_quote_money((int)$l['qty'] * (int)$l['unit_cents'])) . '</td></tr>';
    }
    $out .= '<tr><td style="' . $td . ';font-weight:bold;border-top:1px solid #c9923a">Total</td><td style="' . $td . ';text-align:right;font-weight:bold;border-top:1px solid #c9923a">' . $h(bcrf_quote_money($q['total_cents'])) . '</td></tr>';
    if ($status['paid'] !== 0) {
        $out .= '<tr><td style="' . $td . '">Paid so far</td><td style="' . $td . ';text-align:right">' . $h(bcrf_quote_money($status['paid'])) . '</td></tr>'
              . '<tr><td style="' . $td . ';font-weight:bold">Left to pay</td><td style="' . $td . ';text-align:right;font-weight:bold">' . $h(bcrf_quote_money(max(0, $status['balance']))) . '</td></tr>';
    }
    return $out . '</table>';
}

/** Email the quote to the customer's own email. Returns the address it went to. Staff press Send. */
function bcrf_quote_email(PDO $pdo, array $q, ?int $uid): string {
    $s = bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
    if (!$s) throw new BcrfQuoteError('That booking or party was not found.');
    $to = filter_var(trim($s['email']), FILTER_VALIDATE_EMAIL);
    if (!$to) throw new BcrfQuoteError('This ' . ($s['is_party'] ? 'party' : 'booking') . ' has no email address to send to.');
    if (!$q['lines'] || $q['total_cents'] <= 0) throw new BcrfQuoteError('Add a price before sending the quote.');
    if (!$s['live']) throw new BcrfQuoteError('This ' . ($s['is_party'] ? 'party' : 'booking') . ' is ' . $s['status'] . '.');
    $status = bcrf_quote_status($pdo, $q);
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $first = trim(explode(' ', trim($s['name']))[0] ?? '') ?: 'there';
    $p = 'margin:0 0 12px;color:#e8d5b0';
    $pay = bcrf_quote_payable($pdo, $q, $s, $status);
    $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.3rem;margin:0 0 14px">Your ' . $h(strtolower($s['what'])) . ' quote</h1>'
        . '<p style="' . $p . '">Hi ' . $h($first) . ', thanks for thinking of us! Here\'s the price for your ' . $h(strtolower($s['what'])) . ' on <strong style="color:#f5ead6">'
        . $h(bcrf_quote_when($s)) . '</strong> for ' . (int)$s['guests'] . ' guest' . ($s['guests'] === 1 ? '' : 's') . '.</p>'
        . bcrf_quote_lines_html($q, $status);
    if ($status['deposit_left'] > 0) {
        $inner .= '<p style="' . $p . '"><strong style="color:#f5ead6">Deposit: ' . $h(bcrf_quote_money($status['deposit_left'])) . '</strong>'
                . ($q['deposit_due'] ? ' by ' . $h(date('l, F j', strtotime($q['deposit_due']))) : '') . '.</p>';
    }
    if (!empty($q['customer_note'])) $inner .= '<p style="' . $p . '">' . nl2br($h($q['customer_note'])) . '</p>';
    $inner .= '<ul style="margin:0 0 14px;padding-left:18px;color:#e8d5b0">';
    foreach (bcrf_quote_terms($q, $s) as $t) $inner .= '<li style="margin:0 0 4px">' . $h($t) . '</li>';
    $inner .= '</ul>';
    $btn = 'display:inline-block;background:#8A3324;color:#F7F1E3;text-decoration:none;font-weight:bold;padding:11px 22px;border-radius:8px;border:2px solid #D9A441';
    $inner .= '<p style="margin:0 0 16px"><a style="' . $btn . '" href="' . $h(bcrf_quote_url($q)) . '">' . ($pay['balance'] > 0 ? 'See your quote and pay online' : 'See your quote') . '</a></p>'
        . '<p style="' . $p . '">Questions, or need to change anything? Just reply to this email.</p>'
        . '<p style="' . $p . '">' . $h(bcrf_quote_signoff()) . '</p>';
    require_once __DIR__ . '/nc_warning.php';
    $inner .= bcrf_nc_warning_email_html();
    if (!function_exists('bcf_send_mail')) require_once dirname(__DIR__) . '/admin/includes/email_template.php';
    $ok = bcf_send_mail($to, 'Your ' . strtolower($s['what']) . ' quote — ' . bcrf_farm_name(), $inner,
                        ['subtitle' => $s['is_party'] ? 'Parties' : 'Bookings', 'reply_to' => bcrf_farm_email('support')]);
    if (!$ok) throw new BcrfQuoteError('The email didn\'t send' . (!empty($GLOBALS['bcf_mail_last_error']) ? ': ' . $GLOBALS['bcf_mail_last_error'] : '') . '. Try again in a minute.');
    bcrf_quote_mark_sent($pdo, $q, $s, $uid, 'emailed to ' . $to);
    return $to;
}

/** Sent (by email above, or staff sent the link themselves). A party still New or Contacted moves to Quoted. */
function bcrf_quote_mark_sent(PDO $pdo, array $q, ?array $s, ?int $uid, string $how): void {
    $pdo->prepare("UPDATE event_quotes SET sent_at = UTC_TIMESTAMP(), sent_count = sent_count + 1, updated_by = ? WHERE id = ?")->execute([$uid, $q['id']]);
    $s = $s ?? bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
    if ($s && $s['is_party'] && in_array($s['status'], ['new', 'contacted'], true)) {
        $pdo->prepare("UPDATE event_inquiries SET status = 'quoted' WHERE id = ? AND status IN ('new', 'contacted')")->execute([$s['id']]);
    }
    if (function_exists('bcrf_event')) bcrf_event('quote.sent', 'event_quotes', $q['id'], ['total_cents' => $q['total_cents']]);
    bcrf_quote_audit($pdo, 'update', $q, $s, 'quote ' . bcrf_quote_money($q['total_cents']) . ' ' . $how);
}

/** After an online payment: receipt to the customer, a note to the farm. Never throws. */
function bcrf_quote_paid_emails(PDO $pdo, array $q, ?array $s, int $cents): void {
    if (!$s) return;
    try {
        if (!function_exists('bcf_send_mail')) require_once dirname(__DIR__) . '/admin/includes/email_template.php';
        $q = bcrf_quote_by_id($pdo, $q['id']);
        $status = bcrf_quote_status($pdo, $q);
        $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
        $p = 'margin:0 0 12px;color:#e8d5b0';
        if ($to = filter_var(trim($s['email']), FILTER_VALIDATE_EMAIL)) {
            $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.3rem;margin:0 0 14px">Thanks, we got your payment</h1>'
                . '<p style="' . $p . '">We received <strong style="color:#f5ead6">' . $h(bcrf_quote_money($cents)) . '</strong> for your ' . $h(strtolower($s['what']))
                . ' on ' . $h(bcrf_quote_when($s)) . '.</p>'
                . bcrf_quote_lines_html($q, $status)
                . '<p style="' . $p . '">' . ($status['balance'] > 0 ? 'The remaining ' . $h(bcrf_quote_money($status['balance'])) . ' is due '
                    . ($q['balance_when'] === 'before' ? 'before the day.' : 'when you arrive.') : 'You\'re all paid up.') . '</p>'
                . '<p style="' . $p . '"><a style="color:#e8b84b" href="' . $h(bcrf_quote_url($q)) . '">See your quote and payments</a></p>';
            bcf_send_mail($to, 'Payment received — ' . bcrf_farm_name(), $inner, ['subtitle' => $s['is_party'] ? 'Parties' : 'Bookings', 'reply_to' => bcrf_farm_email('support')]);
        }
        $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6">Online payment received</h1>'
            . '<p style="' . $p . '">' . $h($s['name']) . ' paid <strong>' . $h(bcrf_quote_money($cents)) . '</strong> for their ' . $h(strtolower($s['what'])) . ' on '
            . $h(bcrf_quote_when($s)) . '. Now: ' . $h($status['label']) . '.</p>'
            . '<p style="' . $p . '">The ' . ($s['is_party'] ? 'party' : 'booking') . ' is ' . $h($s['status']) . ($s['status'] === 'confirmed' ? '.' : ': confirm it in the console when you\'re ready.') . '</p>';
        bcf_send_mail(bcrf_farm_email('support'), 'Paid online: ' . $s['name'] . ' ' . bcrf_quote_money($cents), $inner, ['subtitle' => 'Bookings']);
    } catch (Throwable $e) { error_log('[quotes] paid emails ' . $q['id'] . ': ' . $e->getMessage()); }
}

/** Audit row on the booking or party itself, so its history shows quote and money changes. */
function bcrf_quote_audit(PDO $pdo, string $action, array $q, ?array $s, string $what, ?string $actor = null): void {
    if (!function_exists('erp_audit')) return;
    try {
        $s = $s ?? bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
        $table = $q['subject_type'] === 'booking' ? 'bookings' : 'event_inquiries';
        erp_audit($pdo, $action === 'create' ? 'update' : $action, $table, $q['subject_id'],
                  trim(($s['name'] ?? '') . ($s && $s['date'] ? ' (' . $s['date'] . ')' : '')) . ' - ' . $what, $actor);
    } catch (Throwable $e) { /* never stops the money */ }
}

}
