<?php
// admin/garden/areas.php — Garden › Beds & areas (Nov 18, 2026; includes/garden.php, includes/garden_areas.php, migration 0072).
//   GET  areas.php[?site=N]          the garden's beds, rows, plots, greenhouses and orchard blocks, nested; add, edit, retire
//   GET  areas.php?tab=map           top-down map of the beds that have a size and position (feet)
//   GET  areas.php?tab=soil          soil tests and notes per bed (pH)
//   POST act=site_add | site_save | area_add | area_save | area_place | area_retire  (garden_manage)
//        act=soil_add                                                                (garden)
// Sizes are typed in feet and kept in meters (the garden classes validate in metric).
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
use Garden\Domain\SoilPerennials;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    bcrf_shell_open('Beds & areas — Garden');
    echo '<h1 class="', $ui['h1'], '">Beds &amp; areas</h1>', bcrf_shell_notice('warning', 'Run migration 0072 in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
$svc = new GardenService();
$lay = new GrowingAreaLayout();
$soil = new SoilPerennials();
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? '');
$tz = bcrf_tz()->getName();

garden_post($pdo, 'areas.php', [
    'site_add' => [true, function () use ($pdo, $org, $svc, $tz) {
        $id = $svc->createSite($pdo, $org, garden_in('name'), $tz);
        return ['Garden added.', 'site=' . $id];
    }],
    'site_save' => [true, function () use ($pdo, $org, $svc, $tz) {
        $site = garden_id('site_id', 'Choose a garden.');
        $svc->updateSite($pdo, $org, $site, garden_in('name'), $tz);
        $svc->setFrostDates($pdo, $org, $site, garden_in('last_spring_frost') ?: null, garden_in('first_fall_frost') ?: null);
        return ['Garden saved.', 'site=' . $site];
    }],
    'area_add' => [true, function () use ($pdo, $org, $svc, $lay) {
        $site = garden_id('site_id', 'Choose a garden.');
        [$len, $wid, $area] = garden_area_size_in();
        $parent = (int)($_POST['parent_id'] ?? 0);
        $id = $parent > 0 ? $svc->addChildLocation($pdo, $org, $site, $parent, garden_in('name'), garden_in('kind'), $area)
                          : $svc->createLocation($pdo, $org, $site, garden_in('name'), garden_in('kind'), $area);
        if ($len !== '') $lay->save($pdo, $org, $site, $id, $len, $wid, '', '', '0');
        return ['Added ' . trim(garden_in('name')) . '.', 'site=' . $site, '#area-' . $id];
    }],
    'area_save' => [true, function () use ($pdo, $org, $svc, $lay) {
        $site = garden_id('site_id', 'Choose a garden.');
        $id = garden_id('area_id', 'Choose a bed or area.');
        [$len, $wid, $area] = garden_area_size_in();
        $svc->updateLocation($pdo, $org, $site, $id, garden_in('name'), garden_in('kind'), $area);
        $cur = garden_area_row($pdo, $org, $id);
        $lay->save($pdo, $org, $site, $id, $len, $wid, $len === '' ? '' : (string)($cur['layout_x_m'] ?? ''), $len === '' ? '' : (string)($cur['layout_y_m'] ?? ''), (string)(int)($cur['rotation_degrees'] ?? 0));
        return ['Saved ' . trim(garden_in('name')) . '.', 'site=' . $site, '#area-' . $id];
    }],
    'area_place' => [true, function () use ($pdo, $org, $lay) {
        $site = garden_id('site_id', 'Choose a garden.');
        $id = garden_id('area_id', 'Choose a bed or area.');
        $cur = garden_area_row($pdo, $org, $id);
        if (!$cur || $cur['length_m'] === null) throw new InvalidArgumentException('Give the bed a length and width first (Beds & areas › Edit).');
        $x = garden_ft_to_m(garden_in('x_ft')); $y = garden_ft_to_m(garden_in('y_ft'));
        $rot = trim(garden_in('rotation', '0')) === '' ? '0' : trim(garden_in('rotation', '0'));
        $lay->save($pdo, $org, $site, $id, (string)$cur['length_m'], (string)$cur['width_m'], $x, $y, $rot);
        return ['Map position saved.', 'site=' . $site . '&tab=map'];
    }],
    'area_retire' => [true, function () use ($pdo, $org, $svc) {
        $site = garden_id('site_id', 'Choose a garden.');
        $svc->retireLocation($pdo, $org, $site, garden_id('area_id', 'Choose a bed or area.'));
        return ['Retired. Its history stays in the records.', 'site=' . $site];
    }],
    'soil_add' => [false, function () use ($pdo, $org, $soil) {
        $site = garden_id('site_id', 'Choose a garden.');
        $soil->addSoil($pdo, $org, $site, garden_id('location_id', 'Choose a bed.'), garden_in('observed_on'), trim(garden_in('ph')), garden_in('notes'));
        return ['Soil note saved.', 'site=' . $site . '&tab=soil'];
    }],
]);

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

[$sites, $site] = garden_site_pick($pdo, $org);
$manage = garden_can_manage();
$areas = $site ? $svc->locations($pdo, $org, (int)$site['id']) : [];
$names = garden_area_options($areas);
$kinds = garden_area_kinds();
$sid = $site ? (int)$site['id'] : 0;
[$err, $errAct] = garden_open('Beds & areas');
$hid = fn(string $act, array $x = []) => garden_hidden($act, ['site_id' => $sid] + $x);

echo '<h1 class="', $ui['h1'], '">', $tab === 'map' ? 'Garden map' : ($tab === 'soil' ? 'Soil' : 'Beds &amp; areas'), '</h1>';
if ($err !== '' && !in_array($errAct, ['site_add', 'site_save', 'area_add', 'area_save', 'area_place', 'area_retire', 'soil_add'], true)) echo bcrf_shell_notice('err', $err);
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
    $groups = $lay->groups($areas);
    $byId = []; foreach ($areas as $a) $byId[(string)$a['id']] = $a;
    $drewAny = false;
    foreach ($groups as $key => $sib) {
        $drawn = array_values(array_filter($sib, fn($a) => $a['length_m'] !== null && $a['layout_x_m'] !== null));
        if (!$drawn) continue;
        $drewAny = true;
        $minX = 0.0; $minY = 0.0; $maxX = 1.0; $maxY = 1.0;
        if ($key !== 'site' && isset($byId[$key]) && $byId[$key]['length_m'] !== null) { $maxX = (float)$byId[$key]['length_m']; $maxY = (float)$byId[$key]['width_m']; }
        foreach ($drawn as $s) {
            $x = (float)$s['layout_x_m']; $y = (float)$s['layout_y_m']; $w = (float)$s['length_m']; $hh = (float)$s['width_m'];
            $r = deg2rad((int)$s['rotation_degrees']); $ew = abs($w * cos($r)) + abs($hh * sin($r)); $eh = abs($w * sin($r)) + abs($hh * cos($r));
            $cx = $x + $w / 2; $cy = $y + $hh / 2;
            $minX = min($minX, $cx - $ew / 2); $minY = min($minY, $cy - $eh / 2); $maxX = max($maxX, $cx + $ew / 2); $maxY = max($maxY, $cy + $eh / 2);
        }
        $scale = min(760 / max(1, $maxX - $minX), 420 / max(1, $maxY - $minY), 80);
        $cw = max(280, ($maxX - $minX) * $scale + 24); $ch = max(120, ($maxY - $minY) * $scale + 24);
        echo '<div class="', $ui['card'], '"><h2 class="', $ui['h2'], '">', $key === 'site' ? $h($site['name']) : $h($names[(int)$key] ?? 'Area'), '</h2>';
        echo '<div class="overflow-x-auto"><svg class="w-full h-auto block max-w-3xl" role="img" aria-label="Top-down map of ', $h($key === 'site' ? $site['name'] : ($names[(int)$key] ?? 'area')), '" viewBox="0 0 ', round($cw), ' ', round($ch), '"><rect x="0" y="0" width="', round($cw), '" height="', round($ch), '" rx="8" fill="#f5f1e9" stroke="#c4b8a5"/>';
        foreach ($drawn as $s) {
            $sx = 12 + ((float)$s['layout_x_m'] - $minX) * $scale; $sy = 12 + ((float)$s['layout_y_m'] - $minY) * $scale;
            $sw = (float)$s['length_m'] * $scale; $sh = (float)$s['width_m'] * $scale;
            $label = $s['name'] . ' · ' . garden_m_to_ft($s['length_m']) . ' × ' . garden_m_to_ft($s['width_m']) . ' ft';
            echo '<g transform="rotate(', (int)$s['rotation_degrees'], ' ', round($sx + $sw / 2, 1), ' ', round($sy + $sh / 2, 1), ')"><rect x="', round($sx, 1), '" y="', round($sy, 1), '" width="', round($sw, 1), '" height="', round($sh, 1), '" fill="', $s['kind'] === 'greenhouse' ? '#e7ecdf' : '#c7d4b5', '" stroke="#5c714b" stroke-width="1.5"><title>', $h($label), '</title></rect>';
            if ($sw >= 40 && $sh >= 16) echo '<text x="', round($sx + 4, 1), '" y="', round($sy + 13, 1), '" fill="#283622" font-size="11">', $h(mb_strimwidth($s['name'], 0, max(4, (int)($sw / 7)), '…')), '</text>';
            echo '</g>';
        }
        echo '</svg></div><p class="text-xs text-stone-500 mt-2">Top-left corner is 0, 0. Each box is one bed, drawn to scale.</p></div>';
    }
    if (!$drewAny) echo '<div class="', $ui['card'], '"><p class="', $ui['muted'], '">Nothing on the map yet. Give a bed its length and width, then place it below.</p></div>';
    if ($manage) {
        $sized = array_filter($areas, fn($a) => $a['length_m'] !== null);
        $t = garden_typed('area_place'); ?>
  <form id="form-area_place" method="POST" action="areas.php" data-submit-once class="<?= $ui['card'] ?>">
    <?= garden_form_error($err, $errAct, 'area_place') ?><?= $hid('area_place') ?>
    <h2 class="<?= $ui['h2'] ?>">Place a bed on the map</h2>
    <?php if (!$sized): ?><p class="<?= $ui['muted'] ?>">No bed has a length and width yet. Add them on the <a class="<?= $ui['link'] ?>" href="areas.php?site=<?= $sid ?>">Beds &amp; areas</a> tab.</p>
    <?php else: ?>
    <p class="<?= $ui['muted'] ?> mb-3">Feet from the top-left corner of the garden (or of the area the bed sits in). Turn it to angle the bed.</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      <div class="col-span-2"><label class="<?= $ui['label'] ?>" for="pa">Bed</label><select id="pa" name="area_id" required class="<?= $ui['input'] ?>"><?= garden_options(array_intersect_key($names, array_flip(array_map(fn($a) => (int)$a['id'], $sized))), $t['area_id'] ?? '', 'Choose…') ?></select></div>
      <div><label class="<?= $ui['label'] ?>" for="px">Across (ft)</label><input id="px" name="x_ft" inputmode="decimal" required value="<?= $h($t['x_ft'] ?? '0') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="py">Down (ft)</label><input id="py" name="y_ft" inputmode="decimal" required value="<?= $h($t['y_ft'] ?? '0') ?>" class="<?= $ui['input'] ?>"></div>
      <div><label class="<?= $ui['label'] ?>" for="pr">Turn (degrees)</label><input id="pr" name="rotation" type="number" min="0" max="359" value="<?= $h($t['rotation'] ?? '0') ?>" class="<?= $ui['input'] ?>"></div>
    </div>
    <button class="<?= $ui['btnMain'] ?>">Save position</button>
    <?php endif; ?>
  </form>
<?php }
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
      <div><label class="<?= $ui['label'] ?>" for="sd">Date</label><input id="sd" name="observed_on" type="date" required max="<?= $h(bcrf_today()) ?>" value="<?= $h($t['observed_on'] ?? bcrf_today()) ?>" class="<?= $ui['input'] ?>"></div>
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
      <li class="py-2 text-sm"><strong><?= $h($names[(int)$r['location_id']] ?? $r['location_name']) ?></strong> · <?= $h(garden_date($r['observed_on'])) ?><?= $r['ph'] !== null ? ' · pH ' . $h(garden_num($r['ph'])) : '' ?>
        <?php if ($r['notes']): ?><span class="block text-stone-600"><?= nl2br($h($r['notes'])) ?></span><?php endif; ?></li>
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
$render = function (string $pid, int $depth) use (&$render, $kids, $h, $ui, $kinds, $manage, $names, $hid, $taskCounts, $err, $errAct, $sid) {
    foreach ($kids[$pid] ?? [] as $a) {
        $id = (int)$a['id'];
        $size = $a['length_m'] !== null ? garden_m_to_ft($a['length_m']) . ' × ' . garden_m_to_ft($a['width_m']) . ' ft' : ($a['area_sq_m'] !== null ? garden_sqm_to_sqft($a['area_sq_m']) . ' sq ft' : '');
        $t = (garden_typed('area_save')['area_id'] ?? '') == $id ? garden_typed('area_save') : [];
        echo '<li id="area-', $id, '" class="py-2', ['', ' pl-4', ' pl-8', ' pl-12'][min(3, $depth)], '"><details', $t ? ' open' : '', '><summary class="cursor-pointer text-sm"><strong>', $h($a['name']), '</strong> <span class="text-stone-500">· ', $h($kinds[$a['kind']] ?? garden_label($a['kind'])), $size !== '' ? ' · ' . $h($size) : '', !empty($taskCounts[$id]) ? ' · ' . (int)$taskCounts[$id] . ' open task' . ($taskCounts[$id] > 1 ? 's' : '') : '', '</span></summary>';
        echo '<div class="mt-2 flex flex-wrap gap-2"><a class="', $ui['btnSec'], '" href="/admin/garden/plantings.php?site=', $sid, '&amp;area=', $id, '">Plantings</a><a class="', $ui['btnSec'], '" href="/admin/tasks.php?new=1&amp;related=garden_area:', $id, '">Add a task</a>';
        if (!empty($taskCounts[$id])) echo '<a class="', $ui['btnSec'], '" href="/admin/tasks.php?tab=all&amp;related=garden_area:', $id, '">Tasks</a>';
        echo '</div>';
        if ($manage) {
            echo '<form method="POST" action="areas.php" data-submit-once class="mt-3 border-t border-stone-100 pt-3">', $t ? garden_form_error($err, $errAct, 'area_save') : '', $hid('area_save', ['area_id' => $id, 'back_anchor' => 'area-' . $id]);
            echo '<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3"><div class="col-span-2"><label class="', $ui['label'], '" for="an', $id, '">Name</label><input id="an', $id, '" name="name" maxlength="160" required value="', $h($t['name'] ?? $a['name']), '" class="', $ui['input'], '"></div>';
            echo '<div class="col-span-2"><label class="', $ui['label'], '" for="ak', $id, '">Type</label><select id="ak', $id, '" name="kind" class="', $ui['input'], '">', garden_options($kinds, $t['kind'] ?? $a['kind']), '</select></div>';
            echo '<div><label class="', $ui['label'], '" for="al', $id, '">Length (ft)</label><input id="al', $id, '" name="length_ft" inputmode="decimal" value="', $h($t['length_ft'] ?? garden_m_to_ft($a['length_m'], 2)), '" class="', $ui['input'], '"></div>';
            echo '<div><label class="', $ui['label'], '" for="aw', $id, '">Width (ft)</label><input id="aw', $id, '" name="width_ft" inputmode="decimal" value="', $h($t['width_ft'] ?? garden_m_to_ft($a['width_m'], 2)), '" class="', $ui['input'], '"></div>';
            echo '<div class="col-span-2"><label class="', $ui['label'], '" for="aa', $id, '">Growing area (sq ft, if not a rectangle)</label><input id="aa', $id, '" name="area_sqft" inputmode="decimal" value="', $h($t['area_sqft'] ?? ($a['length_m'] === null ? garden_sqm_to_sqft($a['area_sq_m']) : '')), '" class="', $ui['input'], '"></div></div>';
            echo '<div class="flex flex-wrap gap-2"><button class="', $ui['btnMain'], '">Save</button></div></form>';
            echo '<form method="POST" action="areas.php" class="mt-2">', $hid('area_retire', ['area_id' => $id, 'back_anchor' => 'area-' . $id]), '<button class="text-xs text-amber-800 underline" data-confirm="Retire ', $h($a['name']), '? It leaves the lists; its plantings, care and harvests stay on record.">Retire this bed</button></form>';
        }
        echo '</details></li>';
        $render((string)$id, $depth + 1);
    }
};
?>
  <div class="<?= $ui['card'] ?>">
    <h2 class="<?= $ui['h2'] ?>"><?= $h($site['name']) ?></h2>
    <?php if (!$areas): ?><p class="<?= $ui['muted'] ?>">No beds yet.<?= $manage ? ' Add the first one below.' : '' ?></p>
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
      <button class="<?= $ui['btnMain'] ?>">Add bed</button>
    </form>
  </details>
  <?php $t = garden_typed('site_save'); ?>
  <details class="<?= $ui['sec'] ?>" <?= $t ? 'open' : '' ?>>
    <summary class="<?= $ui['sum'] ?>">Garden settings and frost dates <span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <form id="form-site_save" method="POST" action="areas.php" data-submit-once class="p-4">
      <?= garden_form_error($err, $errAct, 'site_save') ?><?= $hid('site_save') ?>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
        <div><label class="<?= $ui['label'] ?>" for="sn">Garden name</label><input id="sn" name="name" maxlength="160" required value="<?= $h($t['name'] ?? $site['name']) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="sf">Last spring frost (average)</label><input id="sf" name="last_spring_frost" type="date" value="<?= $h($t['last_spring_frost'] ?? $site['last_spring_frost']) ?>" class="<?= $ui['input'] ?>"></div>
        <div><label class="<?= $ui['label'] ?>" for="ff">First fall frost (average)</label><input id="ff" name="first_fall_frost" type="date" value="<?= $h($t['first_fall_frost'] ?? $site['first_fall_frost']) ?>" class="<?= $ui['input'] ?>"></div>
      </div>
      <p class="text-xs text-stone-500 mb-3">Frost dates drive the planting planner. Your county extension office lists them.</p>
      <button class="<?= $ui['btnMain'] ?>">Save garden</button>
    </form>
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
