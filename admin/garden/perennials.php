<?php
// admin/garden/perennials.php — Garden › Orchard & greenhouse (Nov 18, 2026; includes/garden.php, garden_areas.php, migration 0072).
//   GET  perennials.php                 orchard: each tree, bush or cane (from a perennial planting) and its growth stages through the year
//   GET  perennials.php?tab=greenhouse  greenhouse temperature (°F) and humidity readings
//   POST act=tree_add | tree_stage | gh_add   ('garden')
require_once __DIR__ . '/../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';
require_permission([['view', 'garden']]);
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../../includes/garden.php';
require_once __DIR__ . '/../../includes/admin_shell.php';
date_default_timezone_set(bcrf_tz()->getName());

use Garden\Domain\GardenService;
use Garden\Domain\GrowingRecords;
use Garden\Domain\SoilPerennials;
use Garden\Domain\SpecializedGrowing;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    bcrf_shell_open('Orchard & greenhouse — Garden');
    echo '<h1 class="', $ui['h1'], '">Orchard &amp; greenhouse</h1>', bcrf_shell_notice('warning', 'Run the garden migrations (0072 and 0073) in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
$tab = (string)($_GET['tab'] ?? '');
$sp = new SoilPerennials();
$sg = new SpecializedGrowing();

garden_post($pdo, 'perennials.php', [
    'tree_add' => [false, function () use ($pdo, $org, $sp) {
        $sp->addPerennial($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('planting_id', 'Choose a perennial planting.'), garden_in('label'),
                          trim(garden_in('planted_year')), garden_in('rootstock'), garden_in('notes'));
        return 'Saved to the orchard.';
    }],
    'tree_stage' => [false, function () use ($pdo, $org, $sg) {
        $id = garden_id('perennial_id', 'Choose a tree or bush.');
        $sg->recordPerennialStage($pdo, $org, garden_id('site_id', 'Choose a garden.'), $id, garden_in('observed_on'), garden_in('stage'), garden_in('notes'));
        return ['Stage saved.', '', 'tree-' . $id];
    }],
    'gh_add' => [false, function () use ($pdo, $org, $sg) {
        $hum = trim(garden_in('humidity_pct'));
        if ($hum !== '' && is_numeric($hum)) $hum = number_format((float)$hum, 1, '.', '');
        $sg->recordGreenhouse($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('location_id', 'Choose a greenhouse.'), garden_in('observed_on'),
                              garden_f_to_c(garden_in('temperature_f')), $hum, garden_in('notes'));
        return ['Reading saved.', 'tab=greenhouse'];
    }],
]);

[$sites, $site] = garden_site_pick($pdo, $org);
[$err, $errAct] = garden_open('Orchard & greenhouse');
echo '<h1 class="', $ui['h1'], '">', $tab === 'greenhouse' ? 'Greenhouse' : 'Orchard', '</h1>';
if (!$site) { echo garden_need_site(); bcrf_shell_close(); exit; }
$sid = (int)$site['id'];
$own = ['' => ['tree_add', 'tree_stage'], 'greenhouse' => ['gh_add']];
if ($err !== '' && !in_array($errAct, $own[$tab] ?? [], true)) echo bcrf_shell_notice('err', $err);
echo garden_site_pills($sites, $site, 'perennials.php', $tab);
$areas = (new GardenService())->locations($pdo, $org, $sid);
$today = bcrf_today();
$hid = fn(string $act, array $x = []) => garden_hidden($act, ['site_id' => $sid] + $x);

// ── Greenhouse ──
if ($tab === 'greenhouse') {
    $houses = garden_area_options(array_filter($areas, fn($a) => (int)($a['active'] ?? 1) === 1), 'greenhouse');
    $rows = $sg->greenhouseReadings($pdo, $org, $sid);
    $t = garden_typed('gh_add'); ?>
  <form id="form-gh_add" method="POST" action="perennials.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'gh_add') ?><?= $hid('gh_add', ['back_query' => 'tab=greenhouse']) ?>
    <h2 class="<?= $ui['h2'] ?>">Add a reading</h2>
    <?php if (!$houses): ?><p class="<?= $ui['muted'] ?>">No greenhouse yet. Add an area with kind Greenhouse on <a class="<?= $ui['link'] ?>" href="areas.php">Beds &amp; areas</a>.</p><?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="gl">Greenhouse</label><select id="gl" name="location_id" required class="<?= $ui['input'] ?>"><?= garden_options($houses, $t['location_id'] ?? (count($houses) === 1 ? array_key_first($houses) : ''), count($houses) === 1 ? '' : 'Choose…') ?></select></div>
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="gd">Date</label><input id="gd" name="observed_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['observed_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gt">Temp (°F)</label><input id="gt" name="temperature_f" inputmode="decimal" value="<?= $h($t['temperature_f'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gh">Humidity (%)</label><input id="gh" name="humidity_pct" inputmode="decimal" value="<?= $h($t['humidity_pct'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="gn">Notes</label><input id="gn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <button class="<?= $ui['btnMain'] ?>">Save reading</button>
    <?php endif; ?>
  </form>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Readings</h2>
    <?php if (!$rows): ?><p class="<?= $ui['muted'] ?>">No readings yet.</p><?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-stone-500"><th class="py-1 pr-3">Date</th><th class="py-1 pr-3">Greenhouse</th><th class="py-1 pr-3">Temp</th><th class="py-1 pr-3">Humidity</th><th class="py-1">Notes</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr class="border-t border-stone-100"><td class="py-1 pr-3"><?= $h(garden_date($r['observed_on'])) ?></td><td class="py-1 pr-3"><?= $h($r['location_name']) ?></td><td class="py-1 pr-3"><?= $r['temperature_c'] !== null ? $h(garden_c_to_f($r['temperature_c'])) . '°F' : '' ?></td><td class="py-1 pr-3"><?= $r['humidity_pct'] !== null ? $h(garden_num($r['humidity_pct'])) . '%' : '' ?></td><td class="py-1"><?= $h($r['notes']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Orchard ──
$trees = $sp->perennials($pdo, $org, $sid);
$obs = []; foreach ($sg->perennialObservations($pdo, $org, $sid) as $o) $obs[(int)$o['perennial_id']][] = $o;
$stages = ['dormant' => 'Dormant', 'bud_break' => 'Buds breaking', 'flowering' => 'Flowering', 'fruit_set' => 'Fruit set', 'fruit_development' => 'Fruit growing',
           'harvest_ready' => 'Ready to pick', 'leaf_drop' => 'Leaves dropping', 'other' => 'Other'];
$perennialPlantings = garden_planting_options(array_filter((new GrowingRecords())->plantings($pdo, $org, $sid), fn($p) => $p['method'] === 'perennial_installation'), ['active', 'planned']);
$failed = (int)(garden_typed('tree_stage')['perennial_id'] ?? 0);
$t = garden_typed('tree_add'); ?>
  <?php if (!$trees): ?><div class="<?= $ui['card'] ?>"><p class="<?= $ui['muted'] ?>">Nothing in the orchard yet. Add a planting with how = "Planted a perennial", then name it below.</p></div><?php endif; ?>
  <?php foreach ($trees as $r): $rid = (int)$r['id']; $last = $obs[$rid][0] ?? null; ?>
  <details id="tree-<?= $rid ?>" class="<?= $ui['sec'] ?>" <?= $failed === $rid ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>"><span><?= $h($r['label']) ?> <span class="font-normal text-stone-500">· <?= $h($r['crop_name'] . ' — ' . $r['variety_name']) ?> · <?= $h($r['location_name']) ?><?= $last ? ' · ' . $h($stages[$last['stage']] ?? $last['stage']) . ' ' . $h(garden_date($last['observed_on'])) : '' ?></span></span><span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <div class="p-4 text-sm">
      <p class="mb-2"><?= $r['planted_year'] ? 'Planted ' . (int)$r['planted_year'] . '. ' : '' ?><?= $r['rootstock'] ? 'Rootstock ' . $h($r['rootstock']) . '. ' : '' ?><?= $h($r['notes']) ?> <a class="<?= $ui['link'] ?>" href="plantings.php?id=<?= (int)$r['planting_id'] ?>">Open planting</a></p>
      <?php if (!empty($obs[$rid])): ?><ul class="mb-3 list-disc pl-5"><?php foreach (array_slice($obs[$rid], 0, 12) as $o): ?><li><?= $h(garden_date($o['observed_on'])) ?> · <?= $h($stages[$o['stage']] ?? $o['stage']) ?><?= $o['notes'] ? ' · ' . $h($o['notes']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
      <form method="POST" action="perennials.php" data-submit-once class="grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><?= $hid('tree_stage', ['perennial_id' => $rid, 'back_anchor' => 'tree-' . $rid]) ?>
        <?= $failed === $rid ? '<div class="col-span-2 sm:col-span-4">' . garden_form_error($err, $errAct, 'tree_stage') . '</div>' : '' ?>
        <div><label class="<?= $ui['label'] ?>" for="st<?= $rid ?>">Stage</label><select id="st<?= $rid ?>" name="stage" class="<?= $ui['input'] ?>"><?= garden_options($stages, '') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="sd<?= $rid ?>">Seen on</label><input id="sd<?= $rid ?>" name="observed_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($today) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="sn<?= $rid ?>">Notes</label><input id="sn<?= $rid ?>" name="notes" maxlength="2000" class="<?= $ui['input'] ?>"></div>
        <div><button class="<?= $ui['btnSec'] ?>">Save stage</button></div>
      </form>
      <p class="mt-2"><a class="<?= $ui['link'] ?>" href="care.php?planting=<?= (int)$r['planting_id'] ?>">Log care</a> · <a class="<?= $ui['link'] ?>" href="harvest.php?planting=<?= (int)$r['planting_id'] ?>">Log harvest</a></p>
    </div>
  </details>
  <?php endforeach; ?>
  <details class="<?= $ui['sec'] ?>" <?= $t ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Add a tree, bush or cane <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <?php if (!$perennialPlantings): ?><p class="p-4 text-sm text-stone-600">First add a planting on <a class="<?= $ui['link'] ?>" href="plantings.php">Plantings</a> with how = "Planted a perennial". Then name each tree here.</p><?php else: ?>
    <form id="form-tree_add" method="POST" action="perennials.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'tree_add') ?><?= $hid('tree_add') ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="tp">Planting</label><select id="tp" name="planting_id" required class="<?= $ui['input'] ?>"><?= garden_options($perennialPlantings, $t['planting_id'] ?? '', 'Choose…') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="tl">Name or tag</label><input id="tl" name="label" maxlength="120" required placeholder="Apple #3, north row" value="<?= $h($t['label'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="ty">Year planted</label><input id="ty" name="planted_year" inputmode="numeric" maxlength="4" value="<?= $h($t['planted_year'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="tr">Rootstock</label><input id="tr" name="rootstock" maxlength="120" value="<?= $h($t['rootstock'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="tn">Notes</label><input id="tn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <button class="<?= $ui['btnMain'] ?>">Add to orchard</button>
    </form>
    <?php endif; ?>
  </details>
<?php
bcrf_shell_close();
