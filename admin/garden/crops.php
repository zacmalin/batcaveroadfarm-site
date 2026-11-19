<?php
// admin/garden/crops.php — Garden › Crops (Nov 18, 2026; includes/garden.php, includes/garden_crops.php, migration 0072).
//   GET  crops.php[?q=&show=all|missing]   every crop: the shared starter list and the farm's own, with what's filled in; add one
//   GET  crops.php?crop=s12|o5      the crop editor (like Kitchen › Ingredients): how it grows (timing around frost, days to harvest,
//                                   spacing, depth, sprouting, sun, water, pH, frost tolerance), Fill from reference, its varieties,
//                                   where it's used; archive or delete (Nov 19, 2026; includes/garden_crop_settings.php, migration 0075)
//   GET  crops.php?tab=guides       growing guide per variety (sun, water, pH, spacing in inches, depth, germination, zones)
//   GET  crops.php?tab=planner      frost-date planner: start indoors, harden off, plant out, ready to pick; succession dates
//   POST act=crop_add | crop_save | crop_archive | variety_add | variety_save | variety_archive | shared_note | guide_save
//        | crop_grow_save | reference_fill (mode=blanks|all) | reference_fill_all | shared_unhide
//        | reference_update (download OpenPlantDB) | reference_upload (plants.csv/json; includes/garden_openplantdb.php)
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
    echo '<h1 class="', $ui['h1'], '">Crops</h1>', bcrf_shell_notice('warning', 'Run the garden migrations (0072 to 0075) in Settings › Database first.');
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
        try { garden_crop_reference_fill($pdo, $org, 'o' . $id, garden_in('name')); } catch (InvalidArgumentException $e) { /* no reference numbers for it */ }
        return ['Added ' . trim(garden_in('name')) . '. Check how it grows below.', 'crop=o' . $id];
    }],
    'crop_save' => [true, function () use ($pdo, $org, $own, $prof) {
        $id = garden_id('crop_id', 'Choose a crop.');
        $own->updateCrop($pdo, $org, $id, garden_in('name'), garden_in('lifecycle', 'annual'));
        $prof->updatePrivateCropTaxonomy($pdo, $org, $id, garden_in('crop_family'), garden_in('scientific_name'));
        return ['Saved ' . trim(garden_in('name')) . '.', 'crop=o' . $id];
    }],
    'crop_archive' => [true, function () use ($pdo, $org, $own) {
        $own->archiveCrop($pdo, $org, garden_id('crop_id', 'Choose a crop.'));
        return ['Crop archived. Past records keep it.', ''];
    }],
    'variety_add' => [true, function () use ($pdo, $org, $own) {
        $crop = garden_id('crop_id', 'Choose a crop.');
        $own->createVariety($pdo, $org, $crop, garden_in('name'), garden_in('days_to_maturity') ?: null, garden_in('maturity_basis', 'unknown'));
        return ['Variety added.', 'crop=o' . $crop, '#varieties'];
    }],
    'variety_save' => [true, function () use ($pdo, $org, $own) {
        $own->updateVariety($pdo, $org, garden_id('variety_id', 'Choose a variety.'), garden_in('name'), garden_in('days_to_maturity') ?: null, garden_in('maturity_basis', 'unknown'));
        return ['Variety saved.', 'crop=o' . (int)($_POST['crop_id'] ?? 0), '#varieties'];
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
        return ['Saved for this farm.', garden_in('back_query'), '#varieties'];
    }],
    'crop_grow_save' => [true, function () use ($pdo, $org) {
        $key = garden_in('crop');
        garden_crop_settings_save($pdo, $org, $key, garden_crop_settings_in($_POST));
        return ['Saved how it grows.', 'crop=' . $key];
    }],
    'reference_fill' => [true, function () use ($pdo, $org) {
        $key = garden_in('crop');
        $n = garden_crop_reference_fill($pdo, $org, $key, garden_in('crop_name'), garden_in('mode') !== 'all');
        return [$n ? 'Filled ' . $n . ' number' . ($n === 1 ? '' : 's') . ' from the reference table.' : 'Nothing to fill: every number is already in.', 'crop=' . $key, '#grow'];
    }],
    'reference_fill_all' => [true, function () use ($pdo, $org, $cat, $own) {
        $crops = 0; $fields = 0;
        $names = [];
        foreach ($cat->varietiesForOrganization($pdo, $org) as $v) if (empty($v['hidden_at'])) $names['s' . $v['crop_id']] = $v['crop_name'];
        foreach ($own->crops($pdo, $org) as $c) if (!$c['archived_at']) $names['o' . $c['id']] = $c['name'];
        foreach ($names as $key => $name) {
            if (!garden_reference_match($name, $pdo)) continue;
            $n = garden_crop_reference_fill($pdo, $org, (string)$key, $name, true);
            if ($n) { $crops++; $fields += $n; }
        }
        return $crops ? 'Filled the blanks for ' . $crops . ' crop' . ($crops === 1 ? '' : 's') . ' from the reference table (' . $fields . ' numbers). Anything already typed in stayed.' : 'Nothing to fill: the crops the reference table knows already have their numbers.';
    }],
    'reference_update' => [true, function () use ($pdo) {
        $n = garden_opdb_download($pdo);
        return ['Loaded ' . $n . ' plants from OpenPlantDB. Fill from reference now uses them.', 'refs=1', '#refs'];
    }],
    'reference_upload' => [true, function () use ($pdo) {
        $f = $_FILES['plants'] ?? [];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) throw new InvalidArgumentException('Choose the plants.csv or plants.json file.');
        $n = garden_opdb_import_file($pdo, (string)$f['tmp_name'], (string)($f['name'] ?? 'plants.csv'));
        return ['Loaded ' . $n . ' plants from the file. Fill from reference now uses them.', 'refs=1', '#refs'];
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
$cropKey = (string)($_GET['crop'] ?? '');
if (!preg_match('/^[so][1-9]\d*$/D', $cropKey)) $cropKey = '';
if ($cropKey === '') echo '<h1 class="', $ui['h1'], '">', $tab === 'guides' ? 'Growing guides' : ($tab === 'planner' ? 'Planting planner' : 'Crops'), '</h1>';
if ($err !== '' && !in_array($errAct, ['crop_add', 'crop_save', 'guide_save', 'shared_note', 'variety_add', 'variety_save', 'crop_grow_save'], true)) echo bcrf_shell_notice('err', $err);
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
    $fr = garden_frost_for($pdo, $org, $site, isset($_GET['year']) ? max(2000, min(2100, (int)$_GET['year'])) : null);
    $spring = $fr['spring']; $fall = $fr['fall'];
    $sel = (string)($_GET['variety'] ?? '');
    $days = (int)($_GET['days'] ?? 0);
    if (!$days) foreach ($opts as $o) if ($o['value'] === $sel && $o['days']) $days = (int)$o['days'];
    // The crop's own timing (crop editor) fills the boxes the first time a variety is picked.
    $cs = $sel !== '' ? (garden_crop_settings_all($pdo, $org)[garden_variety_crop_key($pdo, $org, $sel)] ?? []) : [];
    if (!$days && !empty($cs['days_to_harvest'])) $days = (int)$cs['days_to_harvest'];
    $weeks = (int)($_GET['weeks'] ?? ($cs['start_indoors_weeks'] ?? 6)); $after = (int)($_GET['after'] ?? max(0, (int)($cs['plant_out_days'] ?? 14))); $harden = (int)($_GET['harden'] ?? 7);
    $every = (int)($_GET['every'] ?? 14); $rounds = (int)($_GET['rounds'] ?? 4); $start = (string)($_GET['start'] ?? '');
    echo garden_site_pills($sites, $site, 'crops.php', 'planner');
    if (!$spring || !$fall) {
        echo '<div class="', $ui['card'], '"><p class="text-sm text-stone-600">Set the farm\'s frost dates once first, in <a class="', $ui['link'], '" href="/admin/garden/areas.php">Beds &amp; areas › Frost dates and garden settings</a>. They carry over every year.</p></div>';
    } ?>
  <form method="GET" action="crops.php" class="<?= $ui['card'] ?>"><input type="hidden" name="tab" value="planner"><input type="hidden" name="site" value="<?= (int)($site['id'] ?? 0) ?>">
    <h2 class="<?= $ui['h2'] ?>">Start indoors, then plant out</h2>
    <p class="<?= $ui['muted'] ?> mb-3"><?= (int)$fr['year'] ?> frost dates<?= $fr['from'] === 'garden' ? ' for this garden' : '' ?>: spring <?= $h($spring ? garden_date($spring) : 'not set') ?>, fall <?= $h($fall ? garden_date($fall) : 'not set') ?>.<?= !empty($cs) ? ' Timing filled from the crop.' : '' ?></p><input type="hidden" name="year" value="<?= (int)$fr['year'] ?>">
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

// ── Crops: every crop, the starter list and the farm's own ──
$crops = [];   // key ("s12" / "o5") => [name, family, life, own row|null, crop_id, vars[]]
$hiddenN = 0;
foreach ($shared as $v) {
    if (!empty($v['hidden_at'])) { $hiddenN++; continue; }   // taken off this farm's list (Nov 19, 2026)
    $k = 's' . $v['crop_id'];
    $crops[$k] ??= ['name' => $v['crop_name'], 'family' => $v['crop_family'], 'life' => $v['lifecycle'], 'own' => null, 'crop_id' => (int)$v['crop_id'], 'vars' => []];
    $crops[$k]['vars'][] = ['shared' => true] + $v;
}
foreach ($ownCrops as $c) {
    if ($c['archived_at']) continue;
    $crops['o' . $c['id']] = ['name' => $c['name'], 'family' => $c['crop_family'], 'life' => $c['lifecycle'], 'own' => $c, 'crop_id' => (int)$c['id'], 'vars' => []];
}
foreach ($ownVars as $v) if (!$v['archived_at'] && isset($crops['o' . $v['crop_id']])) $crops['o' . $v['crop_id']]['vars'][] = ['shared' => false] + $v;
uasort($crops, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$days = fn($d, $b) => $d ? (int)$d . ' days' . ($b && $b !== 'unknown' ? ' ' . strtolower($bases[$b] ?? '') : '') : '';
$settings = garden_crop_settings_all($pdo, $org);
$missing = fn(array $s) => !$s || $s['plant_out_days'] === null || $s['days_to_harvest'] === null || $s['spacing_cm'] === null;
$sun = ['unknown' => 'Not sure', 'full_sun' => 'Full sun', 'partial_shade' => 'Part shade', 'shade' => 'Shade'];
$water = ['unknown' => 'Not sure', 'low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High'];
$frostTol = ['tender' => 'Tender (frost kills it)', 'half_hardy' => 'Half-hardy (light frost OK)', 'hardy' => 'Hardy (takes hard frost)'];
$toF = fn($c) => $c === null ? '' : garden_num((float)$c * 9 / 5 + 32, 0);

// ── One crop: the editor ──
if ($cropKey !== '') {
    $c = $crops[$cropKey] ?? null;
    echo '<p class="mb-2"><a class="', $ui['link'], ' text-sm" href="crops.php">← All crops</a></p>';
    if (!$c) { echo '<h1 class="', $ui['h1'], '">Crop</h1>', bcrf_shell_notice('warning', 'That crop is archived, removed from your list, or gone.'); bcrf_shell_close(); exit; }
    $oc = $c['own']; $cid = $oc ? (int)$oc['id'] : 0;
    $s = $settings[$cropKey] ?? [];
    $t = garden_typed('crop_grow_save');
    $val = fn(string $k, $def) => $t[$k] ?? $def;
    $ref = garden_reference_match($c['name'], $pdo);
    $use = garden_crop_usage($pdo, $org, $cropKey);
    $fr = garden_frost_for($pdo, $org, garden_site_pick($pdo, $org)[1]);
    $plan = garden_crop_plan($s, $fr);
    echo '<h1 class="', $ui['h1'], '">', $h($c['name']), '</h1>';
    echo '<p class="', $ui['muted'], ' mb-3">', $h($cycles[$c['life']] ?? $c['life']), $c['family'] ? ' · ' . $h(garden_family_names()[$c['family']] ?? $c['family']) : '', ' · ', $oc ? 'your crop' : 'starter list', '</p>';
    if ($plan['out']) {
        echo '<div class="', $ui['card'], '"><h2 class="', $ui['h2'], '">', (int)$fr['year'], ' with your frost dates</h2><dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">';
        if ($plan['indoors']) echo '<div><dt class="text-stone-500">Start seeds indoors</dt><dd class="font-semibold">', $h(garden_date($plan['indoors'])), '</dd></div>';
        echo '<div><dt class="text-stone-500">', $plan['how'] === 'transplant' ? 'Plant out' : 'Sow outside', '</dt><dd class="font-semibold">', $h(garden_date($plan['out'])), '</dd></div>';
        if ($plan['ready']) echo '<div><dt class="text-stone-500">Ready about</dt><dd class="font-semibold', $plan['late'] ? ' text-red-700' : '', '">', $h(garden_date($plan['ready'])), $plan['late'] ? ' (after fall frost)' : '', '</dd></div>';
        echo '</dl><p class="text-xs text-stone-500 mt-2">', $h(garden_crop_timing_words($s)), '. Each variety\'s own days to harvest wins when you plant it.</p></div>';
    } elseif ($fr['spring'] === '') {
        echo '<div class="', $ui['card'], ' text-sm text-stone-600">Set the farm\'s frost dates once in <a class="', $ui['link'], '" href="areas.php">Beds &amp; areas › Frost dates and garden settings</a> to see this crop\'s dates.</div>';
    }
    ?>
  <form id="grow" method="POST" action="crops.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'crop_grow_save') ?><?= garden_hidden('crop_grow_save', ['crop' => $cropKey]) ?>
    <h2 class="<?= $ui['h2'] ?>">How it grows</h2>
    <fieldset <?= $manage ? '' : 'disabled' ?>>
    <p class="text-xs font-semibold text-stone-500 mb-2">Timing around frost</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <div><label class="<?= $ui['label'] ?>" for="gsea">Season</label><select id="gsea" name="season" class="<?= $ui['input'] ?>"><?= garden_options(['warm' => 'Warm season', 'cool' => 'Cool season'], $val('season', $s['season'] ?? ''), 'Not set') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="gwk">Start indoors (weeks before last frost)</label><input id="gwk" name="start_indoors_weeks" type="number" min="1" max="20" placeholder="Sown outside" value="<?= $h($val('start_indoors_weeks', $s['start_indoors_weeks'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gdh">Days to harvest</label><input id="gdh" name="days_to_harvest" type="number" min="1" max="999" value="<?= $h($val('days_to_harvest', $s['days_to_harvest'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gft">Frost</label><select id="gft" name="frost_tolerance" class="<?= $ui['input'] ?>"><?= garden_options($frostTol, $val('frost_tolerance', $s['frost_tolerance'] ?? ''), 'Not set') ?></select></div>
      <?php $po = $s['plant_out_days'] ?? null; ?>
      <div><label class="<?= $ui['label'] ?>" for="gpo">Sow or plant out (days)</label><input id="gpo" name="plant_out_days" type="number" min="0" max="180" value="<?= $h($val('plant_out_days', $po === null ? '' : abs((int)$po))) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gpw">Before or after</label><select id="gpw" name="plant_out_when" class="<?= $ui['input'] ?>"><?= garden_options(['after' => 'After', 'before' => 'Before'], $val('plant_out_when', $po !== null && (int)$po < 0 ? 'before' : 'after')) ?></select></div>
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="gpf">Which frost</label><select id="gpf" name="plant_out_from" class="<?= $ui['input'] ?>"><?= garden_options(['spring' => 'The last spring frost', 'fall' => 'The first fall frost (fall-planted, like garlic)'], $val('plant_out_from', $s['plant_out_from'] ?? 'spring')) ?></select></div>
    </div>
    <p class="text-xs font-semibold text-stone-500 mb-2">Sowing and spacing</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <div><label class="<?= $ui['label'] ?>" for="gsp">Plant spacing (in)</label><input id="gsp" name="spacing_in" inputmode="decimal" value="<?= $h($val('spacing_in', garden_cm_to_in($s['spacing_cm'] ?? null))) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="grs">Row spacing (in)</label><input id="grs" name="row_spacing_in" inputmode="decimal" value="<?= $h($val('row_spacing_in', garden_cm_to_in($s['row_spacing_cm'] ?? null))) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gsd">Sow depth (in)</label><input id="gsd" name="sow_depth_in" inputmode="decimal" value="<?= $h($val('sow_depth_in', garden_cm_to_in($s['sow_depth_cm'] ?? null))) ?>" class="<?= $ui['input'] ?>"></div>
      <div></div>
      <div><label class="<?= $ui['label'] ?>" for="gg1">Sprouts in (days) from</label><input id="gg1" name="germination_min_days" type="number" min="1" max="365" value="<?= $h($val('germination_min_days', $s['germination_min_days'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gg2">to</label><input id="gg2" name="germination_max_days" type="number" min="1" max="365" value="<?= $h($val('germination_max_days', $s['germination_max_days'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gt1">Soil temp to sprout (°F) from</label><input id="gt1" name="germ_temp_min_f" inputmode="decimal" value="<?= $h($val('germ_temp_min_f', $toF($s['germ_temp_min_c'] ?? null))) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gt2">to</label><input id="gt2" name="germ_temp_max_f" inputmode="decimal" value="<?= $h($val('germ_temp_max_f', $toF($s['germ_temp_max_c'] ?? null))) ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <p class="text-xs font-semibold text-stone-500 mb-2">Where it likes to grow</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div><label class="<?= $ui['label'] ?>" for="gsu">Sun</label><select id="gsu" name="sun_exposure" class="<?= $ui['input'] ?>"><?= garden_options($sun, $val('sun_exposure', $s['sun_exposure'] ?? 'unknown')) ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="gwa">Water</label><select id="gwa" name="water_need" class="<?= $ui['input'] ?>"><?= garden_options($water, $val('water_need', $s['water_need'] ?? 'unknown')) ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="gp1">Soil pH from</label><input id="gp1" name="soil_ph_min" inputmode="decimal" value="<?= $h($val('soil_ph_min', $s['soil_ph_min'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="gp2">to</label><input id="gp2" name="soil_ph_max" inputmode="decimal" value="<?= $h($val('soil_ph_max', $s['soil_ph_max'] ?? '')) ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <div class="mb-3"><label class="<?= $ui['label'] ?>" for="gno">Notes</label><textarea id="gno" name="notes" rows="3" maxlength="4000" placeholder="Trellis, succession every 2 weeks, bolts in heat…" class="<?= $ui['input'] ?>"><?= $h($val('notes', $s['notes'] ?? '')) ?></textarea></div>
    <?php if ($manage): ?><button class="<?= $ui['btnMain'] ?>">Save</button><?php endif; ?>
    <?php if (!empty($s['source'])): ?><p class="text-xs text-stone-500 mt-2">Some numbers came from the <?= $h($s['source']) ?>. Change any of them to match your seed packet.</p><?php endif; ?>
    </fieldset>
  </form>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Reference numbers</h2>
    <?php if (!$ref): ?><p class="text-sm text-stone-600">The reference table has nothing for <?= $h($c['name']) ?>. Type the numbers from the seed packet.</p>
    <?php else: ?>
    <?php
      $bits = array_filter([
          garden_crop_timing_words($ref) ?: ($ref['start_indoors_weeks'] ? 'Start indoors ' . (int)$ref['start_indoors_weeks'] . ' weeks before the last spring frost' : ''),
          $ref['days_to_harvest'] ? 'about ' . (int)$ref['days_to_harvest'] . ' days to harvest' : '',
          $ref['spacing_range_in'] !== '' ? $ref['spacing_range_in'] . ' in apart' : '',
          ($ref['row_range_in'] ?? '') !== '' ? 'rows ' . $ref['row_range_in'] . ' in apart' : '',
          !empty($ref['germination_min_days']) ? 'sprouts in ' . (int)$ref['germination_min_days'] . ($ref['germination_max_days'] != $ref['germination_min_days'] ? '–' . (int)$ref['germination_max_days'] : '') . ' days' : '',
          $ref['germ_temp_min_c'] !== null ? 'soil ' . $toF($ref['germ_temp_min_c']) . '–' . $toF($ref['germ_temp_max_c']) . '°F to sprout' : '',
          !empty($ref['sun_exposure']) && $ref['sun_exposure'] !== 'unknown' ? strtolower($sun[$ref['sun_exposure']] ?? '') : '',
          !empty($ref['water_need']) ? strtolower($water[$ref['water_need']] ?? '') . ' water' : '',
          $ref['frost_tolerance'] ? strtolower(explode(' (', $frostTol[$ref['frost_tolerance']] ?? $ref['frost_tolerance'])[0]) : '',
      ]);
    ?>
    <p class="text-sm text-stone-700 mb-2"><?= $h($ref['name']) ?>: <?= $h(implode(', ', $bits)) ?>.</p>
    <?php if ($manage): ?><div class="flex flex-wrap gap-2">
      <form method="POST" action="crops.php"><?= garden_hidden('reference_fill', ['crop' => $cropKey, 'crop_name' => $c['name'], 'mode' => 'blanks']) ?><button class="<?= $ui['btnSec'] ?>">Fill the blanks</button></form>
      <form method="POST" action="crops.php"><?= garden_hidden('reference_fill', ['crop' => $cropKey, 'crop_name' => $c['name'], 'mode' => 'all']) ?><button class="<?= $ui['btnSec'] ?>" data-confirm="Replace the timing, days to harvest, spacing, sprouting, sun, water and frost numbers with the reference ones? Sow depth, pH and notes stay.">Use all reference numbers</button></form>
    </div><?php endif; ?>
    <p class="text-xs text-stone-500 mt-2"><?= $h(garden_reference_credit($ref)) ?> Spacing is the middle of the range. Your seed packet is more exact for a variety.</p>
    <?php endif; ?>
  </div>
  <div id="varieties" class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Varieties</h2>
    <?php if (!$c['vars']): ?><p class="<?= $ui['muted'] ?>">None yet.</p><?php endif; ?>
    <ul class="divide-y divide-stone-100 text-sm">
    <?php foreach ($c['vars'] as $v): $vn = $v['shared'] ? ($v['local_name'] ?: $v['name']) : $v['name']; $vd = $v['shared'] ? ($v['local_days_to_maturity'] ?? $v['days_to_maturity']) : $v['days_to_maturity']; $vb = $v['shared'] ? ($v['local_maturity_basis'] ?? $v['maturity_basis']) : $v['maturity_basis']; ?>
      <li class="py-2"><details><summary class="cursor-pointer"><?= $h($vn) ?><?= $vd ? ' <span class="text-stone-500">· ' . $h($days($vd, $vb)) . '</span>' : ' <span class="text-stone-500">· uses the crop\'s days</span>' ?></summary>
        <?php if ($manage && !$v['shared']): ?>
        <form method="POST" action="crops.php" data-submit-once class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><?= garden_hidden('variety_save', ['variety_id' => (int)$v['id'], 'crop_id' => $cid]) ?>
          <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="vn<?= (int)$v['id'] ?>">Variety name</label><input id="vn<?= (int)$v['id'] ?>" name="name" maxlength="160" required value="<?= $h($v['name']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="vd<?= (int)$v['id'] ?>">Days to pick</label><input id="vd<?= (int)$v['id'] ?>" name="days_to_maturity" type="number" min="1" max="999" value="<?= $h($v['days_to_maturity']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="vb<?= (int)$v['id'] ?>">Counted</label><select id="vb<?= (int)$v['id'] ?>" name="maturity_basis" class="<?= $ui['input'] ?>"><?= garden_options($bases, $v['maturity_basis']) ?></select></div>
          <div class="col-span-2 sm:col-span-4 flex flex-wrap gap-2"><button class="<?= $ui['btnSec'] ?>">Save variety</button><a class="<?= $ui['btnSec'] ?>" href="crops.php?tab=guides&amp;variety=custom:<?= (int)$v['id'] ?>">Variety growing guide</a></div>
        </form>
        <div class="flex flex-wrap gap-3 mt-1">
          <form method="POST" action="crops.php" class="inline"><?= garden_hidden('variety_archive', ['variety_id' => (int)$v['id'], 'back_query' => 'crop=' . $cropKey, 'back_anchor' => 'varieties']) ?><button class="text-xs text-amber-800 underline" data-confirm="Archive <?= $h($v['name']) ?>? It leaves the pickers; past records keep it.">Archive variety</button></form>
          <?= garden_delete_button('crops.php', 'variety', (int)$v['id'], 'Delete ' . $v['name'] . ' for good? Only works when no planting or seed lot uses it.', 'crop=' . $cropKey, 'varieties', 'Delete variety') ?>
        </div>
        <?php elseif ($manage): ?>
        <form method="POST" action="crops.php" data-submit-once class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><?= garden_hidden('shared_note', ['variety_id' => (int)$v['id'], 'back_query' => 'crop=' . $cropKey]) ?>
          <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="sn<?= (int)$v['id'] ?>">Name on this farm</label><input id="sn<?= (int)$v['id'] ?>" name="display_name" maxlength="160" value="<?= $h($v['local_name']) ?>" placeholder="<?= $h($v['name']) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="sd<?= (int)$v['id'] ?>">Days to pick</label><input id="sd<?= (int)$v['id'] ?>" name="days_to_maturity" type="number" min="1" max="999" value="<?= $h($v['local_days_to_maturity'] ?? '') ?>" placeholder="<?= $h($v['days_to_maturity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="sb<?= (int)$v['id'] ?>">Counted</label><select id="sb<?= (int)$v['id'] ?>" name="maturity_basis" class="<?= $ui['input'] ?>"><?= garden_options($bases, $v['local_maturity_basis'] ?? 'unknown') ?></select></div>
          <div class="col-span-2 sm:col-span-4"><label class="<?= $ui['label'] ?>" for="so<?= (int)$v['id'] ?>">Notes</label><input id="so<?= (int)$v['id'] ?>" name="notes" maxlength="2000" value="<?= $h($v['local_notes'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
          <div class="col-span-2 sm:col-span-4 flex flex-wrap gap-2"><button class="<?= $ui['btnSec'] ?>">Save for this farm</button><a class="<?= $ui['btnSec'] ?>" href="crops.php?tab=guides&amp;variety=<?= (int)$v['id'] ?>">Variety growing guide</a></div>
        </form>
        <div class="mt-1"><?= garden_delete_button('crops.php', 'shared_variety', (int)$v['id'], 'Take ' . $vn . ' off your list? Plantings and seed lots that use it keep it. You can bring it back at the bottom of the crop list.', 'crop=' . $cropKey, 'varieties', 'Remove from my list') ?></div>
        <?php else: ?><a class="text-xs <?= $ui['link'] ?>" href="crops.php?tab=guides&amp;variety=<?= $v['shared'] ? (int)$v['id'] : 'custom:' . (int)$v['id'] ?>">Variety growing guide</a><?php endif; ?>
      </details></li>
    <?php endforeach; ?>
    </ul>
    <?php if ($manage && $oc): $tv = garden_typed('variety_add'); ?>
    <form id="form-variety_add" method="POST" action="crops.php" data-submit-once class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end border-t border-stone-100 pt-3">
      <?= garden_form_error($err, $errAct, 'variety_add') ?><?= garden_hidden('variety_add', ['crop_id' => $cid]) ?>
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="nv">New variety</label><input id="nv" name="name" maxlength="160" required value="<?= $h($tv['name'] ?? '') ?>" placeholder="From the seed packet" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="nd">Days to pick</label><input id="nd" name="days_to_maturity" type="number" min="1" max="999" value="<?= $h($tv['days_to_maturity'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <div><button class="<?= $ui['btnSec'] ?>">Add variety</button></div>
    </form>
    <?php endif; ?>
    <p class="text-xs text-stone-500 mt-2">A variety's days to pick (from its seed packet) wins over the crop's days to harvest.</p>
  </div>
  <div class="<?= $ui['card'] ?>"><h2 class="<?= $ui['h2'] ?>">Where it's used</h2>
    <p class="text-sm text-stone-700"><?= $use['active'] ?> growing · <?= $use['planned'] ?> planned · <?= $use['done'] ?> done · <?= $use['lots'] ?> seed lot<?= $use['lots'] === 1 ? '' : 's' ?></p>
    <p class="text-sm mt-1"><a class="<?= $ui['link'] ?>" href="plantings.php">Plantings</a> · <a class="<?= $ui['link'] ?>" href="seeds.php">Seeds</a> · <a class="<?= $ui['link'] ?>" href="crops.php?tab=planner">Planting planner</a></p>
  </div>
  <?php if ($manage && $oc): $tc = garden_typed('crop_save'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $tc ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Name, family, archive or delete <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-crop_save" method="POST" action="crops.php" data-submit-once class="p-4 grid grid-cols-2 sm:grid-cols-4 gap-2 items-end">
      <?= garden_form_error($err, $errAct, 'crop_save') ?><?= garden_hidden('crop_save', ['crop_id' => $cid]) ?>
      <div><label class="<?= $ui['label'] ?>" for="cn">Crop name</label><input id="cn" name="name" maxlength="160" required value="<?= $h($tc['name'] ?? $oc['name']) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="cl">Lives</label><select id="cl" name="lifecycle" class="<?= $ui['input'] ?>"><?= garden_options($cycles, $tc['lifecycle'] ?? $oc['lifecycle']) ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="cf">Family (for rotation)</label><input id="cf" name="crop_family" maxlength="120" placeholder="Solanaceae" value="<?= $h($tc['crop_family'] ?? $oc['crop_family']) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="cs">Botanical name</label><input id="cs" name="scientific_name" maxlength="200" value="<?= $h($tc['scientific_name'] ?? $oc['scientific_name']) ?>" class="<?= $ui['input'] ?>"></div>
      <div class="col-span-2 sm:col-span-4"><button class="<?= $ui['btnSec'] ?>">Save crop</button></div>
    </form>
    <div class="px-4 pb-4 flex flex-wrap gap-3">
      <form method="POST" action="crops.php" class="inline"><?= garden_hidden('crop_archive', ['crop_id' => $cid]) ?><button class="text-xs text-amber-800 underline" data-confirm="Archive <?= $h($oc['name']) ?> and its varieties? They leave the pickers; past records keep them.">Archive crop</button></form>
      <?= garden_delete_button('crops.php', 'crop', $cid, 'Delete ' . $oc['name'] . ' and its varieties for good? Only works when no planting or seed lot uses them.', '', '', 'Delete crop') ?>
    </div>
  </details>
  <?php elseif ($manage): ?>
  <div class="mb-4"><?= garden_delete_button('crops.php', 'shared_crop', (int)$c['crop_id'], 'Take ' . $c['name'] . ' and all its varieties off your list? Records that use them keep them. You can bring them back at the bottom of the crop list.', '', '', 'Remove ' . $c['name'] . ' from my list') ?></div>
  <?php endif;
    bcrf_shell_close(); exit;
}

// ── The list ──
$q = trim((string)($_GET['q'] ?? '')); $show = (string)($_GET['show'] ?? '');
$list = array_filter($crops, function ($c, $k) use ($q, $show, $settings, $missing) {
    if ($q !== '' && stripos($c['name'], $q) === false && !array_filter($c['vars'], fn($v) => stripos($v['shared'] ? ($v['local_name'] ?: $v['name']) : $v['name'], $q) !== false)) return false;
    return $show !== 'missing' || $missing($settings[$k] ?? []);
}, ARRAY_FILTER_USE_BOTH);
$needN = count(array_filter(array_keys($crops), fn($k) => $missing($settings[$k] ?? [])));
$refN = count(array_filter($crops, fn($c, $k) => $missing($settings[$k] ?? []) && garden_reference_match($c['name'], $pdo), ARRAY_FILTER_USE_BOTH));
?>
  <form method="GET" action="crops.php" class="flex flex-wrap gap-2 items-end mb-3">
    <div class="flex-1 min-w-48"><label class="<?= $ui['label'] ?>" for="cq">Find a crop or variety</label><input id="cq" name="q" type="search" value="<?= $h($q) ?>" class="<?= $ui['input'] ?>"></div>
    <div><label class="<?= $ui['label'] ?>" for="cw">Show</label><select id="cw" name="show" class="<?= $ui['input'] ?>"><?= garden_options(['' => 'All crops', 'missing' => 'Missing timing or spacing (' . $needN . ')'], $show) ?></select></div>
    <button class="<?= $ui['btnSec'] ?>">Show</button>
  </form>
  <?php if ($manage && $refN): ?>
  <form method="POST" action="crops.php" class="<?= $ui['card'] ?> text-sm flex flex-wrap gap-2 items-center justify-between"><?= garden_hidden('reference_fill_all') ?>
    <span><?= $refN ?> crop<?= $refN === 1 ? ' is' : 's are' ?> missing numbers the reference table has. Fill the blanks for all of them in one go; anything you typed stays.</span>
    <button class="<?= $ui['btnSec'] ?>">Fill the blanks for every crop</button></form>
  <?php endif; ?>
  <div class="<?= $ui['card'] ?>">
    <?php if (!$list): ?><p class="<?= $ui['muted'] ?>"><?= $crops ? 'No crop matches.' : 'No crops yet.' ?></p><?php else: ?>
    <ul class="divide-y divide-stone-100">
    <?php foreach ($list as $k => $c): $s = $settings[$k] ?? []; ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><span><a class="<?= $ui['link'] ?>" href="crops.php?crop=<?= $h($k) ?>"><strong><?= $h($c['name']) ?></strong></a>
        <span class="text-stone-500">· <?= count($c['vars']) ?> variet<?= count($c['vars']) === 1 ? 'y' : 'ies' ?><?= $c['family'] ? ' · ' . $h(garden_family_names()[$c['family']] ?? $c['family']) : '' ?><?= $c['own'] ? '' : ' · starter list' ?></span></span>
        <span class="text-stone-600"><?= !empty($s['days_to_harvest']) ? (int)$s['days_to_harvest'] . ' days' : '' ?><?= !empty($s['spacing_cm']) ? ' · ' . $h(garden_cm_to_in($s['spacing_cm'])) . ' in apart' : '' ?><?= $missing($s) ? ' <span class="text-amber-800">· needs timing or spacing</span>' : '' ?></span></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php $ods = garden_opdb_status($pdo); ?>
  <details id="refs" class="<?= $ui['sec'] ?>" <?= isset($_GET['refs']) ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Reference numbers <span class="text-sm font-normal text-stone-500"><?= $ods['count'] ? '· OpenPlantDB, ' . $ods['count'] . ' plants' : '· built-in table' ?></span> <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <div class="p-4 text-sm">
      <p class="mb-2">Fill from reference uses <?= $ods['count'] ? 'your copy of OpenPlantDB (' . $ods['count'] . ' plants, loaded ' . $h(garden_date((new DateTimeImmutable($ods['at'] . ' UTC'))->setTimezone(bcrf_tz())->format('Y-m-d'))) . ') first, then the built-in table of about 50 vegetables for row spacing and anything missing' : 'the built-in table of about 50 vegetables. Load OpenPlantDB for about 300 plants with sprouting days, sun and water' ?>. Numbers you type always win.</p>
      <p class="text-xs text-stone-500 mb-3">OpenPlantDB is a free, public-domain plant table on GitHub. Farm OS keeps its own copy, so nothing breaks if that project goes away.</p>
      <?php if ($manage): ?>
      <form id="form-reference_update" method="POST" action="crops.php" data-submit-once class="mb-3"><?= garden_form_error($err, $errAct, 'reference_update') ?><?= garden_hidden('reference_update') ?>
        <button class="<?= $ui['btnSec'] ?>"><?= $ods['count'] ? 'Get the latest OpenPlantDB numbers' : 'Load OpenPlantDB' ?></button></form>
      <form id="form-reference_upload" method="POST" action="crops.php" enctype="multipart/form-data" data-submit-once class="flex flex-wrap gap-2 items-end"><?= garden_form_error($err, $errAct, 'reference_upload') ?><?= garden_hidden('reference_upload') ?>
        <div><label class="<?= $ui['label'] ?>" for="rpf">Or upload plants.csv / plants.json</label><input id="rpf" name="plants" type="file" accept=".csv,.json,text/csv,application/json" required class="<?= $ui['input'] ?>"></div>
        <button class="<?= $ui['btnSec'] ?>">Upload</button></form>
      <?php endif; ?>
    </div>
  </details>
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
      <p class="text-xs text-stone-500 mb-3">Family lets the rotation check warn when the same family went in a bed in the last three years. A crop the reference table knows gets its timing and spacing filled in; you can change them on the next page.</p>
      <button class="<?= $ui['btnMain'] ?>">Add crop</button>
    </form>
  </details>
<?php endif;
bcrf_shell_close();
