<?php
// admin/animal_care.php — Animals › Care (Farm OS Phase 5 slice 2, Nov 12, 2026; includes/animal_care.php, migration 0041).
//   GET  animal_care.php[?enc=N]               big buttons for the quick care types, then the latest entries
//   GET  animal_care.php?type=N[&animal=N|&enc=N]   the log form for one care type, ready for one animal or an enclosure
//   GET  animal_care.php?tab=log               all recent entries, with void
//   GET  animal_care.php?tab=setup             care types and which products are feed (edit permission)
//   POST act=log | void | type | feed
// Section tabs (Log care, Recent, Setup) come from the sidebar.
// Permission: table 'animals' view to log care (staff do this from their phones); edit to void entries or change setup.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/animal_care.php';
require_once __DIR__ . '/../includes/admin_shell.php';
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
            $r = bcrf_care_log($pdo, ['type' => $_POST['type'] ?? 0, 'animals' => (array)($_POST['animals'] ?? []), 'enclosure' => $_POST['enclosure'] ?? 0,
                                      'note' => $_POST['note'] ?? '', 'feed_product' => $_POST['feed_product'] ?? 0, 'feed_qty' => $_POST['feed_qty'] ?? ''], $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'animal_care_logs', $r['id'], 'Care logged for ' . $r['animals'] . ' animal(s)');
            $msg = 'Logged for ' . $r['animals'] . ' animal' . ($r['animals'] > 1 ? 's' : '') . '.';
            $_SESSION['ac_flash'] = $r['stock_note'] ? ['err', $msg . ' ' . $r['stock_note']] : ['ok', $msg];
        } elseif ($act === 'void') {
            if (!$canEdit) throw new BcrfCareError('Ask a manager to void an entry.');
            $id = (int)($_POST['id'] ?? 0);
            $back = 'animal_care.php?tab=log';
            bcrf_care_void($pdo, $id, (string)($_POST['reason'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animal_care_logs', $id, 'Care entry voided: ' . trim((string)$_POST['reason']));
            $_SESSION['ac_flash'] = ['ok', 'Voided. Any feed went back into stock.'];
        } elseif ($act === 'type' || $act === 'feed') {
            if (!$canEdit) throw new BcrfCareError('You can log care but not change the setup.');
            $back = 'animal_care.php?tab=setup';
            if ($act === 'type') {
                $tid = (int)($_POST['type_id'] ?? 0);
                $saved = bcrf_care_type_save($pdo, $tid, (string)($_POST['name'] ?? ''), !empty($_POST['is_feeding']), !empty($_POST['quick']), !empty($_POST['active']) || !$tid, (int)($_POST['sort_order'] ?? 0));
                if (function_exists('erp_audit')) erp_audit($pdo, $tid ? 'update' : 'create', 'animal_care_types', $saved, 'Care type ' . trim((string)$_POST['name']));
                $_SESSION['ac_flash'] = ['ok', $tid ? 'Saved.' : 'Added.'];
            } else {
                bcrf_care_set_feed_ids($pdo, (array)($_POST['feed'] ?? []));
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'site_settings', 0, 'Animal feed products changed');
                $_SESSION['ac_flash'] = ['ok', 'Feed list saved.'];
            }
        } else {
            throw new BcrfCareError('Unknown action.');
        }
    } catch (BcrfCareError | BcrfAnimalError $e) {
        $_SESSION['ac_flash'] = ['err', $e->getMessage()];
        if ($act === 'log' && !(int)($_POST['return_animal'] ?? 0)) $back = 'animal_care.php?type=' . (int)($_POST['type'] ?? 0);
    } catch (Throwable $e) {
        error_log('[animal_care] ' . $act . ': ' . $e->getMessage());
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
    $feed = $r['feed_name'] ? ' · ' . bcrf_qtxt((float)$r['feed_qty']) . ' of ' . $r['feed_name'] : '';
    return '<span class="text-sm ' . ($r['voided_at'] ? 'line-through text-stone-500' : 'text-stone-800') . '"><strong>' . $h($r['type_name']) . '</strong>' . $h($feed) . '</span>'
         . '<span class="block text-xs text-stone-500">' . $h($who) . '</span>'
         . '<span class="block text-xs text-stone-500">' . $h($local($r['logged_at'])) . ($r['by_name'] ? ' · ' . $h($r['by_name']) : '') . ($r['note'] ? ' · ' . $h($r['note']) : '')
         . ($r['voided_at'] ? ' · voided: ' . $h($r['void_reason']) : '') . '</span>';
};

// ── Setup ──
if ($tab === 'setup' && $canEdit) {
    $types = bcrf_care_types($pdo, true);
    $feedIds = bcrf_care_feed_ids($pdo);
    bcrf_shell_open('Animal care', ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animal care</h1>';
    if ($error) echo bcrf_shell_notice('err', $error); ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">What staff can log</h2>
    <p class="text-sm text-stone-500 mb-3">Quick types get a big button on the phone. A feeding type asks which feed and how much.</p>
    <ul class="divide-y divide-stone-100">
    <?php foreach ($types as $t): ?>
      <li class="py-3"><form method="POST" action="animal_care.php" class="flex flex-wrap gap-2 items-end"><?= $hidden ?><input type="hidden" name="act" value="type"><input type="hidden" name="type_id" value="<?= $t['id'] ?>">
        <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="tn<?= $t['id'] ?>">Name</label><input id="tn<?= $t['id'] ?>" name="name" maxlength="60" required value="<?= $h($t['name']) ?>" class="<?= $input ?>"></div>
        <div class="w-24"><label class="<?= $label ?>" for="to<?= $t['id'] ?>">Order</label><input id="to<?= $t['id'] ?>" name="sort_order" type="number" value="<?= $t['sort_order'] ?>" class="<?= $input ?>"></div>
        <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="quick" value="1" <?= $t['quick'] ? 'checked' : '' ?>> Quick</label>
        <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="is_feeding" value="1" <?= $t['is_feeding'] ? 'checked' : '' ?>> Feeding</label>
        <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="active" value="1" <?= $t['active'] ? 'checked' : '' ?>> In use</label>
        <button class="<?= $btnSec ?>">Save</button></form></li>
    <?php endforeach; ?>
    </ul>
    <form method="POST" action="animal_care.php" class="flex flex-wrap gap-2 items-end mt-3"><?= $hidden ?><input type="hidden" name="act" value="type"><input type="hidden" name="type_id" value="0">
      <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="tnew">Add a care type</label><input id="tnew" name="name" maxlength="60" required placeholder="e.g. Dust bath" class="<?= $input ?>"></div>
      <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="quick" value="1"> Quick</label>
      <label class="flex gap-1 items-center text-sm"><input type="checkbox" name="is_feeding" value="1"> Feeding</label>
      <button class="<?= $btnMain ?>">Add</button></form>
  </div>
  <form method="POST" action="animal_care.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="feed">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Feed products</h2>
    <p class="text-sm text-stone-500 mb-3">Tick the products you feed the animals. Logging a feeding takes the amount out of stock (as "Used on the farm") and records its cost. Products that aren't stocked are recorded without touching stock.</p>
    <div class="max-h-96 overflow-y-auto border border-stone-200 rounded p-2 mb-3">
    <?php foreach (bcrf_care_all_products($pdo) as $p): ?>
      <label class="flex gap-2 items-center text-sm py-1"><input type="checkbox" name="feed[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $feedIds, true) ? 'checked' : '' ?>> <?= $h($p['name']) ?></label>
    <?php endforeach; ?>
    </div>
    <button class="<?= $btnMain ?>">Save feed list</button>
  </form>
<?php
    bcrf_shell_close(); exit;
}

// ── Recent ──
if ($tab === 'log') {
    $rows = bcrf_care_recent($pdo, 0, 200);
    bcrf_shell_open('Animal care', ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animal care</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if (!$rows) { echo '<p class="text-sm text-stone-500">Nothing logged yet.</p>'; bcrf_shell_close(); exit; } ?>
  <div class="<?= $card ?> p-0 overflow-hidden">
    <ul class="divide-y divide-stone-100">
    <?php foreach ($rows as $r): ?>
      <li class="px-4 py-2"><?= $entryLine($r) ?>
        <?php if ($canEdit && !$r['voided_at']): ?>
        <details class="mt-1"><summary class="cursor-pointer text-xs text-amber-800 underline">Void</summary>
          <form method="POST" action="animal_care.php" class="flex flex-wrap gap-2 items-end mt-2"><?= $hidden ?><input type="hidden" name="act" value="void"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="vr<?= (int)$r['id'] ?>">Why</label><input id="vr<?= (int)$r['id'] ?>" name="reason" maxlength="255" required class="<?= $input ?>" placeholder="e.g. logged twice"></div>
            <button class="<?= $btnSec ?>" data-confirm="Void this entry? It stays on file<?= $r['feed_name'] ? ' and the feed goes back into stock' : '' ?>.">Void</button></form></details>
        <?php endif; ?></li>
    <?php endforeach; ?>
    </ul>
  </div>
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
    $feeds = $type['is_feeding'] ? bcrf_care_feed_products($pdo) : [];
    bcrf_shell_open('Log ' . $type['name'], ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<a href="', $oneAnimal ? 'animals.php?id=' . $oneAnimal . '&amp;tab=care' : 'animal_care.php', '" class="text-sm text-amber-800 underline">‹ Back</a>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mt-2 mb-3">', $h($type['name']), '</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if (!$animals) { echo bcrf_shell_notice('warning', 'No animals on the roster yet.'); bcrf_shell_close(); exit; } ?>
  <form method="POST" action="animal_care.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="log"><input type="hidden" name="type" value="<?= $type['id'] ?>">
    <?php if ($oneAnimal): ?><input type="hidden" name="return_animal" value="<?= $oneAnimal ?>"><?php endif; ?>
    <?php if ($enclosures): ?>
    <div class="mb-3"><label class="<?= $label ?>" for="le">Everyone in an enclosure</label>
      <select id="le" name="enclosure" class="<?= $input ?>"><option value="0">No, just the animals I tick below</option>
        <?php foreach ($enclosures as $e): ?><option value="<?= $e['id'] ?>" <?= $preEnc === $e['id'] ? 'selected' : '' ?>><?= $h($e['name']) ?> (<?= $e['animal_count'] ?>)</option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <fieldset class="mb-3"><legend class="<?= $label ?>"><?= $enclosures ? 'Or these animals' : 'Which animals' ?></legend>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-1 max-h-96 overflow-y-auto border border-stone-200 rounded p-2">
      <?php foreach ($animals as $a): ?>
        <label class="flex gap-2 items-center text-sm py-1"><input type="checkbox" name="animals[]" value="<?= (int)$a['id'] ?>" <?= $oneAnimal === (int)$a['id'] ? 'checked' : '' ?>> <?= $h($a['name']) ?></label>
      <?php endforeach; ?>
      </div></fieldset>
    <?php if ($type['is_feeding']): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="lf">Feed (optional)</label><select id="lf" name="feed_product" class="<?= $input ?>"><option value="0">Not recorded</option>
        <?php foreach ($feeds as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $h($p['name']) ?><?= (int)$p['track_inventory'] === 1 ? ' (' . $h(bcrf_qtxt((float)$p['stock_quantity'])) . ($p['unit'] ? ' ' . $h($p['unit']) : '') . ' on hand)' : '' ?></option><?php endforeach; ?></select>
        <?php if (!$feeds && $canEdit): ?><span class="block text-xs text-stone-500 mt-1">Pick your feed products under <a class="underline text-amber-800" href="animal_care.php?tab=setup">Setup</a>.</span><?php endif; ?></div>
      <div><label class="<?= $label ?>" for="lq">Amount (in the product's unit)</label><input id="lq" name="feed_qty" inputmode="decimal" class="<?= $input ?>" placeholder="e.g. 2"></div>
    </div>
    <?php endif; ?>
    <div class="mb-3"><label class="<?= $label ?>" for="ln">Note (optional)</label><input id="ln" name="note" maxlength="500" class="<?= $input ?>" placeholder="<?= $type['is_feeding'] ? 'e.g. Hazel left half' : 'Anything worth noting' ?>"></div>
    <button class="<?= $btnMain ?> w-full sm:w-auto">Log <?= $h(strtolower($type['name'])) ?></button>
  </form>
<?php
    bcrf_shell_close(); exit;
}

// ── Log care: big buttons ──
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
  <h2 class="text-base font-semibold text-stone-800 mb-2">Latest</h2>
  <?php if (!$recent): ?><p class="text-sm text-stone-500">Nothing logged yet.</p><?php else: ?>
  <div class="<?= $card ?> p-0 overflow-hidden"><ul class="divide-y divide-stone-100">
    <?php foreach ($recent as $r): ?><li class="px-4 py-2"><?= $entryLine($r) ?></li><?php endforeach; ?>
  </ul></div>
  <?php endif; ?>
<?php
bcrf_shell_close();
