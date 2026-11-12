<?php
// includes/capacity_holds.php — Farm OS Phase 3 slice 5 (Nov 12, 2026; 07 › Large parties and holds, Bookings › arrival).
//
//   Holds    A confirmed group booking or party holds capacity for its time window: the farm's allowance for that
//            window goes up by the group's expected guests, so the group's spaces are theirs and walk-ins keep the
//            usual limit (capacity_limit). Nothing is stored: holds are worked out from confirmed bookings (not day
//            tickets, not "date flexible") and confirmed parties each time, so a date or status change can't leave a
//            stale hold. Window: bookings start–end; parties start + party_length_minutes, or the whole open day
//            when no start time is set. A hold counts from hold_early_minutes before the start, and for as long as
//            any of the group is still checked in.
//   Arrival  The gate checks a group in against its booking or party (check_ins.booking_id / event_inquiry_id).
//            Its guests count against its own hold first; anyone beyond the expected number uses walk-in spaces.
//            The actual headcount can update the quote's per-guest line (includes/event_quotes.php).
//   Pool     walk-ins on site = everyone not in an active group + each group's guests beyond its hold. New walk-ins
//            fit while that stays within capacity_limit.
// Called from includes/admission.php (occupancy and the capacity check) and admin/gate.php.
if (!function_exists('bcrf_holds_ready')) {

if (!function_exists('bcrf_occupancy')) require_once __DIR__ . '/admission.php';

function bcrf_holds_ready(PDO $pdo): bool {
    static $ready = [];
    $k = spl_object_id($pdo);
    if (isset($ready[$k]) && empty($GLOBALS['bcrf_holds_nocache'])) return $ready[$k];
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                               AND ((table_name = 'check_ins' AND column_name IN ('booking_id', 'event_inquiry_id'))
                                 OR (table_name = 'event_inquiries' AND column_name = 'start_time'))")->fetchColumn();
        return $ready[$k] = ($n === 3);
    } catch (Throwable $e) { return $ready[$k] = false; }
}

function bcrf_holds_tz(): DateTimeZone { return new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York'); }

/** Farm-local date + time → UTC 'Y-m-d H:i:s'. */
function bcrf_holds_utc(string $date, string $time): string {
    return (new DateTimeImmutable($date . ' ' . $time, bcrf_holds_tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** [open, close] 'HH:MM:SS' for a date from Settings › Hours (general), or 10:00–16:00 if none are set. */
function bcrf_holds_day_hours(PDO $pdo, string $date): array {
    $dow = (int)(new DateTimeImmutable($date))->format('w');
    try {
        $st = $pdo->prepare("SELECT MIN(open_time), MAX(close_time) FROM site_hours WHERE scope = 'general' AND day_of_week = ? AND is_closed = 0
                             AND open_time IS NOT NULL AND close_time IS NOT NULL");
        $st->execute([$dow]);
        [$o, $c] = $st->fetch(PDO::FETCH_NUM) ?: [null, null];
        if ($o && $c) return [(string)$o, (string)$c];
    } catch (Throwable $e) { /* no hours table */ }
    return ['10:00:00', '16:00:00'];
}

/**
 * Confirmed bookings and parties on a farm-local date, each as a hold:
 * type (booking|event_inquiry), id, name, what, guests, timed, from_utc, start_utc, end_utc, start_label, end_label,
 * arrived (guests checked in today), on_site (still checked in), active (holding capacity right now).
 */
function bcrf_holds_for_date(PDO $pdo, string $date): array {
    if (!bcrf_holds_ready($pdo)) return [];
    $early = max(0, bcrf_adm_setting($pdo, 'hold_early_minutes', 30));
    $partyLen = max(15, bcrf_adm_setting($pdo, 'party_length_minutes', 120));
    $out = [];
    $st = $pdo->prepare("SELECT b.id, b.customer_name, b.party_size, b.start_time, b.end_time, t.label
                         FROM bookings b JOIN booking_types t ON t.id = b.type_id
                         WHERE b.booking_date = ? AND b.status = 'confirmed' AND b.date_flexible = 0 AND t.type_key <> 'day_admission'
                         ORDER BY b.start_time, b.id");
    $st->execute([$date]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $start = (string)$r['start_time'];
        $end = (string)$r['end_time'];
        if ($end <= $start) $end = (new DateTimeImmutable($date . ' ' . $start))->modify('+2 hours')->format('H:i:s');
        $out[] = ['type' => 'booking', 'id' => (int)$r['id'], 'name' => (string)$r['customer_name'], 'what' => (string)$r['label'],
                  'guests' => max(1, (int)$r['party_size']), 'timed' => true, 'start' => $start, 'end' => $end];
    }
    $kinds = defined('BCRF_QUOTE_PARTY_KINDS') ? BCRF_QUOTE_PARTY_KINDS
           : ['birthday' => 'Birthday party', 'field_trip' => 'Field trip', 'buyout' => 'Farm buyout', 'corporate' => 'Corporate event', 'other' => 'Private event'];
    $st = $pdo->prepare("SELECT id, customer_name, adult_count, child_count, start_time, event_type FROM event_inquiries
                         WHERE preferred_date = ? AND status = 'confirmed' ORDER BY start_time IS NULL, start_time, id");
    $st->execute([$date]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['start_time']) {
            $start = (string)$r['start_time'];
            $end = (new DateTimeImmutable($date . ' ' . $start))->modify("+{$partyLen} minutes")->format('H:i:s');
            if ($end < $start) $end = '23:59:00';   // ran past midnight
            $timed = true;
        } else {
            [$start, $end] = bcrf_holds_day_hours($pdo, $date);
            $timed = false;
        }
        $out[] = ['type' => 'event_inquiry', 'id' => (int)$r['id'], 'name' => (string)$r['customer_name'],
                  'what' => $kinds[$r['event_type']] ?? $kinds['other'], 'guests' => max(1, (int)$r['adult_count'] + (int)$r['child_count']),
                  'timed' => $timed, 'start' => $start, 'end' => $end];
    }
    if (!$out) return [];

    // Arrivals and who is still here
    $ids = ['booking' => [], 'event_inquiry' => []];
    foreach ($out as $h) $ids[$h['type']][] = $h['id'];
    $arr = ['booking' => [], 'event_inquiry' => []];
    foreach (['booking' => 'booking_id', 'event_inquiry' => 'event_inquiry_id'] as $t => $col) {
        if (!$ids[$t]) continue;
        $in = implode(',', array_fill(0, count($ids[$t]), '?'));
        $st = $pdo->prepare("SELECT $col AS sid, SUM(party_size) AS arrived,
                                    SUM(CASE WHEN checked_out_at IS NULL AND expected_out_at > UTC_TIMESTAMP() THEN guests_counted ELSE 0 END) AS here
                             FROM check_ins WHERE $col IN ($in) AND business_date = ? GROUP BY $col");
        $st->execute(array_merge($ids[$t], [$date]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $arr[$t][(int)$r['sid']] = [(int)$r['arrived'], (int)$r['here']];
    }
    $now = gmdate('Y-m-d H:i:s');
    foreach ($out as &$h) {
        $h['start_utc'] = bcrf_holds_utc($date, $h['start']);
        $h['end_utc'] = bcrf_holds_utc($date, $h['end']);
        $h['from_utc'] = (new DateTimeImmutable($h['start_utc'], new DateTimeZone('UTC')))->modify("-{$early} minutes")->format('Y-m-d H:i:s');
        $h['start_label'] = date('g:i A', strtotime($h['start']));
        $h['end_label'] = date('g:i A', strtotime($h['end']));
        [$h['arrived'], $h['on_site']] = $arr[$h['type']][$h['id']] ?? [0, 0];
        $h['active'] = ($now >= $h['from_utc'] && $now < $h['end_utc']) || $h['on_site'] > 0;
        unset($h['start'], $h['end']);
    }
    unset($h);
    return $out;
}

/** The hold for one booking or party today, or null (not confirmed for today). */
function bcrf_hold_today(PDO $pdo, string $type, int $id): ?array {
    foreach (bcrf_holds_for_date($pdo, bcrf_business_date()) as $h) if ($h['type'] === $type && $h['id'] === $id) return $h;
    return null;
}

/**
 * Split what's on site between groups (inside their holds) and the walk-in pool.
 * $rows: open check-ins (bcrf_on_site). Returns [pool_on, held_total, held_waiting, active_holds, pool_rows].
 */
function bcrf_holds_split(PDO $pdo, array $rows): array {
    $holds = array_values(array_filter(bcrf_holds_for_date($pdo, bcrf_business_date()), fn($h) => $h['active']));
    $byKey = [];
    foreach ($holds as $i => $h) $byKey[$h['type'] . ':' . $h['id']] = $i;
    $inHold = array_fill(0, count($holds), 0);
    $pool = 0; $poolRows = [];
    foreach ($rows as $r) {
        $k = !empty($r['booking_id']) ? 'booking:' . (int)$r['booking_id'] : (!empty($r['event_inquiry_id']) ? 'event_inquiry:' . (int)$r['event_inquiry_id'] : '');
        if ($k !== '' && isset($byKey[$k])) { $inHold[$byKey[$k]] += (int)$r['guests_counted']; continue; }
        $pool += (int)$r['guests_counted'];
        $poolRows[] = $r;
    }
    $held = 0; $waiting = 0;
    foreach ($holds as $i => $h) {
        $held += $h['guests'];
        $pool += max(0, $inHold[$i] - $h['guests']);
        $waiting += max(0, $h['guests'] - $inHold[$i]);
        $holds[$i]['in_hold'] = $inHold[$i];
    }
    return [$pool, $held, $waiting, $holds, $poolRows];
}

/** Walk-in spaces a group's arrival of $n would use (guests beyond its hold). */
function bcrf_hold_overflow(array $hold, int $n): int {
    $have = (int)($hold['in_hold'] ?? $hold['on_site'] ?? 0);
    return max(0, $have + $n - $hold['guests']) - max(0, $have - $hold['guests']);
}

/**
 * Check a group in at the gate against its booking or party (today, confirmed). $n = guests arriving now.
 * $updateQuote: set the quote's per-guest line to everyone who has arrived. Returns
 * ['check_in_id' => int, 'arrived' => total today, 'quote' => message or null].
 */
function bcrf_hold_arrive(PDO $pdo, string $type, int $id, int $n, bool $updateQuote = false, ?string $override = null): array {
    if (!bcrf_holds_ready($pdo)) throw new BcrfSaleError('Run migration 0033 in Settings › Database first.');
    if (!in_array($type, ['booking', 'event_inquiry'], true)) throw new BcrfSaleError('Pick a booking or party.');
    if ($n <= 0 || $n > 500) throw new BcrfSaleError('Type how many guests arrived.');
    bcrf_capacity_lock($pdo);
    try {
        $hold = bcrf_hold_today($pdo, $type, $id);
        if (!$hold) throw new BcrfSaleError('That ' . ($type === 'booking' ? 'booking' : 'party') . ' isn\'t confirmed for today. Confirm it (or fix its date) first.');
        $occ = bcrf_occupancy($pdo);
        foreach ($occ['holds'] ?? [] as $a) if ($a['type'] === $type && $a['id'] === $id) $hold = $a;   // with how many are in it now
        [$ov, $ovBy] = bcrf_capacity_check($pdo, $n, $override, $hold);
        $pdo->beginTransaction();
        $endTs = strtotime($hold['end_utc'] . ' UTC');
        $mins = max(30, (int)ceil(($endTs - time()) / 60));
        $cid = bcrf_check_in_row($pdo, ['source_type' => $type === 'booking' ? 'booking' : 'party', 'party_size' => $n, 'guests_counted' => $n,
                                        'override' => $ov, 'override_by' => $ovBy, 'minutes' => $mins,
                                        'booking_id' => $type === 'booking' ? $id : null, 'event_inquiry_id' => $type === 'event_inquiry' ? $id : null,
                                        'note' => $hold['what'] . ': ' . $hold['name']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        bcrf_capacity_unlock($pdo);
    }
    $arrived = $hold['arrived'] + $n;
    bcrf_adm_audit($pdo, 'checkin', $type === 'booking' ? 'bookings' : 'event_inquiries', $id,
        sprintf('%s (%s) arrived: %d in, %d of %d expected%s', $hold['name'], $hold['what'], $n, $arrived, $hold['guests'], $ov ? ' - capacity override: ' . $ov : ''));
    if (function_exists('bcrf_event')) bcrf_event('group.arrived', $type === 'booking' ? 'bookings' : 'event_inquiries', $id, ['guests' => $n, 'arrived' => $arrived, 'expected' => $hold['guests']]);
    $msg = null;
    if ($updateQuote) {
        try { $msg = bcrf_hold_update_quote($pdo, $type, $id, $arrived, $hold['guests']); }
        catch (Throwable $e) { $msg = 'The quote wasn\'t changed: ' . $e->getMessage(); }
    }
    return ['check_in_id' => $cid, 'arrived' => $arrived, 'quote' => $msg];
}

/** Which quote line is priced per guest: the first whose quantity is the expected guest count, else one that says "guest". */
function bcrf_hold_per_guest_line(array $lines, int $expected): ?int {
    foreach ($lines as $i => $l) if ((int)$l['qty'] === $expected) return $i;
    foreach ($lines as $i => $l) if (stripos((string)$l['label'], 'guest') !== false) return $i;
    return null;
}

/** Set the quote's per-guest line to the actual headcount. Returns what happened, or null when there's no quote. */
function bcrf_hold_update_quote(PDO $pdo, string $type, int $id, int $actual, int $expected): ?string {
    if (!function_exists('bcrf_quotes_ready')) require_once __DIR__ . '/event_quotes.php';
    if (!bcrf_quotes_ready($pdo)) return null;
    $q = bcrf_quote_get($pdo, $type, $id);
    if (!$q) return null;
    $i = bcrf_hold_per_guest_line($q['lines'], $expected);
    if ($i === null) return 'The quote has no per-guest line, so it wasn\'t changed.';
    if ((int)$q['lines'][$i]['qty'] === $actual) return 'The quote already has ' . $actual . ' guests.';
    $old = $q['total_cents'];
    $q['lines'][$i]['qty'] = $actual;
    $q = bcrf_quote_save($pdo, $q['id'], ['lines' => $q['lines'], 'deposit' => $q['deposit_cents'], 'deposit_due' => (string)$q['deposit_due'],
                                          'balance_when' => $q['balance_when'], 'pay_online' => (bool)$q['pay_online'], 'customer_note' => (string)$q['customer_note']],
                         (int)($_SESSION['user_id'] ?? 0) ?: null);
    $st = bcrf_quote_status($pdo, $q);
    return 'Quote updated to ' . $actual . ' guests: ' . bcrf_quote_money($old) . ' → ' . bcrf_quote_money($q['total_cents'])
         . ($st['balance'] > 0 ? '. ' . bcrf_quote_money($st['balance']) . ' left to pay.' : '.');
}

/** Today's groups for the gate screen. */
function bcrf_holds_gate_view(PDO $pdo): array {
    $quoteBadges = [];
    $holds = bcrf_holds_for_date($pdo, bcrf_business_date());
    if ($holds) {
        try {
            if (!function_exists('bcrf_quotes_ready')) require_once __DIR__ . '/event_quotes.php';
            foreach (['booking', 'event_inquiry'] as $t) {
                $quoteBadges[$t] = bcrf_quote_badges($pdo, $t, array_column(array_filter($holds, fn($h) => $h['type'] === $t), 'id'));
            }
        } catch (Throwable $e) { $quoteBadges = []; }
    }
    return array_map(function ($h) use ($quoteBadges) {
        $b = $quoteBadges[$h['type']][$h['id']] ?? null;
        $perGuest = $b ? bcrf_hold_per_guest_line($b['quote']['lines'], $h['guests']) !== null : false;
        return ['type' => $h['type'], 'id' => $h['id'], 'name' => $h['name'], 'what' => $h['what'], 'guests' => $h['guests'],
                'when' => $h['timed'] ? $h['start_label'] . '–' . $h['end_label'] : 'All day (no start time set)',
                'arrived' => $h['arrived'], 'on_site' => $h['on_site'], 'active' => $h['active'],
                'money' => $b ? $b['status']['label'] : '', 'per_guest_quote' => $perGuest];
    }, $holds);
}

}
