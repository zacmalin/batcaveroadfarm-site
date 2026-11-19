<?php
// admin/garden/plantings.php — Garden › Plantings (Nov 18, 2026; includes/garden.php, garden_seeds.php, garden_plantings.php, migration 0072).
//   GET  plantings.php[?area=N&status=…]   what's planned, growing and done, by bed; add a planting (seed comes off the lot)
//   GET  plantings.php?id=N                one planting: milestones (sown, sprouted, transplanted…), status, bed space
//   GET  plantings.php?tab=trays           seed-starting trays: cells sown, sprouted, moved out
//   GET  plantings.php?tab=succession      repeat sowings on a schedule, each round linked to the planting that filled it
//   GET  plantings.php?tab=rotation        which crop families were in each bed, and where a family can go this year
//   POST act=plant_add | plant_status | plant_event | plant_space | tray_add | tray_save | succ_add | succ_link   ('garden')
// Bed planner (Nov 19, 2026; includes/garden_planner.php, admin/assets/garden.js): each planting has "Out of the bed by"
//   (expected_end_on; empty = worked out from days to maturity). Adding one warns, but still saves, when the bed grew the same
//   crop family in the last 3 years or already has something in it then; the planting page repeats those notes.
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
use Garden\Domain\SeedStock;
use Garden\Domain\PlantingLifecycle;
use Garden\Domain\PropagationReports;
use Garden\Domain\SuccessionSchedules;
use Garden\Domain\FieldWorkflows;
use Garden\Domain\SeasonGuidance;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    bcrf_shell_open('Plantings — Garden');
    echo '<h1 class="', $ui['h1'], '">Plantings</h1>', bcrf_shell_notice('warning', 'Run the garden migrations (0072 to 0075) in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
$rec = new GrowingRecords();
$life = new PlantingLifecycle();
$trays = new PropagationReports();
$succ = new SuccessionSchedules();
$tab = (string)($_GET['tab'] ?? '');

garden_post($pdo, 'plantings.php', [
    'plant_add' => [false, function () use ($pdo, $org, $rec, $life) {
        $site = garden_id('site_id', 'Choose a garden.');
        $lot = (int)($_POST['seed_lot_id'] ?? 0) ?: null;
        $used = trim(garden_in('seed_used'));
        $pdo->beginTransaction();
        try {
            $id = $rec->createPlanting($pdo, $org, $site, garden_id('location_id', 'Choose a bed.'), garden_in('variety'), $lot, garden_in('planted_on'), garden_in('method'),
                                       garden_in('quantity') ?: null, garden_in('unit'), garden_in('status', 'active'));
            garden_planting_set_end($pdo, $org, $site, $id, garden_end_date_in(garden_in('expected_end_on'), garden_in('planted_on')));
            if ($used !== '') {
                if (!$lot) throw new InvalidArgumentException('Pick the seed lot the seed came from, or leave "Seed used" empty.');
                (new SeedStock())->record($pdo, $org, $lot, 'used', '-' . ltrim($used, '-'), garden_in('planted_on'), 'Planting #' . $id, garden_uid());
            }
            $space = garden_sqft_to_sqm(garden_in('space_sqft'));
            if ($space !== '') $life->allocateArea($pdo, $org, $site, $id, $space);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $warn = garden_planting_warnings_for($pdo, $org, $site, $id);
        return $warn ? ['Planting saved. ' . implode(' ', $warn), 'id=' . $id, '', 'warning'] : ['Planting saved.', 'id=' . $id];
    }],
    'plant_status' => [false, function () use ($pdo, $org, $rec) {
        $id = garden_id('planting_id', 'Choose a planting.');
        $rec->setPlantingStatus($pdo, $org, garden_id('site_id', 'Choose a garden.'), $id, garden_in('status'));
        return ['Status saved.', 'id=' . $id];
    }],
    'plant_event' => [false, function () use ($pdo, $org, $life) {
        $id = garden_id('planting_id', 'Choose a planting.');
        $life->record($pdo, $org, garden_id('site_id', 'Choose a garden.'), $id, garden_in('event_type'), garden_in('occurred_on'), trim(garden_in('quantity')), trim(garden_in('quantity_unit')), garden_in('notes'));
        return ['Saved.', 'id=' . $id];
    }],
    'plant_space' => [false, function () use ($pdo, $org, $life) {
        $id = garden_id('planting_id', 'Choose a planting.');
        $site = garden_id('site_id', 'Choose a garden.');
        $st = $pdo->prepare('SELECT planted_on FROM garden_plantings WHERE id = ? AND organization_id = ?'); $st->execute([$id, $org]);
        $start = (string)$st->fetchColumn();
        $pdo->beginTransaction();
        try {
            garden_planting_set_end($pdo, $org, $site, $id, garden_end_date_in(garden_in('expected_end_on'), $start));
            $life->allocateArea($pdo, $org, $site, $id, garden_sqft_to_sqm(garden_in('space_sqft')));
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $warn = garden_planting_warnings_for($pdo, $org, $site, $id);
        return $warn ? ['Saved. ' . implode(' ', $warn), 'id=' . $id, '', 'warning'] : ['Bed space and dates saved.', 'id=' . $id];
    }],
    'tray_add' => [false, function () use ($pdo, $org, $trays) {
        $trays->createTray($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('planting_id', 'Choose the planting the tray is for.'), garden_in('label'), trim(garden_in('cells_sown')), garden_in('sown_on'), garden_in('notes'));
        return ['Tray saved.', 'tab=trays'];
    }],
    'tray_save' => [false, function () use ($pdo, $org, $trays) {
        $trays->updateTray($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('tray_id', 'Choose a tray.'), trim(garden_in('germinated')), garden_in('germination_checked_on'), garden_in('transplanted_on'), trim(garden_in('transplanted_count')));
        return ['Tray saved.', 'tab=trays'];
    }],
    'succ_add' => [false, function () use ($pdo, $org, $succ) {
        $every = filter_var($_POST['interval_days'] ?? null, FILTER_VALIDATE_INT); $rounds = filter_var($_POST['rounds'] ?? null, FILTER_VALIDATE_INT);
        if ($every === false || $rounds === false) throw new InvalidArgumentException('Enter how many days apart and how many sowings.');
        $succ->create($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('location_id', 'Choose a bed.'), garden_in('variety'), garden_in('start_on'), $every, $rounds, garden_in('method', 'direct_sow'), garden_in('notes'));
        return ['Succession saved.', 'tab=succession'];
    }],
    'succ_link' => [false, function () use ($pdo, $org, $succ) {
        $succ->linkPlanting($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('schedule_id', 'Choose a succession.'), garden_id('round_number', 'Choose a round.'), garden_id('planting_id', 'Choose a planting.'));
        return ['Round linked to its planting.', 'tab=succession'];
    }],
]);

/** Rotation and crowding notes for one planting, as sentences. */
function garden_planting_warnings_for(PDO $pdo, int $org, int $site, int $id): array {
    $all = (new GrowingRecords())->plantings($pdo, $org, $site);
    $cap = [];
    foreach ((new GardenService())->locations($pdo, $org, $site) as $a) $cap[(int)$a['id']] = $a['area_sq_m'] !== null ? (float)$a['area_sq_m'] : ($a['length_m'] !== null ? (float)$a['length_m'] * (float)$a['width_m'] : null);
    return garden_planting_warnings($all, $id, $cap);
}

[$sites, $site] = garden_site_pick($pdo, $org);
[$err, $errAct] = garden_open('Plantings', ['css' => ['garden.css'], 'js' => ['garden.js']]);
$titles = ['trays' => 'Seed trays', 'succession' => 'Successions', 'rotation' => 'Crop rotation'];
if (!$site) { echo '<h1 class="', $ui['h1'], '">Plantings</h1>', garden_need_site(); bcrf_shell_close(); exit; }
$sid = (int)$site['id'];
$areas = (new GardenService())->locations($pdo, $org, $sid);
$names = garden_area_options($areas);
$plantings = $rec->plantings($pdo, $org, $sid);
$opts = garden_variety_options($pdo, $org);
$optMap = []; foreach ($opts as $o) $optMap[$o['value']] = $o['label'];
$methods = ['direct_sow' => 'Sowed in the bed', 'transplant' => 'Transplanted', 'perennial_installation' => 'Planted a perennial (tree, bush, cane)'];
$statuses = ['planned' => 'Planned', 'active' => 'Growing', 'completed' => 'Done', 'failed' => 'Failed'];
$events = ['sown' => 'Sowed', 'germinated' => 'Sprouted', 'hardened_off' => 'Hardened off', 'transplanted' => 'Transplanted', 'thinned' => 'Thinned', 'established' => 'Established', 'completed' => 'Done', 'failed' => 'Failed'];
$today = bcrf_today();
$hid = fn(string $act, array $x = []) => garden_hidden($act, ['site_id' => $sid] + $x);
$pName = fn(array $p) => $p['crop_name'] . ' — ' . $p['variety_name'];

// ── One planting ──
$pid = (int)($_GET['id'] ?? 0);
$p = null; foreach ($plantings as $x) if ((int)$x['id'] === $pid) $p = $x;
if ($p) {
    $evs = array_values(array_filter($life->events($pdo, $org, $sid), fn($e) => (int)$e['planting_id'] === $pid));
    $open = in_array($p['status'], ['planned', 'active'], true);
    $span = garden_planting_span($p);
    echo '<p class="mb-2"><a class="', $ui['link'], ' text-sm" href="plantings.php">← All plantings</a></p>';
    echo '<h1 class="', $ui['h1'], '">', $h($pName($p)), '</h1>';
    if ($err !== '' && !in_array($errAct, ['plant_status', 'plant_event', 'plant_space'], true)) echo bcrf_shell_notice('err', $err);
    foreach (garden_planting_warnings_for($pdo, $org, $sid, $pid) as $w) echo bcrf_shell_notice('warning', $w);
    echo '<div class="', $ui['card'], '"><dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">',
         '<div><dt class="text-stone-500">Bed</dt><dd>', $h($names[(int)$p['location_id']] ?? $p['location_name']), '</dd></div>',
         '<div><dt class="text-stone-500">', $p['status'] === 'planned' ? 'Planned for' : 'Planted', '</dt><dd>', $h(garden_date($p['planted_on'])), '</dd></div>',
         '<div><dt class="text-stone-500">How</dt><dd>', $h($methods[$p['method']] ?? $p['method']), '</dd></div>',
         '<div><dt class="text-stone-500">Status</dt><dd class="font-semibold">', $h($statuses[$p['status']] ?? $p['status']), '</dd></div>',
         $p['quantity'] !== null ? '<div><dt class="text-stone-500">Amount</dt><dd>' . $h(garden_num($p['quantity'], 3) . ' ' . $p['quantity_unit']) . '</dd></div>' : '',
         $p['allocated_area_sq_m'] !== null ? '<div><dt class="text-stone-500">Bed space</dt><dd>' . $h(garden_sqm_to_sqft($p['allocated_area_sq_m'])) . ' sq ft</dd></div>' : '',
         '<div><dt class="text-stone-500">', in_array($p['status'], ['completed', 'failed'], true) ? 'Came out' : 'Out of the bed by', '</dt><dd>', $span[1] === null ? 'Stays (perennial)' : $h(garden_date($span[1])) . ($span[2] ? ' <span class="text-stone-500">(about)</span>' : ''), '</dd></div>',
         $p['crop_family'] ? '<div><dt class="text-stone-500">Family</dt><dd>' . $h(garden_family_label($p['crop_family'])) . '</dd></div>' : '',
         '</dl><div class="flex flex-wrap gap-2 mt-3"><a class="', $ui['btnSec'], '" href="areas.php?bed=', (int)$p['location_id'], '">Open bed</a><a class="', $ui['btnSec'], '" href="care.php?planting=', $pid, '">Log care</a><a class="', $ui['btnSec'], '" href="harvest.php?planting=', $pid, '">Log harvest</a></div>',
         '<div class="gm-delrow">', garden_delete_button('plantings.php', 'planting', $pid, 'Delete this planting for good? Its steps, care, harvests (and any stock they added), pest notes, trays and orchard trees go too. Seed it used goes back to the packet. This can\'t be undone.', '', '', 'Delete this planting'), '</div></div>';
    $t = garden_typed('plant_event'); ?>
  <form id="form-plant_event" method="POST" action="plantings.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'plant_event') ?><?= $hid('plant_event', ['planting_id' => $pid, 'back_query' => 'id=' . $pid]) ?>
    <h2 class="<?= $ui['h2'] ?>">What happened</h2>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div><label class="<?= $ui['label'] ?>" for="et">Step</label><select id="et" name="event_type" class="<?= $ui['input'] ?>"><?= garden_options($open ? $events : ['completed' => 'Done', 'failed' => 'Failed'], $t['event_type'] ?? '') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="ed">Date</label><input id="ed" name="occurred_on" type="date" required min="<?= $h($p['planted_on']) ?>" max="<?= $h($today) ?>" value="<?= $h($t['occurred_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="eq">How many</label><input id="eq" name="quantity" inputmode="decimal" value="<?= $h($t['quantity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="eu">Unit</label><input id="eu" name="quantity_unit" maxlength="32" placeholder="plants" value="<?= $h($t['quantity_unit'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <div class="mb-3"><label class="<?= $ui['label'] ?>" for="en">Notes</label><input id="en" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    <button class="<?= $ui['btnMain'] ?>">Save step</button>
  </form>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Steps so far</h2>
    <?php if (!$evs): ?><p class="<?= $ui['muted'] ?>">None yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100"><?php foreach ($evs as $e): ?><li class="py-2 text-sm"><strong><?= $h($events[$e['event_type']] ?? $e['event_type']) ?></strong> · <?= $h(garden_date($e['occurred_on'])) ?><?= $e['quantity'] !== null ? ' · ' . $h(garden_num($e['quantity'], 3) . ' ' . $e['quantity_unit']) : '' ?><?= $e['notes'] ? '<span class="block text-stone-600">' . $h($e['notes']) . '</span>' : '' ?> <?= garden_delete_button('plantings.php', 'step', (int)$e['id'], 'Delete this step?' . (in_array($e['event_type'], ['completed', 'failed'], true) ? ' The planting goes back to Growing.' : ''), 'id=' . $pid) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <form id="form-plant_status" method="POST" action="plantings.php" data-submit-once class="<?= $ui['card'] ?>">
      <?= garden_form_error($err, $errAct, 'plant_status') ?><?= $hid('plant_status', ['planting_id' => $pid, 'back_query' => 'id=' . $pid]) ?>
      <h2 class="<?= $ui['h2'] ?>">Status</h2>
      <div class="flex flex-wrap gap-2 items-end"><div class="flex-1 min-w-32"><label class="sr-only" for="ps">Status</label><select id="ps" name="status" class="<?= $ui['input'] ?>"><?= garden_options($statuses, $p['status']) ?></select></div><button class="<?= $ui['btnSec'] ?>">Save status</button></div>
    </form>
    <?php if ($open): $t = garden_typed('plant_space'); ?>
    <form id="form-plant_space" method="POST" action="plantings.php" data-submit-once class="<?= $ui['card'] ?>">
      <?= garden_form_error($err, $errAct, 'plant_space') ?><?= $hid('plant_space', ['planting_id' => $pid, 'back_query' => 'id=' . $pid]) ?>
      <h2 class="<?= $ui['h2'] ?>">Bed space and dates</h2>
      <div class="grid grid-cols-2 gap-3 mb-3"><div><label class="<?= $ui['label'] ?>" for="sp">Bed space (sq ft)</label><input id="sp" name="space_sqft" inputmode="decimal" value="<?= $h($t['space_sqft'] ?? garden_sqm_to_sqft($p['allocated_area_sq_m'])) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="se">Out of the bed by</label><input id="se" name="expected_end_on" type="date" min="<?= $h($p['planted_on']) ?>" value="<?= $h($t['expected_end_on'] ?? $p['expected_end_on']) ?>" class="<?= $ui['input'] ?>"></div></div>
      <button class="<?= $ui['btnSec'] ?>">Save space and dates</button>
      <p class="text-xs text-stone-500 mt-2">Space empty = not measured. Date empty = worked out from days to maturity plus 3 weeks of picking. Both let the garden catch two crops planned into a full bed at the same time.</p>
    </form>
    <?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

echo '<h1 class="', $ui['h1'], '">', $titles[$tab] ?? 'Plantings', '</h1>';
$own = ['' => ['plant_add'], 'trays' => ['tray_add', 'tray_save'], 'succession' => ['succ_add', 'succ_link'], 'rotation' => []];
if ($err !== '' && !in_array($errAct, $own[$tab] ?? [], true)) echo bcrf_shell_notice('err', $err);
echo garden_site_pills($sites, $site, 'plantings.php', $tab);
if (!$areas) { echo garden_need_site(); bcrf_shell_close(); exit; }

// ── Trays ──
if ($tab === 'trays') {
    $rows = $trays->trays($pdo, $org, $sid);
    $forTray = garden_planting_options(array_filter($plantings, fn($x) => $x['method'] === 'transplant'));
    $t = garden_typed('tray_add'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t || !$rows ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Start a tray <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <?php if (!$forTray): ?><p class="p-4 text-sm text-stone-600">Add the planting first (how: Transplanted, status Planned) on the <a class="<?= $ui['link'] ?>" href="plantings.php">Plantings</a> tab, then start its tray here.</p><?php else: ?>
    <form id="form-tray_add" method="POST" action="plantings.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'tray_add') ?><?= $hid('tray_add', ['back_query' => 'tab=trays']) ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="tp">For planting</label><select id="tp" name="planting_id" required class="<?= $ui['input'] ?>"><?= garden_options($forTray, $t['planting_id'] ?? '', 'Choose…') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="tl">Tray label</label><input id="tl" name="label" maxlength="120" required placeholder="Tray A" value="<?= $h($t['label'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="tc">Cells sown</label><input id="tc" name="cells_sown" type="number" min="1" required value="<?= $h($t['cells_sown'] ?? '72') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="ts">Sown on</label><input id="ts" name="sown_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['sown_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <div class="mb-3"><label class="<?= $ui['label'] ?>" for="tn">Notes</label><input id="tn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <button class="<?= $ui['btnMain'] ?>">Add tray</button>
    </form>
    <?php endif; ?>
  </details>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Trays</h2>
    <?php if (!$rows): ?><p class="<?= $ui['muted'] ?>">No trays yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
    <?php foreach ($rows as $r): $tid = (int)$r['id']; $pct = $r['germinated'] !== null ? round(100 * (int)$r['germinated'] / max(1, (int)$r['cells_sown'])) : null; ?>
      <li class="py-2 text-sm"><details><summary class="cursor-pointer"><strong><?= $h($r['label']) ?></strong> · <?= $h($r['crop_name'] . ' — ' . $r['variety_name']) ?> · <?= (int)$r['cells_sown'] ?> cells, sown <?= $h(garden_date($r['sown_on'])) ?><?= $pct !== null ? ' · ' . $pct . '% sprouted' : '' ?><?= $r['transplanted_on'] ? ' · moved out ' . $h(garden_date($r['transplanted_on'])) : '' ?></summary>
        <form method="POST" action="plantings.php" data-submit-once class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><?= $hid('tray_save', ['tray_id' => $tid, 'back_query' => 'tab=trays']) ?>
          <div><label class="<?= $ui['label'] ?>" for="g<?= $tid ?>">Sprouted</label><input id="g<?= $tid ?>" name="germinated" type="number" min="0" max="<?= (int)$r['cells_sown'] ?>" value="<?= $h($r['germinated']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="gc<?= $tid ?>">Counted on</label><input id="gc<?= $tid ?>" name="germination_checked_on" type="date" max="<?= $h($today) ?>" value="<?= $h($r['germination_checked_on']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="to<?= $tid ?>">Moved out on</label><input id="to<?= $tid ?>" name="transplanted_on" type="date" max="<?= $h($today) ?>" value="<?= $h($r['transplanted_on']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="tk<?= $tid ?>">Plants moved</label><input id="tk<?= $tid ?>" name="transplanted_count" type="number" min="1" max="<?= (int)$r['cells_sown'] ?>" value="<?= $h($r['transplanted_count']) ?>" class="<?= $ui['input'] ?>"></div>
          <div class="col-span-2 sm:col-span-4"><button class="<?= $ui['btnSec'] ?>">Save tray</button> <a class="text-xs <?= $ui['link'] ?> ml-2" href="plantings.php?id=<?= (int)$r['planting_id'] ?>">Open planting</a></div>
        </form>
        <p class="text-xs text-stone-500 mt-1">Moving plants out adds a Transplanted step to the planting.</p>
        <div class="gm-delrow"><?= garden_delete_button('plantings.php', 'tray', $tid, 'Delete ' . $r['label'] . '? The planting stays.', 'tab=trays') ?></div>
      </details></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Successions ──
if ($tab === 'succession') {
    $scheds = $succ->schedules($pdo, $org, $sid);
    $t = garden_typed('succ_add'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t || !$scheds ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Plan repeat sowings <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-succ_add" method="POST" action="plantings.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'succ_add') ?><?= $hid('succ_add', ['back_query' => 'tab=succession']) ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="sv">Variety</label><select id="sv" name="variety" required class="<?= $ui['input'] ?>"><?= garden_options($optMap, $t['variety'] ?? '', 'Choose…') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="sl">Bed</label><select id="sl" name="location_id" required class="<?= $ui['input'] ?>"><?= garden_options($names, $t['location_id'] ?? '', 'Choose…') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="ss">First sowing</label><input id="ss" name="start_on" type="date" required value="<?= $h($t['start_on'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="se">Every (days)</label><input id="se" name="interval_days" type="number" min="1" max="365" required value="<?= $h($t['interval_days'] ?? '14') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="sr">Sowings</label><input id="sr" name="rounds" type="number" min="2" max="24" required value="<?= $h($t['rounds'] ?? '4') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="sm">How</label><select id="sm" name="method" class="<?= $ui['input'] ?>"><?= garden_options(['direct_sow' => 'Sow in the bed', 'transplant' => 'Transplant'], $t['method'] ?? 'direct_sow') ?></select></div>
      </div>
      <div class="mb-3"><label class="<?= $ui['label'] ?>" for="sn">Notes</label><input id="sn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <button class="<?= $ui['btnMain'] ?>">Add succession</button>
    </form>
  </details>
  <?php foreach ($scheds as $s): $rounds = $succ->rounds($pdo, $org, (int)$s['id']);
      $match = garden_planting_options(array_filter($plantings, fn($x) => (int)$x['location_id'] === (int)$s['location_id'] && (string)$x['variety_id'] === (string)$s['variety_id'] && (string)$x['organization_variety_id'] === (string)$s['organization_variety_id']), []); ?>
  <div class="<?= $ui['card'] ?>">
    <div class="flex flex-wrap justify-between gap-2 items-start"><h2 class="<?= $ui['h2'] ?>"><?= $h($s['crop_name'] . ' — ' . $s['variety_name']) ?> <span class="font-normal text-stone-500">· <?= $h($names[(int)$s['location_id']] ?? $s['location_name']) ?> · every <?= (int)$s['interval_days'] ?> days</span></h2>
      <?= garden_delete_button('plantings.php', 'succession', (int)$s['id'], 'Delete this repeat sowing plan? Plantings already made from it stay.', 'tab=succession') ?></div>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rounds as $r): $late = !$r['planting_id'] && $r['due_on'] < $today; ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2 items-center"><span>Sowing <?= (int)$r['round_number'] ?> · <?= $h(garden_date($r['due_on'])) ?><?= $late ? ' · <span class="text-red-700 font-semibold">due</span>' : '' ?></span>
        <?php if ($r['planting_id']): ?><a class="<?= $ui['link'] ?>" href="plantings.php?id=<?= (int)$r['planting_id'] ?>">Planted (<?= $h($statuses[$r['planting_status']] ?? $r['planting_status']) ?>)</a>
        <?php elseif ($match): ?>
        <form method="POST" action="plantings.php" class="flex gap-2 items-center"><?= $hid('succ_link', ['schedule_id' => (int)$s['id'], 'round_number' => (int)$r['round_number'], 'back_query' => 'tab=succession']) ?><label class="sr-only" for="lk<?= (int)$s['id'] ?>_<?= (int)$r['round_number'] ?>">Planting</label><select id="lk<?= (int)$s['id'] ?>_<?= (int)$r['round_number'] ?>" name="planting_id" class="p-1 border border-stone-300 rounded text-sm"><?= garden_options($match) ?></select><button class="<?= $ui['btnSec'] ?>">Link</button></form>
        <?php else: ?><a class="<?= $ui['link'] ?>" href="plantings.php?location=<?= (int)$s['location_id'] ?>&amp;variety=<?= $h(rawurlencode($s['organization_variety_id'] ? 'custom:' . $s['organization_variety_id'] : (string)$s['variety_id'])) ?>&amp;date=<?= $h($r['due_on']) ?>#form-plant_add">Add the planting</a><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endforeach;
  bcrf_shell_close(); exit; }

// ── Rotation ──
if ($tab === 'rotation') {
    $hist = (new FieldWorkflows())->rotationHistory($pdo, $org, $sid);
    $fam = trim((string)($_GET['family'] ?? ''));
    $year = (int)date('Y');
    $families = [];
    foreach ($hist as $r) if ($r['crop_family'] !== 'Unspecified') $families[$r['crop_family']] = $r['crop_family'];
    foreach ($opts as $o) if ($o['family']) $families[$o['family']] = $o['family'];
    foreach ($pdo->query('SELECT DISTINCT crop_family FROM garden_organization_crops WHERE crop_family IS NOT NULL AND archived_at IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $f) $families[$f] = $f;
    ksort($families); ?>
  <form method="GET" action="plantings.php" class="<?= $ui['card'] ?>"><input type="hidden" name="tab" value="rotation">
    <h2 class="<?= $ui['h2'] ?>">Where can a crop family go this year?</h2>
    <div class="flex flex-wrap gap-2 items-end"><div class="flex-1 min-w-40"><label class="<?= $ui['label'] ?>" for="rf">Family</label><select id="rf" name="family" class="<?= $ui['input'] ?>"><?= garden_options($families, $fam, 'Choose…') ?></select></div><button class="<?= $ui['btnSec'] ?>">Check</button></div>
    <?php if ($fam !== ''):
        $ok = (new SeasonGuidance())->rotationSuggestions(array_map(fn($a) => $a + ['active' => 1], $areas), $hist, $fam, $year); ?>
    <p class="text-sm mt-3"><?= $ok ? 'No ' . $h($fam) . ' in the last three years: ' . $h(implode(', ', array_map(fn($a) => $names[(int)$a['id']] ?? $a['name'], $ok))) . '.' : 'Every bed had ' . $h($fam) . ' in the last three years.' ?></p>
    <?php endif; ?>
    <p class="text-xs text-stone-500 mt-2">Families come from <a class="<?= $ui['link'] ?>" href="crops.php">Crops</a>. Planned and failed plantings don't count.</p>
  </form>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">By bed and year</h2>
    <?php if (!$hist): ?><p class="<?= $ui['muted'] ?>">Nothing planted yet.</p><?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-stone-500"><th class="py-1 pr-3">Bed</th><th class="py-1 pr-3">Year</th><th class="py-1 pr-3">Crop</th><th class="py-1">Family</th></tr></thead><tbody>
      <?php foreach ($hist as $r): ?><tr class="border-t border-stone-100"><td class="py-1 pr-3"><?= $h($names[(int)$r['location_id']] ?? $r['location_name']) ?></td><td class="py-1 pr-3"><?= (int)$r['season'] ?></td><td class="py-1 pr-3"><?= $h($r['crop_name']) ?></td><td class="py-1"><?= $h($r['crop_family']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Plantings list ──
$area = (int)($_GET['area'] ?? 0);
$st = (string)($_GET['status'] ?? 'current');
$list = array_values(array_filter($plantings, function ($x) use ($area, $st) {
    if ($area && (int)$x['location_id'] !== $area) return false;
    if ($st === 'current') return in_array($x['status'], ['planned', 'active'], true);
    return $st === 'all' || $x['status'] === $st;
}));
$occ = []; foreach ($life->occupancy($pdo, $org, $sid) as $o) $occ[(int)$o['location_id']] = $o;
$pill = function (string $key, string $label) use ($st, $area, $ui, $h) {
    $on = $st === $key;
    return '<a class="' . $ui['pill'] . ($on ? ' is-active' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="plantings.php?status=' . $key . ($area ? '&amp;area=' . $area : '') . '">' . $h($label) . '</a>';
};
?>
  <div class="flex flex-wrap gap-2 justify-end mb-3"><?= $pill('current', 'Planned & growing') ?><?= $pill('completed', 'Done') ?><?= $pill('failed', 'Failed') ?><?= $pill('all', 'All') ?></div>
  <form method="GET" action="plantings.php" class="flex flex-wrap gap-2 items-end mb-4"><input type="hidden" name="status" value="<?= $h($st) ?>">
    <div class="flex-1 min-w-40"><label class="<?= $ui['label'] ?>" for="fa">Bed</label><select id="fa" name="area" class="<?= $ui['input'] ?>"><option value="0">Every bed</option><?= garden_options($names, $area ?: '') ?></select></div>
    <button class="<?= $ui['btnSec'] ?>">Show</button>
  </form>
  <div class="<?= $ui['card'] ?>">
    <?php if ($area && isset($occ[$area])): $o = $occ[$area]; ?>
    <p class="text-sm text-stone-600 mb-2"><?= (int)$o['current_plantings'] ?> planned or growing<?= $o['capacity_sq_m'] !== null ? ' · ' . $h(garden_sqm_to_sqft($o['allocated_area_sq_m'])) . ' of ' . $h(garden_sqm_to_sqft($o['capacity_sq_m'])) . ' sq ft spoken for' : '' ?><?= (int)$o['unmeasured_plantings'] ? ' (' . (int)$o['unmeasured_plantings'] . ' not measured)' : '' ?>.</p>
    <?php endif; ?>
    <?php if (!$list): ?><p class="<?= $ui['muted'] ?>">No plantings here.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($list as $x): ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><a class="<?= $ui['link'] ?>" href="plantings.php?id=<?= (int)$x['id'] ?>"><?= $h($pName($x)) ?></a>
        <span class="text-stone-600"><?= $h($names[(int)$x['location_id']] ?? $x['location_name']) ?> · <?= $h($statuses[$x['status']] ?? $x['status']) ?> · <?= $h(garden_date($x['planted_on'])) ?> <?= garden_delete_button('plantings.php', 'planting', (int)$x['id'], 'Delete ' . $pName($x) . ' for good? Its steps, care, harvests, pest notes and trays go too. This can\'t be undone.', 'status=' . $st . ($area ? '&area=' . $area : '')) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
$t = garden_typed('plant_add');
$lots = []; foreach ($rec->seedLots($pdo, $org) as $l) $lots[(int)$l['id']] = $l['crop_name'] . ' — ' . $l['variety_name'] . ($l['lot_code'] ? ' (' . $l['lot_code'] . ')' : '') . ($l['unit'] ? ' · ' . $l['unit'] : '');
$preVar = (string)($t['variety'] ?? ($_GET['variety'] ?? '')); $preLoc = $t['location_id'] ?? ($_GET['location'] ?? ($area ?: '')); $preDate = (string)($t['planted_on'] ?? ($_GET['date'] ?? $today));
// What garden.js needs to warn before saving: each bed's plantings (family and dates), and each variety's family.
$pd = ['beds' => [], 'families' => [], 'familyNames' => garden_family_names(), 'cap' => []];
foreach ($areas as $a) { $c = $a['area_sq_m'] !== null ? (float)$a['area_sq_m'] : ($a['length_m'] !== null ? (float)$a['length_m'] * (float)$a['width_m'] : null); if ($c !== null) $pd['cap'][(int)$a['id']] = garden_sqft_num($c); }
foreach ($plantings as $x) {
    [$s, $e] = garden_planting_span($x);
    $pd['beds'][(int)$x['location_id']][] = ['crop' => $x['crop_name'], 'family' => (string)$x['crop_family'], 'status' => $x['status'], 'start' => $s, 'end' => $e, 'from' => garden_date($s), 'to' => $e === null ? '' : garden_date($e), 'sqft' => $x['allocated_area_sq_m'] !== null ? garden_sqft_num($x['allocated_area_sq_m']) : null];
}
foreach ($opts as $o) if ($o['family']) $pd['families'][$o['value']] = $o['family'];
$pd['today'] = $today; $pd['plans'] = garden_variety_plans($pdo, $org, $site, $today);   // suggested dates from the crop and the frost dates (Nov 19, 2026)
?>
  <script type="application/json" id="garden-plant-data"><?= json_encode($pd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
  <details class="<?= $ui['sec'] ?>" <?= $t || !$plantings || isset($_GET['variety']) || isset($_GET['location']) ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Add a planting <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <?php if (!$optMap): ?><p class="p-4 text-sm text-stone-600">Add the crop first in <a class="<?= $ui['link'] ?>" href="crops.php">Crops</a>.</p><?php else: ?>
    <form id="form-plant_add" method="POST" action="plantings.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'plant_add') ?><?= $hid('plant_add') ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pv">Variety</label><select id="pv" name="variety" required class="<?= $ui['input'] ?>"><?= garden_options($optMap, $preVar, 'Choose…') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pl">Bed</label><select id="pl" name="location_id" required class="<?= $ui['input'] ?>"><?= garden_options($names, $preLoc, 'Choose…') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="ps">Status</label><select id="ps" name="status" class="<?= $ui['input'] ?>"><?= garden_options(['active' => 'Planted', 'planned' => 'Planned'], $t['status'] ?? (isset($_GET['date']) ? 'planned' : 'active')) ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="pd">Date</label><input id="pd" name="planted_on" type="date" required value="<?= $h($preDate) ?>" class="<?= $ui['input'] ?>"></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pm">How</label><select id="pm" name="method" class="<?= $ui['input'] ?>"><?= garden_options($methods, $t['method'] ?? 'direct_sow') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="pq">How many</label><input id="pq" name="quantity" inputmode="decimal" value="<?= $h($t['quantity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="pu">Unit</label><input id="pu" name="unit" maxlength="32" placeholder="plants, row ft" value="<?= $h($t['unit'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="pa">Bed space (sq ft)</label><input id="pa" name="space_sqft" inputmode="decimal" value="<?= $h($t['space_sqft'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="pz">Out of the bed by</label><input id="pz" name="expected_end_on" type="date" value="<?= $h($t['expected_end_on'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <?php if ($lots): ?>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pk">Seed lot</label><select id="pk" name="seed_lot_id" class="<?= $ui['input'] ?>"><?= garden_options($lots, $t['seed_lot_id'] ?? '', 'None') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pe">Seed used (in the lot's unit)</label><input id="pe" name="seed_used" inputmode="decimal" value="<?= $h($t['seed_used'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <?php endif; ?>
      </div>
      <p class="text-xs text-stone-500 mb-3">Seed used comes off the lot's count. The lot must be the same variety. Leave "Out of the bed by" empty to work it out from days to maturity.</p>
      <div class="gm-suggest" data-plant-suggest role="status" aria-live="polite" hidden></div>
      <div class="gm-plant-warn" data-plant-warn role="status" aria-live="polite" hidden></div>
      <button class="<?= $ui['btnMain'] ?>">Add planting</button>
    </form>
    <?php endif; ?>
  </details>
<?php
bcrf_shell_close();
