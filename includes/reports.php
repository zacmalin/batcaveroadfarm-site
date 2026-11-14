<?php // includes/reports.php
/*
 * Farm OS Phase 1, slice 5 (Nov 6, 2026): the day-to-day reports from 13 — Reports & Analytics.
 * Used by admin/farm_reports.php (pages and CSV). Definitions follow 13:
 *   Gross sales     line totals before discounts, no tax, sales not voided
 *   Net sales       gross − discounts − refunds (goods part), no tax
 *   Tax collected   line tax − tax refunded, by tax category
 *   Visitors        guests on check-ins (party_size); "counted" = guests that count toward capacity
 *   Peak occupancy  most guests counted on site at one moment (check-in until check-out or expected-out, whichever is first)
 * Everything is by business date (farm-local). Refunds count on the day they were given, not the day of the sale.
 * Online tickets (Stripe) are reported beside the register: they have no sale row and no tax.
 * Money comes back in integer cents.
 */

if (!function_exists('bcrf_rpt_days')) {

require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/drawers.php';
require_once __DIR__ . '/refunds.php';
require_once __DIR__ . '/admission.php';
require_once __DIR__ . '/included_tax.php';   // tax inside ticket, pass and booking prices (all money in)

/** CSV columns for the daily/monthly export. Keep names and order stable (13: spreadsheet formulas depend on them). */
function bcrf_rpt_day_columns(): array {
    return ['business_date', 'sales_count', 'gross_sales', 'discounts', 'refunds', 'net_sales', 'tax', 'register_total',
            'cash', 'paypal', 'venmo', 'cashapp', 'check', 'other_tender', 'voids_count', 'voids_total',
            'online_ticket_orders', 'online_ticket_guests', 'online_ticket_sales', 'visits', 'visitors', 'peak_occupancy'];
}

function bcrf_rpt_tz(): DateTimeZone {
    return new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
}

/** [UTC start, UTC end) for farm-local dates $from..$to inclusive. */
function bcrf_rpt_utc_range(string $from, string $to): array {
    $tz = bcrf_rpt_tz(); $utc = new DateTimeZone('UTC');
    $a = (new DateTimeImmutable($from . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $b = (new DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    return [$a, $b];
}

function bcrf_rpt_dates(string $from, string $to): array {
    $out = [];
    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) $out[] = $d->format('Y-m-d');
    return $out;
}

/** Online tickets columns exist (migration 0013). */
function bcrf_rpt_online_ready(PDO $pdo): bool {
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tickets' AND column_name = 'paid_at'");
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) { return false; }
}

/** One row per business date from $from to $to (both 'Y-m-d'), keys = bcrf_rpt_day_columns(). */
function bcrf_rpt_days(PDO $pdo, string $from, string $to, bool $withPeak = true): array {
    $tenderCols = ['cash', 'paypal', 'venmo', 'cashapp', 'check'];
    $rows = [];
    foreach (bcrf_rpt_dates($from, $to) as $d) {
        $rows[$d] = array_fill_keys(bcrf_rpt_day_columns(), 0);
        $rows[$d]['business_date'] = $d;
    }
    $q = function (string $sql) use ($pdo, $from, $to) { $st = $pdo->prepare($sql); $st->execute([$from, $to]); return $st->fetchAll(PDO::FETCH_ASSOC); };

    foreach ($q("SELECT s.business_date d, COUNT(DISTINCT s.id) n, SUM(l.gross_cents) g, SUM(l.discount_cents) dc, SUM(l.tax_cents) t, SUM(l.line_total_cents) tot
                 FROM sales s JOIN sale_lines l ON l.sale_id = s.id
                 WHERE s.status <> 'voided' AND s.business_date BETWEEN ? AND ? GROUP BY s.business_date") as $r) {
        $x = &$rows[$r['d']];
        $x['sales_count'] = (int)$r['n']; $x['gross_sales'] = (int)$r['g']; $x['discounts'] = (int)$r['dc'];
        $x['tax'] = (int)$r['t']; $x['register_total'] = (int)$r['tot'];
        unset($x);
    }
    foreach ($q("SELECT business_date d, COUNT(*) n, SUM(total_cents) tot FROM sales WHERE status = 'voided' AND business_date BETWEEN ? AND ? GROUP BY business_date") as $r) {
        $rows[$r['d']]['voids_count'] = (int)$r['n']; $rows[$r['d']]['voids_total'] = (int)$r['tot'];
    }
    foreach ($q("SELECT business_date d, SUM(net_cents) n, SUM(tax_cents) t, SUM(total_cents) tot FROM refunds WHERE business_date BETWEEN ? AND ? GROUP BY business_date") as $r) {
        $x = &$rows[$r['d']];
        $x['refunds'] = (int)$r['n']; $x['tax'] -= (int)$r['t']; $x['register_total'] -= (int)$r['tot'];
        unset($x);
    }
    $tender = function (array $r, int $sign) use (&$rows, $tenderCols) {
        $col = in_array($r['tender'], $tenderCols, true) ? $r['tender'] : 'other_tender';
        $rows[$r['d']][$col] += $sign * (int)$r['amt'];
    };
    foreach ($q("SELECT p.business_date d, p.tender, SUM(p.amount_cents) amt FROM payments p JOIN sales s ON s.id = p.sale_id
                 WHERE s.status <> 'voided' AND p.business_date BETWEEN ? AND ? GROUP BY p.business_date, p.tender") as $r) $tender($r, 1);
    foreach ($q("SELECT r.business_date d, rp.tender, SUM(rp.amount_cents) amt FROM refund_payments rp JOIN refunds r ON r.id = rp.refund_id
                 WHERE r.business_date BETWEEN ? AND ? GROUP BY r.business_date, rp.tender") as $r) $tender($r, -1);
    foreach ($rows as &$x) $x['net_sales'] = $x['gross_sales'] - $x['discounts'] - $x['refunds'];
    unset($x);

    if (bcrf_rpt_online_ready($pdo)) {
        [$a, $b] = bcrf_rpt_utc_range($from, $to);
        $st = $pdo->prepare("SELECT paid_at, quantity, amount_cents FROM tickets
                             WHERE source = 'online' AND payment_status = 'paid' AND paid_at >= ? AND paid_at < ?");
        $st->execute([$a, $b]);
        foreach ($st as $t) {
            $d = bcrf_local_time($t['paid_at'], 'Y-m-d');
            if (!isset($rows[$d])) continue;
            $rows[$d]['online_ticket_orders']++; $rows[$d]['online_ticket_guests'] += (int)$t['quantity'];
            $rows[$d]['online_ticket_sales'] += (int)$t['amount_cents'];
        }
    }
    if (bcrf_admission_ready($pdo)) {
        foreach ($q("SELECT business_date d, COUNT(*) n, SUM(party_size) g FROM check_ins WHERE business_date BETWEEN ? AND ? GROUP BY business_date") as $r) {
            $rows[$r['d']]['visits'] = (int)$r['n']; $rows[$r['d']]['visitors'] = (int)$r['g'];
        }
        if ($withPeak) foreach ($rows as $d => &$x) if ($x['visits']) $x['peak_occupancy'] = bcrf_rpt_occupancy($pdo, $d)['peak'];
        unset($x);
    }
    return $rows;
}

/** Add up day rows (dates become the range). */
function bcrf_rpt_total(array $days): array {
    $t = array_fill_keys(bcrf_rpt_day_columns(), 0);
    foreach ($days as $r) foreach ($r as $k => $v) if ($k !== 'business_date') $t[$k] = $k === 'peak_occupancy' ? max($t[$k], $v) : $t[$k] + $v;
    $t['business_date'] = 'Total';
    return $t;
}

/** Sales by product category, or by tax category, for $from..$to, refunds taken off on their own date.
 *  $by = 'category' | 'tax'. Rows: [label, rate_bp (tax), gross, discounts, refunds, net, tax, qty]. */
function bcrf_rpt_breakdown(PDO $pdo, string $from, string $to, string $by): array {
    $key = $by === 'tax' ? "COALESCE(l.tax_category_id, 0)" : "COALESCE(NULLIF(l.category_label, ''), 'Uncategorized')";
    $out = [];
    $st = $pdo->prepare("SELECT $key k, SUM(l.gross_cents) g, SUM(l.discount_cents) dc, SUM(l.tax_cents) t, SUM(l.quantity) q
                         FROM sales s JOIN sale_lines l ON l.sale_id = s.id
                         WHERE s.status <> 'voided' AND s.business_date BETWEEN ? AND ? GROUP BY k");
    $st->execute([$from, $to]);
    foreach ($st as $r) $out[$r['k']] = ['gross' => (int)$r['g'], 'discounts' => (int)$r['dc'], 'refunds' => 0, 'tax' => (int)$r['t'], 'qty' => (float)$r['q']];
    $st = $pdo->prepare("SELECT $key k, SUM(rl.net_cents) n, SUM(rl.tax_cents) t
                         FROM refunds r JOIN refund_lines rl ON rl.refund_id = r.id JOIN sale_lines l ON l.id = rl.sale_line_id
                         WHERE r.business_date BETWEEN ? AND ? GROUP BY k");
    $st->execute([$from, $to]);
    foreach ($st as $r) {
        $out[$r['k']] ??= ['gross' => 0, 'discounts' => 0, 'refunds' => 0, 'tax' => 0, 'qty' => 0];
        $out[$r['k']]['refunds'] += (int)$r['n']; $out[$r['k']]['tax'] -= (int)$r['t'];
    }
    $names = [];
    if ($by === 'tax') foreach ($pdo->query("SELECT id, name, rate_bp FROM tax_categories") as $c) $names[(int)$c['id']] = $c;
    $rows = [];
    foreach ($out as $k => $v) {
        $rows[] = ['label' => $by === 'tax' ? ($names[(int)$k]['name'] ?? 'No tax category') : (string)$k,
                   'rate_bp' => $by === 'tax' ? (int)($names[(int)$k]['rate_bp'] ?? 0) : null] + $v
                 + ['net' => $v['gross'] - $v['discounts'] - $v['refunds']];
    }
    usort($rows, fn($a, $b) => $b['net'] <=> $a['net'] ?: strcmp($a['label'], $b['label']));
    return $rows;
}

/** Refunds, voids and discounts between $from and $to, newest first. */
function bcrf_rpt_log(PDO $pdo, string $from, string $to): array {
    $tenders = bcrf_tenders();
    $out = [];
    $st = $pdo->prepare("SELECT r.*, s.sale_number FROM refunds r JOIN sales s ON s.id = r.sale_id WHERE r.business_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $pay = $pdo->prepare("SELECT tender, amount_cents, reference FROM refund_payments WHERE refund_id = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pay->execute([$r['id']]);
        $how = implode(', ', array_map(fn($p) => ($tenders[$p['tender']] ?? $p['tender']) . ($p['reference'] ? ' #' . $p['reference'] : ''), $pay->fetchAll(PDO::FETCH_ASSOC)));
        $out[] = ['at' => $r['created_at'], 'date' => $r['business_date'], 'type' => 'Refund', 'number' => $r['refund_number'], 'sale' => $r['sale_number'],
                  'by' => bcrf_staff_name($pdo, $r['created_by']), 'approved_by' => bcrf_staff_name($pdo, $r['approved_by']),
                  'reason' => (string)$r['reason'], 'how' => $how, 'amount' => -(int)$r['total_cents']];
    }
    $st = $pdo->prepare("SELECT * FROM sales WHERE status = 'voided' AND business_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $out[] = ['at' => $s['voided_at'] ?: $s['completed_at'], 'date' => $s['business_date'], 'type' => 'Void', 'number' => $s['sale_number'], 'sale' => $s['sale_number'],
                  'by' => bcrf_staff_name($pdo, $s['voided_by']), 'approved_by' => '', 'reason' => (string)$s['void_reason'], 'how' => '', 'amount' => -(int)$s['total_cents']];
    }
    $items = function_exists('bcrf_sales_line_disc_ready') && bcrf_sales_line_disc_ready($pdo) ? "SUM(l.line_discount_bp > 0)" : "0";   // slice 7
    $st = $pdo->prepare("SELECT s.id, s.sale_number, s.business_date, s.completed_at, s.created_by, s.discount_bp, SUM(l.discount_cents) dc, $items item_lines
                         FROM sales s JOIN sale_lines l ON l.sale_id = s.id
                         WHERE s.status <> 'voided' AND s.business_date BETWEEN ? AND ? GROUP BY s.id HAVING dc > 0");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $pct = implode(' + ', array_filter([(int)$s['item_lines'] ? (int)$s['item_lines'] . ' item discount' . ((int)$s['item_lines'] === 1 ? '' : 's') : '',
                                            (int)$s['discount_bp'] ? rtrim(rtrim(number_format($s['discount_bp'] / 100, 2), '0'), '.') . '% off the sale' : '']));
        $out[] = ['at' => $s['completed_at'], 'date' => $s['business_date'], 'type' => 'Discount', 'number' => $s['sale_number'], 'sale' => $s['sale_number'],
                  'by' => bcrf_staff_name($pdo, $s['created_by']), 'approved_by' => '', 'reason' => $pct, 'how' => '', 'amount' => -(int)$s['dc']];
    }
    usort($out, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    return $out;
}

/** Occupancy over one business date: peak, when, capacity hits, and guests on site per hour (most at once in that hour). */
function bcrf_rpt_occupancy(PDO $pdo, string $date): array {
    $limit = max(1, bcrf_adm_setting($pdo, 'capacity_limit', 20));
    $st = $pdo->prepare("SELECT checked_in_at, LEAST(COALESCE(checked_out_at, expected_out_at), expected_out_at) out_at, guests_counted
                         FROM check_ins WHERE business_date = ? AND guests_counted > 0");
    $st->execute([$date]);
    $ev = [];
    foreach ($st as $c) {
        if ($c['out_at'] <= $c['checked_in_at']) continue;   // checked straight back out (e.g. voided sale)
        $ev[] = [$c['checked_in_at'], 1, (int)$c['guests_counted']];
        $ev[] = [$c['out_at'], 0, -(int)$c['guests_counted']];
    }
    usort($ev, fn($a, $b) => strcmp($a[0], $b[0]) ?: $a[1] <=> $b[1]);   // a leave and an arrival at the same second: leave first
    $on = 0; $peak = 0; $peakAt = null; $hits = 0; $atLimit = false; $hourly = [];
    foreach ($ev as [$t, , $n]) {
        $on += $n;
        $h = (int)bcrf_local_time($t, 'G');
        $hourly[$h] = max($hourly[$h] ?? 0, $on);
        if ($on > $peak) { $peak = $on; $peakAt = $t; }
        if ($on >= $limit && !$atLimit) $hits++;
        $atLimit = $on >= $limit;
    }
    // carry occupancy through hours with no arrivals or departures
    if ($hourly) {
        ksort($hourly); $on = 0; $filled = [];
        $byHour = [];
        foreach ($ev as [$t, , $n]) $byHour[(int)bcrf_local_time($t, 'G')][] = $n;
        for ($h = min(array_keys($hourly)); $h <= max(array_keys($hourly)); $h++) {
            $start = $on;
            foreach ($byHour[$h] ?? [] as $n) $on += $n;
            $filled[$h] = max($hourly[$h] ?? 0, $start);
        }
        $hourly = $filled;
    }
    return ['limit' => $limit, 'peak' => $peak, 'peak_at' => $peakAt, 'hits' => $hits, 'hourly' => $hourly];
}

/** Visitors for one business date (13: Admission & visits). */
function bcrf_rpt_visitors(PDO $pdo, string $date): array {
    $st = $pdo->prepare("SELECT c.*, t.public_code, t.purchaser_name, p.holder_name FROM check_ins c
                         LEFT JOIN tickets t ON t.id = c.ticket_id LEFT JOIN passes p ON p.id = c.pass_id
                         WHERE c.business_date = ? ORDER BY c.checked_in_at");
    $st->execute([$date]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $src = ['ticket' => 'Online ticket', 'gate_sale' => 'Gate sale', 'pass' => 'Annual pass', 'booking' => 'Booking', 'comp' => 'Comp'];
    $bySource = []; $byHour = []; $overrides = [];
    foreach ($rows as $c) {
        $k = $src[$c['source_type']] ?? $c['source_type'];
        $bySource[$k] ??= ['visits' => 0, 'guests' => 0];
        $bySource[$k]['visits']++; $bySource[$k]['guests'] += (int)$c['party_size'];
        $h = (int)bcrf_local_time($c['checked_in_at'], 'G');
        $byHour[$h] ??= ['arrivals' => 0, 'guests' => 0];
        $byHour[$h]['arrivals']++; $byHour[$h]['guests'] += (int)$c['party_size'];
        if ($c['capacity_override_reason'] !== null && $c['capacity_override_reason'] !== '') {
            $overrides[] = ['time' => bcrf_local_time($c['checked_in_at']), 'guests' => (int)$c['party_size'], 'reason' => $c['capacity_override_reason'],
                            'by' => bcrf_staff_name($pdo, $c['override_by']),
                            'who' => $c['holder_name'] ?: ($c['purchaser_name'] ?: ($src[$c['source_type']] ?? ''))];
        }
    }
    ksort($byHour);
    return ['date' => $date, 'visits' => count($rows), 'guests' => array_sum(array_column($rows, 'party_size')),
            'counted' => array_sum(array_column($rows, 'guests_counted')), 'by_source' => $bySource, 'by_hour' => $byHour,
            'overrides' => $overrides, 'occupancy' => bcrf_rpt_occupancy($pdo, $date), 'checkins' => $rows];
}

/** Online ticket liability now (13: value of sold, unexpired online tickets not yet redeemed), plus expired-unused. */
function bcrf_rpt_ticket_liability(PDO $pdo): array {
    $out = ['open_tickets' => 0, 'open_guests' => 0, 'open_cents' => 0, 'expired_tickets' => 0, 'expired_guests' => 0, 'expired_cents' => 0];
    if (!bcrf_rpt_online_ready($pdo)) return $out;
    $st = $pdo->query("SELECT quantity, quantity_redeemed, amount_cents, (expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()) expired
                       FROM tickets WHERE source = 'online' AND payment_status = 'paid' AND status IN ('issued', 'partially_redeemed') AND quantity > quantity_redeemed");
    foreach ($st as $t) {
        $left = (int)$t['quantity'] - (int)$t['quantity_redeemed'];
        $val = intdiv((int)$t['amount_cents'] * $left, max(1, (int)$t['quantity']));
        $p = $t['expired'] ? 'expired_' : 'open_';
        $out[$p . 'tickets']++; $out[$p . 'guests'] += $left; $out[$p . 'cents'] += $val;
    }
    return $out;
}

/**
 * Margin from sale_lines.unit_cost_cents snapshot (Phase 2 cost tracking).
 * Revenue = gross − discounts (before tax). Cost only counted when unit_cost_cents is known.
 * Returns: totals + rows by product_type and by category_label.
 */
function bcrf_rpt_margin(PDO $pdo, string $from, string $to): array {
    $empty = ['revenue' => 0, 'cost' => 0, 'margin' => 0, 'qty' => 0.0,
              'lines_with_cost' => 0, 'lines_no_cost' => 0, 'revenue_no_cost' => 0,
              'by_type' => [], 'by_category' => []];
    if (!bcrf_sales_ready($pdo)) return $empty;

    // unit_cost_cents exists from migration 0009; still defensive.
    $hasCost = true;
    try {
        $hasCost = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                                     AND table_name = 'sale_lines' AND column_name = 'unit_cost_cents'")->fetchColumn() === 1;
    } catch (Throwable $e) { $hasCost = false; }
    if (!$hasCost) return $empty;

    $st = $pdo->prepare("SELECT l.product_type, COALESCE(NULLIF(l.category_label, ''), 'Uncategorized') AS cat,
                                l.quantity, l.gross_cents, l.discount_cents, l.unit_cost_cents
                         FROM sale_lines l JOIN sales s ON s.id = l.sale_id
                         WHERE s.status <> 'voided' AND s.business_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);

    $tot = $empty;
    $byType = []; $byCat = [];
    $bump = function (array &$bucket, string $key, int $rev, ?int $costCents, float $qty) {
        $bucket[$key] ??= ['label' => $key, 'revenue' => 0, 'cost' => 0, 'margin' => 0, 'qty' => 0.0,
                           'lines_with_cost' => 0, 'lines_no_cost' => 0, 'revenue_no_cost' => 0];
        $bucket[$key]['revenue'] += $rev;
        $bucket[$key]['qty'] += $qty;
        if ($costCents !== null) {
            $bucket[$key]['cost'] += $costCents;
            $bucket[$key]['margin'] += $rev - $costCents;
            $bucket[$key]['lines_with_cost']++;
        } else {
            $bucket[$key]['lines_no_cost']++;
            $bucket[$key]['revenue_no_cost'] += $rev;
        }
    };

    foreach ($st as $r) {
        $rev = (int)$r['gross_cents'] - (int)$r['discount_cents'];
        $qty = (float)$r['quantity'];
        $unitCost = $r['unit_cost_cents'] !== null && $r['unit_cost_cents'] !== '' ? (int)$r['unit_cost_cents'] : null;
        // cost for the line = unit_cost × quantity, half-up in milli-units to match sales rounding spirit
        $lineCost = $unitCost !== null ? (int)round($unitCost * $qty) : null;

        $tot['revenue'] += $rev;
        $tot['qty'] += $qty;
        if ($lineCost !== null) {
            $tot['cost'] += $lineCost;
            $tot['margin'] += $rev - $lineCost;
            $tot['lines_with_cost']++;
        } else {
            $tot['lines_no_cost']++;
            $tot['revenue_no_cost'] += $rev;
        }

        $type = (string)($r['product_type'] ?: 'retail');
        $bump($byType, $type, $rev, $lineCost, $qty);
        $bump($byCat, (string)$r['cat'], $rev, $lineCost, $qty);
    }

    $sort = function (array $rows): array {
        usort($rows, fn($a, $b) => $b['revenue'] <=> $a['revenue'] ?: strcmp($a['label'], $b['label']));
        return $rows;
    };
    $tot['by_type'] = $sort(array_values($byType));
    $tot['by_category'] = $sort(array_values($byCat));
    return $tot;
}

/**
 * All money in (Nov 14, 2026): every way money comes in, for farm-local dates $from..$to, net of refunds.
 * One figure for the Sales tab, the dashboard card, the Monthly report and the connector, so they agree.
 *   register  register sale lines after discounts and refunds (refunds on their own date), no gift card lines
 *   tickets   online tickets paid to Stripe (by paid date)
 *   passes    annual passes paid (Stripe or cash at the office; comp passes are not money)
 *   events    booking and party payments not taken at the register (the register sale holds those),
 *             plus Stripe booking payments that have no ledger row yet
 * Gift cards count when they're spent, not when they're bought: a card sold is money owed (gc_sold), and the
 * register sale it's spent on is already in "register". Online tickets, passes and bookings include tax in the
 * price; tax is only known from Nov 13, 2026 (before that, net = what was paid).
 * Returns cents: ['sources' => [key => [label, count, gross, tax, net]], 'by_day' => [date => [key => net]],
 *                 'gross', 'tax', 'net', 'gc_sold', 'gc_sold_count', 'gc_spent'].
 */
function bcrf_rpt_money_in(PDO $pdo, string $from, string $to): array {
    $labels = ['register' => 'Register', 'tickets' => 'Online tickets', 'passes' => 'Annual passes', 'events' => 'Bookings & parties'];
    $src = [];
    foreach ($labels as $k => $l) $src[$k] = ['label' => $l, 'count' => 0, 'gross' => 0, 'tax' => 0, 'net' => 0];
    $byDay = [];
    foreach (bcrf_rpt_dates($from, $to) as $d) $byDay[$d] = array_fill_keys(array_keys($labels), 0);
    $add = function (string $k, ?string $day, int $gross, int $tax, int $n = 1) use (&$src, &$byDay) {
        if ($day === null || !isset($byDay[$day])) return;
        $src[$k]['count'] += $n; $src[$k]['gross'] += $gross; $src[$k]['tax'] += $tax; $src[$k]['net'] += $gross - $tax;
        $byDay[$day][$k] += $gross - $tax;
    };
    $utcDay = fn($utc) => $utc ? bcrf_local_time((string)$utc, 'Y-m-d') : null;
    [$ua, $ub] = bcrf_rpt_utc_range($from, $to);

    if (bcrf_sales_ready($pdo)) {
        $st = $pdo->prepare("SELECT s.business_date d, COUNT(DISTINCT s.id) n, SUM(l.gross_cents - l.discount_cents) net, SUM(l.tax_cents) t
                             FROM sales s JOIN sale_lines l ON l.sale_id = s.id
                             WHERE s.status <> 'voided' AND l.product_type <> 'gift_card' AND s.business_date BETWEEN ? AND ? GROUP BY s.business_date");
        $st->execute([$from, $to]);
        foreach ($st as $r) $add('register', $r['d'], (int)$r['net'] + (int)$r['t'], (int)$r['t'], (int)$r['n']);
        if (bcrf_refunds_ready($pdo)) {
            $st = $pdo->prepare("SELECT r.business_date d, SUM(rl.net_cents) net, SUM(rl.tax_cents) t
                                 FROM refunds r JOIN refund_lines rl ON rl.refund_id = r.id JOIN sale_lines l ON l.id = rl.sale_line_id
                                 WHERE l.product_type <> 'gift_card' AND r.business_date BETWEEN ? AND ? GROUP BY r.business_date");
            $st->execute([$from, $to]);
            foreach ($st as $r) $add('register', $r['d'], -((int)$r['net'] + (int)$r['t']), -(int)$r['t'], 0);
        }
    }
    if (bcrf_rpt_online_ready($pdo)) {
        $hasTax = function_exists('bcrf_itax_ready') && bcrf_itax_ready($pdo, 'tickets');
        $st = $pdo->prepare("SELECT paid_at, amount_cents" . ($hasTax ? ", COALESCE(tax_cents, 0) tax_cents" : ", 0 tax_cents") . " FROM tickets
                             WHERE source = 'online' AND payment_status = 'paid' AND paid_at >= ? AND paid_at < ?");
        $st->execute([$ua, $ub]);
        foreach ($st as $t) $add('tickets', $utcDay($t['paid_at']), (int)$t['amount_cents'], (int)$t['tax_cents']);
    }
    try {   // passes: paid_at from migration 0060; older rows fall back to created_at
        $hasTax = function_exists('bcrf_itax_ready') && bcrf_itax_ready($pdo, 'passes');
        $st = $pdo->prepare("SELECT " . ($hasTax ? "COALESCE(paid_at, created_at) at, COALESCE(tax_cents, 0) tax_cents" : "created_at at, 0 tax_cents") . ", amount_paid
                             FROM passes WHERE payment_status = 'paid' AND COALESCE(payment_provider, '') <> 'comp' AND amount_paid > 0
                               AND " . ($hasTax ? "COALESCE(paid_at, created_at)" : "created_at") . " >= ? AND " . ($hasTax ? "COALESCE(paid_at, created_at)" : "created_at") . " < ?");
        $st->execute([gmdate('Y-m-d H:i:s', strtotime($ua . ' UTC') - 86400), gmdate('Y-m-d H:i:s', strtotime($ub . ' UTC') + 86400)]);
        foreach ($st as $p) {
            $at = (string)$p['at'];
            if ($at < $ua || $at >= $ub) continue;
            $add('passes', $utcDay($at), (int)round((float)$p['amount_paid'] * 100), (int)$p['tax_cents']);
        }
    } catch (Throwable $e) { /* passes table missing */ }
    if (function_exists('bcrf_itax_ready') && bcrf_itax_ready($pdo, 'event_payments')) {
        try {   // the ledger: deposits, balances and refunds (negative) not taken at the register
            $st = $pdo->prepare("SELECT created_at, amount_cents, COALESCE(tax_cents, 0) tax_cents FROM event_payments
                                 WHERE voided_at IS NULL AND method <> 'register' AND created_at >= ? AND created_at < ?");
            $st->execute([$ua, $ub]);
            foreach ($st as $p) $add('events', $utcDay($p['created_at']), (int)$p['amount_cents'], (int)$p['tax_cents'], (int)$p['amount_cents'] > 0 ? 1 : 0);
        } catch (Throwable $e) { error_log('[reports] money in, ledger: ' . $e->getMessage()); }
    }
    try {   // Stripe booking payments with no ledger row (paid_at is farm-local, as bcrf_itax_rows reads it)
        $hasLedger = function_exists('bcrf_itax_ready') && bcrf_itax_ready($pdo, 'event_payments');
        $bp = $hasLedger && function_exists('bcrf_itax_admission_rate') ? bcrf_itax_admission_rate($pdo) : 0;
        $st = $pdo->prepare("SELECT b.amount_total, b.paid_at FROM bookings b
                             WHERE b.payment_status = 'paid' AND b.payment_provider = 'stripe' AND b.amount_total > 0 AND b.paid_at >= ? AND b.paid_at < ?"
                          . ($hasLedger ? " AND NOT EXISTS (SELECT 1 FROM event_payments e WHERE e.subject_type = 'booking' AND e.subject_id = b.id)" : ""));
        $st->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
        foreach ($st as $b) {
            $c = (int)round((float)$b['amount_total'] * 100);
            $tax = $bp && (string)$b['paid_at'] >= '2026-11-13' ? bcrf_itax_part($c, $bp) : 0;
            $add('events', substr((string)$b['paid_at'], 0, 10), $c, $tax);
        }
    } catch (Throwable $e) { /* bookings table missing */ }

    $gcSold = 0; $gcN = 0; $gcSpent = 0;
    try {   // gift cards bought (register or online) = money owed until spent; cards given without a sale are not money in
        $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(initial_cents), 0) c FROM gift_cards
                             WHERE status NOT IN ('pending', 'void') AND source IN ('pos', 'online')
                               AND COALESCE(paid_at, created_at) >= ? AND COALESCE(paid_at, created_at) < ?");
        $st->execute([$ua, $ub]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $gcN = (int)$r['n']; $gcSold = (int)$r['c'];
        $st = $pdo->prepare("SELECT COALESCE(-SUM(amount_cents), 0) FROM gift_card_txns WHERE type IN ('redeem', 'reverse', 'refund_credit') AND created_at >= ? AND created_at < ?");   // spent, less put back by voids and refunds
        $st->execute([$ua, $ub]);
        $gcSpent = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* gift cards not installed */ }

    $sum = fn($f) => array_sum(array_column($src, $f));
    return ['sources' => $src, 'by_day' => $byDay, 'gross' => $sum('gross'), 'tax' => $sum('tax'), 'net' => $sum('net'),
            'gc_sold' => $gcSold, 'gc_sold_count' => $gcN, 'gc_spent' => $gcSpent];
}

/** The same number of days just before $from..$to; the same dates last month when $from is the 1st ("vs last month"), last year when it is Jan 1. */
function bcrf_rpt_prev_range(string $from, string $to): array {
    if (substr($from, 8) === '01' && substr($from, 0, 7) === substr($to, 0, 7)) {
        $pf = date('Y-m-01', strtotime($from . ' -1 month'));
        $pt = min(date('Y-m-t', strtotime($pf)), substr($pf, 0, 8) . substr($to, 8));
        return [$pf, $pt];
    }
    if (substr($from, 5) === '01-01' && substr($from, 0, 4) === substr($to, 0, 4)) {   // year so far: same dates last year
        $y = (int)substr($from, 0, 4) - 1;
        return [$y . '-01-01', min(date('Y-m-t', strtotime($y . substr($to, 4, 3) . '-01')), $y . substr($to, 4))];
    }
    $n = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    return [date('Y-m-d', strtotime($from . " -$n days")), date('Y-m-d', strtotime($from . ' -1 day'))];
}

/** Register sales by farm-local hour (net, no tax, no gift cards) and top items after discounts and refunds. */
function bcrf_rpt_register_detail(PDO $pdo, string $from, string $to, int $topN = 15): array {
    $out = ['hours' => [], 'items' => []];
    if (!bcrf_sales_ready($pdo)) return $out;
    $st = $pdo->prepare("SELECT s.completed_at, SUM(l.gross_cents - l.discount_cents) net
                         FROM sales s JOIN sale_lines l ON l.sale_id = s.id
                         WHERE s.status <> 'voided' AND l.product_type <> 'gift_card' AND s.business_date BETWEEN ? AND ? GROUP BY s.id, s.completed_at");
    $st->execute([$from, $to]);
    foreach ($st as $r) {
        $hr = (int)bcrf_local_time((string)$r['completed_at'], 'G');
        $out['hours'][$hr]['cents'] = ($out['hours'][$hr]['cents'] ?? 0) + (int)$r['net'];
        $out['hours'][$hr]['count'] = ($out['hours'][$hr]['count'] ?? 0) + 1;
    }
    $st = $pdo->prepare("SELECT COALESCE(l.product_id, CONCAT('x', l.description)) k, MAX(l.description) name, MAX(COALESCE(NULLIF(l.category_label, ''), 'Uncategorized')) cat,
                                SUM(l.quantity - COALESCE(rb.q, 0)) qty, SUM(l.gross_cents - l.discount_cents - COALESCE(rb.c, 0)) net
                         FROM sales s JOIN sale_lines l ON l.sale_id = s.id
                         LEFT JOIN (SELECT sale_line_id, SUM(quantity) q, SUM(net_cents) c FROM refund_lines GROUP BY sale_line_id) rb ON rb.sale_line_id = l.id
                         WHERE s.status <> 'voided' AND l.product_type <> 'gift_card' AND s.business_date BETWEEN ? AND ?
                         GROUP BY k");
    $st->execute([$from, $to]);
    foreach ($st as $r) $out['items'][] = ['name' => (string)$r['name'], 'category' => (string)$r['cat'], 'qty' => (float)$r['qty'], 'cents' => (int)$r['net']];
    usort($out['items'], fn($a, $b) => $b['cents'] <=> $a['cents'] ?: strcmp($a['name'], $b['name']));
    $out['items'] = array_slice($out['items'], 0, max(1, $topN));
    return $out;
}

/** Cents as a plain decimal for CSV (no $ or commas, so spreadsheets read it as a number). */
function bcrf_rpt_csv_money(int $c): string { return number_format($c / 100, 2, '.', ''); }

/** Send a CSV download and log the export (13: exports are logged). */
function bcrf_rpt_send_csv(PDO $pdo, string $filename, array $header, array $rows, string $what): void {
    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$type, $uid, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $type, 'actor_id' => $uid, 'actor_label' => $name, 'ip' => $ip,
                                'action' => 'export', 'object_type' => 'report', 'object_id' => null, 'object_label' => $what . ' (' . count($rows) . ' rows)']);
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Cache-Control: no-store');
    $f = fopen('php://output', 'w');
    fputcsv($f, $header);
    foreach ($rows as $r) {
        // Guard against spreadsheet formula injection in free-text cells (names, reasons)
        $r = array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v) ? "'" . $v : $v, $r);
        fputcsv($f, $r);
    }
    fclose($f);
    exit;
}

}
