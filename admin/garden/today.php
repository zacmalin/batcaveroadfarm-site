<?php
// admin/garden/today.php — Garden › Today (Nov 18, 2026; includes/garden.php, migration 0072). Read-only.
//   What needs doing in the garden: watering due, garden tasks (Farm OS Tasks linked to a bed), plantings and repeat sowings due
//   in the next two weeks, open pest and disease problems, and what was picked lately.
require_once __DIR__ . '/../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';
require_permission([['view', 'garden']]);
require_once __DIR__ . '/../../includes/garden.php';
require_once __DIR__ . '/../../includes/admin_shell.php';
date_default_timezone_set(bcrf_tz()->getName());

use Garden\Domain\GardenService;
use Garden\Domain\GrowingRecords;
use Garden\Domain\IrrigationService;
use Garden\Domain\CropHealth;
use Garden\Domain\PlantingJournal;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ui = garden_ui();
if (!garden_ready($pdo)) {
    bcrf_shell_open('Today — Garden');
    echo '<h1 class="', $ui['h1'], '">Garden today</h1>', bcrf_shell_notice('warning', 'Run the garden migrations (0072 and 0073) in Settings › Database first.');
    bcrf_shell_close(); exit;
}
$org = garden_org($pdo);
[$sites, $site] = garden_site_pick($pdo, $org);
garden_open('Today');
echo '<h1 class="', $ui['h1'], '">Garden today</h1>';
if (!$site) {
    echo '<div class="', $ui['card'], '"><p class="text-sm">Start by adding your garden and its beds on <a class="', $ui['link'], '" href="areas.php">Beds &amp; areas</a>, then your crops on <a class="', $ui['link'], '" href="crops.php">Crops</a>.</p></div>';
    bcrf_shell_close(); exit;
}
$sid = (int)$site['id'];
echo garden_site_pills($sites, $site, 'today.php');
$today = bcrf_today();
$soon = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
$areas = (new GardenService())->locations($pdo, $org, $sid);
$names = garden_area_options($areas);
$plantings = (new GrowingRecords())->plantings($pdo, $org, $sid);

$water = array_values(array_filter((new IrrigationService())->irrigation($pdo, $org, $sid), fn($w) => (int)$w['active'] === 1 && $w['next_due_on'] <= $today));
$tasks = garden_tasks($pdo, array_map(fn($a) => (int)$a['id'], $areas), true, 20);
$planned = array_values(array_filter($plantings, fn($p) => $p['status'] === 'planned' && $p['planted_on'] <= $soon));
usort($planned, fn($a, $b) => strcmp($a['planted_on'], $b['planted_on']));
$st = $pdo->prepare("SELECT r.schedule_id, r.round_number, r.due_on, s.location_id, COALESCE(oc.name, c.common_name) AS crop_name, COALESCE(ov.name, v.name) AS variety_name
                     FROM garden_succession_rounds r
                     JOIN garden_succession_schedules s ON s.id = r.schedule_id AND s.organization_id = r.organization_id
                     LEFT JOIN garden_varieties v ON v.id = s.variety_id LEFT JOIN garden_crops c ON c.id = v.crop_id
                     LEFT JOIN garden_organization_varieties ov ON ov.id = s.organization_variety_id LEFT JOIN garden_organization_crops oc ON oc.id = ov.crop_id
                     WHERE r.organization_id = ? AND s.site_id = ? AND r.planting_id IS NULL AND r.due_on <= ? ORDER BY r.due_on LIMIT 20");
$st->execute([$org, $sid, $soon]);
$rounds = $st->fetchAll(PDO::FETCH_ASSOC);
$pests = array_values(array_filter((new CropHealth())->incidents($pdo, $org, $sid), fn($i) => $i['status'] !== 'resolved'));
$picked = array_slice((new PlantingJournal())->harvestEvents($pdo, $org, $sid), 0, 6);
$growing = count(array_filter($plantings, fn($p) => $p['status'] === 'active'));

$card = function (string $title, string $href, string $link, array $rows, string $empty) use ($ui, $h) {
    echo '<div class="', $ui['card'], '"><div class="flex justify-between items-baseline gap-2 mb-2"><h2 class="', $ui['h2'], ' mb-0">', $h($title), '</h2><a class="text-sm ', $ui['link'], '" href="', $h($href), '">', $h($link), '</a></div>';
    if (!$rows) echo '<p class="', $ui['muted'], '">', $h($empty), '</p>';
    else { echo '<ul class="divide-y divide-stone-100">'; foreach ($rows as $r) echo '<li class="py-2 text-sm">', $r, '</li>'; echo '</ul>'; }
    echo '</div>';
};
?>
  <p class="text-sm text-stone-600 mb-4"><?= $growing ?> planting<?= $growing === 1 ? '' : 's' ?> growing in <?= count($areas) ?> bed<?= count($areas) === 1 ? '' : 's' ?>.</p>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-4">
<?php
$card('Water today', 'care.php?tab=water', 'Watering', array_map(fn($w) => '<strong>' . $h($names[(int)$w['location_id']] ?? $w['location_name']) . '</strong> · ' . $h($w['method'])
      . ($w['next_due_on'] < $today ? ' · <span class="text-red-700 font-semibold">late since ' . $h(garden_date($w['next_due_on'])) . '</span>' : ''), $water), 'Nothing due.');
$card('Garden tasks', '/admin/tasks.php', 'All tasks', array_map(fn($t) => '<a class="' . $ui['link'] . '" href="/admin/tasks.php?id=' . (int)$t['id'] . '">' . $h($t['title']) . '</a> · ' . $h($names[(int)$t['related_id']] ?? '')
      . ($t['due_date'] ? ' · ' . ($t['due_date'] < $today ? '<span class="text-red-700 font-semibold">' : '<span>') . 'due ' . $h(garden_date($t['due_date'])) . '</span>' : ''), $tasks), 'No open tasks on garden beds. Add one from a bed on Beds & areas.');
$card('To plant in the next 2 weeks', 'plantings.php', 'Plantings', array_merge(
      array_map(fn($p) => '<a class="' . $ui['link'] . '" href="plantings.php?id=' . (int)$p['id'] . '">' . $h($p['crop_name'] . ' — ' . $p['variety_name']) . '</a> · ' . $h($names[(int)$p['location_id']] ?? $p['location_name']) . ' · ' . $h(garden_date($p['planted_on'])), $planned),
      array_map(fn($r) => 'Repeat sowing ' . (int)$r['round_number'] . ': ' . $h($r['crop_name'] . ' — ' . $r['variety_name']) . ' · ' . $h($names[(int)$r['location_id']] ?? '') . ' · ' . ($r['due_on'] < $today ? '<span class="text-red-700 font-semibold">' : '<span>') . $h(garden_date($r['due_on'])) . '</span> <a class="' . $ui['link'] . '" href="plantings.php?tab=succession">Open</a>', $rounds)), 'Nothing planned.');
$card('Pests & disease', 'care.php?tab=pests', 'All problems', array_map(fn($i) => '<a class="' . $ui['link'] . '" href="care.php?tab=pests#pest-' . (int)$i['id'] . '">' . $h($i['identified_as']) . '</a> · ' . $h($i['location_name'] . ' · ' . $i['crop_name']) . ' · ' . $h(ucfirst($i['severity'])) . ($i['status'] === 'monitoring' ? ' · watching' : ''), $pests), 'No open problems.');
$card('Picked lately', 'harvest.php', 'Log harvest', array_map(fn($e) => $h(garden_date($e['harvested_on'])) . ' · <strong>' . $h(garden_num($e['quantity'], 3) . ' ' . $e['unit']) . '</strong> ' . $h($e['crop_name']) . ' · ' . $h($e['location_name']), $picked), 'Nothing picked yet.');
?>
  </div>
<?php
bcrf_shell_close();
