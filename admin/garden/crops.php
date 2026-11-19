<?php
// admin/garden/crops.php — Garden › Crops (Nov 18, 2026; includes/garden.php, includes/garden_crops.php, migration 0072).
//   GET  crops.php                  every crop and variety: the shared starter list and the farm's own; add, edit, archive
//   GET  crops.php?tab=guides       growing guide per variety (sun, water, pH, spacing in inches, depth, germination, zones)
//   GET  crops.php?tab=planner      frost-date planner: start indoors, harden off, plant out, ready to pick; succession dates
//   POST act=crop_add | crop_save | crop_archive | variety_add | variety_save | variety_archive | shared_note | guide_save
//        (all garden_manage; the planner is read-only)
// Archiving hides a crop or variety from new records; everything already recorded keeps it.
require_once __DIR__ . '/../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';
require_permission([['view', 'garden']]);
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../../includes/garden.php';
require_once __DIR__ . '/../../includes/admin_shell.php';
date_default_timezone_set(bcrf_tz()->getName());

use Garden\Domain\CropCatalog;
use Garden\Domain\CustomCatalog;
use Garden\Domain\CropGrowingProfiles;
use Garden\Domain\SeasonGuidance;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    bcrf_shell_open('Crops — Garden');
    echo '<h1 class="', $ui['h1'], '">Crops</h1>', bcrf_shell_notice('warning', 'Run the garden migrations (0072, 0073 and 0074) in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
$cat = new CropCatalog();
$own = new CustomCatalog();
$prof = new CropGrowingProfiles();
$tab = (string)($_GET['tab'] ?? '');

garden_post($pdo, 'crops.php', [
    'crop_add' => [true, function () use ($pdo, $org, $own, $prof) {
        $id = $own->createCrop($pdo, $org, garden_in('name'), garden_in('lifecycle', 'annual'));
        if (trim(garden_in('crop_family')) !== '' || trim(garden_in('scientific_name')) !== '') $prof->updatePrivateCropTaxonomy($pdo, $org, $id, garden_in('crop_family'), garden_in('scientific_name'));
        $first = trim(garden_in('variety'));
        if ($first !== '') $own->createVariety($pdo, $org, $id, $first, garden_in('days_to_maturity') ?: null, garden_in('maturity_basis', 'unknown'));
        return ['Added ' . trim(garden_in('name')) . '.', '', '#crop-' . $id];
    }],
    'crop_save' => [true, function () use ($pdo, $org, $own, $prof) {
        $id = garden_id('crop_id', 'Choose a crop.');
        $own->updateCrop($pdo, $org, $id, garden_in('name'), garden_in('lifecycle', 'annual'));
        $prof->updatePrivateCropTaxonomy($pdo, $org, $id, garden_in('crop_family'), garden_in('scientific_name'));
        return ['Saved ' . trim(garden_in('name')) . '.', '', '#crop-' . $id];
    }],
    'crop_archive' => [true, function () use ($pdo, $org, $own) {
        $own->archiveCrop($pdo, $org, garden_id('crop_id', 'Choose a crop.'));
        return 'Crop archived. Past records keep it.';
    }],
    'variety_add' => [true, function () use ($pdo, $org, $own) {
        $crop = garden_id('crop_id', 'Choose a crop.');
        $own->createVariety($pdo, $org, $crop, garden_in('name'), garden_in('days_to_maturity') ?: null, garden_in('maturity_basis', 'unknown'));
        return ['Variety added.', '', '#crop-' . $crop];
    }],
    'variety_save' => [true, function () use ($pdo, $org, $own) {
        $own->updateVariety($pdo, $org, garden_id('variety_id', 'Choose a variety.'), garden_in('name'), garden_in('days_to_maturity') ?: null, garden_in('maturity_basis', 'unknown'));
        return ['Variety saved.', '', '#crop-' . (int)($_POST['crop_id'] ?? 0)];
    }],
    'variety_archive' => [true, function () use ($pdo, $org, $own) {
        $own->archiveVariety($pdo, $org, garden_id('variety_id', 'Choose a variety.'));
        return 'Variety archived. Past records keep it.';
    }],
    'shared_unhide' => [true, function () use ($pdo, $org) {
        garden_variety_unhide_all($pdo, $org);
        return 'Starter crops are back on your list.';
    }],
    'shared_note' => [true, function () use ($pdo, $org, $cat) {
        $cat->saveVarietyPreference($pdo, $org, garden_id('variety_id', 'Choose a variety.'), garden_in('display_name'), garden_in('days_to_maturity') ?: null, garden_in('maturity_basis', 'unknown'), garden_in('notes'));
        return 'Saved for this farm.';
    }],
    'guide_save' => [true, function () use ($pdo, $org, $prof) {
        $v = garden_in('variety');
        $prof->save($pdo, $org, $v, [
            'sun_exposure' => garden_in('sun_exposure', 'unknown'), 'water_need' => garden_in('water_need', 'unknown'),
            'soil_ph_min' => trim(garden_in('soil_ph_min')), 'soil_ph_max' => trim(garden_in('soil_ph_max')),
            'spacing_cm' => garden_guide_cm('spacing_in'), 'row_spacing_cm' => garden_guide_cm('row_spacing_in'), 'sow_depth_cm' => garden_guide_cm('sow_depth_in'),
            'germination_min_days' => trim(garden_in('germination_min_days')), 'germination_max_days' => trim(garden_in('germination_max_days')),
            'hardiness_min_zone' => trim(garden_in('hardiness_min_zone')), 'hardiness_max_zone' => trim(garden_in('hardiness_max_zone')),
            'notes' => garden_in('notes'),
        ]);
        return ['Growing guide saved.', 'tab=guides&variety=' . rawurlencode($v)];
    }],
]);

/** Inches from a guide field -> cm with one decimal (the class allows one). */
function garden_guide_cm(string $key): string {
    $cm = garden_in_to_cm(garden_in($key));
    return $cm === '' ? '' : number_format(round((float)$cm, 1), 1, '.', '');
}

$manage = garden_can_manage();
$shared = $cat->varietiesForOrganization($pdo, $org);
$ownCrops = $own->crops($pdo, $org);
$ownVars = $own->varieties($pdo, $org);
$bases = ['unknown' => 'Not sure', 'from_sowing' => 'From sowing', 'from_transplant' => 'From transplant'];
$cycles = ['annual' => 'Annual', 'biennial' => 'Biennial', 'perennial' => 'Perennial'];
[$err, $errAct] = garden_open('Crops', ['css' => ['garden.css']]);
echo '<h1 class="', $ui['h1'], '">', $tab === 'guides' ? 'Growing guides' : ($tab === 'planner' ? 'Planting planner' : 'Crops'), '</h1>';
if ($err !== '' && !in_array($errAct, ['crop_add', 'crop_save', 'guide_save', 'shared_note', 'variety_add', 'variety_save'], true)) echo bcrf_shell_notice('err', $err);
$opts = garden_variety_options($pdo, $org);
$optMap = []; foreach ($opts as $o) $optMap[$o['value']] = $o['label'];

// ── Growing guides ──
if ($tab === 'guides') {
    $profiles = [];
    foreach ($prof->all($pdo, $org) as $p) $profiles[$p['organization_variety_id'] ? 'custom:' . $p['organization_variety_id'] : (string)$p['variety_id']] = $p;
    $sel = (string)($_GET['variety'] ?? (garden_typed('guide_save')['variety'] ?? ''));
    $t = garden_typed('guide_save');
    $p = $profiles[$sel] ?? [];
    $val = fn(string $k, $def = '') => $t[$k] ?? $def;
    $sun = ['unknown' => 'Not sure', 'full_sun' => 'Full sun', 'partial_shade' => 'Part shade', 'shade' => 'Shade'];
    $water = ['unknown' => 'Not sure', 'low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High'];
    if (!$opts) { echo '<div class="', $ui['card'], '"><p class="', $ui['muted'], '">Add a crop first.</p></div>'; bcrf_shell_close(); exit; } ?>
  <form method="GET" action="crops.php" class="flex flex-wrap gap-2 items-end mb-4"><input type="hidden" name="tab" value="guides">
    <div class="flex-1 min-w-48"><label class="<?= $ui['label'] ?>" for="gv">Variety</label><select id="gv" name="variety" class="<?= $ui['input'] ?>"><?= garden_options($optMap, $sel, 'Choose…') ?></select></div>
    <button class="<?= $ui['btnSec'] ?>">Show</button>
  </form>
  <?php if ($sel !== '' && isset($optMap[$sel])): ?>
  <form id="form-guide_save" method="POST" action="crops.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'guide_save') ?><?= garden_hidden('guide_save', ['variety' => $sel]) ?>
    <h2 class="<?= $ui['h2'] ?>"><?= $h($optMap[$sel]) ?></h2>
    <fieldset <?= $manage ? '' : 'disabled' ?>>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="gs">Sun</label><select id="gs" name="sun_exposure" class="<?= $ui['input'] ?>"><?= garden_options($sun, $val('sun_exposure', $p['sun_exposure'] ?? 'unknown')) ?></select></div>
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="gw">Water</label><select id="gw" name="water_need" class="<?= $ui['input'] ?>"><?= garden_options($water, $val('water_need', $p['water_need'] ?? 'unknown')) ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="g1">Plant spacing (in)</label><input id="g1" name="spacing_in" inputmode="decimal" value="<?= $h($val('spacing_in', garden_cm_to_in($p['spacing_cm'] ?? null))) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="g2">Row spacing (in)</label><input id="g2" name="row_spacing_in" inputmode="decimal" value="<?= $h($val('row_spacing_in', garden_cm_to_in($p['row_spacing_cm'] ?? null))) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="g3">Sow depth (in)</label><input id="g3" name="sow_depth_in" inputmode="decimal" value="<?= $h($val('sow_depth_in', garden_cm_to_in($p['sow_depth_cm'] ?? null))) ?>" class="<?= $ui['input'] ?>"></div>
      <div></div>
      <div><label class="<?= $ui['label'] ?>" for="g4">Soil pH from</label><input id="g4" name="soil_ph_min" inputmode="decimal" value="<?= $h($val('soil_ph_min', $p['soil_ph_min'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="g5">to</label><input id="g5" name="soil_ph_max" inputmode="decimal" value="<?= $h($val('soil_ph_max', $p['soil_ph_max'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="g6">Sprouts in (days) from</label><input id="g6" name="germination_min_days" type="number" min="1" max="365" value="<?= $h($val('germination_min_days', $p['germination_min_days'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="g7">to</label><input id="g7" name="germination_max_days" type="number" min="1" max="365" value="<?= $h($val('germination_max_days', $p['germination_max_days'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="g8">Hardiness zone from</label><input id="g8" name="hardiness_min_zone" type="number" min="1" max="13" value="<?= $h($val('hardiness_min_zone', $p['hardiness_min_zone'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="g9">to</label><input id="g9" name="hardiness_max_zone" type="number" min="1" max="13" value="<?= $h($val('hardiness_max_zone', $p['hardiness_max_zone'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <div class="mb-3"><label class="<?= $ui['label'] ?>" for="gn">Notes</label><textarea id="gn" name="notes" rows="3" maxlength="2000" class="<?= $ui['input'] ?>"><?= $h($val('notes', $p['notes'] ?? '')) ?></textarea></div>
    <?php if ($manage): ?><button class="<?= $ui['btnMain'] ?>">Save guide</button><?php endif; ?>
    </fieldset>
  </form>
  <?php endif; ?>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Varieties with a guide</h2>
    <?php $have = array_intersect_key($optMap, $profiles); if (!$have): ?><p class="<?= $ui['muted'] ?>">None yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100"><?php foreach ($have as $v => $l): ?><li class="py-2 text-sm"><a class="<?= $ui['link'] ?>" href="crops.php?tab=guides&amp;variety=<?= $h(rawurlencode((string)$v)) ?>"><?= $h($l) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Planner (read-only) ──
if ($tab === 'planner') {
    [$sites, $site] = garden_site_pick($pdo, $org);
    $g = new SeasonGuidance();
    $spring = (string)($site['last_spring_frost'] ?? ''); $fall = (string)($site['first_fall_frost'] ?? '');
    $sel = (string)($_GET['variety'] ?? '');
    $days = (int)($_GET['days'] ?? 0);
    if (!$days) foreach ($opts as $o) if ($o['value'] === $sel && $o['days']) $days = (int)$o['days'];
    $weeks = (int)($_GET['weeks'] ?? 6); $after = (int)($_GET['after'] ?? 14); $harden = (int)($_GET['harden'] ?? 7);
    $every = (int)($_GET['every'] ?? 14); $rounds = (int)($_GET['rounds'] ?? 4); $start = (string)($_GET['start'] ?? '');
    echo garden_site_pills($sites, $site, 'crops.php', 'planner');
    if (!$spring || !$fall) {
        echo '<div class="', $ui['card'], '"><p class="text-sm text-stone-600">Set the average frost dates for ', $h($site['name'] ?? 'the garden'), ' first, in <a class="', $ui['link'], '" href="/admin/garden/areas.php">Beds &amp; areas › Garden settings</a>.</p></div>';
    } ?>
  <form method="GET" action="crops.php" class="<?= $ui['card'] ?>"><input type="hidden" name="tab" value="planner"><input type="hidden" name="site" value="<?= (int)($site['id'] ?? 0) ?>">
    <h2 class="<?= $ui['h2'] ?>">Start indoors, then plant out</h2>
    <p class="<?= $ui['muted'] ?> mb-3">Frost dates: spring <?= $h($spring ? garden_date($spring) : 'not set') ?>, fall <?= $h($fall ? garden_date($fall) : 'not set') ?>.</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pv">Variety</label><select id="pv" name="variety" class="<?= $ui['input'] ?>"><?= garden_options($optMap, $sel, 'Choose…') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="pd">Days to pick</label><input id="pd" name="days" type="number" min="1" max="365" value="<?= $days ?: '' ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="pw">Weeks indoors</label><input id="pw" name="weeks" type="number" min="1" max="20" value="<?= $weeks ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="pa">Plant out, days after frost</label><input id="pa" name="after" type="number" min="0" max="180" value="<?= $after ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="ph">Harden off (days)</label><input id="ph" name="harden" type="number" min="0" max="21" value="<?= $harden ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <button class="<?= $ui['btnSec'] ?>">Work out dates</button>
    <?php if ($spring && $fall && $days > 0):
        try { $w = $g->transplant($spring, $fall, $weeks, $after, $harden, $days); ?>
    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 text-sm">
      <div><dt class="text-stone-500">Start seeds indoors</dt><dd class="font-semibold"><?= $h(garden_date($w['indoor_sow'])) ?></dd></div>
      <div><dt class="text-stone-500">Start hardening off</dt><dd class="font-semibold"><?= $h(garden_date($w['harden_off_start'])) ?></dd></div>
      <div><dt class="text-stone-500">Plant out</dt><dd class="font-semibold"><?= $h(garden_date($w['outdoor_plant'])) ?></dd></div>
      <div><dt class="text-stone-500">Ready to pick about</dt><dd class="font-semibold <?= $w['before_fall_frost'] ? '' : 'text-red-700' ?>"><?= $h(garden_date($w['estimated_maturity'])) ?><?= $w['before_fall_frost'] ? '' : ' (after fall frost)' ?></dd></div>
    </dl>
    <?php } catch (InvalidArgumentException $e) { echo bcrf_shell_notice('err', $e->getMessage()); } endif; ?>
  </form>
  <form method="GET" action="crops.php" class="<?= $ui['card'] ?>"><input type="hidden" name="tab" value="planner"><input type="hidden" name="site" value="<?= (int)($site['id'] ?? 0) ?>">
    <h2 class="<?= $ui['h2'] ?>">Succession sowing dates</h2>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div><label class="<?= $ui['label'] ?>" for="ss">First sowing</label><input id="ss" name="start" type="date" value="<?= $h($start) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="se">Every (days)</label><input id="se" name="every" type="number" min="1" max="365" value="<?= $every ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="sr">Sowings</label><input id="sr" name="rounds" type="number" min="2" max="24" value="<?= $rounds ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <button class="<?= $ui['btnSec'] ?>">Work out dates</button>
    <?php if ($start !== ''):
        try { $dates = $g->succession($start, $every, $rounds, $fall); ?>
    <p class="text-sm mt-3"><?= $h(implode(' · ', array_map('garden_date', $dates))) ?><?= count($dates) < $rounds && $fall ? ' <span class="text-stone-500">(stops before the fall frost)</span>' : '' ?></p>
    <p class="text-xs text-stone-500 mt-1">To keep these as a plan, add a succession on <a class="<?= $ui['link'] ?>" href="/admin/garden/plantings.php?tab=succession">Plantings › Successions</a>.</p>
    <?php } catch (InvalidArgumentException $e) { echo bcrf_shell_notice('err', $e->getMessage()); } endif; ?>
  </form>
<?php bcrf_shell_close(); exit; }

// ── Crops list ──
$crops = [];   // key => [name, family, lifecycle, own crop row|null, varieties[]]
$hiddenN = 0;
foreach ($shared as $v) {
    if (!empty($v['hidden_at'])) { $hiddenN++; continue; }   // taken off this farm's list (Nov 19, 2026)
    $k = 's' . $v['crop_id'];
    $crops[$k] ??= ['name' => $v['crop_name'], 'family' => $v['crop_family'], 'life' => $v['lifecycle'], 'own' => null, 'crop_id' => (int)$v['crop_id'], 'vars' => []];
    $crops[$k]['vars'][] = ['shared' => true] + $v;
}
foreach ($ownCrops as $c) {
    if ($c['archived_at']) continue;
    $crops['o' . $c['id']] = ['name' => $c['name'], 'family' => $c['crop_family'], 'life' => $c['lifecycle'], 'own' => $c, 'vars' => []];
}
foreach ($ownVars as $v) if (!$v['archived_at'] && isset($crops['o' . $v['crop_id']])) $crops['o' . $v['crop_id']]['vars'][] = ['shared' => false] + $v;
uasort($crops, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$days = fn($d, $b) => $d ? (int)$d . ' days' . ($b && $b !== 'unknown' ? ' ' . strtolower($bases[$b] ?? '') : '') : '';
?>
  <div class="<?= $ui['card'] ?>">
    <?php if (!$crops): ?><p class="<?= $ui['muted'] ?>">No crops yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
    <?php foreach ($crops as $k => $c): $oc = $c['own']; $cid = $oc ? (int)$oc['id'] : 0; $t = ($oc && (int)(garden_typed('crop_save')['crop_id'] ?? 0) === $cid) ? garden_typed('crop_save') : []; ?>
      <li id="<?= $oc ? 'crop-' . $cid : 'shared-' . $h($k) ?>" class="py-2"><details <?= $t || ($oc && (int)(garden_typed('variety_add')['crop_id'] ?? 0) === $cid) ? 'open' : '' ?>>
        <summary class="cursor-pointer text-sm"><strong><?= $h($c['name']) ?></strong> <span class="text-stone-500">· <?= $h($cycles[$c['life']] ?? $c['life']) ?><?= $c['family'] ? ' · ' . $h($c['family']) : '' ?> · <?= count($c['vars']) ?> variet<?= count($c['vars']) === 1 ? 'y' : 'ies' ?><?= $oc ? '' : ' · starter list' ?></span></summary>
        <ul class="mt-2 ml-4 text-sm">
          <?php foreach ($c['vars'] as $v): $vn = $v['shared'] ? ($v['local_name'] ?: $v['name']) : $v['name']; $vd = $v['shared'] ? ($v['local_days_to_maturity'] ?? $v['days_to_maturity']) : $v['days_to_maturity']; $vb = $v['shared'] ? ($v['local_maturity_basis'] ?? $v['maturity_basis']) : $v['maturity_basis']; ?>
          <li class="py-1"><details><summary class="cursor-pointer"><?= $h($vn) ?><?= $vd ? ' <span class="text-stone-500">· ' . $h($days($vd, $vb)) . '</span>' : '' ?></summary>
            <?php if ($manage && !$v['shared']): ?>
            <form method="POST" action="crops.php" data-submit-once class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><?= garden_hidden('variety_save', ['variety_id' => (int)$v['id'], 'crop_id' => $cid, 'back_anchor' => 'crop-' . $cid]) ?>
              <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="vn<?= (int)$v['id'] ?>">Variety name</label><input id="vn<?= (int)$v['id'] ?>" name="name" maxlength="160" required value="<?= $h($v['name']) ?>" class="<?= $ui['input'] ?>"></div>
              <div><label class="<?= $ui['label'] ?>" for="vd<?= (int)$v['id'] ?>">Days to pick</label><input id="vd<?= (int)$v['id'] ?>" name="days_to_maturity" type="number" min="1" max="999" value="<?= $h($v['days_to_maturity']) ?>" class="<?= $ui['input'] ?>"></div>
              <div><label class="<?= $ui['label'] ?>" for="vb<?= (int)$v['id'] ?>">Counted</label><select id="vb<?= (int)$v['id'] ?>" name="maturity_basis" class="<?= $ui['input'] ?>"><?= garden_options($bases, $v['maturity_basis']) ?></select></div>
              <div class="col-span-2 sm:col-span-4 flex flex-wrap gap-2"><button class="<?= $ui['btnSec'] ?>">Save variety</button><a class="<?= $ui['btnSec'] ?>" href="crops.php?tab=guides&amp;variety=custom:<?= (int)$v['id'] ?>">Growing guide</a></div>
            </form>
            <form method="POST" action="crops.php" class="mt-1"><?= garden_hidden('variety_archive', ['variety_id' => (int)$v['id'], 'back_anchor' => 'crop-' . $cid]) ?><button class="text-xs text-amber-800 underline" data-confirm="Archive <?= $h($v['name']) ?>? It leaves the pickers; past records keep it.">Archive variety</button></form>
            <?= garden_delete_button('crops.php', 'variety', (int)$v['id'], 'Delete ' . $v['name'] . ' for good? Only works when no planting or seed lot uses it.', '', 'crop-' . $cid, 'Delete variety') ?>
            <?php elseif ($manage): ?>
            <form method="POST" action="crops.php" data-submit-once class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><?= garden_hidden('shared_note', ['variety_id' => (int)$v['id'], 'back_anchor' => 'shared-' . $k]) ?>
              <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="sn<?= (int)$v['id'] ?>">Name on this farm</label><input id="sn<?= (int)$v['id'] ?>" name="display_name" maxlength="160" value="<?= $h($v['local_name']) ?>" placeholder="<?= $h($v['name']) ?>" class="<?= $ui['input'] ?>"></div>
              <div><label class="<?= $ui['label'] ?>" for="sd<?= (int)$v['id'] ?>">Days to pick</label><input id="sd<?= (int)$v['id'] ?>" name="days_to_maturity" type="number" min="1" max="999" value="<?= $h($v['local_days_to_maturity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
              <div><label class="<?= $ui['label'] ?>" for="sb<?= (int)$v['id'] ?>">Counted</label><select id="sb<?= (int)$v['id'] ?>" name="maturity_basis" class="<?= $ui['input'] ?>"><?= garden_options($bases, $v['local_maturity_basis'] ?? 'unknown') ?></select></div>
              <div class="col-span-2 sm:col-span-4"><label class="<?= $ui['label'] ?>" for="so<?= (int)$v['id'] ?>">Notes</label><input id="so<?= (int)$v['id'] ?>" name="notes" maxlength="2000" value="<?= $h($v['local_notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
              <div class="col-span-2 sm:col-span-4 flex flex-wrap gap-2"><button class="<?= $ui['btnSec'] ?>">Save for this farm</button><a class="<?= $ui['btnSec'] ?>" href="crops.php?tab=guides&amp;variety=<?= (int)$v['id'] ?>">Growing guide</a></div>
            </form>
            <?= garden_delete_button('crops.php', 'shared_variety', (int)$v['id'], 'Take ' . $vn . ' off your list? Plantings and seed lots that use it keep it. You can bring it back at the bottom of this page.', '', 'shared-' . $k, 'Remove from my list') ?>
            <?php else: ?><a class="text-xs <?= $ui['link'] ?>" href="crops.php?tab=guides&amp;variety=<?= $v['shared'] ? (int)$v['id'] : 'custom:' . (int)$v['id'] ?>">Growing guide</a><?php endif; ?>
          </details></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$oc): ?><div class="ml-4 mt-1"><?= garden_delete_button('crops.php', 'shared_crop', (int)$c['crop_id'], 'Take ' . $c['name'] . ' and all its varieties off your list? Records that use them keep them. You can bring them back at the bottom of this page.', '', '', 'Remove ' . $c['name'] . ' from my list') ?></div><?php endif; ?>
        <?php if ($manage && $oc): $tv = (int)(garden_typed('variety_add')['crop_id'] ?? 0) === $cid ? garden_typed('variety_add') : []; ?>
        <form method="POST" action="crops.php" data-submit-once class="mt-3 ml-4 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end border-t border-stone-100 pt-3">
          <?= $tv ? garden_form_error($err, $errAct, 'variety_add') : '' ?><?= garden_hidden('variety_add', ['crop_id' => $cid, 'back_anchor' => 'crop-' . $cid]) ?>
          <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="nv<?= $cid ?>">New variety</label><input id="nv<?= $cid ?>" name="name" maxlength="160" required value="<?= $h($tv['name'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="nd<?= $cid ?>">Days to pick</label><input id="nd<?= $cid ?>" name="days_to_maturity" type="number" min="1" max="999" value="<?= $h($tv['days_to_maturity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
          <div><button class="<?= $ui['btnSec'] ?>">Add variety</button></div>
        </form>
        <form method="POST" action="crops.php" data-submit-once class="mt-3 ml-4 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end border-t border-stone-100 pt-3">
          <?= $t ? garden_form_error($err, $errAct, 'crop_save') : '' ?><?= garden_hidden('crop_save', ['crop_id' => $cid, 'back_anchor' => 'crop-' . $cid]) ?>
          <div><label class="<?= $ui['label'] ?>" for="cn<?= $cid ?>">Crop name</label><input id="cn<?= $cid ?>" name="name" maxlength="160" required value="<?= $h($t['name'] ?? $oc['name']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="cl<?= $cid ?>">Lives</label><select id="cl<?= $cid ?>" name="lifecycle" class="<?= $ui['input'] ?>"><?= garden_options($cycles, $t['lifecycle'] ?? $oc['lifecycle']) ?></select></div>
          <div><label class="<?= $ui['label'] ?>" for="cf<?= $cid ?>">Family (for rotation)</label><input id="cf<?= $cid ?>" name="crop_family" maxlength="120" placeholder="Solanaceae" value="<?= $h($t['crop_family'] ?? $oc['crop_family']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="cs<?= $cid ?>">Botanical name</label><input id="cs<?= $cid ?>" name="scientific_name" maxlength="200" value="<?= $h($t['scientific_name'] ?? $oc['scientific_name']) ?>" class="<?= $ui['input'] ?>"></div>
          <div class="col-span-2 sm:col-span-4"><button class="<?= $ui['btnSec'] ?>">Save crop</button></div>
        </form>
        <form method="POST" action="crops.php" class="mt-1 ml-4"><?= garden_hidden('crop_archive', ['crop_id' => $cid]) ?><button class="text-xs text-amber-800 underline" data-confirm="Archive <?= $h($oc['name']) ?> and its varieties? They leave the pickers; past records keep them.">Archive crop</button></form>
        <div class="ml-4 mt-1"><?= garden_delete_button('crops.php', 'crop', $cid, 'Delete ' . $oc['name'] . ' and its varieties for good? Only works when no planting or seed lot uses them.', '', '', 'Delete crop') ?></div>
        <?php endif; ?>
      </details></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php if ($manage && $hiddenN): ?>
  <form method="POST" action="crops.php" class="<?= $ui['card'] ?> text-sm"><?= garden_hidden('shared_unhide') ?>
    <?= $hiddenN ?> starter variet<?= $hiddenN === 1 ? 'y is' : 'ies are' ?> off your list. <button class="<?= $ui['link'] ?>">Bring them all back</button></form>
<?php endif; ?>
<?php if ($manage): $t = garden_typed('crop_add'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t || !$ownCrops ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Add a crop <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-crop_add" method="POST" action="crops.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'crop_add') ?><?= garden_hidden('crop_add') ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="an">Crop</label><input id="an" name="name" maxlength="160" required placeholder="Tomato" value="<?= $h($t['name'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="al">Lives</label><select id="al" name="lifecycle" class="<?= $ui['input'] ?>"><?= garden_options($cycles, $t['lifecycle'] ?? 'annual') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="af">Family (for rotation)</label><input id="af" name="crop_family" maxlength="120" placeholder="Solanaceae" value="<?= $h($t['crop_family'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="av">First variety</label><input id="av" name="variety" maxlength="160" placeholder="Cherokee Purple" value="<?= $h($t['variety'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="ad">Days to pick</label><input id="ad" name="days_to_maturity" type="number" min="1" max="999" value="<?= $h($t['days_to_maturity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="ab">Counted</label><select id="ab" name="maturity_basis" class="<?= $ui['input'] ?>"><?= garden_options($bases, $t['maturity_basis'] ?? 'unknown') ?></select></div>
      </div>
      <p class="text-xs text-stone-500 mb-3">Family lets the rotation check warn when the same family went in a bed in the last three years.</p>
      <button class="<?= $ui['btnMain'] ?>">Add crop</button>
    </form>
  </details>
<?php endif;
bcrf_shell_close();
