<?php
// admin/reconcile.php — match PayPal, Venmo and Cash App payments against the apps (Farm OS Phase 1, slice 6, Nov 6, 2026; 05 › Peer-to-peer reconciliation).
// Staff open the app on the farm phone, find each payment by name and amount, and tap Matched, or flag a problem.
//   GET  reconcile.php[?tab=open|flagged|matched][&from=&to=]
//   POST reconcile.php  act=match|flag|reopen, ids[] (or id), flag=missing|wrong_amount|reversed, csrf_token   → back to the same tab
// Permission: "P2P Reconcile" in the Permissions Matrix (owners always). Plain forms, no script (strict CSP, C-09).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'p2p_reconcile']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/reconcile.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn($c) => '$' . number_format((int)$c / 100, 2);
$tab = in_array($_GET['tab'] ?? '', ['open', 'flagged', 'matched'], true) ? $_GET['tab'] : 'open';
$isDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$today = bcrf_farm_today();
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime($today . ' -13 days'));
$to   = $isDate($_GET['to'] ?? null) ? $_GET['to'] : $today;
$self = fn(array $p = []) => 'reconcile.php' . ($p ? '?' . http_build_query($p) : '');

$head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; base-uri \'self\'; form-action \'self\'">'
      . '<meta name="robots" content="noindex"><link rel="stylesheet" href="assets/register.css?v=5"><link rel="stylesheet" href="assets/reports.css?v=2">';
if (!bcrf_sales_ready($pdo)) {
    echo $head, '<title>P2P matching</title></head><body class="page"><main class="narrow"><p class="flash err">Run migration 0009 in Settings › Database first.</p></main></body></html>';
    exit;
}

// -- Actions (POST → redirect back) --
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = $self(array_filter(['tab' => $_POST['tab'] ?? $tab, 'from' => $_POST['from'] ?? null, 'to' => $_POST['to'] ?? null]));
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['p2p_flash'] = ['err', 'Security token expired; try again.'];
    } else {
        $ids = array_merge((array)($_POST['ids'] ?? []), isset($_POST['id']) ? [$_POST['id']] : []);
        try {
            $n = bcrf_p2p_mark($pdo, $ids, (string)($_POST['act'] ?? ''), $_POST['flag'] ?? null);
            $what = ['match' => 'matched', 'flag' => 'flagged', 'reopen' => 'moved back to check'][$_POST['act']] ?? 'updated';
            $_SESSION['p2p_flash'] = ['ok', $n === 0 ? 'Nothing changed (already done).' : $n . ' payment' . ($n === 1 ? '' : 's') . ' ' . $what . '.'];
        } catch (InvalidArgumentException $e) {
            $_SESSION['p2p_flash'] = ['err', $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[BCRF reconcile] ' . $e->getMessage());
            $_SESSION['p2p_flash'] = ['err', 'Could not save. Try again.'];
        }
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['p2p_flash'] ?? null; unset($_SESSION['p2p_flash']);
$c = bcrf_p2p_counts($pdo);
$rows = $tab === 'matched' ? bcrf_p2p_list($pdo, 'matched', $from, $to) : bcrf_p2p_list($pdo, $tab);
$groups = bcrf_p2p_group($rows);
$tenders = bcrf_p2p_tenders(); $flags = bcrf_p2p_flags();
$stale = date('Y-m-d', strtotime($today . ' -' . bcrf_p2p_stale_days() . ' days'));
$hidden = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="tab" value="' . $h($tab) . '">'
        . ($tab === 'matched' ? '<input type="hidden" name="from" value="' . $h($from) . '"><input type="hidden" name="to" value="' . $h($to) . '">' : '');
$btn = fn(string $act, string $label, array $extra = [], string $cls = 'btn sm') => '<button class="' . $cls . '" type="submit" name="act" value="' . $h($act) . '"'
        . implode('', array_map(fn($k, $v) => ' ' . $k . '="' . $h($v) . '"', array_keys($extra), $extra)) . '>' . $h($label) . '</button>';

echo $head, '<title>P2P matching</title></head><body class="page"><main class="narrow wide">';
echo '<p><a href="admin.php">← Admin</a> · <a href="farm_reports.php">Reports</a></p>';
echo '<h1>PayPal, Venmo & Cash App</h1>';
if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
echo '<nav class="rtabs" aria-label="P2P">',
     '<a href="', $h($self(['tab' => 'open'])), '"', $tab === 'open' ? ' aria-current="page"' : '', '>To check', $c['open'] ? ' (' . $c['open'] . ')' : '', '</a>',
     '<a href="', $h($self(['tab' => 'flagged'])), '"', $tab === 'flagged' ? ' aria-current="page"' : '', '>Problems', $c['flagged'] ? ' (' . $c['flagged'] . ')' : '', '</a>',
     '<a href="', $h($self(['tab' => 'matched'])), '"', $tab === 'matched' ? ' aria-current="page"' : '', '>Matched</a></nav>';

if ($tab === 'open') {
    echo '<p class="muted">Open each app on the farm phone, find the payment by name and amount (the # is the end of its transaction ID), and tap <b>Matched</b>. If it isn\'t there, or the amount is off, tap <b>Problem</b>.</p>';
    if ($c['stale']) echo '<p class="flash err">', $c['stale'], ' payment', $c['stale'] === 1 ? ' is' : 's are', ' more than ', bcrf_p2p_stale_days(), ' days old and still not checked (', $m($c['stale_cents']), ').</p>';
}
if ($tab === 'flagged') echo '<p class="muted">Payments marked as a problem. If it turns up in the app later, tap <b>Found it</b>. Settle money problems with the customer, then match it or leave it here as a record.</p>';
if ($tab === 'matched') {
    echo '<form class="rform" method="GET" action="reconcile.php"><input type="hidden" name="tab" value="matched">',
         '<input type="date" name="from" value="', $h($from), '" aria-label="From"><span>to</span><input type="date" name="to" value="', $h($to), '" aria-label="To">',
         '<button class="btn sm" type="submit">Show</button></form>';
}

if (!$groups) {
    echo '<p class="card muted">', ['open' => 'All caught up. Nothing to check.', 'flagged' => 'No problems flagged.', 'matched' => 'Nothing matched in these dates.'][$tab], '</p>';
}
foreach ($groups as $date => $byT) {
    foreach ($byT as $t => $list) {
        $sum = array_sum(array_column($list, 'amount_cents'));
        $old = $tab === 'open' && $date < $stale;
        echo '<section class="card p2p', $old ? ' old' : '', '"><div class="p2phead"><div><b>', $h($tenders[$t] ?? $t), '</b> · ', $h(date('D, M j', strtotime($date))),
             '<br><small class="muted">', count($list), ' payment', count($list) === 1 ? '' : 's', ' · ', $m($sum), $old ? ' · over ' . bcrf_p2p_stale_days() . ' days old' : '', '</small></div>';
        if ($tab === 'open' && count($list) > 1) {
            echo '<form method="POST" action="reconcile.php">', $hidden;
            foreach ($list as $p) echo '<input type="hidden" name="ids[]" value="', (int)$p['id'], '">';
            echo $btn('match', 'All ' . count($list) . ' matched'), '</form>';
        }
        echo '</div>';
        foreach ($list as $p) {
            echo '<div class="p2prow"><div><b>', $m($p['amount_cents']), '</b> · ', $h($p['payer_handle'] ?: 'no name'), $p['reference'] ? ' · #' . $h($p['reference']) : '',
                 '<br><small class="muted">', $h(bcrf_local_time($p['created_at'])), ' · ', $h($p['sale_number']), ($n = bcrf_staff_name($pdo, $p['created_by'])) !== '' ? ' · ' . $h($n) : '',
                 '</small>';
            if ($tab === 'flagged') echo '<br><small class="off">', $h($flags[$p['reconcile_flag']] ?? $p['reconcile_flag']), '</small>', ($n = bcrf_staff_name($pdo, $p['reconciled_by'])) !== '' ? ' <small class="muted">· flagged by ' . $h($n) . '</small>' : '';
            if ($tab === 'matched') echo '<br><small class="even">Matched</small> <small class="muted">', $h(bcrf_local_time($p['reconciled_at'], 'M j, g:i A')), ($n = bcrf_staff_name($pdo, $p['reconciled_by'])) !== '' ? ' · ' . $h($n) : '', '</small>';
            $one = '<form method="POST" action="reconcile.php">' . $hidden . '<input type="hidden" name="id" value="' . (int)$p['id'] . '">';
            echo '</div><div class="p2pact">';
            if ($tab === 'open') {
                echo $one, $btn('match', 'Matched', [], 'btn sm primary'), '</form>',
                     '<details class="p2pflag"><summary class="btn sm">Problem</summary>', $one, '<input type="hidden" name="act" value="flag"><div class="p2pflags">';
                foreach ($flags as $k => $label) echo '<button class="btn sm" type="submit" name="flag" value="', $h($k), '">', $h($label), '</button>';
                echo '</div></form></details>';
            } elseif ($tab === 'flagged') {
                echo $one, $btn('match', 'Found it', [], 'btn sm primary'), '</form>', $one, $btn('reopen', 'Clear flag'), '</form>';
            } else {
                echo $one, $btn('reopen', 'Undo'), '</form>';
            }
            echo '</div></div>';
        }
        echo '</section>';
    }
}
echo '</main></body></html>';
