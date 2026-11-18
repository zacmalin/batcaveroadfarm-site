<?php
// admin/garden/seeds.php — Garden › Seeds (Nov 18, 2026; includes/garden.php, includes/garden_seeds.php, migration 0072).
//   GET  seeds.php                  seed lots with what's left and the germination rate; add a lot (from a Farm OS vendor)
//   GET  seeds.php?lot=N            one lot: stock changes (received, used, correction) and undo with a reason
//   GET  seeds.php?tab=germination  germination tests (sprout a sample, count) and the rate per lot
//   POST act=lot_add | stock_start | stock_move | stock_undo | germ_add   (all 'garden': staff log seed use)
// The seed ledger never goes below zero; an undo is a new linked line, the original stays. Who did it = the Farm OS user.
require_once __DIR__ . '/../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';
require_permission([['view', 'garden']]);
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../../includes/garden.php';
require_once __DIR__ . '/../../includes/admin_shell.php';
if (is_file(__DIR__ . '/../../includes/vendors.php')) require_once __DIR__ . '/../../includes/vendors.php';
date_default_timezone_set(bcrf_tz()->getName());

use Garden\Domain\GrowingRecords;
use Garden\Domain\SeedStock;
use Garden\Domain\SeedGermination;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    bcrf_shell_open('Seeds — Garden');
    echo '<h1 class="', $ui['h1'], '">Seeds</h1>', bcrf_shell_notice('warning', 'Run migration 0072 in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
$rec = new GrowingRecords();
$stock = new SeedStock();
$germ = new SeedGermination();
$tab = (string)($_GET['tab'] ?? '');
$vendors = function_exists('bcrf_vendor_options') ? (function () use ($pdo) { try { return bcrf_vendor_options($pdo); } catch (Throwable $e) { return []; } })() : [];

garden_post($pdo, 'seeds.php', [
    'lot_add' => [false, function () use ($pdo, $org, $rec, $vendors) {
        $vid = (int)($_POST['vendor_id'] ?? 0);
        if ($vid && !isset($vendors[$vid])) throw new InvalidArgumentException('Pick a vendor from the list.');
        $source = $vid ? $vendors[$vid] : garden_in('source');
        $v = garden_in('variety');
        if ($v === '') throw new InvalidArgumentException('Choose a variety.');
        $id = $rec->createSeedLot($pdo, $org, $v, garden_in('lot_code'), $source, garden_in('acquired_on') ?: null, garden_in('quantity') ?: null, garden_in('unit'));
        if ($vid) { $st = $pdo->prepare('UPDATE garden_seed_lots SET vendor_id = ? WHERE id = ? AND organization_id = ?'); $st->execute([$vid, $id, $org]); }
        return ['Seed lot added.', 'lot=' . $id];
    }],
    'stock_start' => [false, function () use ($pdo, $org, $stock) {
        $lot = garden_id('seed_lot_id', 'Choose a seed lot.');
        $stock->startTracking($pdo, $org, $lot, garden_in('quantity'), garden_in('unit'), garden_in('occurred_on', bcrf_today()), (int)garden_uid());
        return ['Now counting this lot.', 'lot=' . $lot];
    }],
    'stock_move' => [false, function () use ($pdo, $org, $stock) {
        $lot = garden_id('seed_lot_id', 'Choose a seed lot.');
        $kind = garden_in('kind');
        $amt = trim(garden_in('amount'));
        if ($kind === 'used' && $amt !== '' && $amt[0] !== '-') $amt = '-' . $amt;
        if ($kind === 'adjustment' && garden_in('direction') === 'down' && $amt !== '' && $amt[0] !== '-') $amt = '-' . $amt;
        $stock->record($pdo, $org, $lot, $kind, $amt, garden_in('occurred_on', bcrf_today()), garden_in('notes'), garden_uid());
        return ['Saved.', 'lot=' . $lot];
    }],
    'stock_undo' => [false, function () use ($pdo, $org, $stock) {
        $lot = garden_id('seed_lot_id', 'Choose a seed lot.');
        $stock->reverse($pdo, $org, $lot, garden_id('movement_id', 'Choose a line to undo.'), (int)garden_uid(), bcrf_today(), garden_in('reason'));
        return ['Undone. Both lines stay in the history.', 'lot=' . $lot];
    }],
    'germ_add' => [false, function () use ($pdo, $org, $germ) {
        $germ->record($pdo, $org, garden_id('seed_lot_id', 'Choose a seed lot.'), garden_in('started_on'), garden_in('checked_on'), garden_in('seeds_tested'), garden_in('seeds_germinated'), garden_in('method'), garden_in('notes'));
        return ['Germination test saved.', 'tab=germination'];
    }],
]);

$lots = $rec->seedLots($pdo, $org);
$bal = []; foreach ($stock->balances($pdo, $org) as $b) $bal[(int)$b['id']] = $b;
$gs = []; foreach ($germ->summary($pdo, $org) as $g) $gs[(int)$g['seed_lot_id']] = $g;
$lotName = fn(array $l) => $l['crop_name'] . ' — ' . $l['variety_name'] . ($l['lot_code'] ? ' (' . $l['lot_code'] . ')' : '');
$lotOpts = []; foreach ($lots as $l) $lotOpts[(int)$l['id']] = $lotName($l);
$left = function (int $id) use ($bal): string {
    $b = $bal[$id] ?? null;
    if (!$b || !(int)$b['movement_count']) return 'Not counted';
    return garden_num($b['balance'], 3) . ' ' . $b['unit'] . ' left';
};
[$err, $errAct] = garden_open('Seeds');
$today = bcrf_today();

// ── One lot ──
$lotId = (int)($_GET['lot'] ?? 0);
if ($lotId && isset($lotOpts[$lotId])) {
    $l = null; foreach ($lots as $x) if ((int)$x['id'] === $lotId) $l = $x;
    $b = $bal[$lotId] ?? null; $counted = $b && (int)$b['movement_count'] > 0;
    $moves = $stock->movements($pdo, $org, $lotId);
    $undone = []; foreach ($moves as $m) if ($m['reverses_movement_id']) $undone[(int)$m['reverses_movement_id']] = true;
    $who = [];
    $ids = array_values(array_unique(array_filter(array_map(fn($m) => (int)$m['actor_user_id'], $moves))));
    if ($ids) { try { foreach ($pdo->query('SELECT id, CONCAT(first_name, \' \', last_name) AS n FROM users WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC) as $u) $who[(int)$u['id']] = $u['n']; } catch (Throwable $e) { /* names are optional */ } }
    $kinds = ['opening' => 'Starting amount', 'received' => 'Received', 'used' => 'Used', 'adjustment' => 'Correction', 'reversal' => 'Undo'];
    echo '<p class="mb-2"><a class="', $ui['link'], ' text-sm" href="seeds.php">← All seed lots</a></p>';
    echo '<h1 class="', $ui['h1'], '">', $h($lotName($l)), '</h1>';
    if ($err !== '' && !in_array($errAct, ['stock_start', 'stock_move', 'stock_undo'], true)) echo bcrf_shell_notice('err', $err);
    echo '<div class="', $ui['card'], '"><dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">',
         '<div><dt class="text-stone-500">On hand</dt><dd class="font-semibold">', $h($left($lotId)), '</dd></div>',
         '<div><dt class="text-stone-500">From</dt><dd>', $l['vendor_id'] ? '<a class="' . $ui['link'] . '" href="/admin/vendors.php?id=' . (int)$l['vendor_id'] . '">' . $h($l['source']) . '</a>' : $h($l['source'] ?: '—'), '</dd></div>',
         '<div><dt class="text-stone-500">Bought</dt><dd>', $h(garden_date($l['acquired_on']) ?: '—'), '</dd></div>',
         '<div><dt class="text-stone-500">Sprouts</dt><dd>', isset($gs[$lotId]) ? $h(garden_num($gs[$lotId]['germination_percent'])) . '% (' . (int)$gs[$lotId]['tests'] . ' test' . ((int)$gs[$lotId]['tests'] > 1 ? 's' : '') . ')' : 'Not tested', '</dd></div></dl></div>';
    if (!$counted) { $t = garden_typed('stock_start'); ?>
  <form id="form-stock_start" method="POST" action="seeds.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'stock_start') ?><?= garden_hidden('stock_start', ['seed_lot_id' => $lotId, 'back_query' => 'lot=' . $lotId]) ?>
    <h2 class="<?= $ui['h2'] ?>">Start counting this lot</h2>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div><label class="<?= $ui['label'] ?>" for="q">Amount on hand</label><input id="q" name="quantity" inputmode="decimal" required value="<?= $h($t['quantity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="u">Unit</label><input id="u" name="unit" maxlength="32" required placeholder="packets, g, oz, seeds" value="<?= $h($t['unit'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="d">As of</label><input id="d" name="occurred_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['occurred_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <button class="<?= $ui['btnMain'] ?>">Start counting</button>
  </form>
<?php } else { $t = garden_typed('stock_move'); ?>
  <form id="form-stock_move" method="POST" action="seeds.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'stock_move') ?><?= garden_hidden('stock_move', ['seed_lot_id' => $lotId, 'back_query' => 'lot=' . $lotId]) ?>
    <h2 class="<?= $ui['h2'] ?>">Seed in or out (<?= $h($b['unit']) ?>)</h2>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div><label class="<?= $ui['label'] ?>" for="k">What happened</label><select id="k" name="kind" class="<?= $ui['input'] ?>"><?= garden_options(['used' => 'Used (sowed)', 'received' => 'Received more', 'adjustment' => 'Correction'], $t['kind'] ?? 'used') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="a">Amount</label><input id="a" name="amount" inputmode="decimal" required value="<?= $h($t['amount'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="dr">For a correction</label><select id="dr" name="direction" class="<?= $ui['input'] ?>"><?= garden_options(['down' => 'Fewer than recorded', 'up' => 'More than recorded'], $t['direction'] ?? 'down') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="od">Date</label><input id="od" name="occurred_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['occurred_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <div class="mb-3"><label class="<?= $ui['label'] ?>" for="n">Note</label><input id="n" name="notes" maxlength="500" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    <button class="<?= $ui['btnMain'] ?>">Save</button>
  </form>
<?php } ?>
  <div class="<?= $ui['card'] ?>">
    <h2 class="<?= $ui['h2'] ?>">History</h2>
    <?php if (!$moves): ?><p class="<?= $ui['muted'] ?>">Nothing yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($moves as $m): $mid = (int)$m['id']; ?>
      <li class="py-2 text-sm"><div class="flex flex-wrap justify-between gap-2"><span><strong><?= $h($kinds[$m['kind']] ?? $m['kind']) ?></strong> <?= $h(((float)$m['delta'] > 0 ? '+' : '') . garden_num($m['delta'], 3)) ?> · <?= $h(garden_date($m['occurred_on'])) ?><?= $m['actor_user_id'] && isset($who[(int)$m['actor_user_id']]) ? ' · ' . $h($who[(int)$m['actor_user_id']]) : '' ?><?= isset($undone[$mid]) ? ' · <em>undone</em>' : '' ?></span></div>
        <?php if ($m['notes']): ?><span class="block text-stone-600"><?= $h($m['notes']) ?></span><?php endif; ?>
        <?php if (!in_array($m['kind'], ['opening', 'reversal'], true) && !isset($undone[$mid])): ?>
        <details class="mt-1"><summary class="cursor-pointer text-xs text-amber-800 underline">Undo this line</summary>
          <form method="POST" action="seeds.php" data-submit-once class="mt-2 flex flex-wrap gap-2 items-end"><?= garden_hidden('stock_undo', ['seed_lot_id' => $lotId, 'movement_id' => $mid, 'back_query' => 'lot=' . $lotId]) ?>
            <div class="flex-1 min-w-40"><label class="<?= $ui['label'] ?>" for="r<?= $mid ?>">Why</label><input id="r<?= $mid ?>" name="reason" maxlength="500" required class="<?= $ui['input'] ?>"></div>
            <button class="<?= $ui['btnSec'] ?>">Undo</button></form>
        </details>
        <?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Germination ──
if ($tab === 'germination') {
    echo '<h1 class="', $ui['h1'], '">Germination tests</h1>';
    if ($err !== '' && $errAct !== 'germ_add') echo bcrf_shell_notice('err', $err);
    $tests = $germ->tests($pdo, $org);
    $t = garden_typed('germ_add');
    if (!$lots) { echo '<div class="', $ui['card'], '"><p class="', $ui['muted'], '">Add a seed lot first.</p></div>'; bcrf_shell_close(); exit; } ?>
  <form id="form-germ_add" method="POST" action="seeds.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'germ_add') ?><?= garden_hidden('germ_add', ['back_query' => 'tab=germination']) ?>
    <h2 class="<?= $ui['h2'] ?>">Record a test</h2>
    <p class="<?= $ui['muted'] ?> mb-3">Sprout a sample (on a damp paper towel, say) and count what came up.</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="gl">Seed lot</label><select id="gl" name="seed_lot_id" required class="<?= $ui['input'] ?>"><?= garden_options($lotOpts, $t['seed_lot_id'] ?? '', 'Choose…') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="gs">Started</label><input id="gs" name="started_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['started_on'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gc">Counted</label><input id="gc" name="checked_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['checked_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gt">Seeds tested</label><input id="gt" name="seeds_tested" type="number" min="1" max="65535" required value="<?= $h($t['seeds_tested'] ?? '10') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gg">Sprouted</label><input id="gg" name="seeds_germinated" type="number" min="0" max="65535" required value="<?= $h($t['seeds_germinated'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="gm">How</label><input id="gm" name="method" maxlength="80" placeholder="Paper towel" value="<?= $h($t['method'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <div class="mb-3"><label class="<?= $ui['label'] ?>" for="gn">Notes</label><input id="gn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    <button class="<?= $ui['btnMain'] ?>">Save test</button>
  </form>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Tests</h2>
    <?php if (!$tests): ?><p class="<?= $ui['muted'] ?>">No tests yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($tests as $g): ?>
      <li class="py-2 text-sm"><strong><?= $h($g['crop_name'] . ' — ' . $g['variety_name']) ?></strong><?= $g['lot_code'] ? ' (' . $h($g['lot_code']) . ')' : '' ?> · <?= (int)$g['seeds_germinated'] ?> of <?= (int)$g['seeds_tested'] ?> = <strong><?= $h(garden_num($g['germination_percent'])) ?>%</strong> · <?= $h(garden_date($g['checked_on'])) ?><?= $g['method'] ? ' · ' . $h($g['method']) : '' ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Seed lots ──
echo '<h1 class="', $ui['h1'], '">Seeds</h1>';
if ($err !== '' && $errAct !== 'lot_add') echo bcrf_shell_notice('err', $err);
$opts = garden_variety_options($pdo, $org);
$optMap = []; foreach ($opts as $o) $optMap[$o['value']] = $o['label'];
?>
  <div class="<?= $ui['card'] ?>">
    <?php if (!$lots): ?><p class="<?= $ui['muted'] ?>">No seed lots yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($lots as $l): $id = (int)$l['id']; ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><a class="<?= $ui['link'] ?>" href="seeds.php?lot=<?= $id ?>"><?= $h($lotName($l)) ?></a>
        <span class="text-stone-600"><?= $h($left($id)) ?><?= isset($gs[$id]) ? ' · ' . $h(garden_num($gs[$id]['germination_percent'])) . '% sprout' : '' ?><?= $l['source'] ? ' · ' . $h($l['source']) : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php $t = garden_typed('lot_add'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t || !$lots ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Add a seed lot <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <?php if (!$optMap): ?><p class="p-4 text-sm text-stone-600">Add the crop and variety first in <a class="<?= $ui['link'] ?>" href="crops.php">Crops</a>.</p><?php else: ?>
    <form id="form-lot_add" method="POST" action="seeds.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'lot_add') ?><?= garden_hidden('lot_add') ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="lv">Variety</label><select id="lv" name="variety" required class="<?= $ui['input'] ?>"><?= garden_options($optMap, $t['variety'] ?? '', 'Choose…') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="lc">Lot or packet code</label><input id="lc" name="lot_code" maxlength="120" value="<?= $h($t['lot_code'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <?php if ($vendors): ?><div class="col-span-2"><label class="<?= $ui['label'] ?>" for="lw">Vendor</label><select id="lw" name="vendor_id" class="<?= $ui['input'] ?>"><option value="0">Not in Vendors (type below)</option><?= garden_options($vendors, $t['vendor_id'] ?? '') ?></select></div><?php endif; ?>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="ls"><?= $vendors ? 'Or where it came from' : 'Where it came from' ?></label><input id="ls" name="source" maxlength="160" placeholder="Saved from our own plants" value="<?= $h($t['source'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="la">Bought on</label><input id="la" name="acquired_on" type="date" max="<?= $h($today) ?>" value="<?= $h($t['acquired_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="lq">Amount</label><input id="lq" name="quantity" inputmode="decimal" value="<?= $h($t['quantity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="lu">Unit</label><input id="lu" name="unit" maxlength="32" placeholder="packets" value="<?= $h($t['unit'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <p class="text-xs text-stone-500 mb-3">Leave the amount empty if you don't want to count this lot. A vendor not listed? Add it in <a class="<?= $ui['link'] ?>" href="/admin/vendors.php">Vendors</a>.</p>
      <button class="<?= $ui['btnMain'] ?>">Add seed lot</button>
    </form>
    <?php endif; ?>
  </details>
<?php
bcrf_shell_close();
