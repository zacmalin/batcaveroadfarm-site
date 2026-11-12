<?php
// admin/animal_breeding.php — Animals › Breeding (Farm OS Phase 5 slice 6, Nov 12, 2026; includes/animal_breeding.php, migration 0045).
//   GET  animal_breeding.php     across the farm: open breedings (soonest expected first) and outcomes in the last 90 days
//   POST act=add | status | birth | parents   (from an animal's Breeding tab, animals.php?id=N&tab=breeding; returns there)
// Permission: table 'animals' view to see; edit to record or change anything.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/animal_breeding.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$canEdit = hasTableAccess((string)($_SESSION['user_role'] ?? ''), 'animals', 'edit');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_breeding_ready($pdo)) {
    bcrf_shell_open('Breeding');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Breeding</h1>', bcrf_shell_notice('warning', 'Run migration 0045 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $animal = (int)($_POST['animal_id'] ?? 0);
    $back = $animal ? 'animals.php?id=' . $animal . '&tab=breeding' : 'animal_breeding.php';
    $key = $animal ? 'an_flash' : 'ab_flash';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfAnimalError('Security token expired; try again.');
        if (!$canEdit) throw new BcrfAnimalError('You can look at breeding records but not change them.');
        if ($act === 'add') {
            $id = bcrf_breeding_add($pdo, $animal, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'animal_breedings', $id, 'Breeding recorded');
            $b = bcrf_breeding_get($pdo, $id);
            $_SESSION[$key] = ['ok', 'Recorded.' . ($b['expected_on'] ? ' Expected around ' . date('M j', strtotime($b['expected_on'])) . '; a task goes on the list a week before.' : '')];
        } elseif ($act === 'status') {
            $bid = (int)($_POST['breeding_id'] ?? 0);
            $to = (string)($_POST['status'] ?? '');
            bcrf_breeding_set($pdo, $bid, $to, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animal_breedings', $bid, 'Breeding: ' . $to);
            $_SESSION[$key] = ['ok', 'Saved.'];
        } elseif ($act === 'birth') {
            $bid = (int)($_POST['breeding_id'] ?? 0);
            $ids = bcrf_breeding_birth($pdo, $bid, $_POST, $uid);
            if (function_exists('erp_audit')) {
                erp_audit($pdo, 'update', 'animal_breedings', $bid, 'Birth recorded');
                foreach ($ids as $kid) erp_audit($pdo, 'create', 'animals', $kid, 'Born on the farm');
            }
            $_SESSION[$key] = ['ok', $ids ? 'Birth recorded. ' . count($ids) . ' new animal' . (count($ids) > 1 ? 's are' : ' is') . ' on the roster (not on the website yet).' : 'Saved.'];
        } elseif ($act === 'parents') {
            bcrf_animal_set_parents($pdo, $animal, (int)($_POST['dam_id'] ?? 0), (int)($_POST['sire_id'] ?? 0));
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animals', $animal, 'Parents set');
            $_SESSION[$key] = ['ok', 'Saved.'];
        } else {
            throw new BcrfAnimalError('Unknown action.');
        }
    } catch (BcrfAnimalError $e) {
        $_SESSION[$key] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[animal_breeding] ' . $act . ': ' . $e->getMessage());
        $_SESSION[$key] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['ab_flash'] ?? null; unset($_SESSION['ab_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$card = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$open = bcrf_breeding_open($pdo);
$recent = bcrf_breeding_recent($pdo);
$today = bcrf_breeding_today();
bcrf_shell_open('Breeding', ['toast' => $toast, 'width' => 'max-w-3xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Breeding</h1>';
if ($error) echo bcrf_shell_notice('err', $error);
?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Expecting</h2>
    <?php if (!$open): ?><p class="text-sm text-stone-500">Nobody right now.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($open as $b): $late = $b['expected_on'] && $b['expected_on'] < $today; ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><a class="underline text-amber-800" href="animals.php?id=<?= (int)$b['dam_id'] ?>&amp;tab=breeding"><?= $h($b['dam_name']) ?></a>
        <span class="text-stone-700"><?= $h(BCRF_BREED_STATUSES[$b['status']]) ?> · sire <?= $h($b['sire_name'] ?: ($b['sire_outside'] ?: 'unknown')) ?></span>
        <span class="<?= $late ? 'text-red-700 font-semibold' : 'text-stone-500' ?>"><?= $b['expected_on'] ? ($late ? 'Was due ' : 'Due ') . $h(date('D M j', strtotime($b['expected_on']))) : 'No date' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Last 90 days</h2>
    <?php if (!$recent): ?><p class="text-sm text-stone-500">Nothing yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($recent as $b): ?>
      <li class="py-2 text-sm"><a class="underline text-amber-800" href="animals.php?id=<?= (int)$b['dam_id'] ?>&amp;tab=breeding"><?= $h($b['dam_name']) ?></a>
        <span class="block text-xs text-stone-500"><?= $h(bcrf_breeding_line($b)) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <p class="text-sm text-stone-500">Record breedings and births from the mother's Breeding tab. Kids born here go on the roster with their parents, and stay off the website until you publish them.</p>
<?php
bcrf_shell_close();
