<?php
// admin/animal_health.php — Animals › Health (Farm OS Phase 5 slice 3, Nov 12, 2026; includes/animal_health.php, migration 0042).
//   GET  animal_health.php     across the farm: vaccinations due in the next 14 days or overdue, follow-ups, medications now
//   POST act=medical | med | med_stop | vacc   (from an animal's Health tab, animals.php?id=N&tab=health; returns there)
// Permission: table 'animals' view, plus the 'animal_health' view (Owners always; Managers by default). Each animal's
// records are shown on its Health tab in admin/animals.php.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/animal_health.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_health_ready($pdo)) {
    bcrf_shell_open('Animal health');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Animal health</h1>', bcrf_shell_notice('warning', 'Run migration 0042 in Settings › Database first.');
    bcrf_shell_close(); exit;
}
if (!bcrf_health_can()) {
    bcrf_shell_open('Animal health');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Animal health</h1>', bcrf_shell_notice('warning', 'Health records are for owners and managers. Ask an owner if you need access.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $animal = (int)($_POST['animal_id'] ?? 0);
    $back = $animal ? 'animals.php?id=' . $animal . '&tab=health' : 'animal_health.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfHealthError('Security token expired; try again.');
        if ($act === 'medical') {
            $rid = (int)($_POST['record_id'] ?? 0);
            $id = bcrf_medical_save($pdo, $rid, $animal, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, $rid ? 'update' : 'create', 'animal_medical_records', $id, 'Health record: ' . trim((string)$_POST['title']));
            $_SESSION['an_flash'] = ['ok', $rid ? 'Record updated.' : 'Record added.' . (trim((string)($_POST['follow_up_date'] ?? '')) !== '' ? ' A follow-up task is on the list.' : '')];
        } elseif ($act === 'med') {
            $id = bcrf_med_add($pdo, $animal, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'animal_medications', $id, 'Medication: ' . trim((string)$_POST['name']) . ' ' . trim((string)$_POST['dose']));
            $_SESSION['an_flash'] = ['ok', ($_POST['frequency'] ?? '') === 'as_needed' ? 'Medication added.' : 'Medication added. Dose tasks for today and tomorrow are on the list.'];
        } elseif ($act === 'med_stop') {
            $mid = (int)($_POST['med_id'] ?? 0);
            $n = bcrf_med_stop($pdo, $mid, (string)($_POST['reason'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animal_medications', $mid, 'Medication stopped');
            $_SESSION['an_flash'] = ['ok', 'Stopped.' . ($n ? ' ' . $n . ' upcoming dose task' . ($n > 1 ? 's' : '') . ' cancelled.' : '')];
        } elseif ($act === 'vacc') {
            $id = bcrf_vacc_add($pdo, $animal, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'animal_vaccinations', $id, 'Vaccination: ' . trim((string)$_POST['vaccine']));
            $_SESSION['an_flash'] = ['ok', 'Vaccination recorded.' . (trim((string)($_POST['next_due'] ?? '')) !== '' ? ' A task is set for the next one.' : '')];
        } else {
            throw new BcrfHealthError('Unknown action.');
        }
    } catch (BcrfHealthError $e) {
        $_SESSION['an_flash'] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[animal_health] ' . $act . ': ' . $e->getMessage());
        $_SESSION['an_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

bcrf_med_generate($pdo);   // backstop for the nightly run
$d = bcrf_health_due($pdo);
$card = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$day = fn($ymd) => date('D M j', strtotime($ymd));
bcrf_shell_open('Animal health', ['width' => 'max-w-3xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animal health</h1>';
?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Vaccinations due in the next <?= BCRF_HEALTH_DUE_DAYS ?> days</h2>
    <?php if (!$d['vaccinations']): ?><p class="text-sm text-stone-500">Nothing due.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($d['vaccinations'] as $v): $late = $v['next_due'] < $d['today']; ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><a class="underline text-amber-800" href="animals.php?id=<?= (int)$v['animal_id'] ?>&amp;tab=health"><?= $h($v['animal_name']) ?></a> <span><?= $h($v['vaccine']) ?></span>
        <span class="<?= $late ? 'text-red-700 font-semibold' : 'text-stone-500' ?>"><?= $late ? 'Overdue since ' : '' ?><?= $h($day($v['next_due'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Follow-ups</h2>
    <?php if (!$d['follow_ups']): ?><p class="text-sm text-stone-500">None coming up.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($d['follow_ups'] as $f): ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><a class="underline text-amber-800" href="animals.php?id=<?= (int)$f['animal_id'] ?>&amp;tab=health"><?= $h($f['animal_name']) ?></a> <span><?= $h($f['title']) ?></span>
        <span class="<?= $f['follow_up_date'] < $d['today'] ? 'text-red-700 font-semibold' : 'text-stone-500' ?>"><?= $h($day($f['follow_up_date'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">On medication now</h2>
    <?php if (!$d['medications']): ?><p class="text-sm text-stone-500">Nobody.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($d['medications'] as $m): ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><a class="underline text-amber-800" href="animals.php?id=<?= (int)$m['animal_id'] ?>&amp;tab=health"><?= $h($m['animal_name']) ?></a>
        <span><?= $h($m['name'] . ' ' . $m['dose']) ?>, <?= $h(bcrf_med_freq_text($m)) ?></span>
        <span class="text-stone-500"><?= $m['end_date'] ? 'until ' . $h($day($m['end_date'])) : 'until stopped' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <p class="text-sm text-stone-500">Add records, medications and vaccinations from each animal's Health tab. Dose, follow-up and vaccination tasks show on Tasks for everyone on the farm.</p>
<?php
bcrf_shell_close();
