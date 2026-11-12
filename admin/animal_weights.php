<?php
// admin/animal_weights.php — Animals › Weights (Farm OS Phase 5 slice 4, Nov 12, 2026; includes/animal_weights.php, migration 0043).
//   GET  animal_weights.php[?enc=N|?cls=N]   weigh a group: every animal on the farm in an enclosure or of a species, with its
//                                            last weight; plus the changes worth checking (alert percentage or more)
//   GET  animal_weights.php?tab=setup        the alert percentage (edit permission)
//   POST act=weigh | void | setting           (weigh and void also come from an animal's Weight tab and return there)
// Permission: table 'animals' view to record weights (staff weigh from their phones); edit to void or change the setting.
// Weights are private: admin only, never on the website.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/animal_weights.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$canEdit = hasTableAccess((string)($_SESSION['user_role'] ?? ''), 'animals', 'edit');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_weights_ready($pdo)) {
    bcrf_shell_open('Animal weights');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Weights</h1>', bcrf_shell_notice('warning', 'Run migration 0043 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $ret = (int)($_POST['return_animal'] ?? 0);
    $back = $ret ? 'animals.php?id=' . $ret . '&tab=weight' : 'animal_weights.php' . ((int)($_POST['enc'] ?? 0) ? '?enc=' . (int)$_POST['enc'] : ((int)($_POST['cls'] ?? 0) ? '?cls=' . (int)$_POST['cls'] : ''));
    $key = $ret ? 'an_flash' : 'aw_flash';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfWeightError('Security token expired; try again.');
        if ($act === 'weigh') {
            $r = bcrf_weight_add_many($pdo, (string)($_POST['weighed_on'] ?? ''), (array)($_POST['w'] ?? []), $uid);
            if (function_exists('erp_audit')) foreach ($r['ids'] as $wid) erp_audit($pdo, 'create', 'animal_weights', $wid, 'Weight recorded');
            $msg = 'Saved ' . $r['saved'] . ' weight' . ($r['saved'] > 1 ? 's' : '') . '.';
            if ($r['flagged']) {
                $_SESSION[$key] = ['err', $msg . ' Check: ' . implode(', ', array_map(fn($f) => $f[0] . ' ' . ($f[1] < 0 ? 'down ' : 'up ') . abs($f[1]) . '%', $r['flagged'])) . ' since last time.'];
            } else {
                $_SESSION[$key] = ['ok', $msg];
            }
        } elseif ($act === 'void') {
            if (!$canEdit) throw new BcrfWeightError('Ask a manager to void a weighing.');
            $wid = (int)($_POST['weight_id'] ?? 0);
            bcrf_weight_void($pdo, $wid, (string)($_POST['reason'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animal_weights', $wid, 'Weight voided: ' . trim((string)$_POST['reason']));
            $_SESSION[$key] = ['ok', 'Voided.'];
        } elseif ($act === 'setting') {
            if (!$canEdit) throw new BcrfWeightError('Ask a manager to change this.');
            $back = 'animal_weights.php?tab=setup';
            $v = bcrf_weight_set_alert_pct($pdo, (string)($_POST['alert_pct'] ?? ''));
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'site_settings', 0, 'Weight alert set to ' . $v . '%');
            $_SESSION['aw_flash'] = ['ok', 'Saved.'];
        } else {
            throw new BcrfWeightError('Unknown action.');
        }
    } catch (BcrfWeightError | BcrfAnimalError $e) {
        $_SESSION[$key] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[animal_weights] ' . $act . ': ' . $e->getMessage());
        $_SESSION[$key] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['aw_flash'] ?? null; unset($_SESSION['aw_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$hidden  = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
$tab = (string)($_GET['tab'] ?? '');
$alertPct = bcrf_weight_alert_pct($pdo);
$pctTxt = rtrim(rtrim(number_format($alertPct, 1, '.', ''), '0'), '.');

// ── Setup ──
if ($tab === 'setup' && $canEdit) {
    bcrf_shell_open('Animal weights', ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Weights</h1>';
    if ($error) echo bcrf_shell_notice('err', $error); ?>
  <form method="POST" action="animal_weights.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="setting">
    <h2 class="text-base font-semibold text-stone-800 mb-2">When to flag a change</h2>
    <p class="text-sm text-stone-500 mb-3">A weighing that is this much up or down from the animal's last one shows as an alert on its page and here.</p>
    <div class="flex flex-wrap gap-2 items-end">
      <div class="w-32"><label class="<?= $label ?>" for="ap">Percent</label><input id="ap" name="alert_pct" type="number" min="1" max="100" step="0.5" required value="<?= $h($pctTxt) ?>" class="<?= $input ?>"></div>
      <button class="<?= $btnMain ?>">Save</button>
    </div>
  </form>
<?php
    bcrf_shell_close(); exit;
}

// ── Weigh a group ──
$enc = (int)($_GET['enc'] ?? 0);
$cls = (int)($_GET['cls'] ?? 0);
$all = bcrf_animals_list($pdo);
$classes = [];
foreach ($all as $a) if (!empty($a['class_name'])) $classes[$a['class_name']] = true;
$clsRows = $pdo->query("SELECT id, class_name FROM classifications ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$clsRows = array_values(array_filter($clsRows, fn($c) => isset($classes[$c['class_name']])));
$clsName = '';
foreach ($clsRows as $c) if ((int)$c['id'] === $cls) $clsName = $c['class_name'];
$animals = $enc ? array_values(array_filter($all, fn($a) => (int)$a['enclosure_id'] === $enc))
         : ($clsName !== '' ? array_values(array_filter($all, fn($a) => $a['class_name'] === $clsName)) : $all);
$enclosures = array_values(array_filter(bcrf_enclosures($pdo), fn($e) => $e['animal_count'] > 0));
$latest = bcrf_weight_latest($pdo);
$alerts = bcrf_weight_alerts($pdo);
$today = bcrf_weight_today();

bcrf_shell_open('Animal weights', ['toast' => $toast, 'width' => 'max-w-3xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Weights</h1>';
if ($error) echo bcrf_shell_notice('err', $error);
?>
  <?php if ($alerts): ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Changes to check (<?= $h($pctTxt) ?>% or more)</h2>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($alerts as $al): ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><a class="underline text-amber-800" href="animals.php?id=<?= (int)$al['animal_id'] ?>&amp;tab=weight"><?= $h($al['animal_name']) ?></a>
        <span class="<?= $al['pct'] < 0 ? 'text-red-700 font-semibold' : 'text-stone-700' ?>"><?= $h(bcrf_weight_alert_text($al)) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <form method="GET" action="animal_weights.php" class="flex flex-wrap gap-2 items-end mb-4">
    <?php if ($enclosures): ?>
    <div class="flex-1 min-w-40"><label class="<?= $label ?>" for="fe">Enclosure</label><select id="fe" name="enc" class="<?= $input ?>"><option value="0">Any</option>
      <?php foreach ($enclosures as $e): ?><option value="<?= $e['id'] ?>" <?= $enc === $e['id'] ? 'selected' : '' ?>><?= $h($e['name']) ?> (<?= $e['animal_count'] ?>)</option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($clsRows): ?>
    <div class="flex-1 min-w-40"><label class="<?= $label ?>" for="fc">Or species</label><select id="fc" name="cls" class="<?= $input ?>"><option value="0">Any</option>
      <?php foreach ($clsRows as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cls === (int)$c['id'] ? 'selected' : '' ?>><?= $h($c['class_name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <button class="<?= $btnSec ?>">Show</button>
  </form>
  <?php if (!$animals): ?>
  <p class="text-sm text-stone-500">No animals on the farm<?= $enc || $cls ? ' in that group' : '' ?>.</p>
  <?php else: ?>
  <form method="POST" action="animal_weights.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="weigh"><input type="hidden" name="enc" value="<?= $enc ?>"><input type="hidden" name="cls" value="<?= $cls ?>">
    <div class="mb-3 max-w-xs"><label class="<?= $label ?>" for="wd">Weighed on</label><input id="wd" name="weighed_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($today) ?>" class="<?= $input ?>"></div>
    <p class="text-sm text-stone-500 mb-2">Pounds. Leave an animal blank to skip it. Body condition is optional (1 thin to 5 fat).</p>
    <ul class="divide-y divide-stone-100 mb-3">
      <?php foreach ($animals as $a): $l = $latest[(int)$a['id']] ?? null; $aid = (int)$a['id']; ?>
      <li class="py-2 grid grid-cols-6 gap-2 items-center">
        <div class="col-span-6 sm:col-span-2 text-sm"><strong><?= $h($a['name']) ?></strong>
          <span class="block text-xs text-stone-500"><?= $l ? 'Last ' . $h(bcrf_weight_fmt($l['weight_lb'])) . ' on ' . $h(date('M j', strtotime($l['weighed_on']))) : 'Not weighed yet' ?></span></div>
        <div class="col-span-3 sm:col-span-2"><label class="sr-only" for="w<?= $aid ?>">Weight for <?= $h($a['name']) ?></label><input id="w<?= $aid ?>" name="w[<?= $aid ?>][weight]" inputmode="decimal" placeholder="lb" class="<?= $input ?>"></div>
        <div class="col-span-3 sm:col-span-2"><label class="sr-only" for="b<?= $aid ?>">Body condition for <?= $h($a['name']) ?></label><input id="b<?= $aid ?>" name="w[<?= $aid ?>][body_condition]" type="number" min="1" max="5" step="0.5" placeholder="Condition" class="<?= $input ?>"></div>
      </li>
      <?php endforeach; ?>
    </ul>
    <button class="<?= $btnMain ?>">Save weights</button>
  </form>
  <?php endif; ?>
<?php
bcrf_shell_close();
