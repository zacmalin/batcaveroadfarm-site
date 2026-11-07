<?php
// admin/register.php — Farm OS register (Phase 1, slice 1, Nov 6, 2026)
// Replaces admin/pos.php once tested. Server-assigned receipt numbers and idempotent saves (C-12),
// per-line tax by tax category, split tender, P2P payer + reference. Strict CSP (C-09): no inline
// script or style; behaviour in admin/assets/register.js, looks in admin/assets/register.css.
//   GET  register.php               the register
//   GET  register.php?api=catalog   products for the tiles
//   GET  register.php?api=today     today's count and total
//   POST register.php?api=complete  JSON sale → includes/sales.php bcrf_sale_complete()
//   GET register.php?view=tax|settings  redirect to Settings › Payments & Register (admin/payment_settings.php)
// Slice 2 (Nov 6, 2026), cash drawers (includes/drawers.php):
//   GET  register.php?api=drawer&register_id=N   registers + the open drawer's summary
//   POST register.php?api=drawer_open | drawer_move | drawer_close   (JSON, X-CSRF-Token)
//   GET  register.php?view=drawer&id=N            printable drawer report;  ?view=drawers  recent drawers
// Slice 3 (Nov 6, 2026), refunds and voids (includes/refunds.php):
//   GET  register.php?api=sales&register_id=N[&q=R-2026-000012]   today's sales, or one by number
//   GET  register.php?api=sale&id=N                               one sale with refund/void state
//   POST register.php?api=refund_quote | refund | void            (JSON, X-CSRF-Token)
// Slice 4a (Nov 6, 2026): admission sold here checks the party in (includes/admission.php); a full farm returns
// 409 code=capacity and an Owner or Manager can resend with capacity_override (a reason). Gate screen: admin/gate.php.
// Slice 9 (Nov 6, 2026): emailed receipts, farm PayPal/Venmo/Cash App handles with QR codes, preset discounts (includes/receipts.php):
//   POST register.php?api=email_receipt   {sale_id, email}  (JSON, X-CSRF-Token)
//   (handles, return policy and discount buttons are edited in Settings › Payments & Register)
// Slice 7 (Nov 6, 2026): item discounts (disc_pct per line), a customer on the sale (customer_id), held carts
// (includes/register_extras.php):
//   GET  register.php?api=customers&q=...          find customers / pass holders (name, email, phone, pass link)
//   POST register.php?api=customer_new | customer_pass | holds_list | hold | recall | hold_discard   (JSON, X-CSRF-Token)
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
if (($_SESSION['user_role'] ?? '') === 'Media') { http_response_code(403); exit('Media accounts cannot use the register.'); }
$api = (string)($_GET['api'] ?? '');
require_permission([['view', 'pos']], $api !== '');
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/drawers.php';
require_once __DIR__ . '/../includes/refunds.php';
require_once __DIR__ . '/../includes/admission.php';
require_once __DIR__ . '/../includes/register_extras.php';
require_once __DIR__ . '/../includes/receipts.php';
require_once __DIR__ . '/../includes/site_info.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf    = $_SESSION['csrf_token'];
$isAdmin = ($_SESSION['user_role'] ?? '') === 'administrator';
$ready   = bcrf_sales_ready($pdo);
$drawersReady = $ready && bcrf_drawers_ready($pdo);
$refundsReady = $drawersReady && bcrf_refunds_ready($pdo);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

// ── JSON API ──────────────────────────────────────────────────────────────────
if ($api !== '') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $out = function (int $code, array $body) { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; };
    if (!$ready) $out(503, ['ok' => false, 'message' => 'The register is waiting for migration 0009 (Settings › Database).']);

    if ($api === 'catalog') {
        $general = 0;
        foreach (bcrf_tax_categories($pdo) as $tc) if ($tc['code'] === 'general') $general = $tc['rate_bp'];
        $rows = $pdo->query("SELECT i.id, i.name, i.price, i.stock_quantity, i.sku, i.image_url, i.pricing_mode, i.unit, i.track_inventory,
                                    i.product_type, COALESCE(ic.name, i.category, 'Other') AS cat, COALESCE(ic.sort_order, 999) AS cat_sort,
                                    tc.rate_bp
                             FROM inventory i
                             LEFT JOIN inventory_categories ic ON ic.id = i.category_id
                             LEFT JOIN tax_categories tc ON tc.id = i.tax_category_id
                             WHERE i.is_active = 1
                             ORDER BY cat_sort, cat, i.name")->fetchAll(PDO::FETCH_ASSOC);
        $here = null;   // slice 8: stock at this register's location
        if (bcrf_stock_ready($pdo)) {
            $here = [];
            $ls = $pdo->prepare("SELECT product_id, quantity FROM inventory_levels WHERE location_id = ?");
            $ls->execute([bcrf_location_for_register($pdo, (int)($_GET['register_id'] ?? 0) ?: null)]);
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) $here[(int)$l['product_id']] = (float)$l['quantity'];
        }
        $items = array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'price_cents' => (int)round((float)$r['price'] * 100),
            'stock' => (int)$r['track_inventory'] ? ($here === null ? (float)$r['stock_quantity'] : ($here[(int)$r['id']] ?? 0.0)) : null, 'sku' => (string)$r['sku'],
            'img' => (string)$r['image_url'], 'mode' => $r['pricing_mode'], 'unit' => $r['unit'], 'type' => $r['product_type'],
            'cat' => $r['cat'], 'rate_bp' => $r['rate_bp'] === null ? $general : (int)$r['rate_bp'],   // no tax category = general (as the server does)
        ], $rows);
        $out(200, ['ok' => true, 'items' => $items, 'custom_rate_bp' => $general]);
    }

    if ($api === 'today') {
        [, $bdate] = bcrf_farm_now();
        $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_cents), 0) t FROM sales WHERE business_date = ? AND status <> 'voided'");
        $st->execute([$bdate]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $out(200, ['ok' => true, 'count' => (int)$r['n'], 'total_cents' => (int)$r['t']]);
    }

    if ($api === 'complete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'message' => 'POST only.']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $out(403, ['ok' => false, 'message' => 'Your session expired. Reload the register (the cart is kept).']);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $out(400, ['ok' => false, 'message' => 'The sale could not be read. Try again.']);
        try {
            $r = bcrf_sale_complete($pdo, $in);
            $out(200, ['ok' => true, 'replayed' => $r['replayed'], 'sale' => $r['sale']]);
        } catch (BcrfCapacityError $e) {
            $out(409, ['ok' => false, 'code' => 'capacity', 'message' => $e->getMessage(), 'can_override' => bcrf_can_override_capacity()]);
        } catch (BcrfSaleError $e) {
            $out(422, ['ok' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[register] complete: ' . $e->getMessage());
            $out(500, ['ok' => false, 'message' => 'The server could not save the sale. Nothing was charged twice; tap Retry.']);
        }
    }
    // ── Drawers ──
    if (in_array($api, ['drawer', 'drawer_open', 'drawer_move', 'drawer_close'], true)) {
        if (!$drawersReady) $out(503, ['ok' => false, 'message' => 'Cash drawers are waiting for migration 0010 (Settings › Database).']);
        if ($api === 'drawer') {
            $regs = bcrf_registers($pdo);
            $rid = (int)($_GET['register_id'] ?? 0);
            $cur = isset($regs[$rid]) ? bcrf_drawer_current($pdo, $rid) : null;
            $last = null;
            if (isset($regs[$rid])) {
                $st = $pdo->prepare("SELECT id FROM drawer_sessions WHERE register_id = ? AND status = 'closed' ORDER BY closed_at DESC LIMIT 1");
                $st->execute([$rid]);
                $last = (int)$st->fetchColumn() ?: null;
            }
            $out(200, ['ok' => true, 'registers' => (object)$regs, 'session' => $cur ? bcrf_drawer_summary($pdo, (int)$cur['id']) : null,
                       'last_closed_id' => $last, 'denominations' => (object)bcrf_denominations(), 'movement_types' => bcrf_movement_types(),
                       'threshold_cents' => bcrf_variance_threshold($pdo), 'can_approve' => bcrf_can_approve_variance()]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'message' => 'POST only.']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $out(403, ['ok' => false, 'message' => 'Your session expired. Reload the register.']);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $out(400, ['ok' => false, 'message' => 'The request could not be read. Try again.']);
        try {
            if ($api === 'drawer_open') {
                $id = bcrf_drawer_open($pdo, (int)($in['register_id'] ?? 0), (array)($in['counts'] ?? []));
                $out(200, ['ok' => true, 'session' => bcrf_drawer_summary($pdo, $id)]);
            }
            $sid = (int)($in['session_id'] ?? 0);
            if ($api === 'drawer_move') {
                bcrf_drawer_move($pdo, $sid, (string)($in['type'] ?? ''), (int)($in['amount_cents'] ?? 0), (string)($in['reason'] ?? ''));
                $out(200, ['ok' => true, 'session' => bcrf_drawer_summary($pdo, $sid)]);
            }
            $out(200, ['ok' => true, 'session' => bcrf_drawer_close($pdo, $sid, (array)($in['counts'] ?? []), (string)($in['note'] ?? ''))]);
        } catch (BcrfSaleError $e) {
            $out(422, ['ok' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[register] ' . $api . ': ' . $e->getMessage());
            $out(500, ['ok' => false, 'message' => 'The server could not save that. Try again.']);
        }
    }
    // ── Sales list, refunds, voids ──
    if (in_array($api, ['sales', 'sale', 'refund_quote', 'refund', 'void'], true)) {
        if (!$refundsReady) $out(503, ['ok' => false, 'message' => 'Refunds and voids are waiting for migration 0011 (Settings › Database).']);
        if ($api === 'sales') $out(200, ['ok' => true, 'sales' => bcrf_sales_list($pdo, (int)($_GET['register_id'] ?? 0), trim((string)($_GET['q'] ?? '')))]);
        if ($api === 'sale') {
            $d = bcrf_sale_detail($pdo, (int)($_GET['id'] ?? 0));
            $d ? $out(200, ['ok' => true, 'sale' => $d, 'refund_threshold_cents' => bcrf_refund_threshold($pdo), 'can_approve' => bcrf_is_approver()])
               : $out(404, ['ok' => false, 'message' => 'Sale not found.']);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'message' => 'POST only.']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $out(403, ['ok' => false, 'message' => 'Your session expired. Reload the register.']);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $out(400, ['ok' => false, 'message' => 'The request could not be read. Try again.']);
        try {
            if ($api === 'refund_quote') $out(200, ['ok' => true, 'quote' => bcrf_refund_quote($pdo, (int)($in['sale_id'] ?? 0), (array)($in['lines'] ?? []))]);
            if ($api === 'refund') {
                $r = bcrf_refund_complete($pdo, $in);
                $out(200, ['ok' => true] + $r);
            }
            $out(200, ['ok' => true, 'sale' => bcrf_sale_void($pdo, (int)($in['sale_id'] ?? 0), (string)($in['reason'] ?? ''))]);
        } catch (BcrfSaleError $e) {
            $out(422, ['ok' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[register] ' . $api . ': ' . $e->getMessage());
            $out(500, ['ok' => false, 'message' => 'The server could not save that. Nothing was changed; try again.']);
        }
    }
    // -- Emailed receipts (slice 9) --
    if ($api === 'email_receipt') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'message' => 'POST only.']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $out(403, ['ok' => false, 'message' => 'Your session expired. Reload the register.']);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $out(400, ['ok' => false, 'message' => 'The request could not be read. Try again.']);
        try {
            $to = bcrf_receipt_email($pdo, (int)($in['sale_id'] ?? 0), (string)($in['email'] ?? ''));
            $out(200, ['ok' => true, 'email' => $to]);
        } catch (InvalidArgumentException $e) {
            $out(422, ['ok' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[register] email_receipt: ' . $e->getMessage());
            $out(500, ['ok' => false, 'message' => 'The email could not be sent. Try again or print the receipt.']);
        }
    }
    // -- Customers and held carts (slice 7) --
    if (in_array($api, ['customers', 'customer_new', 'customer_pass', 'holds_list', 'hold', 'recall', 'hold_discard'], true)) {
        if ($api === 'customers') $out(200, ['ok' => true, 'results' => bcrf_pos_customer_search($pdo, (string)($_GET['q'] ?? ''))]);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'message' => 'POST only.']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $out(403, ['ok' => false, 'message' => 'Your session expired. Reload the register (the cart is kept).']);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $out(400, ['ok' => false, 'message' => 'The request could not be read. Try again.']);
        try {
            if ($api === 'customer_new')  $out(200, ['ok' => true, 'customer' => bcrf_pos_customer_create($pdo, (string)($in['first'] ?? ''), (string)($in['last'] ?? ''), (string)($in['email'] ?? ''), (string)($in['phone'] ?? ''))]);
            if ($api === 'customer_pass') $out(200, ['ok' => true, 'customer' => bcrf_pos_customer_from_pass($pdo, (int)($in['pass_id'] ?? 0))]);
            if ($api === 'holds_list')    $out(200, ['ok' => true, 'holds' => bcrf_holds_list($pdo)]);
            if ($api === 'hold') {
                $id = bcrf_hold_save($pdo, (int)($in['register_id'] ?? 0) ?: null, (string)($in['label'] ?? ''), (array)($in['state'] ?? []), (int)($in['total_cents'] ?? 0));
                $out(200, ['ok' => true, 'id' => $id, 'holds' => bcrf_holds_list($pdo)]);
            }
            if ($api === 'recall') $out(200, ['ok' => true, 'state' => bcrf_hold_recall($pdo, (int)($in['id'] ?? 0)), 'holds' => bcrf_holds_list($pdo)]);
            bcrf_hold_discard($pdo, (int)($in['id'] ?? 0));
            $out(200, ['ok' => true, 'holds' => bcrf_holds_list($pdo)]);
        } catch (BcrfRegisterError $e) {
            $out(422, ['ok' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[register] ' . $api . ': ' . $e->getMessage());
            $out(500, ['ok' => false, 'message' => 'The server could not do that. Try again.']);
        }
    }
    $out(404, ['ok' => false, 'message' => 'Unknown request.']);
}

// ── Tax rates (administrators) ───────────────────────────────────────────────
$view = (string)($_GET['view'] ?? '');
// Nov 6, 2026: register settings and tax rates moved to Settings › Payments & Register (admin/payment_settings.php).
if ($view === 'settings' || $view === 'tax') {
    header('Location: /admin/payment_settings.php?tab=' . ($view === 'tax' ? 'tax' : 'methods'), true, 302);
    exit;
}
// ── Drawer report and recent drawers ────────────────────────────────────────
if ($view === 'drawer' || $view === 'drawers') {
    $m = fn($c) => ($c < 0 ? '−$' : '$') . number_format(abs((int)$c) / 100, 2);
    $tenders = bcrf_tenders();
    $head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
          . '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; base-uri \'self\'; form-action \'self\'">'
          . '<meta name="robots" content="noindex"><link rel="stylesheet" href="assets/register.css?v=8">';
    if (!$drawersReady) { echo $head, '<title>Drawers</title></head><body class="page"><main class="narrow"><p class="flash err">Run migration 0010 in Settings › Database first.</p></main></body></html>'; exit; }
    if ($view === 'drawers') {
        $rows = $pdo->query("SELECT d.id FROM drawer_sessions d ORDER BY d.opened_at DESC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
        echo $head, '<title>Drawers — Register</title></head><body class="page"><main class="narrow"><p class="noprint"><a href="register.php">← Register</a></p><h1>Drawers</h1>';
        if (!$rows) echo '<p class="muted">No drawers yet.</p>';
        else {
            echo '<div class="card">';
            foreach ($rows as $id) {
                $d = bcrf_drawer_summary($pdo, (int)$id);
                $v = $d['variance_cents'];
                echo '<a class="drow" href="register.php?view=drawer&amp;id=', (int)$id, '"><span><b>', $h($d['register']), '</b> · ', $h($d['opened']),
                     '<br><small class="muted">', $d['status'] === 'open' ? 'Open' : 'Closed ' . $h($d['closed']), ' · ', (int)$d['sales']['n'], ' sales · ', $m($d['sales']['tot']), '</small></span><span class="',
                     $v === null ? 'muted' : ($v === 0 ? 'even' : 'off'), '">', $v === null ? 'open' : ($v === 0 ? 'even' : ($v < 0 ? 'short ' : 'over ') . $m(abs($v))), '</span></a>';
            }
            echo '</div>';
        }
        echo '</main></body></html>'; exit;
    }
    $d = bcrf_drawer_summary($pdo, (int)($_GET['id'] ?? 0));
    if (!$d) { http_response_code(404); echo $head, '<title>Not found</title></head><body class="page"><main class="narrow"><p>No such drawer. <a href="register.php?view=drawers">All drawers</a></p></main></body></html>'; exit; }
    $row = fn($label, $val, $cls = '') => '<tr' . ($cls ? ' class="' . $cls . '"' : '') . '><td>' . $label . '</td><td class="r">' . $val . '</td></tr>';
    echo $head, '<title>Drawer report — ', $h($d['register']), '</title><script src="assets/report.js?v=1" defer></script></head><body class="page report"><main class="narrow">';
    echo '<p class="noprint"><a href="register.php">← Register</a> · <a href="register.php?view=drawers">All drawers</a> · <button type="button" class="btn sm" id="btnPrint">Print</button></p>';
    echo '<h1>Drawer report</h1><p><b>', $h(bcrf_farm_name()), ' · ', $h($d['register']), '</b><br>Opened ', $h($d['opened']), ' by ', $h($d['opened_by']),
         $d['status'] === 'closed' ? '<br>Closed ' . $h($d['closed']) . ' by ' . $h($d['closed_by']) : '<br><b>Still open</b> (figures so far)', '</p>';
    echo '<h2>Cash</h2><table class="rt">', $row('Starting float', $m($d['float_cents'])), $row('Cash sales', $m($d['cash_sales_cents'])),
         $row('Cash refunds', $m(-$d['refunds_cents'])), $row('Paid in', $m($d['paid_in_cents'])), $row('Paid out', $m(-$d['paid_out_cents'])),
         $row('Cash drops', $m(-$d['drops_cents'])), $row('Expected in drawer', $m($d['expected_cents']), 'b');
    if ($d['status'] === 'closed') {
        $v = $d['variance_cents'];
        echo $row('Counted', $m($d['counted_cents']), 'b'),
             $row($v < 0 ? 'Short' : ($v > 0 ? 'Over' : 'Variance'), $m($v), abs($v) > $d['threshold_cents'] ? 'b off' : 'b');
    }
    echo '</table>';
    if ($d['status'] === 'closed' && ($d['close_note'] || $d['variance_approved_by'])) {
        echo '<p>', $d['close_note'] ? 'Note: ' . $h($d['close_note']) : '', $d['variance_approved_by'] ? '<br>Variance approved by ' . $h($d['variance_approved_by']) : '', '</p>';
    }
    if ($d['closing_count']) {
        echo '<h2>Closing count</h2><table class="rt">';
        foreach (bcrf_denominations() as $c => $label) if (!empty($d['closing_count'][$c])) echo $row($label . ' × ' . (int)$d['closing_count'][$c], $m($c * (int)$d['closing_count'][$c]));
        echo '</table>';
    }
    $rt = (int)($d['refunds_total_cents'] ?? 0);
    echo '<h2>Sales</h2><table class="rt">', $row('Sales', (int)$d['sales']['n']), $row('Before discounts', $m($d['sales']['sub'])),
         $row('Discounts', $m(-$d['sales']['disc'])), $row('Tax', $m($d['sales']['tax'])), $row('Total', $m($d['sales']['tot']), 'b'),
         $rt ? $row('Refunds paid from this drawer', $m(-$rt)) . $row('Net', $m($d['sales']['tot'] - $rt), 'b') : '', '</table>';
    echo '<h2>By payment type</h2><table class="rt">';
    if (!$d['by_tender']) echo '<tr><td class="muted">No payments</td><td></td></tr>';
    foreach ($d['by_tender'] as $t => $x) echo $row($h($tenders[$t] ?? $t) . ' (' . $x['count'] . ')', $m($x['cents']));
    echo '</table><h2>Refunds, voids and discounts</h2>';
    if (!$d['refunds'] && !$d['voids'] && !$d['sales']['disc']) echo '<p class="muted">None.</p>';
    else {
        echo '<table class="rt">';
        foreach ($d['refunds'] as $r) {
            $how = implode(', ', array_map(fn($t, $c) => ($tenders[$t] ?? $t) . ' ' . $m($c), array_keys($r['payments']), $r['payments']));
            echo $row('Refund ' . $h($r['refund_number']) . ' on ' . $h($r['sale_number']) . ' · ' . $h($r['time']) . ' · ' . $h($r['by']) . '<br><small>' . $h($r['reason']) . ' · ' . $h($how) . '</small>', $m(-$r['total_cents']));
        }
        foreach ($d['voids'] as $v) echo $row('Void ' . $h($v['sale_number']) . ' · ' . $h($v['time']) . ' · ' . $h($v['by']) . '<br><small>' . $h($v['reason']) . '</small>', $m($v['total_cents']) . ' void');
        if ($d['sales']['disc']) echo $row('Discounts given', $m(-$d['sales']['disc']));
        echo '</table>';
    }
    if ($d['movements']) {
        echo '<h2>Cash in and out</h2><table class="rt">';
        foreach ($d['movements'] as $mv) echo $row($h(bcrf_movement_types()[$mv['type']]) . ' · ' . $h($mv['time']) . ' · ' . $h($mv['by']) . '<br><small>' . $h($mv['reason']) . '</small>',
                                               $m($mv['type'] === 'paid_in' ? $mv['amount_cents'] : -$mv['amount_cents']));
        echo '</table>';
    }
    echo '<h2>PayPal, Venmo, Cash App not yet matched</h2>';
    if (!$d['p2p_unreconciled']) echo '<p class="muted">None.</p>';
    else {
        echo '<p class="muted">Check each against the app on the farm phone.</p><table class="rt">';
        foreach ($d['p2p_unreconciled'] as $p) echo $row($h($tenders[$p['tender']]) . ' · ' . $h($p['payer']) . ($p['reference'] ? ' · #' . $h($p['reference']) : '') . '<br><small>' . $h($p['sale_number'] . ' · ' . $p['time']) . '</small>', $m($p['amount_cents']));
        echo '</table>';
    }
    echo '</main></body></html>';
    exit;
}

// ── The register ─────────────────────────────────────────────────────────────
$me = 'Staff';
try {
    $st = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $st->execute([(int)$_SESSION['user_id']]);
    if ($u = $st->fetch(PDO::FETCH_ASSOC)) $me = trim($u['first_name'] . ' ' . mb_substr((string)$u['last_name'], 0, 1) . '.');
} catch (Throwable $e) {}

$boot = [
    'ready'   => $ready,
    'csrf'    => $csrf,
    'staff'   => $me,
    'farm'    => ['name' => bcrf_farm_name(), 'address' => site_address(), 'phone' => site_phone_display(),
                  'domain' => bcrf_farm_domain(), 'policy' => ($posSet = bcrf_pos_settings($pdo))['policy']],
    'pos'     => ['handles' => $posSet['handles'], 'presets' => $posSet['presets'], 'canOverride' => bcrf_can_override_discount()],   // slice 9
    'tenders' => bcrf_tenders(),                                   // labels for every type (past sales, refunds)
    'payTenders' => array_keys(bcrf_tenders_active($pdo)),         // buttons for new sales, in order (Settings › Payments & Register)
    'isAdmin' => $isAdmin,
    'drawers' => $drawersReady,
    'refunds' => $refundsReady,
    'gate'    => $refundsReady && bcrf_admission_ready($pdo),
    'holds'     => bcrf_holds_ready($pdo),               // slice 7: migration 0014
    'lineDisc'  => bcrf_sales_line_disc_ready($pdo),
    'stock'     => bcrf_stock_ready($pdo),                // slice 8: migration 0015
    'customers' => bcrf_customers_ready($pdo),
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self'; base-uri 'self'; form-action 'self'">
<meta name="robots" content="noindex">
<title>Register — <?= $h(bcrf_farm_name()) ?></title>
<link rel="stylesheet" href="assets/register.css?v=8">
</head>
<body>
<script type="application/json" id="regBoot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<div id="app" class="app">
  <header class="top">
    <a class="back drawerbtn" href="/admin/admin.php"><span aria-hidden="true">←</span>&nbsp;Console</a>
    <div class="today" id="today" aria-live="polite">Today: …</div>
    <?php if ($refundsReady && bcrf_admission_ready($pdo)): ?><a class="drawerbtn" href="gate.php">Gate</a><?php endif; ?>
    <button type="button" class="drawerbtn" id="btnSales" hidden>Sales</button>
    <button type="button" class="drawerbtn" id="btnDrawer" hidden>Drawer</button>
        <div class="who"><?= $h($me) ?></div>
  </header>

  <?php if (!$ready): ?>
  <div class="banner err">The new register needs migration 0009. An administrator can run it in Settings › Database. Until then keep using the <a href="pos.php">old register</a>.</div>
  <?php endif; ?>

  <section class="shop">
    <div class="banner warn" id="drawerBanner" hidden><span id="drawerBannerText">The drawer is closed.</span> <button type="button" class="btn sm primary" id="btnOpenDrawer">Open drawer</button></div>
    <div class="tools">
      <input type="search" id="search" placeholder="Search or type SKU" autocomplete="off" aria-label="Search products">
      <button type="button" class="btn" id="btnScan">Scan</button>
      <button type="button" class="btn" id="btnCustom">Custom</button>
    </div>
    <nav class="cats" id="cats" aria-label="Categories"></nav>
    <div class="grid" id="grid" aria-live="polite"></div>
  </section>

  <aside class="cart" id="cart" aria-label="Cart">
    <div class="cart-head"><h2>Cart</h2><button type="button" class="link" id="btnCartClose">Close</button></div>
    <button type="button" class="custchip" id="custChip" hidden></button>
    <div class="lines" id="lines"></div>
    <div class="adjust">
      <button type="button" class="btn sm" id="btnCustomer" hidden>Customer</button>
      <button type="button" class="btn sm" id="btnHold" hidden>Hold</button>
      <button type="button" class="btn sm" id="btnDiscount">Discount</button>
      <button type="button" class="btn sm" id="btnExempt" aria-pressed="false">Tax exempt</button>
      <button type="button" class="btn sm danger" id="btnClear">Clear</button>
    </div>
    <dl class="totals">
      <div><dt>Subtotal</dt><dd id="tSub">$0.00</dd></div>
      <div id="rowDisc" hidden><dt id="tDiscLbl">Discount</dt><dd id="tDisc">−$0.00</dd></div>
      <div><dt id="tTaxLbl">Tax</dt><dd id="tTax">$0.00</dd></div>
      <div class="grand"><dt>Total</dt><dd id="tTot">$0.00</dd></div>
    </dl>
    <button type="button" class="btn primary big" id="btnPay" disabled>Take payment</button>
  </aside>

  <div class="bar" id="bar">
    <button type="button" class="barcart" id="btnCartOpen"><span id="barCount">0 items</span> · <b id="barTotal">$0.00</b></button>
    <button type="button" class="btn primary" id="btnPay2" disabled>Pay</button>
  </div>
</div>

<!-- Sheets (dialogs) -->
<dialog id="dlgItem" class="sheet">
  <form method="dialog" id="formItem">
    <h3 id="itemTitle">Item</h3>
    <label id="itemQtyWrap">Quantity <span id="itemUnit"></span><input type="number" inputmode="decimal" step="any" min="0" id="itemQty"></label>
    <label id="itemPriceWrap">Price ($)<input type="number" inputmode="decimal" step="0.01" min="0" id="itemPrice"></label>
    <label id="itemNameWrap">Name<input type="text" id="itemName" maxlength="120"></label>
    <p class="err" id="itemErr" hidden></p>
    <div class="row"><button type="button" class="btn" value="cancel" id="itemCancel">Cancel</button><button class="btn primary" id="itemOk" value="ok">Add</button></div>
  </form>
</dialog>

<dialog id="dlgDiscount" class="sheet">
  <form method="dialog">
    <h3>Discount on the whole sale</h3>
    <div class="chips" id="discChips"></div>
    <label id="discOther">Other %<input type="number" inputmode="decimal" min="0" max="100" step="0.5" id="discPct"></label>
    <p class="muted" id="discOtherNote" hidden>Another % needs an Owner or Manager.</p>
    <div class="row"><button type="button" class="btn" id="discNone">No discount</button><button type="button" class="btn primary" id="discOk">Apply</button></div>
  </form>
</dialog>

<dialog id="dlgPay" class="sheet tall">
  <div class="payhead">
    <div><small>Total</small><b id="pTotal">$0.00</b></div>
    <div><small>Paid</small><b id="pPaid">$0.00</b></div>
    <div><small>Left</small><b id="pLeft">$0.00</b></div>
  </div>
  <ul class="paylist" id="payList"></ul>
  <div id="payAdd">
    <div class="tenders" id="tenderBtns"></div>
    <div id="payCash" hidden>
      <label>Cash handed over ($)<input type="number" inputmode="decimal" step="0.01" min="0" id="cashGiven"></label>
      <div class="chips" id="cashQuick"></div>
      <p class="change" id="cashChange"></p>
    </div>
    <div id="payOther" hidden>
      <label>Amount ($)<input type="number" inputmode="decimal" step="0.01" min="0" id="payAmt"></label>
      <div class="p2ppay" id="p2pPay" hidden><div class="qr" id="p2pQr"></div><div><b id="p2pHandle"></b><br><small class="muted" id="p2pAmt"></small><br><a id="p2pLink" href="#" target="_blank" rel="noopener">Open link</a></div></div>
      <label id="payerWrap">Payer name or handle<input type="text" id="payer" maxlength="120" autocomplete="off"></label>
      <label id="refWrap"><span id="refLbl">Last characters of the transaction ID</span><input type="text" id="payRef" maxlength="80" autocomplete="off"></label>
      <p class="muted" id="p2pHint">Check the payment arrived on the farm phone before adding it.</p>
    </div>
    <p class="err" id="payErr" hidden></p>
    <button type="button" class="btn primary big" id="btnAddPay" hidden>Add payment</button>
  </div>
  <div id="payDone" hidden>
    <label class="check"><input type="checkbox" id="chkPrint" checked> Print receipt</label>
    <button type="button" class="btn primary big" id="btnComplete">Complete sale</button>
  </div>
  <div class="status" id="saveStatus" hidden role="status"></div>
  <div class="capbox" id="capBox" hidden>
    <label>Reason to let them in anyway<input type="text" id="capReason" maxlength="255" autocomplete="off" placeholder="e.g. short visit, family already inside"></label>
    <button type="button" class="btn danger big" id="btnCapOverride">Override and complete sale</button>
  </div>
  <button type="button" class="btn big" id="btnRetry" hidden>Retry saving</button>
  <button type="button" class="link" id="payClose">Back to cart</button>
</dialog>

<dialog id="dlgDone" class="sheet">
  <h3>Sale saved</h3>
  <p class="saleno" id="doneNo"></p>
  <p class="bigchange" id="doneChange" hidden></p>
  <div class="row"><button type="button" class="btn" id="btnReprint">Print receipt</button><button type="button" class="btn" id="btnEmailDone">Email receipt</button><button type="button" class="btn primary" id="btnNew">New sale</button></div>
</dialog>

<dialog id="dlgEmail" class="sheet">
  <h3>Email the receipt</h3>
  <p class="muted" id="emSale"></p>
  <label>Email address<input type="email" id="emTo" maxlength="254" autocomplete="off" inputmode="email"></label>
  <p class="err" id="emErr" hidden></p>
  <div class="row"><button type="button" class="btn" id="emCancel">Cancel</button><button type="button" class="btn primary" id="emSend">Send</button></div>
</dialog>

<dialog id="dlgScan" class="sheet">
  <h3>Scan</h3>
  <video id="scanVideo" playsinline muted hidden></video>
  <p class="muted" id="scanMsg">A barcode scanner works anywhere on this screen. Or type the SKU:</p>
  <form id="formSku"><input type="text" id="skuInput" autocomplete="off" placeholder="SKU"><button class="btn primary">Add</button></form>
  <button type="button" class="link" id="scanClose">Done</button>
</dialog>

<!-- Slice 7: item sheet, customer, held carts -->
<dialog id="dlgLine" class="sheet">
  <h3 id="lineTitle">Item</h3>
  <p class="muted" id="lineSub"></p>
  <p class="lbl">Discount on this item</p>
  <div class="chips" id="lineChips"></div>
  <label>Other %<input type="number" inputmode="decimal" min="0" max="100" step="0.5" id="linePct"></label>
  <label>Note (prints on the receipt)<input type="text" id="lineNote" maxlength="120" autocomplete="off" placeholder="e.g. dented lid"></label>
  <p class="err" id="lineErr" hidden></p>
  <div class="row"><button type="button" class="btn" id="lineCancel">Cancel</button><button type="button" class="btn primary" id="lineOk">Save</button></div>
</dialog>

<dialog id="dlgCustomer" class="sheet">
  <h3>Customer</h3>
  <p class="muted">Name, email, phone, or scan/paste their pass link.</p>
  <input type="search" id="custQ" placeholder="Search customers" autocomplete="off" aria-label="Search customers">
  <div id="custResults" class="custlist" aria-live="polite"></div>
  <p class="err" id="custErr" hidden></p>
  <details id="custNewWrap">
    <summary class="btn sm">New customer</summary>
    <div class="custnew">
      <label>First name<input type="text" id="custFirst" maxlength="50" autocomplete="off"></label>
      <label>Last name<input type="text" id="custLast" maxlength="50" autocomplete="off"></label>
      <label>Email<input type="email" id="custEmail" maxlength="100" autocomplete="off"></label>
      <label>Phone (optional)<input type="tel" id="custPhone" maxlength="20" autocomplete="off"></label>
      <button type="button" class="btn primary" id="custAdd">Add and attach</button>
    </div>
  </details>
  <div class="row"><button type="button" class="btn" id="custClose">Close</button></div>
</dialog>

<dialog id="dlgHolds" class="sheet">
  <h3 id="holdTitle">Hold a cart</h3>
  <div id="holdNewWrap">
    <p class="muted">Park this cart while the customer steps away. Take it back from here on any device.</p>
    <label>Who is it for?<input type="text" id="holdLabel" maxlength="80" autocomplete="off" placeholder="e.g. Lady in the blue hat"></label>
    <button type="button" class="btn primary" id="holdSave">Hold this cart</button>
  </div>
  <p class="err" id="holdErr" hidden></p>
  <h3 class="mt" id="holdWaiting">Waiting</h3>
  <div id="holdList" class="custlist"></div>
  <div class="row"><button type="button" class="btn" id="holdClose">Close</button></div>
</dialog>

<dialog id="dlgConfirm" class="sheet">
  <h3 id="confTitle">Are you sure?</h3>
  <p id="confText"></p>
  <label id="confReasonWrap" hidden>Reason<input type="text" id="confReason" maxlength="255" autocomplete="off"></label>
  <p class="err" id="confErr" hidden></p>
  <div class="row"><button type="button" class="btn" id="confNo">Cancel</button><button type="button" class="btn danger" id="confYes">Yes</button></div>
</dialog>

<div class="toast" id="toast" role="status" hidden></div>

<dialog id="dlgRegister" class="sheet">
  <h3>Which register is this?</h3>
  <p class="muted">This phone or tablet remembers the choice.</p>
  <div class="chips" id="regChips"></div>
</dialog>

<dialog id="dlgDrawer" class="sheet tall">
  <h3 id="drTitle">Drawer</h3>
  <p class="muted" id="drOpened"></p>
  <dl class="totals" id="drRows"></dl>
  <div class="tenders" id="drMoves"></div>
  <button type="button" class="btn primary big" id="btnCloseDrawer">Count and close drawer</button>
  <p class="row wrap"><a class="btn" id="drReport" href="#">Report so far</a><a class="btn" href="register.php?view=drawers">All drawers</a></p>
  <button type="button" class="link" id="drClose">Back</button>
</dialog>

<dialog id="dlgMove" class="sheet">
  <form id="formMove">
    <h3 id="mvTitle">Paid out</h3>
    <label>Amount ($)<input type="number" inputmode="decimal" step="0.01" min="0" id="mvAmt"></label>
    <label>What for<input type="text" id="mvReason" maxlength="255" autocomplete="off" placeholder="e.g. ice for the cooler"></label>
    <p class="err" id="mvErr" hidden></p>
    <div class="row"><button type="button" class="btn" id="mvCancel">Cancel</button><button class="btn primary" id="mvOk">Save</button></div>
  </form>
</dialog>

<dialog id="dlgCount" class="sheet tall">
  <form id="formCount">
    <h3 id="cnTitle">Count the drawer</h3>
    <p class="muted" id="cnHint"></p>
    <div class="count" id="cnRows"></div>
    <dl class="totals">
      <div class="grand"><dt>Counted</dt><dd id="cnTotal">$0.00</dd></div>
      <div id="cnExpRow" hidden><dt>Expected</dt><dd id="cnExp">$0.00</dd></div>
      <div id="cnVarRow" hidden><dt id="cnVarLbl">Variance</dt><dd id="cnVar">$0.00</dd></div>
    </dl>
    <label id="cnNoteWrap" hidden>Note<input type="text" id="cnNote" maxlength="500" autocomplete="off" placeholder="Why is it over or short?"></label>
    <p class="err" id="cnErr" hidden></p>
    <div class="row"><button type="button" class="btn" id="cnCancel">Cancel</button><button class="btn primary" id="cnOk">Open drawer</button></div>
  </form>
</dialog>

<dialog id="dlgSales" class="sheet tall">
  <h3>Sales</h3>
  <form id="formSalesFind" class="find"><input type="search" id="salesQ" placeholder="Receipt number, e.g. R-2026-000012 or 12" autocomplete="off"><button class="btn">Find</button></form>
  <p class="muted" id="salesHint">Today on this register. Tap a sale to reprint, refund or void it.</p>
  <ul class="salelist" id="salesList"></ul>
  <button type="button" class="link" id="salesClose">Close</button>
</dialog>

<dialog id="dlgSale" class="sheet tall">
  <h3 id="sdTitle">Sale</h3>
  <p class="muted" id="sdMeta"></p>
  <p class="status bad" id="sdVoided" hidden></p>
  <table class="rt" id="sdLines"></table>
  <dl class="totals" id="sdTotals"></dl>
  <div id="sdRefunds"></div>
  <div class="row wrap">
    <button type="button" class="btn" id="sdPrint">Print receipt</button>
    <button type="button" class="btn" id="sdEmail">Email receipt</button>
    <button type="button" class="btn" id="sdRefund">Refund</button>
    <button type="button" class="btn danger" id="sdVoid">Void</button>
  </div>
  <p class="muted" id="sdVoidWhy" hidden></p>
  <button type="button" class="link" id="sdBack">Back to sales</button>
</dialog>

<dialog id="dlgRefund" class="sheet tall">
  <h3 id="rfTitle">Refund</h3>
  <p class="muted">Choose how many of each item to refund, and whether it goes back on the shelf.</p>
  <div id="rfLines"></div>
  <label>Reason<input type="text" id="rfReason" maxlength="255" autocomplete="off" placeholder="e.g. jar cracked, changed mind"></label>
  <div id="rfMoney" hidden>
    <dl class="totals" id="rfTotals"></dl>
    <p class="muted">How the money goes back (original payment first):</p>
    <div id="rfPays"></div>
    <p class="muted" id="rfApproval" hidden></p>
  </div>
  <p class="err" id="rfErr" hidden></p>
  <div class="status" id="rfStatus" hidden role="status"></div>
  <div class="row"><button type="button" class="btn" id="rfCancel">Cancel</button><button type="button" class="btn primary" id="rfOk">Work out refund</button></div>
</dialog>

<!-- Printed receipt (only visible when printing) -->
<section id="receipt" class="receipt" aria-hidden="true"></section>

<script src="/js/qrcode.min.js"></script>
<script src="assets/register.js?v=9"></script>
</body>
</html>
