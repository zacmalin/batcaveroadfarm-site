<?php
// admin/garden/areas.php — Garden › Beds & areas (Nov 18, 2026; bed planner Nov 19, 2026; includes/garden.php, includes/garden_areas.php,
//   includes/garden_planner.php, admin/assets/garden.js + garden.css, migrations 0072 + 0073).
//   GET  areas.php?tab=map[&season=…]   the garden drawn to scale, top-down: plantings on each bed, colored by crop family or
//                                       status, any season (today, a year, spring/summer/fall/winter); rotation and crowding
//                                       flags; drag beds to move them (managers); lay out many beds at once
//   GET  areas.php?tab=schedule[&year=]  each bed's plantings on a timeline for the year: when beds are full, empty or crowded
//   GET  areas.php?bed=N                one bed: what it's like (size, runs, sun, watering, ground cover, soil), everything
//                                       ever planted there by year, soil notes; change it
//   GET  areas.php[?site=N]             the list of beds, rows, plots, greenhouses and orchard blocks, nested; add one
//   GET  areas.php?tab=soil             soil tests and notes per bed (pH)
//   POST act=site_add | site_save | area_add | area_save | area_place | area_grid | area_retire  (garden_manage)
//        act=area_move (map drag, answers JSON)                                                (garden_manage)
//        act=soil_add                                                                         (garden)
// Sizes are typed in feet and kept in meters (the garden classes validate in metric). Top of the map is north.
require_once __DIR__ . '/../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';
require_permission([['view', 'garden']]);
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../../includes/garden.php';
require_once __DIR__ . '/../../includes/admin_shell.php';
date_default_timezone_set(bcrf_tz()->getName());

use Garden\Domain\GardenService;
use Garden\Domain\GrowingAreaLayout;
use Garden\Domain\GrowingRecords;
use Garden\Domain\SoilPerennials;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    if (($_POST['act'] ?? '') === 'area_move') { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Run the garden migrations first.']); exit; }
    bcrf_shell_open('Beds & areas — Garden');
    echo '<h1 class="', $ui['h1'], '">Beds &amp; areas</h1>', bcrf_shell_notice('warning', 'Run the garden migrations (0072 to 0075) in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
$svc = new GardenService();
$lay = new GrowingAreaLayout();
$soil = new SoilPerennials();
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? '');
$tz = bcrf_tz()->getName();

/** [length m, width m, area m²] from the feet typed in an area form. Area = length × width unless square feet were typed. */
function garden_area_size_in(): array {
    $len = garden_ft_to_m(garden_in('length_ft'));
    $wid = garden_ft_to_m(garden_in('width_ft'));
    if (($len === '') !== ($wid === '')) throw new InvalidArgumentException('Enter both length and width, or leave both empty.');
    $area = garden_sqft_to_sqm(garden_in('area_sqft'));
    if ($area === '' && $len !== '') $area = number_format((float)$len * (float)$wid, 3, '.', '');
    return [$len, $wid, $area === '' ? null : $area];
}
function garden_area_row(PDO $pdo, int $org, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM garden_growing_locations WHERE id = ? AND organization_id = ?');
    $st->execute([$id, $org]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** A small square of color (an SVG, so the strict page policy allows it). */
function garden_swatch(string $color): string {
    return '<svg class="gm-swatch" viewBox="0 0 10 10" aria-hidden="true"><rect width="10" height="10" rx="2" fill="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '"/></svg>';
}

// ── Map drag: save one bed's spot and turn, answer JSON (garden.js) ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'area_move') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!hash_equals(garden_csrf(), (string)($_POST['csrf_token'] ?? ''))) throw new InvalidArgumentException('Security token expired. Reload the page and try again.');
        if (!garden_can_manage()) throw new InvalidArgumentException('Ask a manager to move beds.');
        $site = garden_id('site_id', 'Choose a garden.');
        $id = garden_id('area_id', 'Choose a bed.');
        $cur = garden_area_row($pdo, $org, $id);
        if (!$cur || $cur['length_m'] === null) throw new InvalidArgumentException('Give the bed a length and width first.');
        $rot = (int)garden_in('rotation', '0');
        $rot = (($rot % 360) + 360) % 360;
        $lay->save($pdo, $org, $site, $id, (string)$cur['length_m'], (string)$cur['width_m'], garden_ft_to_m(garden_in('x_ft')), garden_ft_to_m(garden_in('y_ft')), (string)$rot);
        if (function_exists('erp_audit')) { try { erp_audit($pdo, 'update', 'garden', null, 'Garden: moved ' . $cur['name'] . ' on the map.'); } catch (Throwable $e) { /* best effort */ } }
        echo json_encode(['ok' => true]);
    } catch (InvalidArgumentException | DomainException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[garden] areas.php area_move: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Could not save the move. Try again.']);
    }
    exit;
}

garden_post($pdo, 'areas.php', [
    'site_add' => [true, function () use ($pdo, $org, $svc, $tz) {
        $id = $svc->createSite($pdo, $org, garden_in('name'), $tz);
        return ['Garden added.', 'site=' . $id];
    }],
    'site_save' => [true, function () use ($pdo, $org, $svc, $tz) {
        $site = garden_id('site_id', 'Choose a garden.');
        $svc->updateSite($pdo, $org, $site, garden_in('name'), $tz);
        garden_site_frost_save($pdo, $org, $site, garden_in('last_spring_frost'), garden_in('first_fall_frost'));   // blank = the farm's
        return ['Garden saved.', 'site=' . $site];
    }],
    'farm_frost' => [true, function () use ($pdo, $org) {
        garden_farm_frost_save($pdo, $org, garden_in('farm_spring'), garden_in('farm_fall'));
        return ['Frost dates saved for the whole farm. Every year and every garden uses them.', (int)($_POST['site_id'] ?? 0) ? 'site=' . (int)$_POST['site_id'] : ''];
    }],
    'area_add' => [true, function () use ($pdo, $org, $svc, $lay) {
        $site = garden_id('site_id', 'Choose a garden.');
        [$len, $wid, $area] = garden_area_size_in();
        $details = garden_bed_details_in();
        $parent = (int)($_POST['parent_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $id = $parent > 0 ? $svc->addChildLocation($pdo, $org, $site, $parent, garden_in('name'), garden_in('kind'), $area)
                              : $svc->createLocation($pdo, $org, $site, garden_in('name'), garden_in('kind'), $area);
            if ($len !== '') $lay->save($pdo, $org, $site, $id, $len, $wid, '', '', '0');
            garden_bed_details_save($pdo, $org, $site, $id, $details);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return ['Added ' . trim(garden_in('name')) . '.', 'bed=' . $id];
    }],
    'area_save' => [true, function () use ($pdo, $org, $svc, $lay) {
        $site = garden_id('site_id', 'Choose a garden.');
        $id = garden_id('area_id', 'Choose a bed or area.');
        [$len, $wid, $area] = garden_area_size_in();
        $details = garden_bed_details_in();
        $pdo->beginTransaction();
        try {
            $svc->updateLocation($pdo, $org, $site, $id, garden_in('name'), garden_in('kind'), $area);
            $cur = garden_area_row($pdo, $org, $id);
            $lay->save($pdo, $org, $site, $id, $len, $wid, $len === '' ? '' : (string)($cur['layout_x_m'] ?? ''), $len === '' ? '' : (string)($cur['layout_y_m'] ?? ''), (string)(int)($cur['rotation_degrees'] ?? 0));
            garden_bed_details_save($pdo, $org, $site, $id, $details);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return ['Saved ' . trim(garden_in('name')) . '.', 'bed=' . $id];
    }],
    'area_place' => [true, function () use ($pdo, $org, $lay) {
        $site = garden_id('site_id', 'Choose a garden.');
        $id = garden_id('area_id', 'Choose a bed or area.');
        $cur = garden_area_row($pdo, $org, $id);
        if (!$cur || $cur['length_m'] === null) throw new InvalidArgumentException('Give the bed a length and width first (open the bed › Change this bed).');
        $x = garden_ft_to_m(garden_in('x_ft')); $y = garden_ft_to_m(garden_in('y_ft'));
        $rot = trim(garden_in('rotation', '0')) === '' ? '0' : trim(garden_in('rotation', '0'));
        $lay->save($pdo, $org, $site, $id, (string)$cur['length_m'], (string)$cur['width_m'], $x, $y, $rot);
        return ['Map position saved.', 'site=' . $site . '&tab=map&focus=' . $id];
    }],
    'area_grid' => [true, function () use ($pdo, $org) {
        $site = garden_id('site_id', 'Choose a garden.');
        $ids = garden_lay_out_grid($pdo, $org, $site, [
            'prefix' => garden_in('prefix'), 'first' => (int)garden_in('first', '1'), 'rows' => (int)garden_in('rows'), 'cols' => (int)garden_in('cols'),
            'length_ft' => garden_in('length_ft'), 'width_ft' => garden_in('width_ft'), 'path_ft' => garden_in('path_ft'),
            'x_ft' => garden_in('x_ft'), 'y_ft' => garden_in('y_ft'), 'kind' => garden_in('kind', 'raised_bed'),
            'parent_id' => (int)garden_in('parent_id', '0'), 'upright' => garden_in('runs') === 'down', 'details' => garden_bed_details_in(),
        ]);
        return ['Added ' . count($ids) . ' bed' . (count($ids) === 1 ? '' : 's') . ' to the map.', 'site=' . $site . '&tab=map'];
    }],
    'area_retire' => [true, function () use ($pdo, $org, $svc) {
        $site = garden_id('site_id', 'Choose a garden.');
        $svc->retireLocation($pdo, $org, $site, garden_id('area_id', 'Choose a bed or area.'));
        return ['Retired. Its history stays in the records.', 'site=' . $site];
    }],
    'soil_add' => [false, function () use ($pdo, $org, $soil) {
        $site = garden_id('site_id', 'Choose a garden.');
        $loc = garden_id('location_id', 'Choose a bed.');
        $soil->addSoil($pdo, $org, $site, $loc, garden_in('observed_on'), trim(garden_in('ph')), garden_in('notes'));
        return ['Soil note saved.', garden_in('from_bed') === '1' ? 'bed=' . $loc : 'site=' . $site . '&tab=soil'];
    }],
]);

$bedId = (int)($_GET['bed'] ?? 0);
if ($bedId > 0) {   // a bed belongs to one garden: show that garden
    $st = $pdo->prepare('SELECT site_id FROM garden_growing_locations WHERE id = ? AND organization_id = ? AND active = 1');
    $st->execute([$bedId, $org]);
    $bs = (int)$st->fetchColumn();
    if ($bs) $_GET['site'] = (string)$bs; else $bedId = 0;
}
[$sites, $site] = garden_site_pick($pdo, $org);
$manage = garden_can_manage();
$areas = $site ? $svc->locations($pdo, $org, (int)$site['id']) : [];
$names = garden_area_options($areas);
$kinds = garden_area_kinds();
$choices = garden_bed_choices();
$sid = $site ? (int)$site['id'] : 0;
$today = bcrf_today();
$titles = ['map' => 'Garden map', 'schedule' => 'Bed schedule', 'soil' => 'Soil'];
$title = $bedId ? 'Bed' : ($titles[$tab] ?? 'Beds & areas');
$flash = $_SESSION['garden_flash'] ?? null; unset($_SESSION['garden_flash']);
$toast = ($flash && in_array($flash[0], ['ok', 'warning'], true)) ? $flash : null;
bcrf_shell_open($title . ' — Garden', ['toast' => $toast, 'css' => ['garden.css'], 'js' => ['garden.js']]);
$err = ($flash && $flash[0] === 'err') ? (string)$flash[1] : '';
$errAct = (string)(($_SESSION['garden_form'] ?? [])['act'] ?? '');
$hid = fn(string $act, array $x = []) => garden_hidden($act, ['site_id' => $sid] + $x);
$own = ['map' => ['area_place', 'area_grid'], 'schedule' => [], 'soil' => ['soil_add'], '' => ['site_add', 'site_save', 'farm_frost', 'area_add']];

/** The five bed detail selects and the notes box, for the add and change forms. $v = current values. */
$detailFields = function (array $v, string $idp) use ($h, $ui, $choices): string {
    $o = '<div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">';
    foreach (garden_bed_fields() as $f => $label) {
        $o .= '<div><label class="' . $ui['label'] . '" for="' . $idp . $f . '">' . $h($label) . '</label><select id="' . $idp . $f . '" name="' . $f . '" class="' . $ui['input'] . '">'
            . garden_options($choices[$f], $v[$f] ?? '', $f === 'orientation' ? 'From the map' : 'Not set') . '</select></div>';
    }
    $cur = (string)($v['color'] ?? '');
    $o .= '</div><fieldset class="mb-3"><legend class="' . $ui['label'] . '">Bed color (shows on the map)</legend><div class="gm-colors">'
        . '<label class="gm-color"><input type="radio" name="color" value=""' . ($cur === '' ? ' checked' : '') . '> None</label>';
    foreach (garden_bed_colors() as $hex => $cn) $o .= '<label class="gm-color"><input type="radio" name="color" value="' . $hex . '"' . ($cur === $hex ? ' checked' : '') . '>' . garden_swatch($hex) . $h($cn) . '</label>';
    $o .= '</div></fieldset><div class="mb-3"><label class="' . $ui['label'] . '" for="' . $idp . 'notes">Notes about this bed</label><textarea id="' . $idp . 'notes" name="bed_notes" rows="2" maxlength="2000" placeholder="Drainage, shade from the barn after 3 PM, where the spigot is…" class="' . $ui['input'] . '">' . $h($v['bed_notes'] ?? $v['notes'] ?? '') . '</textarea></div>';
    return $o;
};

// ── One bed ──
if ($bedId && $site) {
    $a = null; foreach ($areas as $x) if ((int)$x['id'] === $bedId) $a = $x;
    $plantings = (new GrowingRecords())->plantings($pdo, $org, $sid);
    $mine = array_values(array_filter($plantings, fn($p) => (int)$p['location_id'] === $bedId));
    $rot = garden_rotation_warnings($plantings);
    $cap = [$bedId => $a['area_sq_m'] !== null ? (float)$a['area_sq_m'] : ($a['length_m'] !== null ? (float)$a['length_m'] * (float)$a['width_m'] : null)];
    $over = garden_bed_overlaps($mine, $cap)[$bedId] ?? [];
    $byId = []; foreach ($mine as $p) $byId[(int)$p['id']] = $p;
    $kidsOf = []; foreach ($areas as $x) if ($x['parent_id'] !== null) $kidsOf[(int)$x['parent_id']] = true;
    $statuses = ['planned' => 'Planned', 'active' => 'Growing', 'completed' => 'Done', 'failed' => 'Failed'];
    $taskN = count(garden_tasks($pdo, [$bedId], true, 100));
    echo '<p class="mb-2"><a class="', $ui['link'], ' text-sm" href="areas.php?site=', $sid, '&amp;tab=map&amp;focus=', $bedId, '">← Garden map</a></p>';
    echo '<h1 class="', $ui['h1'], '">', !empty($a['color']) ? garden_swatch($a['color']) : '', $h($names[$bedId] ?? $a['name']), '</h1>';
    if ($err !== '' && !in_array($errAct, ['area_save', 'area_retire', 'soil_add'], true)) echo bcrf_shell_notice('err', $err);
    // What it's like
    $facts = [['Type', $kinds[$a['kind']] ?? garden_label($a['kind'])]];
    if ($a['length_m'] !== null) $facts[] = ['Size', garden_m_to_ft($a['length_m']) . ' × ' . garden_m_to_ft($a['width_m']) . ' ft (' . garden_sqm_to_sqft($cap[$bedId]) . ' sq ft)'];
    elseif ($a['area_sq_m'] !== null) $facts[] = ['Size', garden_sqm_to_sqft($a['area_sq_m']) . ' sq ft'];
    foreach (garden_bed_fields() as $f => $label) {
        $v = $f === 'orientation' ? garden_bed_runs($a) : ($a[$f] !== null ? ($choices[$f][$a[$f]] ?? '') : '');
        $facts[] = [$label, $v !== '' ? $v : 'Not set'];
    }
    if (!empty($a['color'])) $facts[] = ['Color', garden_bed_colors()[$a['color']] ?? $a['color']];
    $soilRows = array_values(array_filter($soil->soil($pdo, $org, $sid), fn($r) => (int)$r['location_id'] === $bedId));
    foreach ($soilRows as $r) if ($r['ph'] !== null) { $facts[] = ['Last pH', garden_num($r['ph']) . ' (' . garden_date($r['observed_on']) . ')']; break; }
    echo '<div class="', $ui['card'], '"><dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">';
    foreach ($facts as [$k, $v]) echo '<div><dt class="text-stone-500">', $h($k), '</dt><dd', $v === 'Not set' ? ' class="text-stone-400"' : '', '>', $h($v), '</dd></div>';
    echo '</dl>';
    if ($a['notes']) echo '<p class="text-sm text-stone-700 mt-3">', nl2br($h($a['notes'])), '</p>';
    echo '<div class="flex flex-wrap gap-2 mt-3"><a class="', $ui['btnSec'], '" href="plantings.php?location=', $bedId, '#form-plant_add">Add a planting</a>',
         '<a class="', $ui['btnSec'], '" href="areas.php?site=', $sid, '&amp;tab=map&amp;focus=', $bedId, '">Show on map</a>',
         '<a class="', $ui['btnSec'], '" href="/admin/tasks.php?new=1&amp;related=garden_area:', $bedId, '">Add a task</a>',
         $taskN ? '<a class="' . $ui['btnSec'] . '" href="/admin/tasks.php?tab=all&amp;related=garden_area:' . $bedId . '">Show tasks (' . $taskN . ')</a>' : '', '</div></div>';
    // Crowding
    foreach ($over as $r) {
        $crops = implode(', ', array_unique(array_map(fn($x) => $byId[$x]['crop_name'], $r['ids'])));
        $when = garden_date($r['from']) . ($r['to'] === null ? ' on' : '–' . garden_date($r['to']));
        echo bcrf_shell_notice('warning', $r['over'] ? 'Over full ' . $when . ': ' . $crops . ' need ' . garden_sqm_to_sqft($r['used']) . ' sq ft and the bed has ' . garden_sqm_to_sqft($cap[$bedId]) . '.'
                                                    : 'Sharing the bed ' . $when . ': ' . $crops . '. Set bed space on each planting to check they fit.');
    }
    // History by year
    $byYear = [];
    foreach ($mine as $p) $byYear[substr((string)$p['planted_on'], 0, 4)][] = $p;
    krsort($byYear);
    echo '<div class="', $ui['card'], '"><h2 class="', $ui['h2'], '">Planting history</h2>';
    if (!$byYear) echo '<p class="', $ui['muted'], '">Nothing planted here yet.</p>';
    foreach ($byYear as $y => $list) {
        echo '<h3 class="text-sm font-semibold text-stone-600 mt-3 mb-1">', $h($y), '</h3><ul class="divide-y divide-stone-100">';
        foreach ($list as $p) {
            [$s, $e, $est] = garden_planting_span($p);
            echo '<li class="py-2 text-sm">', garden_swatch(garden_family_color($p['crop_family'])),
                 '<a class="', $ui['link'], '" href="plantings.php?id=', (int)$p['id'], '">', $h($p['crop_name'] . ' — ' . $p['variety_name']), '</a>',
                 ' <span class="text-stone-500">· ', $h($statuses[$p['status']] ?? $p['status']), ' · ', $h(garden_date($s)), $e !== null ? '–' . $h(garden_date($e)) . ($est ? ' (about)' : '') : ' on',
                 ' · ', $h(garden_family_label($p['crop_family'])), '</span>';
            if (!empty($rot[(int)$p['id']])) echo '<span class="block text-amber-800">⚠️ ', $h(garden_rotation_text($rot[(int)$p['id']])), '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
    // Soil notes for this bed
    $t = garden_typed('soil_add'); ?>
  <div class="<?= $ui['card'] ?>">
    <h2 class="<?= $ui['h2'] ?>">Soil</h2>
    <?php if ($soilRows): ?><ul class="divide-y divide-stone-100 mb-3"><?php foreach ($soilRows as $r): ?><li class="py-2 text-sm"><?= $h(garden_date($r['observed_on'])) ?><?= $r['ph'] !== null ? ' · pH ' . $h(garden_num($r['ph'])) : '' ?><?php if ($r['notes']): ?><span class="block text-stone-600"><?= nl2br($h($r['notes'])) ?></span><?php endif; ?> <?= garden_delete_button('areas.php', 'soil', (int)$r['id'], 'Delete this soil note?', 'bed=' . $bedId) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p class="<?= $ui['muted'] ?> mb-3">No soil notes yet.</p><?php endif; ?>
    <details <?= $t ? 'open' : '' ?>><summary class="cursor-pointer text-sm font-semibold text-stone-700">Add a soil note</summary>
    <form id="form-soil_add" method="POST" action="areas.php" data-submit-once class="mt-3">
      <?= garden_form_error($err, $errAct, 'soil_add') ?><?= $hid('soil_add', ['location_id' => $bedId, 'from_bed' => '1']) ?>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div><label class="<?= $ui['label'] ?>" for="sd">Date</label><input id="sd" name="observed_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['observed_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="sp">pH</label><input id="sp" name="ph" inputmode="decimal" placeholder="6.5" value="<?= $h($t['ph'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <div class="mb-3"><label class="<?= $ui['label'] ?>" for="sn">Notes</label><textarea id="sn" name="notes" rows="2" maxlength="2000" placeholder="Lab results, texture, drainage…" class="<?= $ui['input'] ?>"><?= $h($t['notes'] ?? '') ?></textarea></div>
      <button class="<?= $ui['btnMain'] ?>">Save soil note</button>
    </form></details>
  </div>
<?php
    if ($manage) {
        $t = garden_typed('area_save'); $v = $t ?: $a; ?>
  <details class="<?= $ui['sec'] ?>" <?= $t ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Change this bed <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-area_save" method="POST" action="areas.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'area_save') ?><?= $hid('area_save', ['area_id' => $bedId]) ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="an">Name</label><input id="an" name="name" maxlength="160" required value="<?= $h($v['name']) ?>" class="<?= $ui['input'] ?>"></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="ak">Type</label><select id="ak" name="kind" class="<?= $ui['input'] ?>"><?= garden_options($kinds, $v['kind']) ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="al">Length (ft)</label><input id="al" name="length_ft" inputmode="decimal" value="<?= $h($t['length_ft'] ?? garden_m_to_ft($a['length_m'], 2)) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="aw">Width (ft)</label><input id="aw" name="width_ft" inputmode="decimal" value="<?= $h($t['width_ft'] ?? garden_m_to_ft($a['width_m'], 2)) ?>" class="<?= $ui['input'] ?>"></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="aa">Growing area (sq ft, if not a rectangle)</label><input id="aa" name="area_sqft" inputmode="decimal" value="<?= $h($t['area_sqft'] ?? ($a['length_m'] === null ? garden_sqm_to_sqft($a['area_sq_m']) : '')) ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <?= $detailFields($v, 'e') ?>
      <button class="<?= $ui['btnMain'] ?>">Save bed</button>
    </form>
    <form method="POST" action="areas.php" class="px-4 pb-4"><?= $hid('area_retire', ['area_id' => $bedId]) ?><button class="text-xs text-amber-800 underline" data-confirm="Retire <?= $h($a['name']) ?>? It leaves the lists and the map; its plantings, care and harvests stay on record.">Retire this bed (keep its history)</button></form>
    <div class="px-4 pb-4"><?= garden_delete_button('areas.php', 'area', $bedId, 'Delete ' . $a['name'] . ' for good? Its plantings, care, harvests, soil notes, watering plans' . (isset($kidsOf[$bedId]) ? ' and the beds inside it' : '') . ' are deleted too. This can\'t be undone.', 'site=' . $sid, '', 'Delete this bed and everything in it') ?></div>
  </details>
<?php }
    bcrf_shell_close(); exit;
}

echo '<h1 class="', $ui['h1'], '">', $h($title), '</h1>';
if ($err !== '' && !in_array($errAct, $own[$tab] ?? [], true)) echo bcrf_shell_notice('err', $err);
echo garden_site_pills($sites, $site, 'areas.php', $tab);

// ── No garden yet ──
if (!$site) {
    if (!$manage) { echo garden_need_site(); bcrf_shell_close(); exit; }
    $t = garden_typed('site_add'); ?>
  <form id="form-site_add" method="POST" action="areas.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'site_add') ?><?= garden_hidden('site_add') ?>
    <h2 class="<?= $ui['h2'] ?>">Name your garden</h2>
    <p class="<?= $ui['muted'] ?> mb-3">One garden is enough for most farms. Add beds, rows and greenhouses to it next.</p>
    <div class="max-w-sm mb-3"><label class="<?= $ui['label'] ?>" for="gn">Garden name</label><input id="gn" name="name" maxlength="160" required value="<?= $h($t['name'] ?? 'Kitchen garden') ?>" class="<?= $ui['input'] ?>"></div>
    <button class="<?= $ui['btnMain'] ?>">Add garden</button>
  </form>
<?php bcrf_shell_close(); exit; }

// ── Map ──
if ($tab === 'map') {
    $plantings = (new GrowingRecords())->plantings($pdo, $org, $sid);
    $seasons = garden_season_options($plantings, $today);
    $season = (string)($_GET['season'] ?? 'now');
    if (!isset($seasons[$season])) $season = 'now';
    $window = garden_season_window($season, $today);
    $data = garden_map_data($pdo, $org, $site, $areas, $plantings, $window, $today);
    $data['canEdit'] = $manage;
    $data['csrf'] = garden_csrf();
    $data['focus'] = (int)($_GET['focus'] ?? 0);
    $year = (int)substr($today, 0, 4);
    $quick = ['now' => 'Today', (string)$year => 'This year', (string)($year - 1) => 'Last year'];
    $placed = array_filter($data['beds'], fn($b) => $b['placed']);
    $unplaced = array_filter($data['beds'], fn($b) => $b['sized'] && !$b['placed']);
    $unsized = array_filter($data['beds'], fn($b) => !$b['sized']); ?>
  <form method="GET" action="areas.php" class="flex flex-wrap gap-2 items-end mb-3" data-season-form>
    <input type="hidden" name="tab" value="map"><input type="hidden" name="site" value="<?= $sid ?>">
    <nav class="flex flex-wrap gap-2" aria-label="Season"><?php foreach ($quick as $k => $l): ?><a class="<?= $ui['pill'] ?><?= $season === (string)$k ? ' is-active' : '' ?>"<?= $season === (string)$k ? ' aria-current="page"' : '' ?> href="areas.php?site=<?= $sid ?>&amp;tab=map&amp;season=<?= $h($k) ?>"><?= $h($l) ?></a><?php endforeach; ?></nav>
    <div class="min-w-40"><label class="sr-only" for="gs">Season</label><select id="gs" name="season" class="<?= $ui['input'] ?>"><?= garden_options($seasons, $season) ?></select></div>
    <button class="<?= $ui['btnSec'] ?>" data-season-go>Show</button>
  </form>
  <div class="<?= $ui['card'] ?> gm" data-garden-map>
    <div class="gm-bar" data-no-icons>
      <span class="gm-season"><?= $h($window[2] === 'Today' ? 'Today, ' . garden_date($today) : $window[2]) ?></span>
      <div class="gm-seg" role="group" aria-label="Color beds by">
        <button type="button" class="gm-segbtn" data-color-by="family" aria-pressed="true">Crop family</button>
        <button type="button" class="gm-segbtn" data-color-by="status" aria-pressed="false">Status</button>
        <button type="button" class="gm-segbtn" data-color-by="bed" aria-pressed="false">Bed color</button>
      </div>
      <?php if ($manage && $placed): ?><button type="button" class="gm-segbtn gm-move" data-move-toggle aria-pressed="false">Move beds</button><?php endif; ?>
    </div>
    <div class="gm-editbar" data-editbar hidden>
      <span data-edit-help>Drag a bed to move it. Tap one to pick it, then turn it.</span>
      <span class="gm-editbtns" data-no-icons>
        <button type="button" class="gm-segbtn" data-turn="90" disabled>Turn 90°</button>
        <button type="button" class="gm-segbtn" data-turn="15" disabled>Turn 15°</button>
      </span>
    </div>
    <?php if (!$placed): ?>
    <p class="<?= $ui['muted'] ?> py-6 text-center">Nothing on the map yet. <?= $manage ? 'Use "Lay out many beds" below, or add a bed to the map from the list.' : 'Ask a manager to lay out the beds.' ?></p>
    <?php else: ?>
    <div class="gm-stage" data-stage role="img" aria-label="Top-down map of <?= $h($site['name']) ?>. Top is north. Tap a bed for its details."></div>
    <p class="gm-scale" data-scale></p>
    <?php endif; ?>
    <p class="gm-status" data-status role="status" aria-live="polite"></p>
    <div class="gm-legend" data-legend></div>
  </div>
  <div class="<?= $ui['card'] ?> gm-panel" data-panel hidden tabindex="-1"></div>
  <script type="application/json" id="garden-map-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
  <?php if ($unplaced || $unsized): ?>
  <div class="<?= $ui['card'] ?>">
    <h2 class="<?= $ui['h2'] ?>">Not on the map yet</h2>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($unplaced as $b): ?><li class="py-2 text-sm flex flex-wrap justify-between gap-2 items-center"><span><a class="<?= $ui['link'] ?>" href="areas.php?bed=<?= (int)$b['id'] ?>"><?= $h($b['path']) ?></a> <span class="text-stone-500">· <?= $h(garden_num($b['len']) . ' × ' . garden_num($b['wid']) . ' ft') ?></span></span><?php if ($manage): ?><button type="button" class="<?= $ui['btnSec'] ?>" data-place="<?= (int)$b['id'] ?>">Add to map</button><?php endif; ?></li><?php endforeach; ?>
      <?php foreach ($unsized as $b): ?><li class="py-2 text-sm"><a class="<?= $ui['link'] ?>" href="areas.php?bed=<?= (int)$b['id'] ?>"><?= $h($b['path']) ?></a> <span class="text-stone-500">· needs a length and width first</span></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif;
    if ($manage) {
        $t = garden_typed('area_grid'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t || !$areas ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Lay out many beds <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-area_grid" method="POST" action="areas.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'area_grid') ?><?= $hid('area_grid') ?>
      <p class="<?= $ui['muted'] ?> mb-3">A grid of raised beds, or long rows side by side, made and placed in one go. Numbered left to right, then top to bottom. Drag any of them after.</p>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div><label class="<?= $ui['label'] ?>" for="gp">Name</label><input id="gp" name="prefix" maxlength="120" required value="<?= $h($t['prefix'] ?? 'Bed') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gf">First number</label><input id="gf" name="first" type="number" min="0" max="99999" required value="<?= $h($t['first'] ?? (string)(count($areas) + 1)) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gk">Type</label><select id="gk" name="kind" class="<?= $ui['input'] ?>"><?= garden_options($kinds, $t['kind'] ?? 'raised_bed') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="gr">Beds run</label><select id="gr" name="runs" class="<?= $ui['input'] ?>"><?= garden_options(['across' => 'Across the map', 'down' => 'Up and down the map'], $t['runs'] ?? 'across') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="gw">Rows of beds (down)</label><input id="gw" name="rows" type="number" min="1" max="200" required value="<?= $h($t['rows'] ?? '4') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gc">Beds in each row (across)</label><input id="gc" name="cols" type="number" min="1" max="200" required value="<?= $h($t['cols'] ?? '3') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gl">Bed length (ft)</label><input id="gl" name="length_ft" inputmode="decimal" required value="<?= $h($t['length_ft'] ?? '8') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gwd">Bed width (ft)</label><input id="gwd" name="width_ft" inputmode="decimal" required value="<?= $h($t['width_ft'] ?? '4') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gpa">Path between (ft)</label><input id="gpa" name="path_ft" inputmode="decimal" value="<?= $h($t['path_ft'] ?? '3') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gx">Start across (ft)</label><input id="gx" name="x_ft" inputmode="decimal" value="<?= $h($t['x_ft'] ?? '0') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gy">Start down (ft)</label><input id="gy" name="y_ft" inputmode="decimal" value="<?= $h($t['y_ft'] ?? '0') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="gi">Inside</label><select id="gi" name="parent_id" class="<?= $ui['input'] ?>"><option value="0">The garden itself</option><?= garden_options($names, $t['parent_id'] ?? '') ?></select></div>
      </div>
      <details class="mb-3"><summary class="cursor-pointer text-sm font-semibold text-stone-700">Same details for all of them (optional)</summary><div class="mt-3"><?= $detailFields($t, 'g') ?></div></details>
      <p class="text-xs text-stone-500 mb-3" data-grid-preview></p>
      <button class="<?= $ui['btnMain'] ?>">Add beds</button>
    </form>
  </details>
  <?php $t = garden_typed('area_place'); $sized = array_filter($areas, fn($a) => $a['length_m'] !== null); if ($sized): ?>
  <details class="<?= $ui['sec'] ?>" <?= $t ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Type a bed's exact spot <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-area_place" method="POST" action="areas.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'area_place') ?><?= $hid('area_place') ?>
      <p class="<?= $ui['muted'] ?> mb-3">Feet from the top-left corner of the garden (or of the area the bed sits in). Top of the map is north.</p>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pa">Bed</label><select id="pa" name="area_id" required class="<?= $ui['input'] ?>"><?= garden_options(array_intersect_key($names, array_flip(array_map(fn($a) => (int)$a['id'], $sized))), $t['area_id'] ?? '', 'Choose…') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="px">Across (ft)</label><input id="px" name="x_ft" inputmode="decimal" required value="<?= $h($t['x_ft'] ?? '0') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="py">Down (ft)</label><input id="py" name="y_ft" inputmode="decimal" required value="<?= $h($t['y_ft'] ?? '0') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="pr">Turn (degrees)</label><input id="pr" name="rotation" type="number" min="0" max="359" value="<?= $h($t['rotation'] ?? '0') ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <button class="<?= $ui['btnMain'] ?>">Save position</button>
    </form>
  </details>
  <?php endif; }
    bcrf_shell_close(); exit;
}

// ── Bed schedule (occupancy timeline) ──
if ($tab === 'schedule') {
    $plantings = (new GrowingRecords())->plantings($pdo, $org, $sid);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    if ($year < 1900 || $year > 2200) $year = (int)substr($today, 0, 4);
    $y0 = "$year-01-01"; $y1 = "$year-12-31";
    $days = (int)date('z', strtotime($y1)) + 1;
    $cap = []; foreach ($areas as $a) $cap[(int)$a['id']] = $a['area_sq_m'] !== null ? (float)$a['area_sq_m'] : ($a['length_m'] !== null ? (float)$a['length_m'] * (float)$a['width_m'] : null);
    $over = garden_bed_overlaps($plantings, $cap);
    $rot = garden_rotation_warnings($plantings);
    $byId = []; foreach ($plantings as $p) $byId[(int)$p['id']] = $p;
    $doy = fn(string $d) => (int)date('z', strtotime($d));
    $sc = garden_status_colors();
    echo '<nav class="flex flex-wrap gap-2 mb-3" aria-label="Year">';
    foreach ([$year - 1, $year, $year + 1] as $y) echo '<a class="', $ui['pill'], $y === $year ? ' is-active" aria-current="page"' : '"', ' href="areas.php?site=', $sid, '&amp;tab=schedule&amp;year=', $y, '">', $y, '</a>';
    echo '</nav><p class="text-xs text-stone-500 mb-3">Each bar is a planting, from planting day to when it comes out, colored by crop family. A faded bar with a dashed edge is a guess from days to maturity; set "Out of the bed by" on the planting to firm it up. Bars stacked in one bed share it at the same time.</p>';
    $empty = [];
    $months = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
    foreach ($names as $id => $label) {
        $rows = [];
        foreach ($plantings as $p) {
            if ((int)$p['location_id'] !== $id) continue;
            [$s, $e, $est] = garden_planting_span($p);
            if (!garden_spans_touch($s, $e, $y0, $y1)) continue;
            $rows[] = [$p, max($s, $y0), $e === null ? $y1 : min($e, $y1), $est, $e];
        }
        if (!$rows) { $empty[] = [$id, $label]; continue; }
        usort($rows, fn($a, $b) => strcmp($a[1], $b[1]));
        $lanes = []; $lane = [];
        foreach ($rows as $i => $r) {   // first lane whose last bar ended before this one starts
            $l = 0; while (isset($lanes[$l]) && $lanes[$l] >= $r[1]) $l++;
            $lanes[$l] = $r[2]; $lane[$i] = $l;
        }
        $nL = count($lanes); $bh = 16; $top = 14; $H = $top + $nL * ($bh + 4) + 2;
        echo '<div class="', $ui['card'], '"><h2 class="', $ui['h2'], '"><a class="', $ui['link'], '" href="areas.php?bed=', $id, '">', $h($label), '</a>', $cap[$id] !== null ? ' <span class="font-normal text-stone-500 text-sm">· ' . $h(garden_sqm_to_sqft($cap[$id])) . ' sq ft</span>' : '', '</h2>';
        echo '<svg class="gm-sched" viewBox="0 0 ', $days, ' ', $H, '" role="img" aria-label="', $h($label . ' plantings in ' . $year), '">';
        for ($m = 0; $m < 12; $m++) { $x = $doy(sprintf('%d-%02d-01', $year, $m + 1)); echo '<line x1="', $x, '" y1="0" x2="', $x, '" y2="', $H, '" class="gm-sched-grid"/><text x="', $x + 2, '" y="10" class="gm-sched-month">', $months[$m], '</text>'; }
        foreach ($rows as $i => [$p, $s, $e, $est, $realEnd]) {
            $x = $doy($s); $w = max(2, $doy($e) - $x + 1); $y = $top + $lane[$i] * ($bh + 4);
            $ms = garden_planting_map_status($p, $today);
            $title = $p['crop_name'] . ' — ' . $p['variety_name'] . ' · ' . ($sc[$ms][0] ?? $p['status']) . ' · ' . garden_date($p['planted_on']) . ($realEnd === null ? ' on' : '–' . garden_date($realEnd) . ($est ? ' (about)' : ''));
            echo '<a href="plantings.php?id=', (int)$p['id'], '"><title>', $h($title), '</title>',
                 '<rect x="', $x, '" y="', $y, '" width="', $w, '" height="', $bh, '" rx="3" fill="', $h(garden_family_color($p['crop_family'])), '" class="gm-sbar gm-sbar-', $h($ms), '"/>',
                 $est ? '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $bh . '" rx="3" class="gm-est"/>' : '',
                 '<text x="', $x + 3, '" y="', $y + 11.5, '" class="gm-sched-label">', $h(mb_strimwidth($p['crop_name'], 0, max(1, (int)($w / 6)), '…')), !empty($rot[(int)$p['id']]) ? ' ⚠' : '', '</text></a>';
        }
        if ($today >= $y0 && $today <= $y1) { $tx = $doy($today); echo '<line x1="', $tx, '" y1="12" x2="', $tx, '" y2="', $H, '" class="gm-sched-today"/>'; }
        echo '</svg>';
        foreach ($over[$id] ?? [] as $r) {
            if (!garden_spans_touch($r['from'], $r['to'], $y0, $y1)) continue;
            $crops = implode(', ', array_unique(array_map(fn($x) => $byId[$x]['crop_name'], $r['ids'])));
            $when = garden_date($r['from']) . ($r['to'] === null ? ' on' : '–' . garden_date($r['to']));
            echo '<p class="text-xs text-amber-800 mt-1">⚠️ ', $h($r['over'] ? 'Over full ' . $when . ': ' . $crops . ' need ' . garden_sqm_to_sqft($r['used']) . ' of ' . garden_sqm_to_sqft($cap[$id]) . ' sq ft.' : 'Sharing ' . $when . ': ' . $crops . '. Bed space isn\'t set on all of them.'), '</p>';
        }
        foreach ($rows as [$p]) if (!empty($rot[(int)$p['id']])) echo '<p class="text-xs text-amber-800 mt-1">⚠️ ', $h($p['crop_name'] . ': ' . garden_rotation_text($rot[(int)$p['id']])), '</p>';
        echo '</div>';
    }
    if ($empty) {
        echo '<div class="', $ui['card'], '"><h2 class="', $ui['h2'], '">Empty all of ', $year, '</h2><p class="text-sm">';
        foreach ($empty as $i => [$id, $label]) echo $i ? ', ' : '', '<a class="', $ui['link'], '" href="areas.php?bed=', $id, '">', $h($label), '</a>';
        echo '</p></div>';
    }
    if (!$names) echo garden_need_site();
    bcrf_shell_close(); exit;
}

// ── Soil ──
if ($tab === 'soil') {
    $rows = $soil->soil($pdo, $org, $sid);
    $t = garden_typed('soil_add'); ?>
  <?php if ($areas): ?>
  <form id="form-soil_add" method="POST" action="areas.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'soil_add') ?><?= $hid('soil_add') ?>
    <h2 class="<?= $ui['h2'] ?>">Add a soil note</h2>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="sl">Bed</label><select id="sl" name="location_id" required class="<?= $ui['input'] ?>"><?= garden_options($names, $t['location_id'] ?? '', 'Choose…') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="sd">Date</label><input id="sd" name="observed_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($t['observed_on'] ?? $today) ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="sp">pH</label><input id="sp" name="ph" inputmode="decimal" placeholder="6.5" value="<?= $h($t['ph'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <div class="mb-3"><label class="<?= $ui['label'] ?>" for="sn">Notes</label><textarea id="sn" name="notes" rows="2" maxlength="2000" placeholder="Lab results, texture, drainage…" class="<?= $ui['input'] ?>"><?= $h($t['notes'] ?? '') ?></textarea></div>
    <button class="<?= $ui['btnMain'] ?>">Save soil note</button>
  </form>
  <?php else: echo garden_need_site(); endif; ?>
  <div class="<?= $ui['card'] ?>">
    <h2 class="<?= $ui['h2'] ?>">Soil history</h2>
    <?php if (!$rows): ?><p class="<?= $ui['muted'] ?>">No soil notes yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rows as $r): ?>
      <li class="py-2 text-sm"><a class="<?= $ui['link'] ?>" href="areas.php?bed=<?= (int)$r['location_id'] ?>"><strong><?= $h($names[(int)$r['location_id']] ?? $r['location_name']) ?></strong></a> · <?= $h(garden_date($r['observed_on'])) ?><?= $r['ph'] !== null ? ' · pH ' . $h(garden_num($r['ph'])) : '' ?>
        <?php if ($r['notes']): ?><span class="block text-stone-600"><?= nl2br($h($r['notes'])) ?></span><?php endif; ?> <?= garden_delete_button('areas.php', 'soil', (int)$r['id'], 'Delete this soil note?', 'site=' . $sid . '&tab=soil') ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php bcrf_shell_close(); exit; }

// ── Beds & areas list ──
$kids = [];
foreach ($areas as $a) $kids[(string)($a['parent_id'] ?? '0')][] = $a;
$taskCounts = [];
foreach (garden_tasks($pdo, array_map(fn($a) => (int)$a['id'], $areas), true, 500) as $tk) $taskCounts[(int)$tk['related_id']] = ($taskCounts[(int)$tk['related_id']] ?? 0) + 1;
$render = function (string $pid, int $depth) use (&$render, $kids, $h, $ui, $kinds, $choices, $taskCounts, $sid) {
    foreach ($kids[$pid] ?? [] as $a) {
        $id = (int)$a['id'];
        $bits = [$kinds[$a['kind']] ?? garden_label($a['kind'])];
        if ($a['length_m'] !== null) $bits[] = garden_m_to_ft($a['length_m']) . ' × ' . garden_m_to_ft($a['width_m']) . ' ft';
        elseif ($a['area_sq_m'] !== null) $bits[] = garden_sqm_to_sqft($a['area_sq_m']) . ' sq ft';
        if ($a['sun_exposure']) $bits[] = explode(' (', $choices['sun_exposure'][$a['sun_exposure']])[0];
        if ($a['irrigation']) $bits[] = $choices['irrigation'][$a['irrigation']];
        if (!empty($taskCounts[$id])) $bits[] = (int)$taskCounts[$id] . ' open task' . ($taskCounts[$id] > 1 ? 's' : '');
        echo '<li id="area-', $id, '" class="py-2 text-sm flex flex-wrap justify-between gap-2', ['', ' pl-4', ' pl-8', ' pl-12'][min(3, $depth)], '"><span>', !empty($a['color']) ? garden_swatch($a['color']) : '', '<a class="', $ui['link'], '" href="areas.php?bed=', $id, '"><strong>', $h($a['name']), '</strong></a> <span class="text-stone-500">· ', $h(implode(' · ', $bits)), '</span></span>',
             garden_delete_button('areas.php', 'area', $id, 'Delete ' . $a['name'] . ' for good? Its plantings, care, harvests, soil notes and watering plans' . (!empty($kids[(string)$id]) ? ', and the beds inside it,' : '') . ' are deleted too. This can\'t be undone.', 'site=' . $sid, 'area-' . $id), '</li>';
        $render((string)$id, $depth + 1);
    }
};
?>
  <div class="<?= $ui['card'] ?>">
    <h2 class="<?= $ui['h2'] ?>"><?= $h($site['name']) ?></h2>
    <?php if (!$areas): ?><p class="<?= $ui['muted'] ?>">No beds yet.<?= $manage ? ' Add the first one below, or lay out many on the <a class="' . $ui['link'] . '" href="areas.php?site=' . $sid . '&amp;tab=map">Map</a>.' : '' ?></p>
    <?php else: ?><ul class="divide-y divide-stone-100"><?php $render('0', 0); ?></ul><?php endif; ?>
  </div>
<?php if ($manage): $t = garden_typed('area_add'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t || !$areas ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Add a bed or area <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-area_add" method="POST" action="areas.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'area_add') ?><?= $hid('area_add') ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="nn">Name</label><input id="nn" name="name" maxlength="160" required placeholder="Bed 1" value="<?= $h($t['name'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="nk">Type</label><select id="nk" name="kind" class="<?= $ui['input'] ?>"><?= garden_options($kinds, $t['kind'] ?? 'raised_bed') ?></select></div>
        <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="np">Inside</label><select id="np" name="parent_id" class="<?= $ui['input'] ?>"><option value="0">The garden itself</option><?= garden_options($names, $t['parent_id'] ?? '') ?></select></div>
        <div><label class="<?= $ui['label'] ?>" for="nl">Length (ft)</label><input id="nl" name="length_ft" inputmode="decimal" placeholder="8" value="<?= $h($t['length_ft'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="nw">Width (ft)</label><input id="nw" name="width_ft" inputmode="decimal" placeholder="4" value="<?= $h($t['width_ft'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <details class="mb-3" <?= $t ? 'open' : '' ?>><summary class="cursor-pointer text-sm font-semibold text-stone-700">Sun, watering, soil (optional)</summary><div class="mt-3"><?= $detailFields($t, 'n') ?></div></details>
      <button class="<?= $ui['btnMain'] ?>">Add bed</button>
    </form>
  </details>
  <?php $t = garden_typed('site_save'); $tf = garden_typed('farm_frost'); [$farmS, $farmF] = garden_farm_frost($pdo, $org);
  $showMd = function (?string $d): string { $md = garden_md($d); return $md === '' ? '' : garden_plan_year() . '-' . ($md === '02-29' ? '02-28' : $md); };
  $ownFrost = garden_md($site['last_spring_frost'] ?? null) !== '' && garden_md($site['first_fall_frost'] ?? null) !== ''; ?>
  <details class="<?= $ui['sec'] ?>" <?= $t || $tf || !$farmS ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Frost dates and garden settings <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-farm_frost" method="POST" action="areas.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'farm_frost') ?><?= garden_hidden('farm_frost', ['site_id' => $sid]) ?>
      <h2 class="<?= $ui['h2'] ?>">Frost dates for the whole farm</h2>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-2">
        <div><label class="<?= $ui['label'] ?>" for="fs">Last spring frost (average)</label><input id="fs" name="farm_spring" type="date" value="<?= $h($tf['farm_spring'] ?? $showMd($farmS)) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="fa">First fall frost (average)</label><input id="fa" name="farm_fall" type="date" value="<?= $h($tf['farm_fall'] ?? $showMd($farmF)) ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <p class="text-xs text-stone-500 mb-3">Set these once. Only the month and day count, so they carry over every year, and every garden uses them. Your county extension office lists them.</p>
      <button class="<?= $ui['btnMain'] ?>">Save frost dates</button>
    </form>
    <form id="form-site_save" method="POST" action="areas.php" data-submit-once class="px-4 pb-4 border-t border-stone-100 pt-3">
      <?= garden_form_error($err, $errAct, 'site_save') ?><?= $hid('site_save') ?>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
        <div><label class="<?= $ui['label'] ?>" for="sn">Garden name</label><input id="sn" name="name" maxlength="160" required value="<?= $h($t['name'] ?? $site['name']) ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <details class="mb-3" <?= $ownFrost || isset($t['last_spring_frost']) && $t['last_spring_frost'] !== '' ? 'open' : '' ?>><summary class="cursor-pointer text-sm font-semibold text-stone-700">This garden gets frost on different dates</summary>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
          <div><label class="<?= $ui['label'] ?>" for="sf">Last spring frost here</label><input id="sf" name="last_spring_frost" type="date" value="<?= $h($t['last_spring_frost'] ?? ($ownFrost ? $showMd($site['last_spring_frost']) : '')) ?>" class="<?= $ui['input'] ?>"></div>
          <div><label class="<?= $ui['label'] ?>" for="ff">First fall frost here</label><input id="ff" name="first_fall_frost" type="date" value="<?= $h($t['first_fall_frost'] ?? ($ownFrost ? $showMd($site['first_fall_frost']) : '')) ?>" class="<?= $ui['input'] ?>"></div>
        </div>
        <p class="text-xs text-stone-500 mt-2">Only for a spot that frosts earlier or later than the rest of the farm. Leave both empty to use the farm's dates.</p>
      </details>
      <button class="<?= $ui['btnSec'] ?>">Save garden</button>
    </form>
    <div class="px-4 pb-3"><?= garden_delete_button('areas.php', 'site', $sid, 'Delete the garden ' . $site['name'] . ' for good? Every bed, planting, harvest and note in it is deleted too. This can\'t be undone.', '', '', 'Delete this garden and everything in it') ?></div>
    <?php $t = garden_typed('site_add'); ?>
    <form id="form-site_add" method="POST" action="areas.php" data-submit-once class="px-4 pb-4 border-t border-stone-100 pt-3">
      <?= garden_form_error($err, $errAct, 'site_add') ?><?= garden_hidden('site_add') ?>
      <p class="text-xs text-stone-500 mb-2">Grow in a second spot (a field across the road)? Add it as its own garden.</p>
      <div class="flex flex-wrap gap-2 items-end"><div class="flex-1 min-w-40"><label class="<?= $ui['label'] ?>" for="s2">New garden name</label><input id="s2" name="name" maxlength="160" required value="<?= $h($t['name'] ?? '') ?>" class="<?= $ui['input'] ?>"></div>
      <button class="<?= $ui['btnSec'] ?>">Add garden</button></div>
    </form>
  </details>
<?php endif;
bcrf_shell_close();
