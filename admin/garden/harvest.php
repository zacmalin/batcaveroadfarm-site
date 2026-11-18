<?php
// admin/garden/harvest.php — Garden › Harvest (Nov 18, 2026; includes/garden.php, garden_care.php, includes/stock.php, migration 0072).
//   GET  harvest.php[?planting=N]   log a harvest; pick a product and the usable amount goes into stock (reason "Picked in the garden")
//   GET  harvest.php?tab=reports    yield by season, crop and bed; grade and loss; planting success
//   POST act=harvest_add | harvest_grade   ('garden')
require_once __DIR__ . '/../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';
require_permission([['view', 'garden']]);
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../../includes/garden.php';
require_once __DIR__ . '/../../includes/stock.php';
require_once __DIR__ . '/../../includes/admin_shell.php';
date_default_timezone_set(bcrf_tz()->getName());

use Garden\Domain\GrowingRecords;
use Garden\Domain\PlantingJournal;
use Garden\Domain\HarvestQuality;
use Garden\Domain\SeasonReports;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    bcrf_shell_open('Harvest — Garden');
    echo '<h1 class="', $ui['h1'], '">Harvest</h1>', bcrf_shell_notice('warning', 'Run migration 0072 in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
$tab = (string)($_GET['tab'] ?? '');

garden_post($pdo, 'harvest.php', [
    'harvest_add' => [false, function () use ($pdo, $org) {
        $product = (int)($_POST['product_id'] ?? 0) ?: null;
        [$id, $added] = garden_log_harvest($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('planting_id', 'Choose a planting.'),
            garden_in('harvested_on'), trim(garden_in('quantity')), garden_in('unit'), garden_in('destination'), garden_in('notes'), $product, garden_in('stock_qty'));
        return [$added !== null ? 'Harvest saved and ' . garden_num($added, 3) . ' added to stock.' : 'Harvest saved.', '', 'h-' . $id];
    }],
    'harvest_grade' => [false, function () use ($pdo, $org) {
        $id = garden_id('harvest_id', 'Choose a harvest.');
        (new HarvestQuality())->annotate($pdo, $org, garden_id('site_id', 'Choose a garden.'), $id, garden_in('grade', 'ungraded'), trim(garden_in('loss_quantity')), garden_in('loss_reason'));
        return ['Grade saved.', '', 'h-' . $id];
    }],
]);

[$sites, $site] = garden_site_pick($pdo, $org);
[$err, $errAct] = garden_open('Harvest');
echo '<h1 class="', $ui['h1'], '">', $tab === 'reports' ? 'Harvest reports' : 'Log harvest', '</h1>';
if (!$site) { echo garden_need_site(); bcrf_shell_close(); exit; }
$sid = (int)$site['id'];
if ($err !== '' && !in_array($errAct, ['harvest_add', 'harvest_grade'], true)) echo bcrf_shell_notice('err', $err);
echo garden_site_pills($sites, $site, 'harvest.php', $tab);
$today = bcrf_today();
$hid = fn(string $act, array $x = []) => garden_hidden($act, ['site_id' => $sid] + $x);
$grades = ['ungraded' => 'Not graded', 'premium' => 'Top quality', 'standard' => 'Standard', 'processing' => 'For processing'];

// ── Reports ──
if ($tab === 'reports') {
    $totals = (new SeasonReports())->seasonTotals($pdo, $org, $sid);
    $byCrop = (new SeasonReports())->harvestComparison($pdo, $org, $sid);
    $plants = (new SeasonReports())->plantingComparison($pdo, $org, $sid);
    $detail = (new HarvestQuality())->summary($pdo, $org, $sid);
    $n = fn($v) => $h(garden_num($v, 2));
    $table = function (array $head, array $rows) {
        $o = '<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-stone-500">';
        foreach ($head as $c) $o .= '<th class="py-1 pr-3">' . $c . '</th>';
        $o .= '</tr></thead><tbody>';
        foreach ($rows as $r) { $o .= '<tr class="border-t border-stone-100">'; foreach ($r as $c) $o .= '<td class="py-1 pr-3">' . $c . '</td>'; $o .= '</tr>'; }
        return $o . '</tbody></table></div>';
    }; ?>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">By season</h2>
    <?= $totals ? $table(['Year', 'Picked', 'Lost', 'Usable', 'Harvests'], array_map(fn($r) => [(int)$r['season'], $n($r['gross_yield']) . ' ' . $h($r['unit']), $n($r['recorded_loss']), $n($r['usable_yield']), (int)$r['harvest_events']], $totals)) : '<p class="' . $ui['muted'] . '">No harvests yet.</p>' ?>
  </div>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">By crop and variety</h2>
    <?= $byCrop ? $table(['Crop', 'Year', 'Plantings', 'Usable', 'Lost'], array_map(fn($r) => [$h($r['crop_name'] . ' — ' . $r['variety_name']), (int)$r['season'], (int)$r['harvested_plantings'], $n($r['usable_yield']) . ' ' . $h($r['unit']), $n($r['recorded_loss'])], $byCrop)) : '<p class="' . $ui['muted'] . '">No harvests yet.</p>' ?>
  </div>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">By bed, grade and where it went</h2>
    <?= $detail ? $table(['Year', 'Crop', 'Bed', 'Grade', 'Went to', 'Usable'], array_map(fn($r) => [(int)$r['season'], $h($r['crop_name'] . ' — ' . $r['variety_name']), $h($r['location_name']), $h($grades[$r['grade']] ?? $r['grade']), $h($r['destination']), $n($r['usable_quantity']) . ' ' . $h($r['unit'])], $detail)) : '<p class="' . $ui['muted'] . '">No harvests yet.</p>' ?>
  </div>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">How plantings did</h2>
    <?= $plants ? $table(['Crop', 'Year', 'Planned', 'Growing', 'Done', 'Failed'], array_map(fn($r) => [$h($r['crop_name'] . ' — ' . $r['variety_name']), (int)$r['season'], (int)$r['planned'], (int)$r['active'], (int)$r['completed'], (int)$r['failed']], $plants)) : '<p class="' . $ui['muted'] . '">No plantings yet.</p>' ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Log harvest ──
$plantings = (new GrowingRecords())->plantings($pdo, $org, $sid);
$open = garden_planting_options($plantings, ['active', 'completed']);
$products = garden_harvest_products($pdo);
$pre = (int)($_GET['planting'] ?? 0);
$t = garden_typed('harvest_add');
$log = (new PlantingJournal())->harvestEvents($pdo, $org, $sid);
if ($pre) $log = array_values(array_filter($log, fn($e) => (int)$e['planting_id'] === $pre));
$log = array_slice($log, 0, 40);
$stock = [];
if ($log) {
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $log));
    foreach ($pdo->query("SELECT h.id, h.stock_added, i.name FROM garden_planting_harvest_events h LEFT JOIN inventory i ON i.id = h.product_id WHERE h.id IN ($ids) AND h.product_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) $stock[(int)$r['id']] = $r;
}
$failed = (int)(garden_typed('harvest_grade')['harvest_id'] ?? 0); ?>
  <form id="form-harvest_add" method="POST" action="harvest.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'harvest_add') ?><?= $hid('harvest_add') ?>
    <?php if (!$open): ?><p class="<?= $ui['muted'] ?>">Nothing growing yet. Add a planting on <a class="<?= $ui['link'] ?>" href="plantings.php">Plantings</a>.</p><?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="hp">Planting</label><select id="hp" name="planting_id" required class="<?= $ui['input'] ?>"><?= garden_options($open, $t['planting_id'] ?? ($pre ?: ''), 'Choose…') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="hd">Picked on</label><input id="hd" name="harvested_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['harvested_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
      <div class="grid grid-cols-2 gap-1"><div><label class="<?= $ui['label'] ?>" for="hq">Amount</label><input id="hq" name="quantity" inputmode="decimal" required value="<?= $h($t['quantity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div><div><label class="<?= $ui['label'] ?>" for="hu">Unit</label><input id="hu" name="unit" maxlength="32" required placeholder="lb" value="<?= $h($t['unit'] ?? '') ?>" class="<?= $ui['input'] ?>"></div></div>
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="hw">Where it went</label><input id="hw" name="destination" maxlength="160" list="hw-list" placeholder="Farmstand, kitchen, CSA" value="<?= $h($t['destination'] ?? '') ?>" class="<?= $ui['input'] ?>"><datalist id="hw-list"><option value="Farmstand"><option value="Kitchen"><option value="Animals"><option value="Home"><option value="Compost"></datalist></div>
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="hn">Notes</label><input id="hn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <?php if ($products): ?>
    <fieldset class="border-t border-stone-100 pt-3 mb-3"><legend class="text-sm font-semibold text-stone-800">Put it in stock (optional)</legend>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-2">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="hi">Product</label><select id="hi" name="product_id" class="<?= $ui['input'] ?>"><?= garden_options($products, $t['product_id'] ?? '', 'Not going into stock') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="hs">How many go in (product's unit)</label><input id="hs" name="stock_qty" inputmode="decimal" placeholder="Same as amount" value="<?= $h($t['stock_qty'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <p class="text-xs text-stone-500 mt-2">Adds to the default stock location as "Picked in the garden". Products come from <a class="<?= $ui['link'] ?>" href="/admin/products.php">Products</a> (stock-tracked only).</p>
    </fieldset>
    <?php endif; ?>
    <button class="<?= $ui['btnMain'] ?>">Save harvest</button>
    <?php endif; ?>
  </form>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>"><?= $pre ? 'Harvests from this planting' : 'Recent harvests' ?></h2>
    <?php if (!$log): ?><p class="<?= $ui['muted'] ?>">Nothing picked yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
    <?php foreach ($log as $e): $eid = (int)$e['id']; ?>
      <li id="h-<?= $eid ?>" class="py-2 text-sm"><details <?= $failed === $eid ? 'open' : '' ?>><summary class="cursor-pointer"><?= $h(garden_date($e['harvested_on'])) ?> · <strong><?= $h(garden_num($e['quantity'], 3) . ' ' . $e['unit']) ?></strong> <?= $h($e['crop_name'] . ' — ' . $e['variety_name']) ?> · <?= $h($e['location_name']) ?><?= $e['destination'] ? ' · ' . $h($e['destination']) : '' ?><?= isset($stock[$eid]) ? ' · ' . $h(garden_num($stock[$eid]['stock_added'], 3)) . ' into ' . $h($stock[$eid]['name']) : '' ?><?= $e['grade'] !== 'ungraded' ? ' · ' . $h($grades[$e['grade']] ?? $e['grade']) : '' ?><?= $e['loss_quantity'] !== null && (float)$e['loss_quantity'] > 0 ? ' · lost ' . $h(garden_num($e['loss_quantity'], 3)) : '' ?></summary>
        <form method="POST" action="harvest.php" data-submit-once class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><?= $hid('harvest_grade', ['harvest_id' => $eid, 'back_anchor' => 'h-' . $eid] + ($pre ? ['back_query' => 'planting=' . $pre] : [])) ?>
          <?= $failed === $eid ? '<div class="col-span-2 sm:col-span-4">' . garden_form_error($err, $errAct, 'harvest_grade') . '</div>' : '' ?>
          <div><label class="<?= $ui['label'] ?>" for="g<?= $eid ?>">Grade</label><select id="g<?= $eid ?>" name="grade" class="<?= $ui['input'] ?>"><?= garden_options($grades, $e['grade']) ?></select></div>
          <div><label class="<?= $ui['label'] ?>" for="l<?= $eid ?>">Lost (<?= $h($e['unit']) ?>)</label><input id="l<?= $eid ?>" name="loss_quantity" inputmode="decimal" value="<?= $h($e['loss_quantity'] !== null ? garden_num($e['loss_quantity'], 3) : '') ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="r<?= $eid ?>">Why</label><input id="r<?= $eid ?>" name="loss_reason" maxlength="160" value="<?= $h($e['loss_reason']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><button class="<?= $ui['btnSec'] ?>">Save grade</button></div>
        </form>
        <?= $e['notes'] ? '<p class="text-stone-600 mt-1">' . $h($e['notes']) . '</p>' : '' ?>
      </details></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
bcrf_shell_close();
