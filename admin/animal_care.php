<?php
// admin/animal_care.php — Animals › Care (Farm OS Phase 5 slice 2, Nov 12, 2026; includes/animal_care.php, migration 0041).
//   GET  animal_care.php[?enc=N]               big buttons for the quick care types, then the latest entries
//   GET  animal_care.php?type=N[&animal=N|&enc=N]   the log form for one care type, ready for one animal or an enclosure
//   GET  animal_care.php?tab=log               all recent entries, with void
//   GET  animal_care.php?tab=setup             care types, care schedules per species (slice 5) and which products are feed
//                                              (edit permission)
//   POST act=log | void | type | feed | sched | type_active (In use switch on Setup, Nov 14, 2026; JSON when ajax=1)
//        | bags | measure | measure_rm (feed in pounds and scoops, Nov 15, 2026; migration 0071, admin/assets/feed-units.js)
// Section tabs (Log care, Recent, Setup) come from the sidebar.
// Permission: table 'animals' view to log care (staff do this from their phones); edit to void entries or change setup.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/animal_care.php';
if (is_file(__DIR__ . '/../includes/animal_schedules.php')) require_once __DIR__ . '/../includes/animal_schedules.php';   // slice 5
require_once __DIR__ . '/../includes/admin_shell.php';
require_once __DIR__ . '/../includes/admin_switch.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$canEdit = hasTableAccess((string)($_SESSION['user_role'] ?? ''), 'animals', 'edit');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$local = fn($utc, $fmt = 'M j, g:i A') => $utc ? date($fmt, strtotime($utc . ' UTC')) : '';

if (!bcrf_care_ready($pdo)) {
    bcrf_shell_open('Animal care');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Animal care</h1>', bcrf_shell_notice('warning', 'Run migrations 0040 and 0041 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $back = 'animal_care.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfCareError('Security token expired; try again.');
        if ($act === 'log') {
            $ret = (int)($_POST['return_animal'] ?? 0);
            if ($ret) $back = 'animals.php?id=' . $ret . '&tab=care';
            // One-time form key (Nov 16, 2026): a double tap or a resend on a slow connection can't log the feeding (and draw the
            // feed from stock) twice. PHP holds the session lock for the whole request, so a second copy waits and then sees the key.
            $once = (string)($_POST['once'] ?? '');
            if (!preg_match('/^[a-f0-9]{16,64}$/', $once)) $once = '';
            if ($once !== '' && isset($_SESSION['care_once'][$once])) {
                $_SESSION['ac_flash'] = ['ok', 'Already logged. That form was sent twice, so it was saved once.'];
                header('Location: ' . $back); exit;
            }
            $r = bcrf_care_log($pdo, ['type' => $_POST['type'] ?? 0, 'animals' => (array)($_POST['animals'] ?? []), 'enclosure' => $_POST['enclosure'] ?? 0,
                                      'note' => $_POST['note'] ?? '', 'feed_product' => $_POST['feed_product'] ?? 0, 'feed_qty' => $_POST['feed_qty'] ?? '',
                                      'feed_unit' => $_POST['feed_unit'] ?? ''], $uid);
            if ($once !== '') {
                $seen = (array)($_SESSION['care_once'] ?? []);
                $seen[$once] = time();
                $_SESSION['care_once'] = array_slice($seen, -30, null, true);
            }
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'animal_care_logs', $r['id'], 'Care logged for ' . $r['animals'] . ' animal(s)');
            $msg = 'Logged for ' . $r['animals'] . ' animal' . ($r['animals'] > 1 ? 's' : '') . '.';
            $_SESSION['ac_flash'] = $r['stock_note'] ? ['err', $msg . ' ' . $r['stock_note']] : ['ok', $msg];
            if (function_exists('bcrf_sched_generate')) bcrf_sched_generate($pdo);   // closes schedule tasks this covered
        } elseif ($act === 'void') {
            if (!$canEdit) throw new BcrfCareError('Ask a manager to void an entry.');
            $id = (int)($_POST['id'] ?? 0);
            $back = 'animal_care.php?tab=log';
            bcrf_care_void($pdo, $id, (string)($_POST['reason'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animal_care_logs', $id, 'Care entry voided: ' . trim((string)$_POST['reason']));
            $_SESSION['ac_flash'] = ['ok', 'Voided. Any feed went back into stock.'];
        } elseif ($act === 'type_active') {
            if (!$canEdit) throw new BcrfCareError('You can log care but not change the setup.');
            $back = 'animal_care.php?tab=setup&section=types';
            $want = bcrf_switch_wanted();
            $r = bcrf_switch_set($pdo, 'animal_care_types', 'active', (int)($_POST['type_id'] ?? 0), $want);
            if ($r === null) throw new BcrfCareError('That care type no longer exists.');
            $msg = $r['label'] . ($want ? ' is in use.' : ' is turned off. Staff won\'t see it; past entries stay.');
            if (bcrf_switch_ajax()) bcrf_switch_json(true, $msg, $want);
            $_SESSION['ac_flash'] = ['ok', $msg];
        } elseif ($act === 'type' || $act === 'feed') {
            if (!$canEdit) throw new BcrfCareError('You can log care but not change the setup.');
            $back = 'animal_care.php?tab=setup&section=types';
            if ($act === 'type') {
                $tid = (int)($_POST['type_id'] ?? 0);
                // In use has its own switch (type_active) since Nov 14, 2026, so Save keeps whatever it is now.
                $activeNow = true;
                if ($tid) { $st = $pdo->prepare("SELECT active FROM animal_care_types WHERE id = ?"); $st->execute([$tid]); $activeNow = (int)$st->fetchColumn() === 1; }
                $saved = bcrf_care_type_save($pdo, $tid, (string)($_POST['name'] ?? ''), !empty($_POST['is_feeding']), !empty($_POST['quick']), $activeNow, (int)($_POST['sort_order'] ?? 0));
                if (function_exists('erp_audit')) erp_audit($pdo, $tid ? 'update' : 'create', 'animal_care_types', $saved, 'Care type ' . trim((string)$_POST['name']));
                $_SESSION['ac_flash'] = ['ok', $tid ? 'Saved.' : 'Added.'];
            } else {
                bcrf_care_update_feed_ids($pdo, (array)($_POST['shown'] ?? []), (array)($_POST['feed'] ?? []));   // only what was on screen changes
                $back = 'animal_care.php?tab=setup&section=feed&fcat=' . rawurlencode((string)($_POST['fcat'] ?? '')) . '&fq=' . rawurlencode((string)($_POST['fq'] ?? ''));
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'site_settings', 0, 'Animal feed products changed');
                $_SESSION['ac_flash'] = ['ok', 'Feed list saved.'];
            }
        } elseif (in_array($act, ['bags', 'measure', 'measure_rm'], true)) {
            if (!$canEdit) throw new BcrfCareError('You can log care but not change the setup.');
            if (!bcrf_feed_units_ready($pdo)) throw new BcrfCareError('Run migration 0071 in Settings › Database first.');
            $back = 'animal_care.php?tab=setup&section=units#units';
            if ($act === 'bags') {
                $feedIds = bcrf_care_feed_ids($pdo);
                foreach ((array)($_POST['bag'] ?? []) as $pid => $lb) if (in_array((int)$pid, $feedIds, true)) bcrf_feed_bag_save($pdo, (int)$pid, $lb);
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'inventory', 0, 'Feed bag weights saved');
                $_SESSION['ac_flash'] = ['ok', 'Bag weights saved.'];
            } elseif ($act === 'measure') {
                $mid = (int)($_POST['measure_id'] ?? 0);
                $saved = bcrf_feed_measure_save($pdo, $mid, (string)($_POST['name'] ?? ''), $_POST['weight'] ?? '', (string)($_POST['weight_unit'] ?? ''), (int)($_POST['product_id'] ?? 0) ?: null);
                if (function_exists('erp_audit')) erp_audit($pdo, $mid ? 'update' : 'create', 'animal_feed_measures', $saved, 'Feed scoop ' . trim((string)$_POST['name']));
                $_SESSION['ac_flash'] = ['ok', $mid ? 'Saved.' : 'Scoop added.'];
            } else {
                $mid = (int)($_POST['measure_id'] ?? 0);
                bcrf_feed_measure_remove($pdo, $mid);
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animal_feed_measures', $mid, 'Feed scoop removed');
                $_SESSION['ac_flash'] = ['ok', 'Removed. Past feedings keep what was entered.'];
            }
        } elseif ($act === 'sched' && function_exists('bcrf_sched_save')) {
            if (!$canEdit) throw new BcrfCareError('You can log care but not change the setup.');
            $back = 'animal_care.php?tab=setup&section=schedules';
            $sid = (int)($_POST['sched_id'] ?? 0);
            $saved = bcrf_sched_save($pdo, $sid, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, $sid ? 'update' : 'create', 'animal_care_schedules', $saved, 'Care schedule saved');
            $_SESSION['ac_flash'] = ['ok', ($sid ? 'Saved.' : 'Added.') . ' Tasks are made a week before each animal is due.'];
        } else {
            throw new BcrfCareError('Unknown action.');
        }
    } catch (BcrfCareError | BcrfAnimalError $e) {
        if ($act === 'type_active' && bcrf_switch_ajax()) bcrf_switch_json(false, $e->getMessage());
        $_SESSION['ac_flash'] = ['err', $e->getMessage()];
        if ($act === 'log' && !(int)($_POST['return_animal'] ?? 0)) $back = 'animal_care.php?type=' . (int)($_POST['type'] ?? 0);
    } catch (Throwable $e) {
        error_log('[animal_care] ' . $act . ': ' . $e->getMessage());
        if ($act === 'type_active' && bcrf_switch_ajax()) bcrf_switch_json(false, 'Could not save. Try again.');
        $_SESSION['ac_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['ac_flash'] ?? null; unset($_SESSION['ac_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$btnBig  = 'block rounded-lg border border-stone-300 bg-white hover:bg-stone-100 px-4 py-4 text-center text-base font-semibold text-stone-800 shadow-sm';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$hidden  = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
$tab = (string)($_GET['tab'] ?? '');
$entryLine = function (array $r) use ($h, $local): string {
    $who = ($r['enclosure_name'] ? $r['enclosure_name'] . ': ' : '') . implode(', ', $r['animals']);
    $feed = $r['feed_name'] ? ' · ' . bcrf_feed_amount_text($r) : '';
    return '<span class="text-sm ' . ($r['voided_at'] ? 'line-through text-stone-500' : 'text-stone-800') . '"><strong>' . $h($r['type_name']) . '</strong>' . $h($feed) . '</span>'
         . '<span class="block text-xs text-stone-500">' . $h($who) . '</span>'
         . '<span class="block text-xs text-stone-500">' . $h($local($r['logged_at'])) . ($r['by_name'] ? ' · ' . $h($r['by_name']) : '') . ($r['note'] ? ' · ' . $h($r['note']) : '')
         . ($r['voided_at'] ? ' · voided: ' . $h($r['void_reason']) : '') . '</span>';
};

// ── Setup ──
if ($tab === 'setup' && $canEdit) {
    $types = bcrf_care_types($pdo, true);
    $feedIds = bcrf_care_feed_ids($pdo);
    $section = (string)($_GET['section'] ?? 'types');
    if (!in_array($section, ['types', 'schedules', 'feed', 'units'], true)) $section = 'types';
    bcrf_shell_open('Animal care', ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animal care</h1>';
    if ($error) echo bcrf_shell_notice('err', $error); ?>
  <details name="care-setup" class="site-info-section bg-white border border-stone-200 rounded-lg shadow-sm mb-4 overflow-hidden" <?= $section === 'types' ? 'open' : '' ?>>
    <summary class="flex items-center justify-between gap-3 cursor-pointer font-semibold text-stone-800"><span>📋 Care Types</span><span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <div class="p-4">
    <h2 class="sr-only">What staff can log</h2>
    <p class="text-sm text-stone-500 mb-3">Quick types get a big button on the phone. A feeding type asks which feed and how much.</p>
    <ul class="divide-y divide-stone-100">
    <?php foreach ($types as $t): ?>
      <li class="py-3<?= $t['active'] ? '' : ' is-off' ?>" data-switch-row><form method="POST" action="animal_care.php" class="ui-swmain flex flex-wrap gap-2 items-end"><?= $hidden ?><input type="hidden" name="act" value="type"><input type="hidden" name="type_id" value="<?= $t['id'] ?>">
        <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="tn<?= $t['id'] ?>">Name</label><input id="tn<?= $t['id'] ?>" name="name" maxlength="60" required value="<?= $h($t['name']) ?>" class="<?= $input ?>"></div>
        <div class="w-24"><label class="<?= $label ?>" for="to<?= $t['id'] ?>">Order</label><input id="to<?= $t['id'] ?>" name="sort_order" type="number" value="<?= $t['sort_order'] ?>" class="<?= $input ?>"></div>
        <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="quick" value="1" <?= $t['quick'] ? 'checked' : '' ?>> Quick</label>
        <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="is_feeding" value="1" <?= $t['is_feeding'] ? 'checked' : '' ?>> Feeding</label>
        <button class="<?= $btnSec ?>">Save</button></form>
        <div class="flex items-center gap-2 mt-1 text-sm text-stone-600">In use
          <?= bcrf_switch(['action' => 'animal_care.php', 'csrf' => $csrf, 'fields' => ['act' => 'type_active', 'type_id' => (int)$t['id']],
                           'on' => (bool)$t['active'], 'label' => $t['name'] . ' in use']) ?></div></li>
    <?php endforeach; ?>
    </ul>
    <form method="POST" action="animal_care.php" class="flex flex-wrap gap-2 items-end mt-3"><?= $hidden ?><input type="hidden" name="act" value="type"><input type="hidden" name="type_id" value="0">
      <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="tnew">Add a care type</label><input id="tnew" name="name" maxlength="60" required placeholder="e.g. Dust bath" class="<?= $input ?>"></div>
      <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="quick" value="1"> Quick</label>
      <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="is_feeding" value="1"> Feeding</label>
      <button class="<?= $btnMain ?>">Add</button></form>
    </div>
  </details>
  <?php if (function_exists('bcrf_sched_ready') && bcrf_sched_ready($pdo)):
      $scheds = bcrf_sched_list($pdo); $species = bcrf_sched_species($pdo); $activeTypes = bcrf_care_types($pdo);
      $everyIn = function (array $s) { $d = (int)$s['every_days']; return $d % 7 === 0 ? [$d / 7, 'weeks'] : [$d, 'days']; }; ?>
  <details name="care-setup" class="site-info-section bg-white border border-stone-200 rounded-lg shadow-sm mb-4 overflow-hidden" <?= $section === 'schedules' ? 'open' : '' ?>>
    <summary class="flex items-center justify-between gap-3 cursor-pointer font-semibold text-stone-800"><span>📅 Care Schedules</span><span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <div class="p-4">
    <p class="text-sm text-stone-500 mb-3">Routines by species, like hoof trims every 8 weeks for goats. A week before each animal is due, a task shows up on Tasks. Finishing the task logs the care, and the next one is counted from that day.</p>
    <?php if ($scheds): ?>
    <ul class="divide-y divide-stone-100 mb-3">
    <?php foreach ($scheds as $s): [$en, $eu] = $everyIn($s); $sid = (int)$s['id']; ?>
      <li class="py-2"><details><summary class="cursor-pointer text-sm <?= $s['active'] ? '' : 'text-stone-500' ?>"><strong><?= $h($s['class_name']) ?>: <?= $h($s['type_name']) ?></strong> <?= $h(bcrf_sched_every_text((int)$s['every_days'])) ?> · <?= (int)$s['animal_count'] ?> on the farm<?= $s['active'] ? '' : ' · off' ?></summary>
        <form method="POST" action="animal_care.php" class="mt-3"><?= $hidden ?><input type="hidden" name="act" value="sched"><input type="hidden" name="sched_id" value="<?= $sid ?>">
          <input type="hidden" name="classification_id" value="<?= (int)$s['classification_id'] ?>"><input type="hidden" name="care_type_id" value="<?= (int)$s['care_type_id'] ?>">
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
            <div><label class="<?= $label ?>" for="se<?= $sid ?>">Every</label><input id="se<?= $sid ?>" name="every" type="number" min="1" max="730" required value="<?= $en ?>" class="<?= $input ?>"></div>
            <div><label class="<?= $label ?>" for="su<?= $sid ?>">Unit</label><select id="su<?= $sid ?>" name="unit" class="<?= $input ?>"><option value="weeks" <?= $eu === 'weeks' ? 'selected' : '' ?>>weeks</option><option value="days" <?= $eu === 'days' ? 'selected' : '' ?>>days</option></select></div>
            <div class="col-span-2"><label class="<?= $label ?>" for="ss<?= $sid ?>">First due (animals never done)</label><input id="ss<?= $sid ?>" name="start_date" type="date" required value="<?= $h($s['start_date']) ?>" class="<?= $input ?>"></div>
          </div>
          <div class="mb-3"><label class="<?= $label ?>" for="sn<?= $sid ?>">Note on each task</label><input id="sn<?= $sid ?>" name="note" maxlength="500" value="<?= $h($s['note']) ?>" class="<?= $input ?>"></div>
          <label class="flex gap-1 items-center text-sm mb-3"><input type="checkbox" name="active" value="1" <?= $s['active'] ? 'checked' : '' ?> data-confirm-off="Stop this schedule? Its tasks that nobody has started will be cancelled."> In use (off cancels tasks nobody started)</label>
          <button class="<?= $btnSec ?>">Save</button>
        </form></details></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($species && $activeTypes): ?>
    <details <?= $scheds ? '' : 'open' ?>><summary class="cursor-pointer text-sm text-amber-800 underline">Add a schedule</summary>
    <form method="POST" action="animal_care.php" class="mt-3"><?= $hidden ?><input type="hidden" name="act" value="sched"><input type="hidden" name="sched_id" value="0">
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div><label class="<?= $label ?>" for="nsc">Species</label><select id="nsc" name="classification_id" class="<?= $input ?>"><?php foreach ($species as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $h($c['class_name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="<?= $label ?>" for="nst">What</label><select id="nst" name="care_type_id" class="<?= $input ?>"><?php foreach ($activeTypes as $t): ?><option value="<?= (int)$t['id'] ?>"><?= $h($t['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="<?= $label ?>" for="nse">Every</label><input id="nse" name="every" type="number" min="1" max="730" required value="8" class="<?= $input ?>"></div>
        <div><label class="<?= $label ?>" for="nsu">Unit</label><select id="nsu" name="unit" class="<?= $input ?>"><option value="weeks">weeks</option><option value="days">days</option></select></div>
        <div class="col-span-2"><label class="<?= $label ?>" for="nss">First due (animals never done)</label><input id="nss" name="start_date" type="date" required value="<?= $h(bcrf_tasks_today()) ?>" class="<?= $input ?>"></div>
      </div>
      <div class="mb-3"><label class="<?= $label ?>" for="nsn">Note on each task (optional)</label><input id="nsn" name="note" maxlength="500" class="<?= $input ?>" placeholder="e.g. trimmers are in the barn cabinet"></div>
      <button class="<?= $btnMain ?>">Add schedule</button>
    </form></details>
    <?php endif; ?>
    </div>
  </details>
  <?php endif; ?>
<?php
    // Feed picker (Nov 12, 2026): filter by inventory category (opens on "Feed") and search; ticked products always show first.
    $cats = bcrf_care_categories($pdo);
    $fcat = (string)($_GET['fcat'] ?? '');
    if ($fcat === '' || ($fcat !== 'all' && !isset($cats[(int)$fcat]))) { $fc = bcrf_care_feed_category($pdo); $fcat = $fc ? (string)$fc : 'all'; }
    $fq = mb_substr(trim((string)($_GET['fq'] ?? '')), 0, 60);
    $pick = bcrf_care_pick_products($pdo, $fcat === 'all' ? null : (int)$fcat, $fq); ?>
  <details name="care-setup" class="site-info-section bg-white border border-stone-200 rounded-lg shadow-sm mb-4 overflow-hidden" id="feed" <?= $section === 'feed' ? 'open' : '' ?>>
    <summary class="flex items-center justify-between gap-3 cursor-pointer font-semibold text-stone-800"><span>🌾 Feed Products</span><span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <div class="p-4">
    <p class="text-sm text-stone-500 mb-3">Tick the products you feed the animals. Logging a feeding takes the amount out of stock (as "Used on the farm") and records its cost. Products that aren't stocked are recorded without touching stock.</p>
    <form method="GET" action="animal_care.php#feed" class="flex flex-wrap items-end justify-end gap-2 mb-3"><input type="hidden" name="tab" value="setup"><input type="hidden" name="section" value="feed">
      <div><label class="<?= $label ?>" for="fcat">Category</label><select id="fcat" name="fcat" class="<?= $input ?>"><option value="all"<?= $fcat === 'all' ? ' selected' : '' ?>>All categories</option><?php foreach ($cats as $cid => $cn): ?><option value="<?= (int)$cid ?>"<?= $fcat === (string)$cid ? ' selected' : '' ?>><?= $h($cn) ?></option><?php endforeach; ?></select></div>
      <div class="flex-1 min-w-40"><label class="<?= $label ?>" for="fq">Search</label><input id="fq" name="fq" type="search" value="<?= $h($fq) ?>" class="<?= $input ?>" placeholder="Name or SKU"></div>
      <button class="<?= $btnSec ?>">Show</button>
    </form>
    <form method="POST" action="animal_care.php"><?= $hidden ?><input type="hidden" name="act" value="feed"><input type="hidden" name="fcat" value="<?= $h($fcat) ?>"><input type="hidden" name="fq" value="<?= $h($fq) ?>">
    <div class="max-h-96 overflow-y-auto border border-stone-200 rounded p-2 mb-3">
    <?php if ($pick['ticked']): ?><p class="text-xs font-semibold text-stone-500 uppercase tracking-wide">Feed now (<?= count($pick['ticked']) ?>)</p><?php endif; ?>
    <?php foreach ($pick['ticked'] as $p): ?>
      <label class="flex gap-2 items-center text-sm py-1"><input type="hidden" name="shown[]" value="<?= (int)$p['id'] ?>"><input type="checkbox" name="feed[]" value="<?= (int)$p['id'] ?>" checked> <?= $h($p['name']) ?><?php if ($p['category'] !== null): ?><span class="text-xs text-stone-500"><?= $h($p['category']) ?></span><?php endif; ?></label>
    <?php endforeach; ?>
    <?php if ($pick['ticked'] && $pick['others']): ?><p class="text-xs font-semibold text-stone-500 uppercase tracking-wide mt-2 pt-2 border-t border-stone-100">More products</p><?php endif; ?>
    <?php foreach ($pick['others'] as $p): ?>
      <label class="flex gap-2 items-center text-sm py-1"><input type="hidden" name="shown[]" value="<?= (int)$p['id'] ?>"><input type="checkbox" name="feed[]" value="<?= (int)$p['id'] ?>"> <?= $h($p['name']) ?><?php if ($fcat === 'all' && $p['category'] !== null): ?><span class="text-xs text-stone-500"><?= $h($p['category']) ?></span><?php endif; ?></label>
    <?php endforeach; ?>
    <?php if (!$pick['others']): ?><p class="text-sm text-stone-500 py-1"><?= $fq !== '' ? 'No other products match.' : 'No other products in this category.' ?> Try All categories.</p><?php endif; ?>
    <?php if ($pick['more']): ?><p class="text-xs text-stone-500 py-1">Showing the first 300. Search to narrow it down.</p><?php endif; ?>
    </div>
    <button class="<?= $btnMain ?>">Save feed list</button>
    </form>
    </div>
  </details>
<?php
    // Feed in pounds and scoops (Nov 15, 2026; migration 0071).
    if (!bcrf_feed_units_ready($pdo)) {
        echo bcrf_shell_notice('warning', 'Run migration 0071 in Settings › Database to log feed in pounds and scoops.');
        bcrf_shell_close(); exit;
    }
    $feedRows = bcrf_care_feed_products($pdo);
    $measures = bcrf_feed_measures($pdo); ?>
  <details name="care-setup" class="site-info-section bg-white border border-stone-200 rounded-lg shadow-sm mb-4 overflow-hidden" id="units" <?= $section === 'units' ? 'open' : '' ?>>
    <summary class="flex items-center justify-between gap-3 cursor-pointer font-semibold text-stone-800"><span>⚖️ Bag Weights &amp; Scoops</span><span class="site-info-chevron" aria-hidden="true">⌄</span></summary>
    <div class="p-4">
    <p class="text-sm text-stone-500 mb-3">Stock and cost are counted in bags. Feedings can be logged in scoops, lb, oz or bags, and the app works out the share of a bag. A feed needs its bag weight for that; it's the same as Size on the product.</p>
    <?php if ($feedRows): ?>
    <form method="POST" action="animal_care.php" class="mb-4"><?= $hidden ?><input type="hidden" name="act" value="bags">
      <ul class="divide-y divide-stone-100 mb-3">
      <?php foreach ($feedRows as $p): $soldByWeight = bcrf_feed_g((string)$p['unit']) !== null;
            $bagLb = $p['unit_g'] && !$soldByWeight ? round($p['unit_g'] / 453.59237, 2) : null; ?>
        <li class="py-2 flex flex-wrap gap-2 items-end">
          <div class="flex-1 min-w-48 text-sm"><?= $h($p['name']) ?><?php if ((int)$p['track_inventory'] === 1): ?><span class="block text-xs text-stone-500"><?= $h($p['stock_text']) ?> on hand</span><?php endif; ?></div>
          <?php if ($soldByWeight): ?><span class="text-xs text-stone-500">Counted in <?= $h($p['unit']) ?></span>
          <?php else: ?><div class="w-32"><label class="<?= $label ?>" for="bw<?= (int)$p['id'] ?>">Bag weight (lb)</label><input id="bw<?= (int)$p['id'] ?>" name="bag[<?= (int)$p['id'] ?>]" type="number" min="0" step="any" inputmode="decimal" value="<?= $bagLb !== null ? $h(bcrf_qtxt($bagLb)) : '' ?>" placeholder="e.g. 50" class="<?= $input ?>"></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
      <button class="<?= $btnSec ?>">Save bag weights</button>
    </form>
    <?php else: ?><p class="text-sm text-stone-500 mb-4">Tick your feed products above first.</p><?php endif; ?>
    <h3 class="text-sm font-semibold text-stone-800 mb-1">Scoops</h3>
    <p class="text-xs text-stone-500 mb-2">What a full one weighs. A scoop for every feed shows on all of them; a scoop for one feed only shows on that one (corn and pellets weigh differently).</p>
    <?php if ($measures): ?>
    <ul class="divide-y divide-stone-100 mb-3">
    <?php foreach ($measures as $m): $mid = $m['id']; ?>
      <li class="py-2"><details><summary class="cursor-pointer text-sm"><strong><?= $h($m['name']) ?></strong> = <?= $h(bcrf_qtxt((float)$m['weight']) . ' ' . $m['weight_unit']) ?> · <?= $m['product_id'] ? $h($m['product_name'] ?? 'one feed') : 'every feed' ?></summary>
        <form method="POST" action="animal_care.php" class="flex flex-wrap gap-2 items-end mt-2"><?= $hidden ?><input type="hidden" name="act" value="measure"><input type="hidden" name="measure_id" value="<?= $mid ?>">
          <div class="flex-1 min-w-40"><label class="<?= $label ?>" for="mn<?= $mid ?>">Name</label><input id="mn<?= $mid ?>" name="name" maxlength="40" required value="<?= $h($m['name']) ?>" class="<?= $input ?>"></div>
          <div class="w-24"><label class="<?= $label ?>" for="mw<?= $mid ?>">Full one weighs</label><input id="mw<?= $mid ?>" name="weight" type="number" min="0" step="any" inputmode="decimal" required value="<?= $h(bcrf_qtxt((float)$m['weight'])) ?>" class="<?= $input ?>"></div>
          <div class="w-24"><label class="<?= $label ?>" for="mu<?= $mid ?>">Unit</label><select id="mu<?= $mid ?>" name="weight_unit" class="<?= $input ?>"><option value="lb" <?= $m['weight_unit'] === 'lb' ? 'selected' : '' ?>>lb</option><option value="oz" <?= $m['weight_unit'] === 'oz' ? 'selected' : '' ?>>oz</option></select></div>
          <div class="flex-1 min-w-40"><label class="<?= $label ?>" for="mp<?= $mid ?>">For</label><select id="mp<?= $mid ?>" name="product_id" class="<?= $input ?>"><option value="0">Every feed</option><?php foreach ($feedRows as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $m['product_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= $h($p['name']) ?></option><?php endforeach; ?></select></div>
          <button class="<?= $btnSec ?>">Save</button></form>
        <form method="POST" action="animal_care.php" class="mt-2"><?= $hidden ?><input type="hidden" name="act" value="measure_rm"><input type="hidden" name="measure_id" value="<?= $mid ?>">
          <button class="text-xs text-amber-800 underline" data-confirm="Remove <?= $h($m['name']) ?>? Past feedings keep what was entered.">Remove</button></form></details></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="POST" action="animal_care.php" class="flex flex-wrap gap-2 items-end"><?= $hidden ?><input type="hidden" name="act" value="measure"><input type="hidden" name="measure_id" value="0">
      <div class="flex-1 min-w-40"><label class="<?= $label ?>" for="mnn">Add a scoop</label><input id="mnn" name="name" maxlength="40" required placeholder="e.g. Coffee can" class="<?= $input ?>"></div>
      <div class="w-24"><label class="<?= $label ?>" for="mwn">Full one weighs</label><input id="mwn" name="weight" type="number" min="0" step="any" inputmode="decimal" required placeholder="3" class="<?= $input ?>"></div>
      <div class="w-24"><label class="<?= $label ?>" for="mun">Unit</label><select id="mun" name="weight_unit" class="<?= $input ?>"><option value="lb">lb</option><option value="oz">oz</option></select></div>
      <div class="flex-1 min-w-40"><label class="<?= $label ?>" for="mpn">For</label><select id="mpn" name="product_id" class="<?= $input ?>"><option value="0">Every feed</option><?php foreach ($feedRows as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $h($p['name']) ?></option><?php endforeach; ?></select></div>
      <button class="<?= $btnMain ?>">Add</button></form>
    </div>
  </details>
<?php
    bcrf_shell_close(); exit;
}

// ── Recent ──
if ($tab === 'log') {
    $careTypes = $pdo->query('SELECT DISTINCT t.id, t.name FROM animal_care_types t JOIN animal_care_logs l ON l.care_type_id = t.id ORDER BY t.name')->fetchAll(PDO::FETCH_ASSOC);
    $careType = max(0, (int)($_GET['care_type'] ?? 0));
    if ($careType && !in_array($careType, array_map(fn($t) => (int)$t['id'], $careTypes), true)) $careType = 0;
    $rows = bcrf_care_recent($pdo, 0, 200, true, $careType);
    bcrf_shell_open('Animal care', ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animal care</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
    ?>
  <nav aria-label="Filter care history by type" class="flex flex-wrap justify-end gap-2 mb-4">
    <a href="animal_care.php?tab=log" class="care-filter-pill<?= !$careType ? ' is-active' : '' ?>" <?= !$careType ? 'aria-current="page"' : '' ?>>All</a>
    <?php foreach ($careTypes as $ct): $selected = $careType === (int)$ct['id']; ?>
      <a href="animal_care.php?tab=log&amp;care_type=<?= (int)$ct['id'] ?>" class="care-filter-pill<?= $selected ? ' is-active' : '' ?>" <?= $selected ? 'aria-current="page"' : '' ?>><?= $h($ct['name']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$rows): ?><p class="text-sm text-stone-500">No care entries match this filter.</p><?php else: ?>
  <div class="<?= $card ?> p-0 overflow-hidden">
    <ul class="divide-y divide-stone-100">
    <?php foreach ($rows as $r): ?>
      <li class="px-4 py-2"><?= $entryLine($r) ?>
        <?php if ($canEdit && !$r['voided_at']): ?>
        <details class="mt-2"><summary class="cursor-pointer text-xs font-medium text-amber-800 underline underline-offset-2">Void entry</summary>
          <form method="POST" action="animal_care.php" class="flex flex-wrap gap-2 items-end mt-2"><?= $hidden ?><input type="hidden" name="act" value="void"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="vr<?= (int)$r['id'] ?>">Why</label><input id="vr<?= (int)$r['id'] ?>" name="reason" maxlength="255" required class="<?= $input ?>" placeholder="e.g. logged twice"></div>
            <button class="<?= $btnSec ?>" data-confirm="Void this entry? It stays on file<?= $r['feed_name'] ? ' and the feed goes back into stock' : '' ?>.">Void</button></form></details>
        <?php endif; ?></li>
    <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php
    bcrf_shell_close(); exit;
}

// ── Log form for one care type ──
$typeId = (int)($_GET['type'] ?? 0);
if ($typeId) {
    $type = bcrf_care_type($pdo, $typeId);
    if (!$type || !$type['active']) { header('Location: animal_care.php'); exit; }
    $oneAnimal = (int)($_GET['animal'] ?? 0);
    $preEnc = (int)($_GET['enc'] ?? 0);
    $enclosures = array_values(array_filter(bcrf_enclosures($pdo), fn($e) => $e['animal_count'] > 0));
    $animals = bcrf_animals_list($pdo);
    // Quick ticks by species or breed (Nov 15, 2026, admin/assets/care-pick.js): only groups with 2+ animals are offered.
    // Picking one ticks those boxes on top of what's already ticked; the server still gets plain animals[] ids.
    $pickKey = fn($s) => mb_strtolower(trim((string)$s));
    $pickGroups = ['species' => [], 'breed' => []];
    foreach ($animals as $a) {
        $sp = $pickKey($a['class_name']);
        if ($sp !== '') { $pickGroups['species'][$sp]['label'] = $a['class_name']; $pickGroups['species'][$sp]['n'] = ($pickGroups['species'][$sp]['n'] ?? 0) + 1; }
        if ($pickKey($a['breed_name']) !== '') {
            $bk = $sp . '|' . $pickKey($a['breed_name']);
            $pickGroups['breed'][$bk]['label'] = $a['breed_name'];
            $pickGroups['breed'][$bk]['n'] = ($pickGroups['breed'][$bk]['n'] ?? 0) + 1;
        }
    }
    foreach ($pickGroups as $gk => $list) {
        $list = array_filter($list, fn($g) => $g['n'] >= 2);
        uasort($list, fn($x, $y) => strcasecmp($x['label'], $y['label']));
        $pickGroups[$gk] = $list;
    }
    if (!$pickGroups['species'] && !$pickGroups['breed']) $pickGroups = [];
    $feeds = $type['is_feeding'] ? bcrf_care_feed_products($pdo) : [];
    $pageJs = array_merge($feeds ? ['feed-units.js'] : [], ['care-pick.js']);   // care-pick.js also keeps the "for N animals" count on the button
    bcrf_shell_open('Log ' . $type['name'], ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => $pageJs]);
    echo '<a href="', $oneAnimal ? 'animals.php?id=' . $oneAnimal . '&amp;tab=care' : 'animal_care.php', '" class="text-sm text-amber-800 underline">‹ Back</a>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mt-2 mb-3">', $h($type['name']), '</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if (!$animals) { echo bcrf_shell_notice('warning', 'No animals on the roster yet.'); bcrf_shell_close(); exit; } ?>
  <form method="POST" action="animal_care.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="log"><input type="hidden" name="once" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="type" value="<?= $type['id'] ?>">
    <?php if ($oneAnimal): ?><input type="hidden" name="return_animal" value="<?= $oneAnimal ?>"><?php endif; ?>
    <?php if ($enclosures): ?>
    <div class="mb-3"><label class="<?= $label ?>" for="le">Everyone in an enclosure</label>
      <select id="le" name="enclosure" class="<?= $input ?>"><option value="0">No, just the animals I tick below</option>
        <?php foreach ($enclosures as $e): ?><option value="<?= $e['id'] ?>" <?= $preEnc === $e['id'] ? 'selected' : '' ?>><?= $h($e['name']) ?> (<?= $e['animal_count'] ?>)</option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <fieldset class="mb-3"><legend class="<?= $label ?>"><?= $enclosures ? 'Or these animals' : 'Which animals' ?></legend>
      <?php if ($pickGroups): ?>
      <div class="flex gap-2 items-end mb-2" data-care-pick>
        <div class="flex-1 min-w-0"><label class="<?= $label ?>" for="lg">Tick a whole species or breed</label>
          <select id="lg" class="<?= $input ?>"><option value="">Pick a group…</option>
          <?php foreach (['species' => 'Species', 'breed' => 'Breed'] as $gk => $gl): if (!$pickGroups[$gk]) continue; ?>
            <optgroup label="<?= $gl ?>"><?php foreach ($pickGroups[$gk] as $key => $g): ?><option value="<?= $h($gk . ':' . $key) ?>">All <?= $h($g['label']) ?> (<?= $g['n'] ?>)</option><?php endforeach; ?></optgroup>
          <?php endforeach; ?></select></div>
        <button type="button" class="<?= $btnSec ?> shrink-0" data-pick-clear>Untick all</button>
      </div>
      <span id="lgh" class="block text-xs text-stone-500 mb-2" aria-live="polite"></span>
      <?php endif; ?>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-1 max-h-96 overflow-y-auto border border-stone-200 rounded p-2">
      <?php foreach ($animals as $a): $sp = $pickKey($a['class_name']); $br = $pickKey($a['breed_name']) !== '' ? $sp . '|' . $pickKey($a['breed_name']) : ''; ?>
        <label class="flex gap-2 items-center text-sm py-1 min-w-0"><input type="checkbox" name="animals[]" value="<?= (int)$a['id'] ?>" data-enc="<?= (int)$a['enclosure_id'] ?>" data-species="<?= $h($sp) ?>" data-breed="<?= $h($br) ?>" <?= $oneAnimal === (int)$a['id'] ? 'checked' : '' ?>> <span class="min-w-0"><span class="block truncate"><?= $h($a['name']) ?></span><?php if ($a['breed_name'] || $a['class_name']): ?><span class="block truncate text-xs text-stone-500"><?= $h($a['breed_name'] ?: $a['class_name']) ?></span><?php endif; ?></span></label>
      <?php endforeach; ?>
      </div></fieldset>
    <?php if ($type['is_feeding']): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="lf">Feed (optional)</label><select id="lf" name="feed_product" class="<?= $input ?>"><option value="0">Not recorded</option>
        <?php foreach ($feeds as $p): ?><option value="<?= (int)$p['id'] ?>"<?php if ($p['units']): ?> data-units="<?= $h(json_encode(array_map(fn($u) => ['key' => $u['key'], 'label' => $u['label'], 'g' => round($u['g'], 3), 'name' => $u['name']], $p['units']))) ?>" data-unit-g="<?= $h(round((float)$p['unit_g'], 3)) ?>" data-word="<?= $h(bcrf_feed_stock_word($p)) ?>" data-last="<?= $h($p['last_unit']) ?>"<?php endif; ?>><?= $h($p['name']) ?><?= (int)$p['track_inventory'] === 1 ? ' (' . $h($p['stock_text']) . ' on hand)' : '' ?></option><?php endforeach; ?></select>
        <?php if (!$feeds && $canEdit): ?><span class="block text-xs text-stone-500 mt-1">Pick your feed products under <a class="underline text-amber-800" href="animal_care.php?tab=setup">Setup</a>.</span><?php endif; ?></div>
      <div><div class="flex gap-2 items-end">
        <div class="flex-1"><label class="<?= $label ?>" for="lq">Amount</label><input id="lq" name="feed_qty" inputmode="decimal" class="<?= $input ?>" placeholder="e.g. 2"></div>
        <div class="flex-1" data-unit-wrap hidden><label class="<?= $label ?>" for="lu">Unit</label><select id="lu" name="feed_unit" class="<?= $input ?>" disabled></select></div>
      </div><span id="lqh" class="block text-xs text-stone-500 mt-1" aria-live="polite"></span></div>
    </div>
    <?php endif; ?>
    <div class="mb-3"><label class="<?= $label ?>" for="ln">Note (optional)</label><input id="ln" name="note" maxlength="500" class="<?= $input ?>" placeholder="<?= $type['is_feeding'] ? 'e.g. Hazel left half' : 'Anything worth noting' ?>"></div>
    <button class="<?= $btnMain ?> w-full sm:w-auto" data-care-save data-what="Log <?= $h(strtolower($type['name'])) ?>">Log <?= $h(strtolower($type['name'])) ?></button>
  </form>
<?php
    bcrf_shell_close(); exit;
}

// ── Log care: big buttons ──
if (function_exists('bcrf_sched_generate')) { try { bcrf_sched_generate($pdo); } catch (Throwable $e) { error_log('[animal_care] schedules: ' . $e->getMessage()); } }   // backstop for the nightly run
$types = bcrf_care_types($pdo);
$quick = array_values(array_filter($types, fn($t) => $t['quick']));
$other = array_values(array_filter($types, fn($t) => !$t['quick']));
$recent = bcrf_care_recent($pdo, 0, 10, false);
$encQ = (int)($_GET['enc'] ?? 0) ? '&amp;enc=' . (int)$_GET['enc'] : '';   // from Enclosures › Log care
bcrf_shell_open('Animal care', ['toast' => $toast, 'width' => 'max-w-3xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animal care</h1>';
if ($error) echo bcrf_shell_notice('err', $error);
?>
  <div class="grid grid-cols-2 gap-3 mb-4">
    <?php foreach ($quick as $t): ?><a class="<?= $btnBig ?>" href="animal_care.php?type=<?= $t['id'] ?><?= $encQ ?>"><?= $h($t['name']) ?></a><?php endforeach; ?>
  </div>
  <?php if ($other): ?>
  <div class="flex flex-wrap gap-2 mb-4">
    <?php foreach ($other as $t): ?><a class="<?= $btnSec ?>" href="animal_care.php?type=<?= $t['id'] ?><?= $encQ ?>"><?= $h($t['name']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <h2 class="text-base font-semibold text-stone-800 mb-2">Latest care</h2>
  <?php if (!$recent): ?><p class="text-sm text-stone-500">Nothing logged yet.</p><?php else: ?>
  <div class="<?= $card ?> p-0 overflow-hidden"><ul class="divide-y divide-stone-100">
    <?php foreach ($recent as $r): ?><li class="px-4 py-2"><?= $entryLine($r) ?></li><?php endforeach; ?>
  </ul></div>
  <?php endif; ?>
<?php
bcrf_shell_close();
