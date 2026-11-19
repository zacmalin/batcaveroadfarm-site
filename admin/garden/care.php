<?php
// admin/garden/care.php — Garden › Care (Nov 18, 2026; includes/garden.php, garden_care.php, migration 0072).
//   GET  care.php[?planting=N]   log care for one planting (weeded, fed, pruned…) or one job across many beds (compost, mulch)
//   GET  care.php?tab=water      watering plans per bed: when each is due, mark watered, pause
//   GET  care.php?tab=pests      pests & disease: open a problem on a planting, record what you did, close it out
//   POST act=care_log | care_beds | water_add | water_done | water_pause | pest_add | pest_treat | pest_status   ('garden')
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
use Garden\Domain\PlantingJournal;
use Garden\Domain\IrrigationService;
use Garden\Domain\CareApplications;
use Garden\Domain\CropHealth;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    bcrf_shell_open('Care — Garden');
    echo '<h1 class="', $ui['h1'], '">Care</h1>', bcrf_shell_notice('warning', 'Run the garden migrations (0072, 0073 and 0074) in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
$journal = new PlantingJournal();
$water = new IrrigationService();
$apps = new CareApplications();
$health = new CropHealth();
$tab = (string)($_GET['tab'] ?? '');

garden_post($pdo, 'care.php', [
    'care_log' => [false, function () use ($pdo, $org, $journal) {
        $pid = garden_id('planting_id', 'Choose a planting.');
        $journal->logCare($pdo, $org, garden_id('site_id', 'Choose a garden.'), $pid, garden_in('occurred_on'), garden_in('kind'), garden_in('notes'));
        return ['Care saved.', 'planting=' . $pid];
    }],
    'care_beds' => [false, function () use ($pdo, $org, $apps) {
        $beds = $_POST['location_ids'] ?? [];
        $apps->record($pdo, $org, garden_id('site_id', 'Choose a garden.'), is_array($beds) ? $beds : [], garden_in('kind'), garden_in('applied_on'),
                      garden_in('material'), trim(garden_in('quantity')), garden_in('unit'), garden_in('notes'));
        return ['Saved for ' . count((array)$beds) . ' bed' . (count((array)$beds) === 1 ? '' : 's') . '.', '', 'form-care_beds'];
    }],
    'water_add' => [false, function () use ($pdo, $org, $water) {
        $water->createIrrigation($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('location_id', 'Choose a bed.'), trim(garden_in('interval_days')),
                                 garden_in('next_due_on'), garden_in('method'), garden_in('notes'));
        return ['Watering plan saved.', 'tab=water'];
    }],
    'water_done' => [false, function () use ($pdo, $org, $water) {
        $water->recordWatering($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('plan_id', 'Choose a watering plan.'), garden_in('watered_on'),
                               trim(garden_in('amount')), garden_in('unit'), garden_in('notes'));
        return ['Watered. Next date moved on.', 'tab=water'];
    }],
    'water_pause' => [false, function () use ($pdo, $org, $water) {
        $on = garden_in('active') === '1';
        $water->setIrrigationActive($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('plan_id', 'Choose a watering plan.'), $on);
        return [$on ? 'Watering plan back on.' : 'Watering plan paused.', 'tab=water'];
    }],
    'pest_add' => [false, function () use ($pdo, $org, $health) {
        $health->open($pdo, $org, garden_id('site_id', 'Choose a garden.'), garden_id('planting_id', 'Choose a planting.'), garden_in('category'),
                      garden_in('identified_as'), garden_in('severity', 'unknown'), garden_in('observed_on'), garden_in('notes'));
        return ['Problem logged.', 'tab=pests'];
    }],
    'pest_treat' => [false, function () use ($pdo, $org, $health) {
        $id = garden_id('incident_id', 'Choose a problem.');
        $health->intervene($pdo, $org, garden_id('site_id', 'Choose a garden.'), $id, garden_in('applied_on'), garden_in('method'), garden_in('product'),
                           trim(garden_in('quantity')), garden_in('unit'), garden_in('notes'));
        return ['Treatment saved.', 'tab=pests', 'pest-' . $id];
    }],
    'pest_status' => [false, function () use ($pdo, $org, $health) {
        $id = garden_id('incident_id', 'Choose a problem.');
        $health->updateOutcome($pdo, $org, garden_id('site_id', 'Choose a garden.'), $id, garden_in('status'), garden_in('resolved_on'), garden_in('outcome'));
        return ['Status saved.', 'tab=pests', 'pest-' . $id];
    }],
]);

[$sites, $site] = garden_site_pick($pdo, $org);
[$err, $errAct] = garden_open('Care', ['css' => ['garden.css']]);
$titles = ['water' => 'Watering', 'pests' => 'Pests & disease'];
echo '<h1 class="', $ui['h1'], '">', $titles[$tab] ?? 'Log care', '</h1>';
if (!$site) { echo garden_need_site(); bcrf_shell_close(); exit; }
$sid = (int)$site['id'];
$own = ['' => ['care_log', 'care_beds'], 'water' => ['water_add', 'water_done', 'water_pause'], 'pests' => ['pest_add', 'pest_treat', 'pest_status']];
if ($err !== '' && !in_array($errAct, $own[$tab] ?? [], true)) echo bcrf_shell_notice('err', $err);
echo garden_site_pills($sites, $site, 'care.php', $tab);
$areas = (new GardenService())->locations($pdo, $org, $sid);
if (!$areas) { echo garden_need_site(); bcrf_shell_close(); exit; }
$names = garden_area_options(array_filter($areas, fn($a) => (int)($a['active'] ?? 1) === 1));
$plantings = (new GrowingRecords())->plantings($pdo, $org, $sid);
$current = garden_planting_options($plantings);
$today = bcrf_today();
$hid = fn(string $act, array $x = []) => garden_hidden($act, ['site_id' => $sid] + $x);

// ── Watering ──
if ($tab === 'water') {
    $plans = $water->irrigation($pdo, $org, $sid);
    $done = array_slice($water->events($pdo, $org, $sid), 0, 30);
    $t = garden_typed('water_add'); ?>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Watering plans</h2>
    <?php if (!$plans): ?><p class="<?= $ui['muted'] ?>">No watering plans yet. Add one below for each bed you water on a schedule.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
    <?php foreach ($plans as $w): $wid = (int)$w['id']; $due = (int)$w['active'] === 1 && $w['next_due_on'] <= $today; ?>
      <li id="water-<?= $wid ?>" class="py-3 text-sm">
        <div class="flex flex-wrap justify-between gap-2"><span><strong><?= $h($names[(int)$w['location_id']] ?? $w['location_name']) ?></strong> · <?= $h($w['method']) ?> · every <?= (int)$w['interval_days'] ?> day<?= (int)$w['interval_days'] === 1 ? '' : 's' ?></span>
          <span class="<?= $due ? 'text-red-700 font-semibold' : 'text-stone-600' ?>"><?= (int)$w['active'] === 1 ? ($due ? 'Due ' : 'Next ') . $h(garden_date($w['next_due_on'])) : 'Paused' ?></span></div>
        <?php if ((int)$w['active'] === 1): ?>
        <form method="POST" action="care.php" data-submit-once class="mt-2 grid grid-cols-2 sm:grid-cols-5 gap-2 items-end"><?= $hid('water_done', ['plan_id' => $wid, 'back_query' => 'tab=water', 'back_anchor' => 'water-' . $wid]) ?>
          <div><label class="<?= $ui['label'] ?>" for="wd<?= $wid ?>">Watered on</label><input id="wd<?= $wid ?>" name="watered_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($today) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="wa<?= $wid ?>">How much</label><input id="wa<?= $wid ?>" name="amount" inputmode="decimal" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="wu<?= $wid ?>">Unit</label><input id="wu<?= $wid ?>" name="unit" maxlength="32" placeholder="gal, min" class="<?= $ui['input'] ?>"></div>
          <div class="col-span-2 sm:col-span-1"><label class="<?= $ui['label'] ?>" for="wn<?= $wid ?>">Notes</label><input id="wn<?= $wid ?>" name="notes" maxlength="2000" class="<?= $ui['input'] ?>"></div>
          <div><button class="<?= $ui['btnMain'] ?>">Mark watered</button></div>
        </form>
        <?php endif; ?>
        <form method="POST" action="care.php" class="mt-2"><?= $hid('water_pause', ['plan_id' => $wid, 'active' => (int)$w['active'] === 1 ? '0' : '1', 'back_query' => 'tab=water', 'back_anchor' => 'water-' . $wid]) ?>
          <button class="text-xs <?= $ui['link'] ?>"><?= (int)$w['active'] === 1 ? 'Pause this plan' : 'Turn this plan back on' ?></button></form>
        <div class="gm-delrow"><?= garden_delete_button('care.php', 'water_plan', $wid, 'Delete this watering plan and its watering log?', 'tab=water') ?></div>
      </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <details class="<?= $ui['sec'] ?>" <?= $t || !$plans ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Add a watering plan <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-water_add" method="POST" action="care.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'water_add') ?><?= $hid('water_add', ['back_query' => 'tab=water']) ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="wl">Bed</label><select id="wl" name="location_id" required class="<?= $ui['input'] ?>"><?= garden_options($names, $t['location_id'] ?? '', 'Choose…') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="wm">How</label><input id="wm" name="method" maxlength="80" required placeholder="Drip line, hose, sprinkler" value="<?= $h($t['method'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="wi">Every (days)</label><input id="wi" name="interval_days" type="number" min="1" max="365" required value="<?= $h($t['interval_days'] ?? '2') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="wx">First due</label><input id="wx" name="next_due_on" type="date" required value="<?= $h($t['next_due_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <div class="mb-3"><label class="<?= $ui['label'] ?>" for="wo">Notes</label><input id="wo" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <button class="<?= $ui['btnMain'] ?>">Add plan</button>
    </form>
  </details>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Recent watering</h2>
    <?php if (!$done): ?><p class="<?= $ui['muted'] ?>">Nothing logged yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100"><?php foreach ($done as $e): ?><li class="py-2 text-sm"><?= $h(garden_date($e['watered_on'])) ?> · <?= $h($e['location_name']) ?><?= $e['amount'] !== null ? ' · ' . $h(garden_num($e['amount'], 3) . ' ' . $e['unit']) : '' ?><?= $e['notes'] ? ' · ' . $h($e['notes']) : '' ?> <?= garden_delete_button('care.php', 'water_log', (int)$e['id'], 'Delete this watering?', 'tab=water') ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Pests & disease ──
if ($tab === 'pests') {
    $rows = $health->incidents($pdo, $org, $sid);
    $treat = []; foreach ($health->interventions($pdo, $org, $sid) as $x) $treat[(int)$x['incident_id']][] = $x;
    $sev = ['unknown' => 'Not sure yet', 'low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High', 'critical' => 'Critical'];
    $stat = ['open' => 'Open', 'monitoring' => 'Watching', 'resolved' => 'Resolved'];
    $failed = (int)((garden_typed('pest_treat') ?: garden_typed('pest_status'))['incident_id'] ?? 0);
    $t = garden_typed('pest_add'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Log a pest or disease <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <?php if (!$current): ?><p class="p-4 text-sm text-stone-600">Problems are logged on a planting. Add one on <a class="<?= $ui['link'] ?>" href="plantings.php">Plantings</a> first.</p><?php else: ?>
    <form id="form-pest_add" method="POST" action="care.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'pest_add') ?><?= $hid('pest_add', ['back_query' => 'tab=pests']) ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pp">Planting</label><select id="pp" name="planting_id" required class="<?= $ui['input'] ?>"><?= garden_options($current, $t['planting_id'] ?? ($_GET['planting'] ?? ''), 'Choose…') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pi">What is it</label><input id="pi" name="identified_as" maxlength="160" required placeholder="Squash bugs, powdery mildew" value="<?= $h($t['identified_as'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="pc">Kind</label><select id="pc" name="category" class="<?= $ui['input'] ?>"><?= garden_options(['pest' => 'Pest', 'disease' => 'Disease'], $t['category'] ?? 'pest') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="pv">How bad</label><select id="pv" name="severity" class="<?= $ui['input'] ?>"><?= garden_options($sev, $t['severity'] ?? 'unknown') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="po">Spotted on</label><input id="po" name="observed_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['observed_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <div class="mb-3"><label class="<?= $ui['label'] ?>" for="pn">Notes</label><input id="pn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <button class="<?= $ui['btnMain'] ?>">Log problem</button>
    </form>
    <?php endif; ?>
  </details>
  <?php if (!$rows): ?><div class="<?= $ui['card'] ?>"><p class="<?= $ui['muted'] ?>">No problems logged.</p></div><?php endif; ?>
  <?php foreach ($rows as $r): $iid = (int)$r['id']; $closed = $r['status'] === 'resolved'; ?>
  <details id="pest-<?= $iid ?>" class="<?= $ui['sec'] ?>" <?= $failed === $iid ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>"><span><?= $h($r['identified_as']) ?> <span class="font-normal text-stone-500">· <?= $h($r['location_name'] . ' · ' . $r['crop_name']) ?> · <?= $h($sev[$r['severity']] ?? $r['severity']) ?> · <?= $h($stat[$r['status']] ?? $r['status']) ?></span></span><span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <div class="p-4 text-sm">
      <p class="mb-2">Spotted <?= $h(garden_date($r['observed_on'])) ?> on <a class="<?= $ui['link'] ?>" href="plantings.php?id=<?= (int)$r['planting_id'] ?>"><?= $h($r['crop_name'] . ' — ' . $r['variety_name']) ?></a>.<?= $r['notes'] ? ' ' . $h($r['notes']) : '' ?></p>
      <?php if ($closed): ?><p class="mb-2">Resolved <?= $h(garden_date($r['resolved_on'])) ?>: <?= $h($r['outcome']) ?></p><?php endif; ?>
      <?php if (!empty($treat[$iid])): ?><ul class="mb-3 list-disc pl-5"><?php foreach ($treat[$iid] as $x): ?><li><?= $h(garden_date($x['applied_on'])) ?> · <?= $h($x['method']) ?><?= $x['product'] ? ' · ' . $h($x['product']) : '' ?><?= $x['quantity'] !== null ? ' · ' . $h(garden_num($x['quantity'], 3) . ' ' . $x['unit']) : '' ?> <?= garden_delete_button('care.php', 'treatment', (int)$x['id'], 'Delete this treatment?', 'tab=pests', 'pest-' . $iid) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if (!$closed): ?>
      <form method="POST" action="care.php" data-submit-once class="grid grid-cols-2 sm:grid-cols-5 gap-2 items-end mb-3"><?= $hid('pest_treat', ['incident_id' => $iid, 'back_query' => 'tab=pests', 'back_anchor' => 'pest-' . $iid]) ?>
        <?= $failed === $iid ? garden_form_error($err, $errAct, 'pest_treat') : '' ?>
        <div><label class="<?= $ui['label'] ?>" for="ta<?= $iid ?>">Done on</label><input id="ta<?= $iid ?>" name="applied_on" type="date" required min="<?= $h($r['observed_on']) ?>" max="<?= $h($today) ?>" value="<?= $h($today) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="tm<?= $iid ?>">What you did</label><input id="tm<?= $iid ?>" name="method" maxlength="160" required placeholder="Hand-picked, sprayed" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="tp<?= $iid ?>">Product</label><input id="tp<?= $iid ?>" name="product" maxlength="160" class="<?= $ui['input'] ?>"></div>
        <div class="grid grid-cols-2 gap-1"><div><label class="<?= $ui['label'] ?>" for="tq<?= $iid ?>">Amount</label><input id="tq<?= $iid ?>" name="quantity" inputmode="decimal" class="<?= $ui['input'] ?>"></div><div><label class="<?= $ui['label'] ?>" for="tu<?= $iid ?>">Unit</label><input id="tu<?= $iid ?>" name="unit" maxlength="32" class="<?= $ui['input'] ?>"></div></div>
        <div><button class="<?= $ui['btnSec'] ?>">Save treatment</button></div>
      </form>
      <?php endif; ?>
      <form method="POST" action="care.php" data-submit-once class="grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><?= $hid('pest_status', ['incident_id' => $iid, 'back_query' => 'tab=pests', 'back_anchor' => 'pest-' . $iid]) ?>
        <?= $failed === $iid ? garden_form_error($err, $errAct, 'pest_status') : '' ?>
        <div><label class="<?= $ui['label'] ?>" for="ss<?= $iid ?>">Status</label><select id="ss<?= $iid ?>" name="status" class="<?= $ui['input'] ?>"><?= garden_options($stat, $r['status']) ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="sr<?= $iid ?>">Resolved on</label><input id="sr<?= $iid ?>" name="resolved_on" type="date" min="<?= $h($r['observed_on']) ?>" max="<?= $h($today) ?>" value="<?= $h($r['resolved_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="so<?= $iid ?>">Outcome</label><input id="so<?= $iid ?>" name="outcome" maxlength="2000" value="<?= $h($r['outcome']) ?>" placeholder="Needed when resolved" class="<?= $ui['input'] ?>"></div>
        <div><button class="<?= $ui['btnSec'] ?>">Save status</button></div>
      </form>
      <div class="gm-delrow"><?= garden_delete_button('care.php', 'pest', $iid, 'Delete this problem and its treatments?', 'tab=pests') ?></div>
    </div>
  </details>
  <?php endforeach;
  bcrf_shell_close(); exit; }

// ── Log care ──
$kinds = ['weeding' => 'Weeded', 'fertilizing' => 'Fed / fertilized', 'pruning' => 'Pruned', 'watering' => 'Watered (one-off)', 'treatment' => 'Treated',
          'pest_observation' => 'Saw pests', 'disease_observation' => 'Saw disease', 'other' => 'Other'];
$bedKinds = ['soil_amendment' => 'Added compost / amendment', 'mulching' => 'Mulched', 'fertilizing' => 'Fed / fertilized', 'weeding' => 'Weeded', 'pruning' => 'Pruned', 'other' => 'Other'];
$pre = (int)($_GET['planting'] ?? 0);
$t = garden_typed('care_log'); ?>
  <form id="form-care_log" method="POST" action="care.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'care_log') ?><?= $hid('care_log') ?>
    <h2 class="<?= $ui['h2'] ?>">One planting</h2>
    <?php if (!$current): ?><p class="<?= $ui['muted'] ?>">Nothing planned or growing. Add a planting on <a class="<?= $ui['link'] ?>" href="plantings.php">Plantings</a>.</p><?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="cp">Planting</label><select id="cp" name="planting_id" required class="<?= $ui['input'] ?>"><?= garden_options($current, $t['planting_id'] ?? ($pre ?: ''), 'Choose…') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="ck">What you did</label><select id="ck" name="kind" class="<?= $ui['input'] ?>"><?= garden_options($kinds, $t['kind'] ?? 'weeding') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="cd">Date</label><input id="cd" name="occurred_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['occurred_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <div class="mb-3"><label class="<?= $ui['label'] ?>" for="cn">Notes</label><input id="cn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    <button class="<?= $ui['btnMain'] ?>">Save care</button>
    <p class="text-xs text-stone-500 mt-2">For pests or disease you want to follow up on, use <a class="<?= $ui['link'] ?>" href="care.php?tab=pests">Pests &amp; disease</a>.</p>
    <?php endif; ?>
  </form>
<?php $t = garden_typed('care_beds'); $picked = array_map('intval', (array)($t['location_ids'] ?? [])); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Several beds at once <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-care_beds" method="POST" action="care.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'care_beds') ?><?= $hid('care_beds') ?>
      <fieldset class="mb-3"><legend class="<?= $ui['label'] ?>">Beds</legend>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1">
        <?php foreach ($names as $lid => $nm): ?><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="location_ids[]" value="<?= (int)$lid ?>" <?= in_array((int)$lid, $picked, true) ? 'checked' : '' ?>> <?= $h($nm) ?></label><?php endforeach; ?>
        </div>
      </fieldset>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div><label class="<?= $ui['label'] ?>" for="bk">What you did</label><select id="bk" name="kind" class="<?= $ui['input'] ?>"><?= garden_options($bedKinds, $t['kind'] ?? 'soil_amendment') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="bd">Date</label><input id="bd" name="applied_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['applied_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="bm">Material</label><input id="bm" name="material" maxlength="160" placeholder="Compost, straw, 10-10-10" value="<?= $h($t['material'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="bq">Amount</label><input id="bq" name="quantity" inputmode="decimal" value="<?= $h($t['quantity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="bu">Unit</label><input id="bu" name="unit" maxlength="32" placeholder="bags, lb" value="<?= $h($t['unit'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <div class="mb-3"><label class="<?= $ui['label'] ?>" for="bn">Notes</label><input id="bn" name="notes" maxlength="2000" value="<?= $h($t['notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <button class="<?= $ui['btnMain'] ?>">Save for these beds</button>
    </form>
  </details>
<?php
$log = array_slice($journal->careEvents($pdo, $org, $sid), 0, 40);
if ($pre) $log = array_values(array_filter($log, fn($e) => (int)$e['planting_id'] === $pre));
$bedLog = array_slice($apps->recent($pdo, $org, $sid), 0, 20); ?>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>"><?= $pre ? 'Care for this planting' : 'Recent care' ?></h2>
    <?php if (!$log): ?><p class="<?= $ui['muted'] ?>">Nothing logged yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100"><?php foreach ($log as $e): ?><li class="py-2 text-sm"><?= $h(garden_date($e['occurred_on'])) ?> · <strong><?= $h($kinds[$e['kind']] ?? garden_label($e['kind'])) ?></strong> · <a class="<?= $ui['link'] ?>" href="plantings.php?id=<?= (int)$e['planting_id'] ?>"><?= $h($e['location_name'] . ' · ' . $e['crop_name']) ?></a><?= $e['notes'] ? '<span class="block text-stone-600">' . $h($e['notes']) . '</span>' : '' ?> <?= garden_delete_button('care.php', 'care', (int)$e['id'], 'Delete this care entry?', $pre ? 'planting=' . $pre : '') ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
  <?php if (!$pre && $bedLog): ?>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Bed jobs</h2>
    <ul class="divide-y divide-stone-100"><?php foreach ($bedLog as $a): ?><li class="py-2 text-sm"><?= $h(garden_date($a['applied_on'])) ?> · <strong><?= $h($bedKinds[$a['kind']] ?? garden_label($a['kind'])) ?></strong><?= $a['material'] ? ' · ' . $h($a['material']) : '' ?><?= $a['quantity'] !== null ? ' · ' . $h(garden_num($a['quantity'], 3) . ' ' . $a['unit']) : '' ?><span class="block text-stone-600"><?= $h($a['area_names']) ?></span> <?= garden_delete_button('care.php', 'bed_job', (int)$a['id'], 'Delete this bed job (for every bed it was logged on)?') ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif;
bcrf_shell_close();
