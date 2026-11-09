<?php
// admin/stock.php — stock by location, adjustments, moves, counts and history (Farm OS Phase 1, slice 8, Nov 6, 2026; 06 Purchasing & Inventory)
//   GET  stock.php                          what's on hand where (search, category, location, low only)
//   GET  stock.php?tab=item&id=N            one item: each location, adjust, move between locations, its history
//   GET  stock.php?tab=counts               count sessions; start one
//   GET  stock.php?tab=count&id=N[&q=]      count screen (type what's on the shelf) or a finished count's results
//   GET  stock.php?tab=history[&csv=1]      the stock ledger, with filters and CSV
//   GET  stock.php?tab=locations            locations and which one each register sells from (administrators)
//   POST stock.php  act=adjust|move|count_start|count_save|count_close|location_save|register_location, csrf_token → redirect back
// Permission: "Inventory Update" (Counts) or edit on the inventory table; locations: administrators. Plain forms, no script (strict CSP, C-09).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'inventory_update'], ['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/drawers.php';
require_once __DIR__ . '/../includes/reports.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$isAdmin = ($_SESSION['user_role'] ?? '') === 'administrator';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn($c) => '$' . number_format((int)$c / 100, 2);
$q3 = fn($q) => bcrf_qtxt($q);
$tabs = ['stock', 'item', 'counts', 'count', 'history', 'locations'];
$tab = in_array($_GET['tab'] ?? '', $tabs, true) ? $_GET['tab'] : 'stock';
if ($tab === 'locations' && !$isAdmin) $tab = 'stock';
$self = function (array $p = []): string { $p = array_filter($p, fn($v) => $v !== null && $v !== '' && $v !== 0); return 'stock.php' . ($p ? '?' . http_build_query($p) : ''); };
$isDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; base-uri \'self\'; form-action \'self\'">'
      . '<meta name="robots" content="noindex"><link rel="stylesheet" href="assets/register.css?v=6"><link rel="stylesheet" href="assets/reports.css?v=3">';
if (!bcrf_sales_ready($pdo) || !bcrf_stock_ready($pdo)) {
    echo $head, '<title>Stock</title></head><body class="page"><main class="narrow"><p><a href="admin.php">← Admin</a></p>',
         '<p class="flash err">Run migration 0015 (stock by location) in Settings › Database first. Until then, counts stay on the Counts screen.</p></main></body></html>';
    exit;
}

// -- Actions (POST → redirect back) --
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = (string)($_POST['back'] ?? '');
    if (!preg_match('/^stock\.php(\?[A-Za-z0-9_=&%.\-+]*)?$/', $back)) $back = 'stock.php';
    $say = function (string $kind, string $msg) { $_SESSION['stock_flash'] = [$kind, $msg]; };
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $say('err', 'Security token expired; try again.');
    } else {
        $act = (string)($_POST['act'] ?? '');
        try {
            if ($act === 'adjust') {
                $r = bcrf_stock_adjust($pdo, (int)$_POST['product_id'], (int)$_POST['location_id'], $_POST['qty'] ?? '', (string)($_POST['reason'] ?? ''),
                                       ($_POST['direction'] ?? '') === 'remove' ? -1 : 1, (string)($_POST['note'] ?? ''), $uid);
                $say('ok', $r['name'] . ' at ' . $r['location'] . ': ' . $q3($r['before']) . ' → ' . $q3($r['after']) . '.');
            } elseif ($act === 'move') {
                $r = bcrf_stock_transfer($pdo, (int)$_POST['product_id'], (int)$_POST['from'], (int)$_POST['to'], $_POST['qty'] ?? '', (string)($_POST['note'] ?? ''), $uid);
                $say('ok', 'Moved ' . $q3($r['qty']) . ' ' . $r['name'] . ' from ' . $r['from'] . ' to ' . $r['to'] . '. Now ' . $q3($r['from_after']) . ' and ' . $q3($r['to_after']) . '.');
            } elseif ($act === 'count_start') {
                try {
                    $id = bcrf_count_start($pdo, (int)$_POST['location_id'], (string)($_POST['scope'] ?? 'all'), (int)($_POST['category_id'] ?? 0) ?: null,
                                           !empty($_POST['full']), (string)($_POST['note'] ?? ''), $uid);
                } catch (BcrfStockError $e) {
                    if ($e->getCode()) { $say('err', $e->getMessage()); header('Location: ' . $self(['tab' => 'count', 'id' => $e->getCode()])); exit; }
                    throw $e;
                }
                $back = $self(['tab' => 'count', 'id' => $id]);
                $say('ok', 'Count started. Type what you see on the shelf; tap Save as you go.');
            } elseif ($act === 'count_save') {
                $r = bcrf_count_save($pdo, (int)$_POST['count_id'], (array)($_POST['c'] ?? []), $uid);
                $say($r['flagged'] ? 'err' : 'ok', $r['saved'] . ' counted, ' . $r['changed'] . ' changed.'
                     . ($r['flagged'] ? ' Big difference on ' . implode(', ', array_slice($r['flagged'], 0, 5)) . (count($r['flagged']) > 5 ? '…' : '') . ': check again before finishing.' : ''));
            } elseif ($act === 'count_close') {
                $c = bcrf_count_close($pdo, (int)$_POST['count_id'], ($_POST['how'] ?? '') === 'full', $uid);
                $back = $self(['tab' => 'count', 'id' => (int)$c['id']]);
                $say('ok', 'Count finished: ' . $c['lines_n'] . ' counted, ' . $c['diff_n'] . ' changed' . ($c['zeroed'] ? ', ' . $c['zeroed'] . ' not counted set to 0' : '') . '.');
            } elseif ($act === 'location_save' && $isAdmin) {
                $lid = (int)($_POST['id'] ?? 0) ?: null;
                bcrf_location_save($pdo, $lid, (string)($_POST['name'] ?? ''), !empty($_POST['sellable']), !empty($_POST['is_default']), !empty($_POST['archived']), $uid);
                $say('ok', $lid ? 'Location saved.' : 'Location added.');
            } elseif ($act === 'register_location' && $isAdmin) {
                bcrf_register_set_location($pdo, (int)$_POST['register_id'], (int)$_POST['location_id'], $uid);
                $say('ok', 'Register updated. It now sells from that location.');
            } else {
                $say('err', 'Unknown action.');
            }
        } catch (BcrfStockError $e) {
            $say('err', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[BCRF stock] ' . $e->getMessage());
            $say('err', 'Could not save. Try again.');
        }
    }
    header('Location: ' . $back); exit;
}

// Anything changed outside Stock (old register, Full Editor, connector)? Put the difference on the default location now.
$synced = bcrf_stock_sync_all($pdo, $uid);

$locs = bcrf_locations($pdo);
$locById = []; foreach ($locs as $l) $locById[(int)$l['id']] = $l;
$cats = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_KEY_PAIR);
$flash = $_SESSION['stock_flash'] ?? null; unset($_SESSION['stock_flash']);
$hid = fn(string $back) => '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="back" value="' . $h($back) . '">';
$sel = function (string $name, array $opts, $cur, string $label, string $blank = '') use ($h) {
    $o = '<select name="' . $h($name) . '" aria-label="' . $h($label) . '">' . ($blank !== '' ? '<option value="">' . $h($blank) . '</option>' : '');
    foreach ($opts as $k => $v) $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . $h($v) . '</option>';
    return $o . '</select>';
};
$locNames = array_map(fn($l) => $l['name'], $locById);

// -- History CSV --
$hf = ['from' => $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime('-13 days')),
       'to' => $isDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d'),
       'location_id' => (int)($_GET['location'] ?? 0) ?: null, 'reason' => preg_match('/^[a-z_]{2,20}$/', (string)($_GET['reason'] ?? '')) ? $_GET['reason'] : null,
       'product_id' => (int)($_GET['product'] ?? 0) ?: null];
if ($tab === 'history' && !empty($_GET['csv'])) {
    $rows = bcrf_stock_history($pdo, $hf, 5000);
    bcrf_rpt_send_csv($pdo, 'stock-history-' . $hf['from'] . '-to-' . $hf['to'] . '.csv',
        ['date', 'time', 'item', 'location', 'change', 'before', 'after', 'reason', 'source', 'note', 'by'],
        array_map(fn($r) => [bcrf_local_time($r['created_at'], 'Y-m-d'), bcrf_local_time($r['created_at'], 'H:i'), $r['product_name'], $r['location_name'] ?? '',
            $q3($r['quantity_change']), $q3($r['quantity_before']), $q3($r['quantity_after']), bcrf_stock_reason_label($r['reason']),
            $r['source_label'] ?? ($r['source_type'] ? $r['source_type'] . ($r['source_id'] ? ' ' . $r['source_id'] : '') : ''), (string)$r['note'], bcrf_staff_name($pdo, $r['created_by'])], $rows),
        'stock history ' . $hf['from'] . ' to ' . $hf['to']);
    exit;
}

$histTable = function (array $rows, bool $showItem = true) use ($h, $q3, $pdo) {
    if (!$rows) return '<p class="card muted">Nothing in these dates.</p>';
    $o = '<div class="card hist">';
    foreach ($rows as $r) {
        $chg = (float)$r['quantity_change'];
        $why = bcrf_stock_reason_label($r['reason']);
        $bits = array_filter([$r['source_label'] ?? '', ($r['note'] && !str_starts_with((string)$r['note'], $why)) ? $r['note'] : '',
                              bcrf_local_time($r['created_at'], 'M j, g:i A'), bcrf_staff_name($pdo, $r['created_by'])], fn($x) => $x !== '');
        $o .= '<div class="lrow"><div><b>' . $h($why) . '</b>'
            . ($showItem ? ' · <a href="' . $h('stock.php?tab=item&id=' . (int)$r['product_id']) . '">' . $h($r['product_name']) . '</a>' : '')
            . ' <small class="muted">at ' . $h($r['location_name'] ?? 'all locations') . '</small><br><small class="muted">' . $h(implode(' · ', $bits)) . '</small></div>'
            . '<div class="r"><span class="' . ($chg < 0 ? 'off' : 'even') . '">' . ($chg > 0 ? '+' : '') . $h($q3($chg)) . '</span><br><small class="muted">now ' . $h($q3($r['quantity_after'])) . '</small></div></div>';
    }
    return $o . '</div>';
};

$title = ['stock' => 'Stock', 'item' => 'Stock', 'counts' => 'Counts', 'count' => 'Count', 'history' => 'Stock history', 'locations' => 'Locations'][$tab];
echo $head, '<title>', $h($title), '</title></head><body class="page"><main class="narrow wide">';
echo '<p><a href="admin.php">← Admin</a> · <a href="register.php">Register</a> · <a href="receive.php">Receive</a></p>';
echo '<h1>Stock</h1>';
if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
if ($synced) echo '<p class="flash">', $synced, ' item', $synced === 1 ? '' : 's', ' had been changed outside Stock (old register, Full Editor or connector); the difference is now on ', $h($locById[bcrf_location_default($pdo)]['name'] ?? 'the default location'), ' and in the history.</p>';
$cur = in_array($tab, ['item'], true) ? 'stock' : ($tab === 'count' ? 'counts' : $tab);
$openCounts = (int)$pdo->query("SELECT COUNT(*) FROM inventory_counts WHERE status = 'open'")->fetchColumn();
echo '<nav class="rtabs" aria-label="Stock">';
foreach (['stock' => 'On hand', 'counts' => 'Counts' . ($openCounts ? ' (' . $openCounts . ' open)' : ''), 'history' => 'History'] + ($isAdmin ? ['locations' => 'Locations'] : []) as $k => $label) {
    echo '<a href="', $h($self(['tab' => $k === 'stock' ? null : $k])), '"', $cur === $k ? ' aria-current="page"' : '', '>', $h($label), '</a>';
}
echo '</nav>';

// -- On hand --
if ($tab === 'stock') {
    $f = ['q' => trim((string)($_GET['q'] ?? '')), 'category_id' => (int)($_GET['cat'] ?? 0) ?: null, 'location_id' => (int)($_GET['location'] ?? 0) ?: null, 'low' => !empty($_GET['low'])];
    $rows = bcrf_stock_levels($pdo, $f);
    echo '<form class="rform" method="GET" action="stock.php"><input type="search" name="q" value="', $h($f['q']), '" placeholder="Name or SKU (scan)" aria-label="Search">',
         $sel('cat', $cats, $f['category_id'], 'Category', 'All categories'), $sel('location', $locNames, $f['location_id'], 'Location', 'All locations'),
         '<label class="check"><input type="checkbox" name="low" value="1"', $f['low'] ? ' checked' : '', '> Low only</label>',
         '<button class="btn sm" type="submit">Show</button></form>';
    $show = $f['location_id'] ? [$f['location_id'] => $locById[$f['location_id']]] : array_filter($locById, function ($l) use ($rows) {
        foreach ($rows as $r) if (isset($r['levels'][(int)$l['id']])) return true;
        return (int)$l['is_default'] === 1;
    });
    if (!$rows) echo '<p class="card muted">No stocked items match.', $f['low'] ? ' Nothing is low.' : '', '</p>';
    else {
        echo '<div class="card scroll"><table class="rt grid"><tr class="th"><td>Item</td>';
        foreach ($show as $l) echo '<td class="r">', $h($l['name']), '</td>';
        echo '<td class="r">Total</td><td class="r">Min</td></tr>';
        $lastCat = null;
        foreach ($rows as $r) {
            if ($r['cat'] !== $lastCat) { echo '<tr class="th"><td colspan="', count($show) + 3, '">', $h($r['cat']), '</td></tr>'; $lastCat = $r['cat']; }
            $low = $r['min_stock_level'] !== null && (float)$r['min_stock_level'] > 0 && (float)$r['stock_quantity'] <= (float)$r['min_stock_level'];
            echo '<tr><td><a href="', $h($self(['tab' => 'item', 'id' => (int)$r['id']])), '">', $h($r['name']), '</a>', $r['unit'] !== 'each' ? ' <small class="muted">(' . $h($r['unit']) . ')</small>' : '', '</td>';
            foreach ($show as $lid => $l) echo '<td class="r">', isset($r['levels'][$lid]) ? $h($q3($r['levels'][$lid])) : '<small class="muted">—</small>', '</td>';
            echo '<td class="r', $low ? ' off' : '', '"><b>', $h($q3($r['stock_quantity'])), '</b>', $low ? ' <small>low</small>' : '', '</td><td class="r muted">',
                 $r['min_stock_level'] !== null ? $h($q3($r['min_stock_level'])) : '—', '</td></tr>';
        }
        echo '</table></div><p class="muted">Tap an item to add, remove or move stock. Items that aren\'t stocked (admission, services) aren\'t listed.</p>';
    }
}

// -- One item --
if ($tab === 'item') {
    $p = bcrf_stock_product($pdo, (int)($_GET['id'] ?? 0));
    if (!$p) { echo '<p class="card muted">That item doesn\'t exist.</p>'; }
    elseif (!(int)$p['track_inventory']) { echo '<p class="card muted">', $h($p['name']), ' isn\'t a stocked item (stock isn\'t tracked for it). Turn on tracking in the catalog to count it.</p>'; }
    else {
        $back = $self(['tab' => 'item', 'id' => (int)$p['id']]);
        echo '<h2>', $h($p['name']), '</h2><p class="muted">', $h($p['cat']), $p['sku'] ? ' · ' . $h($p['sku']) : '', ' · ', $m((int)round((float)$p['price'] * 100)),
             $p['unit'] !== 'each' ? ' / ' . $h($p['unit']) : '', $p['min_stock_level'] !== null ? ' · minimum ' . $h($q3($p['min_stock_level'])) : '', '</p>';
        echo '<div class="card">';
        foreach ($locById as $lid => $l) {
            if (!isset($p['levels'][$lid]) && !(int)$l['is_default']) continue;
            echo '<div class="lrow"><span>', $h($l['name']), (int)$l['sellable'] ? '' : ' <small class="muted">not sold here</small>', '</span><span class="r">', $h($q3($p['levels'][$lid] ?? 0)), '</span></div>';
        }
        echo '<div class="lrow"><b>Total</b><span class="r">', $h($q3($p['stock_quantity'])), '</span></div></div>';
        echo '<p><a class="btn sm primary" href="receive.php?new=1&amp;product=', (int)$p['id'], '">Receive this item</a> <span class="muted">(with unit cost → updates average cost)</span></p>';

        $reasons = bcrf_stock_manual_reasons();
        echo '<section class="card"><h3>Add or remove</h3><form class="stackf" method="POST" action="stock.php">', $hid($back),
             '<input type="hidden" name="act" value="adjust"><input type="hidden" name="product_id" value="', (int)$p['id'], '">',
             '<label>Where', $sel('location_id', $locNames, bcrf_location_default($pdo), 'Location'), '</label>',
             '<label>Why<select name="reason" aria-label="Reason">';
        foreach ($reasons as $k => $r) echo '<option value="', $h($k), '">', $h($r[0]), $r[1] > 0 ? ' (+)' : ($r[1] < 0 ? ' (−)' : ' (+ or −)'), '</option>';
        echo '</select></label>',
             '<fieldset class="seg"><legend>For a correction</legend><label class="check"><input type="radio" name="direction" value="add" checked> Add</label><label class="check"><input type="radio" name="direction" value="remove"> Remove</label></fieldset>',
             '<label>How many<input type="number" name="qty" min="0" step="any" inputmode="decimal" required></label>',
             '<label>Note <small class="muted">(needed for corrections and lost or stolen)</small><input type="text" name="note" maxlength="200" autocomplete="off"></label>',
             '<button class="btn primary" type="submit">Save</button></form></section>';
        if (count($locById) > 1) {
            $fromDef = array_key_first(array_filter($p['levels'], fn($q) => $q > 0)) ?? bcrf_location_default($pdo);
            echo '<section class="card"><h3>Move between locations</h3><form class="stackf" method="POST" action="stock.php">', $hid($back),
                 '<input type="hidden" name="act" value="move"><input type="hidden" name="product_id" value="', (int)$p['id'], '">',
                 '<label>From', $sel('from', $locNames, $fromDef, 'From'), '</label>',
                 '<label>To', $sel('to', $locNames, array_key_first(array_diff_key($locNames, [$fromDef => 1])), 'To'), '</label>',
                 '<label>How many<input type="number" name="qty" min="0" step="any" inputmode="decimal" required></label>',
                 '<label>Note (optional)<input type="text" name="note" maxlength="200" autocomplete="off"></label>',
                 '<button class="btn primary" type="submit">Move</button></form></section>';
        }
        echo '<h3>History</h3>', $histTable(bcrf_stock_history($pdo, ['product_id' => (int)$p['id']], 40), false),
             '<p><a href="', $h($self(['tab' => 'history', 'product' => (int)$p['id'], 'from' => date('Y-m-d', strtotime('-90 days'))])), '">All history for this item</a></p>';
    }
}

// -- Counts --
if ($tab === 'counts') {
    echo '<section class="card"><h3>Start a count</h3><form class="stackf" method="POST" action="stock.php">', $hid('stock.php?tab=counts'),
         '<input type="hidden" name="act" value="count_start">',
         '<label>Where', $sel('location_id', $locNames, bcrf_location_default($pdo), 'Location'), '</label>',
         '<label>What', $sel('scope', bcrf_count_scopes(), 'all', 'What to count'), '</label>',
         '<label>Category <small class="muted">(for "One category")</small>', $sel('category_id', $cats, '', 'Category', '—'), '</label>',
         '<label class="check"><input type="checkbox" name="full" value="1"> Full count: when I finish, anything I didn\'t count is set to 0</label>',
         '<label>Note (optional)<input type="text" name="note" maxlength="200" autocomplete="off" placeholder="e.g. Saturday close"></label>',
         '<button class="btn primary" type="submit">Start counting</button></form></section>';
    $list = bcrf_counts_list($pdo);
    if (!$list) echo '<p class="card muted">No counts yet.</p>';
    foreach ($list as $c) {
        echo '<a class="card lrow cardlink" href="', $h($self(['tab' => 'count', 'id' => (int)$c['id']])), '"><span><b>', $h($c['location_name']), '</b> · ',
             $h($c['scope'] === 'category' ? ($c['category_name'] ?? 'category') : bcrf_count_scopes()[$c['scope']]), '<br><small class="muted">',
             $h(bcrf_local_time($c['started_at'], 'M j, g:i A')), ($n = bcrf_staff_name($pdo, $c['started_by'])) !== '' ? ' · ' . $h($n) : '',
             $c['note'] ? ' · ' . $h($c['note']) : '', '</small></span><span class="r">',
             $c['status'] === 'open' ? '<small class="even">Open</small>' : '<small class="muted">Done</small>', '<br><small class="muted">', (int)$c['lines_n'], ' counted',
             (int)$c['flagged_n'] ? ' · <span class="off">' . (int)$c['flagged_n'] . ' flagged</span>' : '', '</small></span></a>';
    }
}

if ($tab === 'count') {
    $c = bcrf_count_get($pdo, (int)($_GET['id'] ?? 0));
    if (!$c) { echo '<p class="card muted">That count doesn\'t exist.</p>'; }
    else {
        $back = $self(['tab' => 'count', 'id' => (int)$c['id'], 'q' => $_GET['q'] ?? null]);
        echo '<h2>Count · ', $h($c['location_name']), '</h2><p class="muted">', $h($c['scope'] === 'category' ? ($c['category_name'] ?? '') : bcrf_count_scopes()[$c['scope']]),
             ' · started ', $h(bcrf_local_time($c['started_at'], 'M j, g:i A')), ($n = bcrf_staff_name($pdo, $c['started_by'])) !== '' ? ' by ' . $h($n) : '',
             $c['note'] ? ' · ' . $h($c['note']) : '', '</p>';
        if ($c['status'] === 'open') {
            $q = trim((string)($_GET['q'] ?? ''));
            echo '<form class="rform" method="GET" action="stock.php"><input type="hidden" name="tab" value="count"><input type="hidden" name="id" value="', (int)$c['id'], '">',
                 '<input type="search" name="q" value="', $h($q), '" placeholder="Find or scan an item" aria-label="Find an item"><button class="btn sm" type="submit">Find</button>',
                 $q !== '' ? '<a class="btn sm" href="' . $h($self(['tab' => 'count', 'id' => (int)$c['id']])) . '">Show all</a>' : '', '</form>';
            $items = bcrf_count_items($pdo, $c, $q);
            echo '<p class="muted">Type what you see on the shelf and tap <b>Save counts</b> (leave a box empty to skip it). Stock changes as soon as you save; sales keep working while you count.',
                 $q === '' ? ' Not listed? Search for it to count it here for the first time.' : '', '</p>';
            if (!$items) echo '<p class="card muted">', $q !== '' ? 'No stocked item matches.' : 'Nothing is stocked here yet. Search for an item to count it here.', '</p>';
            else {
                echo '<form method="POST" action="stock.php">', $hid($back), '<input type="hidden" name="act" value="count_save"><input type="hidden" name="count_id" value="', (int)$c['id'], '">',
                     '<div class="card cnt">';
                $lastCat = null;
                foreach ($items as $i) {
                    if ($i['cat'] !== $lastCat) { echo '<div class="cntcat">', $h($i['cat']), '</div>'; $lastCat = $i['cat']; }
                    $done = $i['counted_qty'] !== null;
                    echo '<label class="cntrow', $done ? ' done' : '', (int)$i['flagged'] ? ' flag' : '', '"><span><b>', $h($i['name']), '</b>',
                         $i['sku'] ? ' <small class="muted">' . $h($i['sku']) . '</small>' : '', '<br><small class="muted">',
                         (int)$i['stocked_here'] ? 'On hand ' . $h($q3($i['on_hand'])) . ($i['unit'] !== 'each' ? ' ' . $h($i['unit']) : '') : 'Not stocked here yet',
                         '</small>', $done ? '<br><small class="' . ((int)$i['flagged'] ? 'off' : 'even') . '">Counted ' . $h($q3($i['counted_qty'])) . ((float)$i['difference'] != 0 ? ' (' . ((float)$i['difference'] > 0 ? '+' : '') . $h($q3($i['difference'])) . ')' : ', no change') . ((int)$i['flagged'] ? ' · check again' : '') . '</small>' : '',
                         '</span><input type="number" name="c[', (int)$i['id'], ']" min="0" step="any" inputmode="decimal" aria-label="Count for ', $h($i['name']), '" placeholder="', $done ? $h($q3($i['counted_qty'])) : '', '"></label>';
                }
                echo '</div><div class="rbar sticky"><button class="btn primary" type="submit">Save counts</button></div></form>';
            }
            $todo = bcrf_count_uncounted($pdo, $c);
            echo '<section class="card"><h3>Finish</h3><p class="muted">', (int)$c['lines_n'], ' counted so far', (int)$c['flagged_n'] ? ', <span class="off">' . (int)$c['flagged_n'] . ' with a big difference</span>' : '', '.</p>',
                 '<form class="rbar" method="POST" action="stock.php">', $hid($back), '<input type="hidden" name="act" value="count_close"><input type="hidden" name="count_id" value="', (int)$c['id'], '">',
                 '<button class="btn" type="submit" name="how" value="partial">Finish (leave the rest as they are)</button>';
            if ($todo) echo '<button class="btn danger" type="submit" name="how" value="full">Finish and set ', count($todo), ' not counted to 0</button>';
            echo '</form>';
            if ($todo && (int)$c['full_count']) echo '<p class="muted">Not counted yet: ', $h(implode(', ', array_map(fn($i) => $i['name'] . ' (' . $q3($i['on_hand']) . ')', array_slice($todo, 0, 12)))), count($todo) > 12 ? '…' : '', '</p>';
            echo '</section>';
        } else {
            echo '<p class="flash">Finished ', $h(bcrf_local_time($c['closed_at'], 'M j, g:i A')), ($n = bcrf_staff_name($pdo, $c['closed_by'])) !== '' ? ' by ' . $h($n) : '',
                 ' · ', (int)$c['lines_n'], ' counted, ', (int)$c['diff_n'], ' changed', (int)$c['full_count'] ? ' · full count' : '', '.</p>';
            $lines = bcrf_count_lines($pdo, (int)$c['id']);
            if ($lines) {
                $net = 0;
                echo '<div class="card scroll"><table class="rt grid"><tr class="th"><td>Item</td><td class="r">Expected</td><td class="r">Counted</td><td class="r">Difference</td><td class="r">Value</td></tr>';
                foreach ($lines as $l) {
                    $v = (int)round((float)$l['difference'] * (float)$l['price'] * 100); $net += $v;
                    echo '<tr><td>', $h($l['name']), (int)$l['flagged'] ? ' <small class="off">flagged</small>' : '', (int)$l['new_here'] ? ' <small class="muted">new here</small>' : '', '</td><td class="r">', $h($q3($l['expected_qty'])),
                         '</td><td class="r">', $h($q3($l['counted_qty'])), '</td><td class="r ', (float)$l['difference'] < 0 ? 'off' : ((float)$l['difference'] > 0 ? 'even' : ''), '">',
                         (float)$l['difference'] > 0 ? '+' : '', $h($q3($l['difference'])), '</td><td class="r">', $v ? $h(($v > 0 ? '+' : '−') . $m(abs($v))) : '', '</td></tr>';
                }
                echo '<tr><td colspan="4"><b>Net, at selling price</b></td><td class="r"><b>', $h(($net >= 0 ? '+' : '−') . $m(abs($net))), '</b></td></tr></table></div>';
            }
        }
    }
}

// -- History --
if ($tab === 'history') {
    $reasons = [];
    foreach (['sale', 'void', 'refund_restock', 'count', 'receive', 'internal_use', 'spoilage', 'damage', 'shrink', 'vendor_return', 'correction', 'transfer_out', 'transfer_in', 'sync', 'opening'] as $r) $reasons[$r] = bcrf_stock_reason_label($r);
    $pp = ['tab' => 'history', 'from' => $hf['from'], 'to' => $hf['to'], 'location' => $hf['location_id'], 'reason' => $hf['reason'], 'product' => $hf['product_id']];
    echo '<form class="rform" method="GET" action="stock.php"><input type="hidden" name="tab" value="history">',
         $hf['product_id'] ? '<input type="hidden" name="product" value="' . (int)$hf['product_id'] . '">' : '',
         '<input type="date" name="from" value="', $h($hf['from']), '" aria-label="From"><span>to</span><input type="date" name="to" value="', $h($hf['to']), '" aria-label="To">',
         $sel('location', $locNames, $hf['location_id'], 'Location', 'All locations'), $sel('reason', $reasons, $hf['reason'], 'Reason', 'All reasons'),
         '<button class="btn sm" type="submit">Show</button><a class="btn sm" href="', $h($self($pp + ['csv' => 1])), '">CSV</a></form>';
    if ($hf['product_id'] && ($pn = bcrf_stock_product($pdo, $hf['product_id']))) echo '<p class="muted">Only ', $h($pn['name']), ' · <a href="', $h($self(array_merge($pp, ['product' => null]))), '">all items</a></p>';
    $rows = bcrf_stock_history($pdo, $hf, 500);
    echo $histTable($rows), count($rows) >= 500 ? '<p class="muted">Showing the latest 500. Use the CSV for everything.</p>' : '';
}

// -- Locations (administrators) --
if ($tab === 'locations') {
    echo '<p class="muted">Where stock is kept. Registers sell from a sellable location; the default is where stock goes when nothing else is said (and what the old register uses).</p>';
    foreach (bcrf_locations($pdo, true) as $l) {
        $n = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(quantity <> 0), 0) FROM inventory_levels WHERE location_id = ?"); $n->execute([(int)$l['id']]); [$items, $nonzero] = $n->fetch(PDO::FETCH_NUM);
        echo '<section class="card"><form class="stackf" method="POST" action="stock.php">', $hid('stock.php?tab=locations'),
             '<input type="hidden" name="act" value="location_save"><input type="hidden" name="id" value="', (int)$l['id'], '">',
             '<label>Name<input type="text" name="name" value="', $h($l['name']), '" maxlength="80" required></label>',
             '<label class="check"><input type="checkbox" name="sellable" value="1"', (int)$l['sellable'] ? ' checked' : '', '> Sellable (registers can sell from here)</label>',
             '<label class="check"><input type="checkbox" name="is_default" value="1"', (int)$l['is_default'] ? ' checked' : '', '> Default location</label>',
             '<label class="check"><input type="checkbox" name="archived" value="1"', $l['archived_at'] ? ' checked' : '', '> Archived (hidden; must be empty)</label>',
             '<p class="muted">', (int)$nonzero, ' item', (int)$nonzero === 1 ? '' : 's', ' in stock here.</p>',
             '<button class="btn sm" type="submit">Save</button></form></section>';
    }
    echo '<section class="card"><h3>Add a location</h3><form class="stackf" method="POST" action="stock.php">', $hid('stock.php?tab=locations'),
         '<input type="hidden" name="act" value="location_save"><label>Name<input type="text" name="name" maxlength="80" required placeholder="e.g. Barn loft"></label>',
         '<label class="check"><input type="checkbox" name="sellable" value="1"> Sellable</label><button class="btn sm" type="submit">Add</button></form></section>';
    $sellable = array_map(fn($l) => $l['name'], array_filter($locById, fn($l) => (int)$l['sellable'] === 1));
    echo '<h3>Registers</h3>';
    foreach ($pdo->query("SELECT id, name, location_id FROM registers WHERE archived_at IS NULL ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo '<form class="card rform" method="POST" action="stock.php">', $hid('stock.php?tab=locations'), '<input type="hidden" name="act" value="register_location">',
             '<input type="hidden" name="register_id" value="', (int)$r['id'], '"><b>', $h($r['name']), '</b> sells from ', $sel('location_id', $sellable, (int)$r['location_id'] ?: bcrf_location_default($pdo), 'Location for ' . $r['name']),
             '<button class="btn sm" type="submit">Save</button></form>';
    }
}
echo '</main></body></html>';
