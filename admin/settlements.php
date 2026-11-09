<?php
// admin/settlements.php — consignor settlements (Farm OS Phase 2, Nov 9, 2026)
// List payable sold-consigned thrift items, create a payout batch, view history.
// Permission: edit on inventory. Plain forms, strict CSP.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/settlements.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn($c) => '$' . number_format(((int)$c) / 100, 2);

$head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data: https:; connect-src \'self\'; base-uri \'self\'; form-action \'self\'">'
      . '<meta name="robots" content="noindex"><link rel="stylesheet" href="assets/register.css?v=7"><link rel="stylesheet" href="assets/reports.css?v=4">';

if (!bcrf_settlements_ready($pdo)) {
    echo $head, '<title>Settlements</title></head><body class="page"><main class="narrow"><p class="flash err">Run migration 0022 in Settings › Database first.</p>',
         '<p><a href="migrations.php">Database migrations</a></p></main></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['set_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: settlements.php'); exit;
    }
    $act = (string)($_POST['act'] ?? '');
    try {
        if ($act === 'create') {
            $cid = (int)($_POST['consignor_customer_id'] ?? 0);
            $ids = array_map('intval', (array)($_POST['product_id'] ?? []));
            $note = (string)($_POST['note'] ?? '');
            $sid = bcrf_settlement_create($pdo, $cid, $ids, $note, $uid);
            $_SESSION['set_flash'] = ['ok', 'Settlement #' . $sid . ' recorded.'];
            header('Location: settlements.php?id=' . $sid); exit;
        }
        throw new InvalidArgumentException('Unknown action.');
    } catch (InvalidArgumentException $e) {
        $_SESSION['set_flash'] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[settlements] ' . $e->getMessage());
        $_SESSION['set_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: settlements.php'); exit;
}

$flash = $_SESSION['set_flash'] ?? null; unset($_SESSION['set_flash']);

echo $head, '<title>Settlements</title></head><body class="page"><main class="narrow wide">';
echo '<p><a href="admin.php">← Admin</a> · <a href="thrift.php">Thrift</a> · <a href="register.php">Register</a></p>';

// Detail
if (!empty($_GET['id'])) {
    $s = bcrf_settlement_get($pdo, (int)$_GET['id']);
    if (!$s) {
        echo '<p class="card muted">That settlement doesn\'t exist. <a href="settlements.php">All settlements</a></p></main></body></html>';
        exit;
    }
    echo '<p><a href="settlements.php">← Payable & history</a></p>';
    if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
    echo '<h1>Settlement #', (int)$s['id'], '</h1>';
    echo '<p class="muted">', $h(trim((string)$s['consignor_name']) ?: ('Customer #' . $s['consignor_customer_id'])),
         $s['consignor_email'] ? ' · ' . $h($s['consignor_email']) : '',
         ' · ', $h(bcrf_local_time($s['paid_at'], 'n/j/Y g:i A')), '</p>';
    echo '<div class="card">';
    echo '<div class="lrow"><span>Items</span><span class="r">', (int)$s['items_count'], '</span></div>';
    echo '<div class="lrow"><span>Sale total <small class="muted">(after discounts)</small></span><span class="r">', $m($s['sale_cents']), '</span></div>';
    echo '<div class="lrow"><span>Paid to consignor</span><span class="r"><b>', $m($s['consignor_cents']), '</b></span></div>';
    echo '<div class="lrow"><span>Farm share</span><span class="r">', $m($s['farm_cents']), '</span></div>';
    if ($s['note']) echo '<div class="lrow"><span>Note</span><span class="r">', $h($s['note']), '</span></div>';
    echo '</div>';
    echo '<h2>Items</h2><div class="card hist">';
    foreach ($s['lines'] as $ln) {
        echo '<div class="lrow"><div><b>', $h($ln['description']), '</b>',
             $ln['sku'] ? ' <small class="muted">' . $h($ln['sku']) . '</small>' : '',
             '<br><small class="muted">', $m($ln['sale_net_cents']), ' × ', $h(number_format(((int)$ln['share_bp']) / 100, 0)), '%</small></div>',
             '<div class="r"><b>', $m($ln['consignor_cents']), '</b></div></div>';
    }
    echo '</div></main></body></html>';
    exit;
}

// Main: payable groups + history
echo '<h1>Consignor settlements</h1>';
if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';

$groups = bcrf_settlement_payable_by_consignor($pdo);
if (!$groups) {
    echo '<p class="card muted">No sold consigned items waiting to be paid out. Take items in on <a href="thrift.php">Thrift</a> with source Consigned, sell them on the register, then settle here.</p>';
} else {
    echo '<h2>Ready to pay</h2>';
    foreach ($groups as $g) {
        $cid = (int)$g['consignor_customer_id'];
        echo '<div class="card" style="margin-bottom:1rem">';
        echo '<p><b>', $h($g['consignor_name']), '</b>',
             $g['consignor_email'] ? ' <small class="muted">' . $h($g['consignor_email']) . '</small>' : '',
             '<br><small class="muted">', count($g['items']), ' item', count($g['items']) === 1 ? '' : 's',
             ' · sale ', $m($g['sale_cents']), ' · consignor <b>', $m($g['consignor_cents']), '</b> · farm ', $m($g['farm_cents']), '</small></p>';
        echo '<form method="POST" action="settlements.php" class="stackf">';
        echo '<input type="hidden" name="csrf_token" value="', $h($csrf), '">';
        echo '<input type="hidden" name="act" value="create">';
        echo '<input type="hidden" name="consignor_customer_id" value="', $cid, '">';
        foreach ($g['items'] as $it) {
            echo '<label class="check"><input type="checkbox" name="product_id[]" value="', (int)$it['id'], '" checked> ',
                 $h($it['name']), ' <small class="muted">', $h($it['sku'] ?? ''),
                 $it['sale_number'] ? ' · ' . $h($it['sale_number']) : '',
                 $it['business_date'] ? ' · ' . $h($it['business_date']) : '',
                 ' · ', $m($it['sale_net_cents']), ' × ', $h(number_format(((int)$it['share_bp']) / 100, 0)), '% = <b>', $m($it['consignor_cents']), '</b></small></label>';
        }
        echo '<label>Note <small class="muted">(optional, e.g. check #)</small><input type="text" name="note" maxlength="255" autocomplete="off"></label>';
        echo '<div class="rbar"><button class="btn primary" type="submit">Record payout · ', $m($g['consignor_cents']), '</button></div>';
        echo '</form></div>';
    }
}

$hist = bcrf_settlement_list($pdo, [], 50);
echo '<h2>History</h2>';
if (!$hist) echo '<p class="muted">No settlements recorded yet.</p>';
else {
    echo '<div class="card hist">';
    foreach ($hist as $r) {
        echo '<a class="lrow cardlink" href="settlements.php?id=', (int)$r['id'], '"><div><b>#', (int)$r['id'], ' · ',
             $h(trim((string)$r['consignor_name']) ?: ('Customer #' . $r['consignor_customer_id'])), '</b>',
             '<br><small class="muted">', (int)$r['items_count'], ' item', (int)$r['items_count'] === 1 ? '' : 's',
             ' · ', $h(bcrf_local_time($r['paid_at'], 'n/j/Y')), '</small></div>',
             '<div class="r"><b>', $m($r['consignor_cents']), '</b></div></a>';
    }
    echo '</div>';
}
echo '</main></body></html>';
