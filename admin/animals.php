<?php
// admin/animals.php — Animals › Roster and Enclosures (Farm OS Phase 5 slice 1, Nov 12, 2026; includes/animals.php, migration 0040).
//   GET  animals.php[?f=off|gone|all&enc=N&q=…]   the roster: on the farm (default), off visitor contact, left, or everyone
//   GET  animals.php?id=N[&tab=care|location|history]  one animal: overview (identity, status), care log (slice 2,
//                                                 admin/animal_care.php), where it has been, status history
//   GET  animals.php?tab=enclosures               pens, barns and pastures with counts and capacity
//   POST act=identity | status | move | move_many | enclosure
// Permission: table 'animals' view to look; edit to change anything. The public bio, photo and publish switch stay in
// the full editor (?view=schema&table=animals) for now.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/animals.php';
if (is_file(__DIR__ . '/../includes/animal_care.php')) require_once __DIR__ . '/../includes/animal_care.php';   // slice 2
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$canEdit = hasTableAccess((string)($_SESSION['user_role'] ?? ''), 'animals', 'edit');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$local = fn($utc, $fmt = 'M j, Y g:i A') => $utc ? date($fmt, strtotime($utc . ' UTC')) : '';

if (!bcrf_animals_ready($pdo)) {
    bcrf_shell_open('Animals');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Animals</h1>', bcrf_shell_notice('warning', 'Run migration 0040 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = $id ? 'animals.php?id=' . $id : 'animals.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfAnimalError('Security token expired; try again.');
        if (!$canEdit) throw new BcrfAnimalError('You can look at animals but not change them.');
        if ($act === 'identity') {
            bcrf_animal_save_identity($pdo, $id, $_POST);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animals', $id, 'Animal details updated');
            $_SESSION['an_flash'] = ['ok', 'Saved.'];
        } elseif ($act === 'status') {
            $to = (string)($_POST['status'] ?? '');
            $r = bcrf_animal_set_status($pdo, $id, $to, (string)($_POST['reason'] ?? ''), (string)($_POST['other_party'] ?? ''), (string)($_POST['sale_ref'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animals', $id, 'Status → ' . $to . ': ' . trim((string)($_POST['reason'] ?? '')));
            $more = [];
            if ($r['unpublished']) $more[] = 'its page is off the website';
            if ($r['left_enclosure']) $more[] = 'it left its enclosure';
            if ($r['tasks']) $more[] = $r['tasks'] . ' open task' . ($r['tasks'] > 1 ? 's' : '') . ' cancelled';
            $_SESSION['an_flash'] = ['ok', 'Now ' . strtolower(BCRF_ANIMAL_STATUSES[$to]) . ($more ? '; ' . implode(', ', $more) : '') . '.'];
        } elseif ($act === 'move' || $act === 'move_many') {
            $ids = $act === 'move' ? [$id] : (array)($_POST['animals'] ?? []);
            $enc = (int)($_POST['enclosure_id'] ?? 0);
            if ($act === 'move_many') $back = 'animals.php' . ($enc ? '?enc=' . $enc : '');
            $r = bcrf_animals_move($pdo, $ids, $enc, (string)($_POST['note'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animals', $act === 'move' ? $id : null, 'Moved ' . $r['moved'] . ' animal(s) to enclosure ' . $enc);
            $msg = $r['moved'] ? 'Moved ' . $r['moved'] . ' animal' . ($r['moved'] > 1 ? 's' : '') . '.' : 'Nothing to move; already there.';
            $_SESSION['an_flash'] = $r['warning'] ? ['err', $msg . ' Heads up: ' . $r['warning']] : ['ok', $msg];
        } elseif ($act === 'enclosure') {
            $eid = (int)($_POST['enclosure_id'] ?? 0);
            $cap = trim((string)($_POST['capacity'] ?? ''));
            $back = 'animals.php?tab=enclosures';
            $saved = bcrf_enclosure_save($pdo, $eid, (string)($_POST['name'] ?? ''), $cap === '' ? null : (int)$cap, (string)($_POST['notes'] ?? ''), !empty($_POST['active']) || !$eid, (int)($_POST['sort_order'] ?? 0));
            if (function_exists('erp_audit')) erp_audit($pdo, $eid ? 'update' : 'create', 'enclosures', $saved, 'Enclosure ' . trim((string)$_POST['name']));
            $_SESSION['an_flash'] = ['ok', $eid ? 'Saved.' : 'Added.'];
        } else {
            throw new BcrfAnimalError('Unknown action.');
        }
    } catch (BcrfAnimalError $e) {
        $_SESSION['an_flash'] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[animals] ' . $act . ': ' . $e->getMessage());
        $_SESSION['an_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['an_flash'] ?? null; unset($_SESSION['an_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$hidden  = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
$tab = (string)($_GET['tab'] ?? '');
$pill = function (string $status) use ($h): string {
    $cls = in_array($status, BCRF_ANIMAL_OFF_CONTACT, true) ? 'bg-amber-50 text-amber-800 border border-amber-300'
         : (bcrf_animal_is_leaving($status) ? 'bg-stone-100 text-stone-600 border border-stone-300' : 'bg-white text-green-700 border border-stone-200');
    return '<span class="inline-block rounded-full px-2 text-xs ' . $cls . '">' . $h(BCRF_ANIMAL_STATUSES[$status] ?? $status) . '</span>';
};
$enclosures = bcrf_enclosures($pdo);
$encOptions = function (?int $sel, bool $none) use ($enclosures, $h): string {
    $o = $none ? '<option value="0">Not in an enclosure</option>' : '';
    foreach ($enclosures as $e) {
        $o .= '<option value="' . $e['id'] . '"' . ($sel === $e['id'] ? ' selected' : '') . '>' . $h($e['name'])
            . ($e['capacity'] !== null ? ' (' . $e['animal_count'] . ' of ' . $e['capacity'] . ')' : ' (' . $e['animal_count'] . ')') . '</option>';
    }
    return $o;
};

// ── One animal ──
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $a = bcrf_animal_get($pdo, $id);
    if (!$a) { header('Location: animals.php'); exit; }
    $status = (string)$a['status'];
    $gone = bcrf_animal_is_leaving($status);
    bcrf_shell_open('Animal: ' . $a['name'], ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    echo '<a href="animals.php" class="text-sm text-amber-800 underline">‹ Roster</a>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mt-2 mb-1">', $h($a['name']), ' ', $pill($status), '</h1>';
    echo '<p class="text-sm text-stone-500 mb-3">', $h(trim(($a['class_name'] ?? '') . ' · ' . ($a['breed_name'] ?? ''), ' ·')), '</p>';
    echo '<div class="flex flex-wrap gap-1 mb-4">';
    $careOn = function_exists('bcrf_care_ready') && bcrf_care_ready($pdo);
    foreach (array_merge([['Overview', '']], $careOn ? [['Care', 'care']] : [], [['Location', 'location'], ['History', 'history']]) as [$t, $k]) {
        echo '<a href="animals.php?id=', $id, $k ? '&amp;tab=' . $k : '', '" class="px-3 py-2 rounded text-sm border ', $k === $tab ? 'border-stone-800 bg-stone-800 text-white' : 'border-stone-300 text-stone-700 bg-white hover:bg-stone-100', '">', $h($t), '</a>';
    }
    echo '</div>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if (in_array($status, BCRF_ANIMAL_OFF_CONTACT, true)) echo bcrf_shell_notice('warning', BCRF_ANIMAL_STATUSES[$status] . ': keep visitors away. It shows on the gate screen.');
    if ($gone) echo bcrf_shell_notice('info', 'This animal has left the farm (' . strtolower(BCRF_ANIMAL_STATUSES[$status]) . ' ' . $local($a['status_changed_at'], 'M j, Y') . '). Its records are kept.');

    if ($tab === 'care' && $careOn) {
        $last = bcrf_care_last_by_type($pdo, $id);
        $log = bcrf_care_recent($pdo, $id, 50); ?>
  <?php if (!$gone): ?>
  <div class="flex flex-wrap gap-2 mb-4">
    <?php foreach (bcrf_care_types($pdo) as $t): ?><a class="<?= $btnSec ?>" href="animal_care.php?type=<?= $t['id'] ?>&amp;animal=<?= $id ?>"><?= $h($t['name']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Last done</h2>
    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-1 text-sm">
    <?php foreach (bcrf_care_types($pdo) as $t): ?><li><?= $h($t['name']) ?>: <span class="text-stone-500"><?= isset($last[$t['id']]) ? $h($local($last[$t['id']], 'M j, g:i A')) : 'never' ?></span></li><?php endforeach; ?>
    </ul>
  </div>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Care log</h2>
    <?php if (!$log): ?><p class="text-sm text-stone-500">Nothing logged yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($log as $r): ?>
      <li class="py-2 text-sm <?= $r['voided_at'] ? 'line-through text-stone-500' : '' ?>"><strong><?= $h($r['type_name']) ?></strong><?= $r['feed_name'] ? ' · ' . $h(bcrf_qtxt((float)$r['feed_qty']) . ' of ' . $r['feed_name']) : '' ?>
        <span class="block text-xs text-stone-500"><?= $h($local($r['logged_at'], 'M j, g:i A')) ?><?= $r['by_name'] ? ' · ' . $h($r['by_name']) : '' ?><?= $r['enclosure_name'] ? ' · whole ' . $h($r['enclosure_name']) : (count($r['animals']) > 1 ? ' · with ' . $h(implode(', ', array_diff($r['animals'], [$a['name']]))) : '') ?><?= $r['note'] ? ' · ' . $h($r['note']) : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
        bcrf_shell_close(); exit;
    }

    if ($tab === 'location') {
        $locs = bcrf_animal_locations($pdo, $id);
        if ($canEdit && !$gone): ?>
  <form method="POST" action="animals.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="move"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Move <?= $h($a['name']) ?></h2>
    <?php if (!$enclosures): ?><p class="text-sm text-stone-500">Add your pens and barns under <a class="underline text-amber-800" href="animals.php?tab=enclosures">Enclosures</a> first.</p><?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="me">To</label><select id="me" name="enclosure_id" class="<?= $input ?>"><?= $encOptions($a['enclosure_id'] ? (int)$a['enclosure_id'] : null, true) ?></select></div>
      <div><label class="<?= $label ?>" for="mn">Note (optional)</label><input id="mn" name="note" maxlength="255" class="<?= $input ?>" placeholder="e.g. kidding pen"></div>
    </div>
    <button class="<?= $btnMain ?>">Move</button>
    <?php endif; ?>
  </form>
<?php   endif; ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Where it has been</h2>
    <?php if (!$locs): ?><p class="text-sm text-stone-500">No moves recorded yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($locs as $l): ?>
      <li class="py-2 text-sm"><strong><?= $h($l['enclosure_name'] ?? 'Removed enclosure') ?></strong>
        <span class="block text-xs text-stone-500"><?= $h($local($l['started_at'], 'M j, Y')) ?> – <?= $l['ended_at'] ? $h($local($l['ended_at'], 'M j, Y')) : 'now' ?><?= $l['moved_by_name'] ? ' · moved by ' . $h($l['moved_by_name']) : '' ?><?= $l['note'] ? ' · ' . $h($l['note']) : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
        bcrf_shell_close(); exit;
    }

    if ($tab === 'history') {
        $hist = bcrf_animal_status_history($pdo, $id); ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Status changes</h2>
    <?php if (!$hist): ?><p class="text-sm text-stone-500">Active since it was added. No changes yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($hist as $r): ?>
      <li class="py-2 text-sm"><?= $h(BCRF_ANIMAL_STATUSES[$r['from_status']] ?? '—') ?> → <strong><?= $h(BCRF_ANIMAL_STATUSES[$r['to_status']] ?? $r['to_status']) ?></strong>
        <span class="block text-xs text-stone-500"><?= $h($local($r['changed_at'])) ?><?= $r['changed_by_name'] ? ' · ' . $h($r['changed_by_name']) : '' ?><?= $r['reason'] ? ' · ' . $h($r['reason']) : '' ?><?= $r['other_party'] ? ' · to ' . $h($r['other_party']) : '' ?><?= $r['sale_ref'] ? ' · sale ' . $h($r['sale_ref']) : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
        bcrf_shell_close(); exit;
    }

    // Overview
    $age = bcrf_animal_age($a['date_of_birth']); ?>
  <div class="<?= $card ?> text-sm">
    <p>Age: <strong><?= $h($age ?: 'unknown') ?></strong><?= (int)$a['birth_estimated'] ? ' (estimated)' : '' ?> · born <?= $h($a['date_of_birth'] ? date('M j, Y', strtotime($a['date_of_birth'])) : '?') ?></p>
    <p class="mt-1">Sex: <?= $h(BCRF_ANIMAL_SEXES[$a['sex'] ?? ''] ?? 'not set') ?> · Enclosure: <?= $a['enclosure_name'] ? '<a class="underline text-amber-800" href="animals.php?enc=' . (int)$a['enclosure_id'] . '">' . $h($a['enclosure_name']) . '</a>' : 'none' ?></p>
    <p class="mt-1">Tag: <?= $h($a['tag'] ?: '—') ?> · Microchip: <?= $h($a['microchip'] ?: '—') ?></p>
    <p class="mt-1">Website: <?= (int)$a['is_active'] ? '<a class="underline text-amber-800" href="' . $h(bcrf_farm_url()) . '/petting-zoo/animals/animal.php?id=' . $id . '">published</a>' : 'not published' ?> · <a class="underline text-amber-800" href="admin.php?view=schema&amp;table=animals">Edit bio, photo and publishing</a></p>
  </div>
  <?php if ($canEdit): ?>
  <form method="POST" action="animals.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="identity"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Details</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="ds">Sex</label><select id="ds" name="sex" class="<?= $input ?>"><option value="">Not set</option>
        <?php foreach (BCRF_ANIMAL_SEXES as $k => $v): ?><option value="<?= $k ?>" <?= ($a['sex'] ?? '') === $k ? 'selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= $label ?>" for="db">Birth date</label><input id="db" name="date_of_birth" type="date" required max="<?= $h(date('Y-m-d')) ?>" value="<?= $h($a['date_of_birth']) ?>" class="<?= $input ?>">
        <label class="flex gap-2 items-center text-xs mt-1"><input type="checkbox" name="birth_estimated" value="1" <?= (int)$a['birth_estimated'] ? 'checked' : '' ?>> Estimated</label></div>
      <div><label class="<?= $label ?>" for="dt">Ear tag or band</label><input id="dt" name="tag" maxlength="60" value="<?= $h($a['tag']) ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="dm">Microchip</label><input id="dm" name="microchip" maxlength="60" value="<?= $h($a['microchip']) ?>" class="<?= $input ?>"></div>
    </div>
    <button class="<?= $btnSec ?>">Save details</button>
  </form>
  <form method="POST" action="animals.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Change status</h2>
    <p class="text-sm text-stone-500 mb-3">Resting and quarantined animals show on the gate so staff keep visitors away. Sold, transferred, adopted and deceased take its page off the website, end its enclosure stay and cancel its open tasks; its records are kept.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="ss">New status</label><select id="ss" name="status" class="<?= $input ?>">
        <?php foreach (BCRF_ANIMAL_STATUSES as $k => $v): if ($k === $status) continue; ?><option value="<?= $k ?>"><?= $h($v) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= $label ?>" for="sr">Why</label><input id="sr" name="reason" maxlength="255" class="<?= $input ?>" placeholder="e.g. limping, vet says rest a week"></div>
      <div><label class="<?= $label ?>" for="so">Went to (sold, transferred, adopted)</label><input id="so" name="other_party" maxlength="160" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="sf">Sale receipt or note (optional)</label><input id="sf" name="sale_ref" maxlength="60" class="<?= $input ?>"></div>
    </div>
    <button class="<?= $btnMain ?>" data-confirm="Change <?= $h($a['name']) ?>'s status?">Change status</button>
  </form>
  <?php endif; ?>
<?php
    bcrf_shell_close(); exit;
}

// ── Enclosures ──
if ($tab === 'enclosures') {
    $all = bcrf_enclosures($pdo, true);
    bcrf_shell_open('Enclosures', ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animals</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
    foreach ($all as $e): $over = $e['capacity'] !== null && $e['animal_count'] > $e['capacity']; ?>
  <form method="POST" action="animals.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="enclosure"><input type="hidden" name="enclosure_id" value="<?= $e['id'] ?>">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
      <h2 class="text-base font-semibold text-stone-800"><?= $h($e['name']) ?><?= $e['active'] ? '' : ' (not in use)' ?></h2>
      <?php if ($e['animal_count'] && function_exists('bcrf_care_ready') && bcrf_care_ready($pdo)): ?><a class="text-sm underline text-amber-800" href="animal_care.php?enc=<?= $e['id'] ?>">Log care</a><?php endif; ?>
      <a class="text-sm underline <?= $over ? 'text-red-700' : 'text-amber-800' ?>" href="animals.php?enc=<?= $e['id'] ?>"><?= $e['animal_count'] ?> animal<?= $e['animal_count'] === 1 ? '' : 's' ?><?= $e['capacity'] !== null ? ' of ' . $e['capacity'] : '' ?><?= $over ? ', over capacity' : '' ?></a>
    </div>
    <?php if ($canEdit): ?>
    <fieldset class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="en<?= $e['id'] ?>">Name</label><input id="en<?= $e['id'] ?>" name="name" maxlength="80" required value="<?= $h($e['name']) ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="ec<?= $e['id'] ?>">Capacity (blank = no limit)</label><input id="ec<?= $e['id'] ?>" name="capacity" type="number" min="1" max="9999" value="<?= $e['capacity'] ?? '' ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="eo<?= $e['id'] ?>">Order</label><input id="eo<?= $e['id'] ?>" name="sort_order" type="number" value="<?= (int)$e['sort_order'] ?>" class="<?= $input ?>"></div>
    </fieldset>
    <div class="mb-3"><label class="<?= $label ?>" for="et<?= $e['id'] ?>">Notes</label><input id="et<?= $e['id'] ?>" name="notes" maxlength="500" value="<?= $h($e['notes']) ?>" class="<?= $input ?>"></div>
    <div class="flex flex-wrap gap-3 items-center"><label class="flex gap-2 items-center text-sm"><input type="checkbox" name="active" value="1" <?= $e['active'] ? 'checked' : '' ?>> In use</label><button class="<?= $btnSec ?>">Save</button></div>
    <?php elseif ($e['notes']): ?><p class="text-sm text-stone-500"><?= $h($e['notes']) ?></p><?php endif; ?>
  </form>
<?php endforeach;
    if (!$all) echo '<p class="text-sm text-stone-500 mb-4">No enclosures yet. Add your pens, barns and pastures so you can record where each animal lives.</p>';
    if ($canEdit): ?>
  <form method="POST" action="animals.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="enclosure"><input type="hidden" name="enclosure_id" value="0">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Add an enclosure</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="nn">Name</label><input id="nn" name="name" maxlength="80" required placeholder="e.g. Goat Barn" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="nc">Capacity (blank = no limit)</label><input id="nc" name="capacity" type="number" min="1" max="9999" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="nt">Notes</label><input id="nt" name="notes" maxlength="500" class="<?= $input ?>"></div>
    </div>
    <button class="<?= $btnMain ?>">Add</button>
  </form>
<?php endif;
    bcrf_shell_close(); exit;
}

// ── Roster ──
$f = (string)($_GET['f'] ?? '');
if (!in_array($f, ['', 'off', 'gone', 'all'], true)) $f = '';
$enc = (int)($_GET['enc'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$rows = bcrf_animals_list($pdo, $f, $enc, $q);
$off = bcrf_animals_off_contact($pdo);
bcrf_shell_open('Animals', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animals</h1>';
if ($error) echo bcrf_shell_notice('err', $error);
if ($off) echo bcrf_shell_notice('warning', 'Off visitor contact: ' . implode(', ', array_map(fn($o) => $o['name'] . ' (' . strtolower(BCRF_ANIMAL_STATUSES[$o['status']]) . ')', $off)) . '.');
?>
  <form method="GET" action="animals.php" class="flex flex-wrap gap-2 mb-4">
    <input name="q" value="<?= $h($q) ?>" placeholder="Name, tag or microchip" aria-label="Search animals" class="<?= $input ?> flex-1 min-w-48">
    <select name="f" aria-label="Which animals" class="<?= $input ?> sm:w-auto"><?php foreach (['' => 'On the farm', 'off' => 'Off visitor contact', 'gone' => 'Left the farm', 'all' => 'Everyone'] as $k => $v): ?><option value="<?= $k ?>" <?= $f === $k ? 'selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select>
    <select name="enc" aria-label="Enclosure" class="<?= $input ?> sm:w-auto"><option value="0">Any enclosure</option><?php foreach ($enclosures as $e): ?><option value="<?= $e['id'] ?>" <?= $enc === $e['id'] ? 'selected' : '' ?>><?= $h($e['name']) ?></option><?php endforeach; ?></select>
    <button class="<?= $btnSec ?> shrink-0">Show</button></form>
  <?php if (!$rows): ?><p class="text-sm text-stone-500"><?= ($q !== '' || $f !== '' || $enc) ? 'Nothing matches that.' : 'No animals yet. Add them in the full editor.' ?></p><?php else: ?>
  <form method="POST" action="animals.php"><?= $hidden ?><input type="hidden" name="act" value="move_many">
  <div class="<?= $card ?> p-0 overflow-hidden">
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rows as $r): $gone = bcrf_animal_is_leaving((string)$r['status']); ?>
      <li class="flex items-center gap-3 px-4 py-2 hover:bg-stone-50">
        <?php if ($canEdit && !$gone): ?><input type="checkbox" name="animals[]" value="<?= (int)$r['id'] ?>" aria-label="Pick <?= $h($r['name']) ?> to move"><?php endif; ?>
        <a href="animals.php?id=<?= (int)$r['id'] ?>" class="flex flex-1 flex-wrap items-baseline justify-between gap-2">
          <span><span class="text-sm font-semibold text-stone-800"><?= $h($r['name']) ?></span> <?= $r['status'] !== 'active' ? $pill((string)$r['status']) : '' ?>
            <span class="block text-xs text-stone-500"><?= $h(trim(($r['class_name'] ?? '') . ' · ' . ($r['breed_name'] ?? ''), ' ·')) ?><?= $r['tag'] ? ' · tag ' . $h($r['tag']) : '' ?></span></span>
          <span class="text-xs text-stone-500"><?= $h($r['enclosure_name'] ?? 'No enclosure') ?> · <?= $h(bcrf_animal_age($r['date_of_birth']) ?: 'age ?') ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php if ($canEdit && $enclosures): ?>
  <div class="<?= $card ?> flex flex-wrap gap-2 items-end">
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="mm">Move the ticked animals to</label><select id="mm" name="enclosure_id" class="<?= $input ?>"><?= $encOptions(null, true) ?></select></div>
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="mnote">Note (optional)</label><input id="mnote" name="note" maxlength="255" class="<?= $input ?>"></div>
    <button class="<?= $btnMain ?>">Move</button>
  </div>
  <?php elseif ($canEdit): ?>
  <p class="text-sm text-stone-500">Add your pens and barns under <a class="underline text-amber-800" href="animals.php?tab=enclosures">Enclosures</a> to start recording where each animal lives.</p>
  <?php endif; ?>
  </form>
  <?php endif; ?>
<?php
bcrf_shell_close();
