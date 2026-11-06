<?php
// admin/farm_reports.php — Farm OS reports (Phase 1, slice 5, Nov 6, 2026). 13 — Reports & Analytics, the reports that ship with the POS.
// Numbers come from includes/reports.php; definitions are written there. Every report exports to CSV (D-10); exports are audited.
//   ?r=daily&date=Y-m-d        daily sales summary (+ online tickets, visitors)        &csv=1  one row for the day, stable columns
//   ?r=month&month=Y-m        sales by day and tax by tax category for filing         &csv=1  sales by day | &csv=tax  tax categories
//   ?r=log&from=&to=           refunds, voids and discounts: who, when, why, how much  &csv=1
//   ?r=visitors&date=Y-m-d     visitors, arrivals by hour, peak occupancy, overrides   &csv=1  check-ins | &csv=hours  by hour
// Access: the Sales Analytics permission (Permissions Matrix). Strict CSP (C-09): styles in assets/register.css + reports.css,
// the only script is assets/report.js (print button).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'sales_analytics']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/reports.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m  = fn($c) => ((int)$c < 0 ? '−$' : '$') . number_format(abs((int)$c) / 100, 2);
$mc = 'bcrf_rpt_csv_money';
$today = bcrf_business_date();
$isDate  = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;
$r = in_array($_GET['r'] ?? '', ['daily', 'month', 'log', 'visitors'], true) ? $_GET['r'] : 'daily';
$date  = $isDate($_GET['date'] ?? null) ? $_GET['date'] : $today;
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : substr($today, 0, 7);
$from  = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime($today . ' -6 days'));
$to    = $isDate($_GET['to'] ?? null) ? $_GET['to'] : $today;
if ($from > $to) [$from, $to] = [$to, $from];
if ((strtotime($to) - strtotime($from)) / 86400 > 366) $from = date('Y-m-d', strtotime($to . ' -366 days'));
$csv = (string)($_GET['csv'] ?? '');

$head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; base-uri \'self\'; form-action \'self\'">'
      . '<meta name="robots" content="noindex"><link rel="stylesheet" href="assets/register.css?v=5"><link rel="stylesheet" href="assets/reports.css?v=2">'
      . '<script src="assets/report.js?v=1" defer></script>';

if (!bcrf_sales_ready($pdo) || !bcrf_refunds_ready($pdo)) {
    echo $head, '<title>Reports</title></head><body class="page"><main class="narrow"><p class="flash err">Run migrations 0009 to 0011 in Settings › Database first.</p></main></body></html>';
    exit;
}
$admission = bcrf_admission_ready($pdo);

// -- CSV exports --
if ($csv !== '') {
    $moneyCols = ['gross_sales', 'discounts', 'refunds', 'net_sales', 'tax', 'register_total', 'cash', 'paypal', 'venmo', 'cashapp', 'check', 'other_tender', 'voids_total', 'online_ticket_sales'];
    $dayRow = function (array $d) use ($moneyCols, $mc) {
        $out = [];
        foreach (bcrf_rpt_day_columns() as $c) $out[] = in_array($c, $moneyCols, true) ? $mc($d[$c]) : $d[$c];
        return $out;
    };
    if ($r === 'daily') {
        bcrf_rpt_send_csv($pdo, "daily-sales-$date.csv", bcrf_rpt_day_columns(), array_map($dayRow, array_values(bcrf_rpt_days($pdo, $date, $date))), "Daily sales $date");
    }
    if ($r === 'month' && $csv === 'tax') {
        [$a, $b] = [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
        $rows = array_map(fn($x) => [$month, $x['label'], number_format($x['rate_bp'] / 100, 2, '.', ''), $mc($x['gross']), $mc($x['discounts']), $mc($x['refunds']), $mc($x['net']), $mc($x['tax'])],
                          bcrf_rpt_breakdown($pdo, $a, $b, 'tax'));
        bcrf_rpt_send_csv($pdo, "sales-tax-$month.csv", ['month', 'tax_category', 'current_rate_pct', 'gross_sales', 'discounts', 'refunds', 'net_sales', 'tax'], $rows, "Monthly tax $month");
    }
    if ($r === 'month') {
        $days = bcrf_rpt_days($pdo, $month . '-01', date('Y-m-t', strtotime($month . '-01')));
        bcrf_rpt_send_csv($pdo, "sales-by-day-$month.csv", bcrf_rpt_day_columns(), array_map($dayRow, array_values($days)), "Sales by day $month");
    }
    if ($r === 'log') {
        $rows = array_map(fn($x) => [$x['date'], bcrf_local_time($x['at'], 'H:i'), $x['type'], $x['number'], $x['sale'], $x['by'], $x['approved_by'], $x['reason'], $x['how'], $mc($x['amount'])],
                          bcrf_rpt_log($pdo, $from, $to));
        bcrf_rpt_send_csv($pdo, "refunds-voids-discounts-$from-to-$to.csv", ['business_date', 'time', 'type', 'number', 'sale_number', 'staff', 'approved_by', 'reason', 'refunded_by', 'amount'], $rows, "Refunds, voids & discounts $from to $to");
    }
    if ($r === 'visitors' && $admission) {
        $v = bcrf_rpt_visitors($pdo, $date);
        if ($csv === 'hours') {
            $rows = [];
            $hrs = array_keys($v['by_hour'] + $v['occupancy']['hourly']);
            foreach ($hrs ? range(min($hrs), max($hrs)) : [] as $hr) {
                $rows[] = [$date, sprintf('%02d:00', $hr), $v['by_hour'][$hr]['arrivals'] ?? 0, $v['by_hour'][$hr]['guests'] ?? 0, $v['occupancy']['hourly'][$hr] ?? 0];
            }
            bcrf_rpt_send_csv($pdo, "visitors-by-hour-$date.csv", ['business_date', 'hour', 'arrivals', 'guests', 'most_on_site'], $rows, "Visitors by hour $date");
        }
        $src = ['ticket' => 'online_ticket', 'gate_sale' => 'gate_sale', 'pass' => 'pass', 'booking' => 'booking', 'comp' => 'comp'];
        $rows = array_map(fn($c) => [$date, bcrf_local_time($c['checked_in_at'], 'H:i'), $c['checked_out_at'] ? bcrf_local_time($c['checked_out_at'], 'H:i') : '',
                                     $src[$c['source_type']] ?? $c['source_type'], (int)$c['party_size'], (int)$c['guests_counted'],
                                     $c['public_code'] ? bcrf_ticket_code_fmt($c['public_code']) : '', $c['holder_name'] ?: $c['purchaser_name'],
                                     (string)$c['capacity_override_reason'], bcrf_staff_name($pdo, $c['staff_user_id'])], $v['checkins']);
        bcrf_rpt_send_csv($pdo, "check-ins-$date.csv", ['business_date', 'checked_in', 'checked_out', 'source', 'guests', 'guests_counted', 'ticket_code', 'name', 'capacity_override', 'staff'], $rows, "Check-ins $date");
    }
    http_response_code(400); exit('Unknown export.');
}

// -- Page --
$titles = ['daily' => 'Daily sales', 'month' => 'Monthly & tax', 'log' => 'Refunds, voids, discounts', 'visitors' => 'Visitors'];
$row = fn($label, $val, $cls = '') => '<tr' . ($cls ? ' class="' . $cls . '"' : '') . '><td>' . $label . '</td><td class="r">' . $val . '</td></tr>';
$q = fn(array $p) => 'farm_reports.php?' . http_build_query($p);
$pl = fn(int $n, string $w) => $n . ' ' . $w . ($n === 1 ? '' : 's');
$step = fn(string $d, int $n) => date('Y-m-d', strtotime("$d $n days"));

echo $head, '<title>', $h($titles[$r]), ' — Reports</title></head><body class="page report"><main class="narrow wide">';
echo '<p class="noprint"><a href="admin.php">← Admin</a> · <a href="register.php">Register</a>', $admission ? ' · <a href="gate.php">Gate</a>' : '', '</p>';
echo '<nav class="rtabs noprint" aria-label="Reports">';
foreach ($titles as $k => $t) {
    if ($k === 'visitors' && !$admission) continue;
    echo '<a href="', $h($q(['r' => $k] + ($k === 'month' ? ['month' => $month] : ($k === 'log' ? [] : ['date' => $date])))), '"', $k === $r ? ' aria-current="page"' : '', '>', $h($t), '</a>';
}
echo '</nav>';

$dateForm = function (string $name, string $value, string $type = 'date', array $extra = []) use ($h, $r) {
    $out = '<form class="rform noprint" method="GET" action="farm_reports.php"><input type="hidden" name="r" value="' . $h($r) . '">';
    foreach ($extra as $k => $v) $out .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
    return $out . '<input type="' . $type . '" name="' . $h($name) . '" value="' . $h($value) . '" aria-label="' . ($type === 'month' ? 'Month' : 'Date') . '"><button class="btn sm" type="submit">Show</button></form>';
};
$csvLink = fn(array $p, string $label = 'Download CSV') => '<a class="btn sm" href="' . $h($q($p)) . '">' . $h($label) . '</a>';

if ($r === 'daily') {
    $d = bcrf_rpt_days($pdo, $date, $date)[$date];
    $dLabel = date('l, F j, Y', strtotime($date));
    echo '<h1>Daily sales</h1><p><b>', $h(bcrf_farm_name()), '</b> · ', $h($dLabel), $date === $today ? ' (so far)' : '', '</p>';
    echo '<div class="rbar noprint"><a class="btn sm" href="', $h($q(['r' => 'daily', 'date' => $step($date, -1)])), '">‹ Prev</a>',
         $date < $today ? '<a class="btn sm" href="' . $h($q(['r' => 'daily', 'date' => $step($date, 1)])) . '">Next ›</a>' : '',
         $dateForm('date', $date), '<button type="button" class="btn sm" id="btnPrint">Print</button>', $csvLink(['r' => 'daily', 'date' => $date, 'csv' => 1]), '</div>';

    echo '<h2>Register</h2><table class="rt">',
         $row('Sales', (int)$d['sales_count']), $row('Gross sales', $m($d['gross_sales'])), $row('Discounts', $m(-$d['discounts'])),
         $row('Refunds', $m(-$d['refunds'])), $row('Net sales', $m($d['net_sales']), 'b'), $row('Tax collected', $m($d['tax'])),
         $row('Taken in, after refunds', $m($d['register_total']), 'b'),
         $d['sales_count'] ? $row('Average sale <small class="muted">before tax</small>', $m(intdiv($d['gross_sales'] - $d['discounts'], $d['sales_count']))) : '',
         $d['voids_count'] ? $row('Voided sales (' . (int)$d['voids_count'] . ')', $m($d['voids_total']) . ' <small class="muted">not counted</small>') : '',
         '</table>';

    echo '<h2>By payment type</h2><table class="rt">';
    $any = false;
    foreach (bcrf_tenders() + ['other_tender' => 'Other'] as $t => $label) {
        $col = $t === 'other_tender' ? 'other_tender' : $t;
        if (!$d[$col]) continue;
        $any = true; echo $row($h($label), $m($d[$col]));
    }
    if (!$any) echo '<tr><td class="muted">No payments</td><td></td></tr>';
    echo '</table><p class="muted">After refunds paid back that day.</p>';

    // P2P matching status (05: flagged payments appear in the daily sales report; 13: reconciled vs not, by app and day)
    require_once __DIR__ . '/../includes/reconcile.php';
    $p2p = bcrf_p2p_day($pdo, $date);
    if ($p2p) {
        echo '<h2>PayPal, Venmo, Cash App matching</h2><table class="rt">';
        foreach ($p2p as $t => $x) {
            echo $row($h(bcrf_p2p_tenders()[$t]) . ' <small class="muted">· ' . $x['n'] . ' payment' . ($x['n'] === 1 ? '' : 's') . ', ' . $m($x['cents']) . '</small>',
                      $x['matched'] . ' matched' . ($x['open'] ? ', <b>' . $x['open'] . ' to check</b>' : '') . ($x['flagged'] ? ', <span class="off">' . count($x['flagged']) . ' problem' . (count($x['flagged']) === 1 ? '' : 's') . '</span>' : ''));
            foreach ($x['flagged'] as $f) echo $row('<small class="off">' . $h(bcrf_p2p_flags()[$f['reconcile_flag']] ?? $f['reconcile_flag']) . '</small> <small>' . $h($f['payer_handle'] ?: '?') . ($f['reference'] ? ' #' . $h($f['reference']) : '') . ' · ' . $h($f['sale_number']) . '</small>', '<small>' . $m($f['amount_cents']) . '</small>');
        }
        echo '</table><p class="noprint"><a href="reconcile.php">Match payments →</a></p>';
    }

    $cats = bcrf_rpt_breakdown($pdo, $date, $date, 'category');
    echo '<h2>By category</h2>';
    if (!$cats) echo '<p class="muted">No sales.</p>';
    else {
        echo '<table class="rt"><tr class="th"><td>Category</td><td class="r">Net sales</td></tr>';
        foreach ($cats as $c) echo $row($h($c['label']) . ($c['discounts'] || $c['refunds'] ? '<br><small class="muted">' . $m($c['gross']) . ' gross' . ($c['discounts'] ? ', ' . $m(-$c['discounts']) . ' discounts' : '') . ($c['refunds'] ? ', ' . $m(-$c['refunds']) . ' refunds' : '') . '</small>' : ''), $m($c['net']));
        echo '</table>';
    }
    $taxes = bcrf_rpt_breakdown($pdo, $date, $date, 'tax');
    echo '<h2>Tax by tax category</h2>';
    if (!$taxes) echo '<p class="muted">No sales.</p>';
    else {
        echo '<table class="rt"><tr class="th"><td>Tax category · sales</td><td class="r">Tax</td></tr>';
        foreach ($taxes as $c) echo $row($h($c['label']) . ' <small class="muted">· ' . $m($c['net']) . ' sales</small>', $m($c['tax']));
        echo '</table>';
    }
    if (bcrf_rpt_online_ready($pdo)) {
        echo '<h2>Online tickets (Stripe)</h2><table class="rt">',
             $row('Orders paid', (int)$d['online_ticket_orders']), $row('Guests', (int)$d['online_ticket_guests']),
             $row('Sales', $m($d['online_ticket_sales']), 'b'), '</table><p class="muted">Paid to Stripe, not in the drawer. No sales tax is charged on online tickets.</p>';
    }
    if ($admission) {
        echo '<h2>Visitors</h2><table class="rt">', $row('Visits', (int)$d['visits']), $row('Guests', (int)$d['visitors']),
             $row('Most on site at once', (int)$d['peak_occupancy']), '</table><p class="noprint"><a href="', $h($q(['r' => 'visitors', 'date' => $date])), '">Visitors report →</a></p>';
    }
    $log = bcrf_rpt_log($pdo, $date, $date);
    if ($log) echo '<p class="noprint"><a href="', $h($q(['r' => 'log', 'from' => $date, 'to' => $date])), '">', count($log), ' refund/void/discount entr', count($log) === 1 ? 'y' : 'ies', ' →</a></p>';
}

if ($r === 'month') {
    [$a, $b] = [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
    if ($b > $today) $b = max($a, $today);
    $days = bcrf_rpt_days($pdo, $a, $b);
    $tot = bcrf_rpt_total($days);
    $prevM = date('Y-m', strtotime($month . '-01 -1 month')); $nextM = date('Y-m', strtotime($month . '-01 +1 month'));
    echo '<h1>Monthly sales & tax</h1><p><b>', $h(bcrf_farm_name()), '</b> · ', $h(date('F Y', strtotime($month . '-01'))), $b === $today ? ' (so far)' : '', '</p>';
    echo '<div class="rbar noprint"><a class="btn sm" href="', $h($q(['r' => 'month', 'month' => $prevM])), '">‹ Prev</a>',
         $nextM <= substr($today, 0, 7) ? '<a class="btn sm" href="' . $h($q(['r' => 'month', 'month' => $nextM])) . '">Next ›</a>' : '',
         $dateForm('month', $month, 'month'), '<button type="button" class="btn sm" id="btnPrint">Print</button></div>';

    $taxes = bcrf_rpt_breakdown($pdo, $a, $b, 'tax');
    echo '<h2>Tax by tax category (for filing)</h2>';
    if (!$taxes) echo '<p class="muted">No sales this month.</p>';
    else {
        echo '<table class="rt"><tr class="th"><td>Tax category</td><td class="r">Net sales</td><td class="r">Tax</td></tr>';
        foreach ($taxes as $c) echo '<tr><td>', $h($c['label']), ' <small class="muted">', $c['rate_bp'] !== null ? $h(rtrim(rtrim(number_format($c['rate_bp'] / 100, 2), '0'), '.')) . '% now' : '', '</small></td><td class="r">', $m($c['net']), '</td><td class="r">', $m($c['tax']), '</td></tr>';
        echo '<tr class="b"><td>Total</td><td class="r">', $m(array_sum(array_column($taxes, 'net'))), '</td><td class="r">', $m(array_sum(array_column($taxes, 'tax'))), '</td></tr></table>';
        echo '<p class="muted">Register sales only, after discounts and refunds. Online tickets (', $m($tot['online_ticket_sales']), ' this month) have no tax charged. Rates shown are today\'s; each sale kept the rate it was sold at.</p>';
    }
    echo '<p class="noprint rbar">', $csvLink(['r' => 'month', 'month' => $month, 'csv' => 'tax'], 'Tax CSV'), $csvLink(['r' => 'month', 'month' => $month, 'csv' => 1], 'Sales by day CSV'), '</p>';

    echo '<h2>Totals</h2><table class="rt">', $row('Sales', (int)$tot['sales_count']), $row('Gross sales', $m($tot['gross_sales'])),
         $row('Discounts', $m(-$tot['discounts'])), $row('Refunds', $m(-$tot['refunds'])), $row('Net sales', $m($tot['net_sales']), 'b'),
         $row('Tax collected', $m($tot['tax'])), $row('Register taken in, after refunds', $m($tot['register_total']), 'b'),
         $row('Online tickets', $m($tot['online_ticket_sales'])), $admission ? $row('Visitors', (int)$tot['visitors']) : '', '</table>';

    echo '<h2>By day</h2><div class="scroll"><table class="rt grid"><tr class="th"><td>Date</td><td class="r">Sales</td><td class="r">Net</td><td class="r">Tax</td><td class="r">Cash</td><td class="r">P2P</td><td class="r">Online</td>', $admission ? '<td class="r">Guests</td><td class="r">Peak</td>' : '', '</tr>';
    foreach ($days as $dd => $x) {
        if (!$x['sales_count'] && !$x['refunds'] && !$x['online_ticket_orders'] && !$x['visits'] && !$x['voids_count']) continue;
        echo '<tr><td><a href="', $h($q(['r' => 'daily', 'date' => $dd])), '">', $h(date('D j', strtotime($dd))), '</a></td><td class="r">', (int)$x['sales_count'], '</td><td class="r">', $m($x['net_sales']),
             '</td><td class="r">', $m($x['tax']), '</td><td class="r">', $m($x['cash']), '</td><td class="r">', $m($x['paypal'] + $x['venmo'] + $x['cashapp']),
             '</td><td class="r">', $m($x['online_ticket_sales']), '</td>', $admission ? '<td class="r">' . (int)$x['visitors'] . '</td><td class="r">' . (int)$x['peak_occupancy'] . '</td>' : '', '</tr>';
    }
    echo '</table></div>';
}

if ($r === 'log') {
    $log = bcrf_rpt_log($pdo, $from, $to);
    echo '<h1>Refunds, voids & discounts</h1><p><b>', $h(bcrf_farm_name()), '</b> · ', $h(date('M j', strtotime($from))), $from !== $to ? ' – ' . $h(date('M j, Y', strtotime($to))) : ', ' . $h(date('Y', strtotime($from))), '</p>';
    echo '<form class="rform noprint" method="GET" action="farm_reports.php"><input type="hidden" name="r" value="log">',
         '<input type="date" name="from" value="', $h($from), '" aria-label="From"><span>to</span><input type="date" name="to" value="', $h($to), '" aria-label="To">',
         '<button class="btn sm" type="submit">Show</button></form>';
    echo '<p class="rbar noprint"><button type="button" class="btn sm" id="btnPrint">Print</button>', $csvLink(['r' => 'log', 'from' => $from, 'to' => $to, 'csv' => 1]), '</p>';
    if (!$log) echo '<p class="muted">None in this period.</p>';
    else {
        $sum = [];
        foreach ($log as $x) { $sum[$x['type']][0] = ($sum[$x['type']][0] ?? 0) + 1; $sum[$x['type']][1] = ($sum[$x['type']][1] ?? 0) + $x['amount']; }
        echo '<table class="rt">';
        foreach ($sum as $t => [$n, $c]) echo $row($h($t) . 's (' . $n . ')', $m($c) . ($t === 'Void' ? ' <small class="muted">not counted</small>' : ''));
        echo '</table><div class="card">';
        foreach ($log as $x) {
            echo '<div class="lrow"><div><b>', $h($x['type']), '</b> ', $h($x['number']), $x['type'] === 'Refund' ? ' <small class="muted">on ' . $h($x['sale']) . '</small>' : '',
                 '<br><small class="muted">', $h(date('D M j', strtotime($x['date']))), ' · ', $h(bcrf_local_time($x['at'])), $x['by'] !== '' ? ' · ' . $h($x['by']) : '',
                 $x['approved_by'] !== '' ? ' · approved by ' . $h($x['approved_by']) : '', '</small>',
                 $x['reason'] !== '' || $x['how'] !== '' ? '<br><small>' . $h(trim($x['reason'] . ($x['how'] ? ' · ' . $x['how'] : ''), ' ·')) . '</small>' : '',
                 '</div><div class="r">', $m($x['amount']), '</div></div>';
        }
        echo '</div>';
    }
}

if ($r === 'visitors') {
    if (!$admission) { echo '<p class="flash err">Run migration 0012 in Settings › Database first.</p></main></body></html>'; exit; }
    $v = bcrf_rpt_visitors($pdo, $date);
    $o = $v['occupancy'];
    echo '<h1>Visitors</h1><p><b>', $h(bcrf_farm_name()), '</b> · ', $h(date('l, F j, Y', strtotime($date))), $date === $today ? ' (so far)' : '', '</p>';
    echo '<div class="rbar noprint"><a class="btn sm" href="', $h($q(['r' => 'visitors', 'date' => $step($date, -1)])), '">‹ Prev</a>',
         $date < $today ? '<a class="btn sm" href="' . $h($q(['r' => 'visitors', 'date' => $step($date, 1)])) . '">Next ›</a>' : '',
         $dateForm('date', $date), '<button type="button" class="btn sm" id="btnPrint">Print</button>',
         $csvLink(['r' => 'visitors', 'date' => $date, 'csv' => 'hours'], 'By hour CSV'), $csvLink(['r' => 'visitors', 'date' => $date, 'csv' => 1], 'Check-ins CSV'), '</div>';
    echo '<table class="rt">', $row('Parties', (int)$v['visits']), $row('Guests', (int)$v['guests'], 'b'),
         $v['counted'] !== $v['guests'] ? $row('Guests counted toward the limit', (int)$v['counted']) : '',
         $row('Most on site at once', (int)$o['peak'] . ' of ' . (int)$o['limit'] . ($o['peak_at'] ? ' <small class="muted">at ' . $h(bcrf_local_time($o['peak_at'])) . '</small>' : ''), 'b'),
         $row('Times the farm was full', (int)$o['hits']), $row('Capacity overrides', count($v['overrides'])), '</table>';
    if ($v['by_source']) {
        echo '<h2>How they came in</h2><table class="rt">';
        foreach ($v['by_source'] as $k => $x) echo $row($h($k) . ' <small class="muted">· ' . (int)$x['visits'] . ' part' . ($x['visits'] === 1 ? 'y' : 'ies') . '</small>', (int)$x['guests'] . ' guest' . ((int)$x['guests'] === 1 ? '' : 's'));
        echo '</table>';
    }
    if ($v['by_hour'] || $o['hourly']) {
        $hours = array_keys($v['by_hour'] + $o['hourly']);
        $max = max(1, (int)$o['limit'], ...array_values($o['hourly'] ?: [0]));
        echo '<h2>By hour</h2><table class="rt hours"><tr class="th"><td>Hour</td><td class="r">Arrived</td><td>Most on site</td></tr>';
        for ($hr = min($hours); $hr <= max($hours); $hr++) {
            $on = (int)($o['hourly'][$hr] ?? 0);
            $w = (int)round($on * 10 / $max);   // 0–10, a class (no inline style under the strict CSP)
            echo '<tr><td>', $h(date('g A', mktime($hr, 0, 0))), '</td><td class="r">', (int)($v['by_hour'][$hr]['guests'] ?? 0), '</td><td><span class="hbar w', $w, $on >= $o['limit'] ? ' full' : '', '"></span> ', $on, '</td></tr>';
        }
        echo '</table>';
    }
    if ($v['overrides']) {
        echo '<h2>Capacity overrides</h2><table class="rt">';
        foreach ($v['overrides'] as $x) echo $row($h($x['time']) . ' · ' . $h($x['who']) . ' · ' . (int)$x['guests'] . ' guest' . ((int)$x['guests'] === 1 ? '' : 's') . '<br><small>' . $h($x['reason']) . ($x['by'] ? ' · ' . $h($x['by']) : '') . '</small>', '');
        echo '</table>';
    }
    $l = bcrf_rpt_ticket_liability($pdo);
    if (bcrf_rpt_online_ready($pdo)) {
        echo '<h2>Online tickets not used yet (today)</h2><table class="rt">',
             $row('Unexpired: ' . $pl((int)$l['open_tickets'], 'ticket') . ', ' . $pl((int)$l['open_guests'], 'admission'), $m($l['open_cents']), 'b'),
             $l['expired_tickets'] ? $row('Expired unused: ' . $pl((int)$l['expired_tickets'], 'ticket') . ', ' . $pl((int)$l['expired_guests'], 'admission'), $m($l['expired_cents'])) : '',
             '</table><p class="muted">Ticket liability: paid online and still owed as visits.</p>';
    }
    if ($o['limit'] && $date !== $today) echo '<p class="muted">Full/peak use today\'s limit of ', (int)$o['limit'], ' guests.</p>';
}
echo '</main></body></html>';
