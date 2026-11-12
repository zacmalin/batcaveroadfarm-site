<?php
// admin/animal_public.php — Animals › Public pages (Farm OS Phase 5 slice 9, Nov 12, 2026; includes/animal_public.php, migration 0048).
//   GET  animal_public.php                 every animal still on the farm: on the website or not, and what its page is
//                                          missing (main photo, photo description, bio, fun fact)
//   POST act=save | publish | unpublish | photo | photo_main | photo_alt | photo_remove | photo_up | photo_down
//        (from an animal's Public tab: id=N returns to animals.php?id=N&tab=public)
// The page itself is petting-zoo/animals/animal.php. Only the words, photos and age line on the Public tab go to the
// website; health, weights, costs, purchase details, incidents, tags and microchips never do.
// Permission: table 'animals' view to look; animals edit to change anything.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/animal_public.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$canEdit = hasTableAccess((string)($_SESSION['user_role'] ?? ''), 'animals', 'edit');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_public_ready($pdo)) {
    bcrf_shell_open('Public pages');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Public pages</h1>', bcrf_shell_notice('warning', 'Run migration 0048 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = $id && empty($_POST['from_list']) ? 'animals.php?id=' . $id . '&tab=public' : 'animal_public.php';
    $key = str_starts_with($back, 'animals.php') ? 'an_flash' : 'ap_flash';
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {   // over the server's upload limit: PHP drops the whole form
        $_SESSION['ap_flash'] = ['err', 'That photo is too big to upload. Try a smaller one.'];
        header('Location: animal_public.php'); exit;
    }
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfAnimalError('Security token expired; try again.');
        if (!$canEdit) throw new BcrfAnimalError('You can look at animals but not change them.');
        $a = bcrf_animal_get($pdo, $id);
        if (!$a) throw new BcrfAnimalError('That animal is not on the roster.');
        $pid = (int)($_POST['photo_id'] ?? 0);
        $audit = fn(string $what) => function_exists('erp_audit') ? erp_audit($pdo, 'update', 'animals', $id, 'Public page: ' . $what) : null;
        if ($act === 'save') {
            $changed = bcrf_public_save($pdo, $id, $_POST);
            if ($changed) $audit('words changed (' . implode(', ', $changed) . ')');
            $_SESSION[$key] = ['ok', $changed ? ((int)$a['is_active'] ? 'Saved. The website shows it now.' : 'Saved.') : 'Nothing changed.'];
        } elseif ($act === 'publish' || $act === 'unpublish') {
            bcrf_public_set($pdo, $id, $act === 'publish');
            $audit($act === 'publish' ? 'put on the website' : 'taken off the website');
            $_SESSION[$key] = ['ok', $act === 'publish' ? $a['name'] . ' is on the website.' : $a['name'] . ' is off the website.'];
        } elseif ($act === 'photo') {
            $r = bcrf_public_photo_add($pdo, $id, $_FILES['photo'] ?? [], (string)($_POST['alt_text'] ?? ''), !empty($_POST['main']), $uid);
            $audit('photo added ' . $r['path'] . ($r['main'] ? ' (main)' : ''));
            $_SESSION[$key] = ['ok', $r['main'] ? 'Photo added as the main photo.' : 'Photo added.'];
        } elseif ($act === 'photo_main') {
            bcrf_public_photo_make_main($pdo, $id, $pid);
            $audit('main photo changed');
            $_SESSION[$key] = ['ok', 'That is the main photo now.'];
        } elseif ($act === 'photo_alt') {
            bcrf_public_photo_alt($pdo, $id, $pid, (string)($_POST['alt_text'] ?? ''));
            $_SESSION[$key] = ['ok', 'Saved.'];
        } elseif ($act === 'photo_remove') {
            bcrf_public_photo_remove($pdo, $id, $pid);
            $audit('photo #' . $pid . ' removed');
            $_SESSION[$key] = ['ok', 'Photo taken off the page.'];
        } elseif ($act === 'photo_up' || $act === 'photo_down') {
            bcrf_public_photo_move($pdo, $id, $pid, $act === 'photo_up' ? -1 : 1);
            $_SESSION[$key] = ['ok', 'Moved.'];
        } else {
            throw new BcrfAnimalError('Unknown action.');
        }
    } catch (BcrfAnimalError $e) {
        $_SESSION[$key] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[animal_public] ' . $act . ': ' . $e->getMessage());
        $_SESSION[$key] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['ap_flash'] ?? null; unset($_SESSION['ap_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$card = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$btnSec = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$rows = bcrf_public_overview($pdo);
$on = array_values(array_filter($rows, fn($r) => (int)$r['is_active'] === 1));
$off = array_values(array_filter($rows, fn($r) => (int)$r['is_active'] !== 1));
$todo = count(array_filter($on, fn($r) => $r['missing']));
$site = function_exists('bcrf_farm_url') ? bcrf_farm_url() : '';
$slug = fn($n) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$n)), '-');

bcrf_shell_open('Public pages', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Public pages</h1>';
echo '<p class="text-sm text-stone-500 mb-3">What visitors see on the website\'s petting zoo pages. Open an animal\'s Public tab to change its words and photos. Health, weights, costs and incidents never go on the website.</p>';
if ($error) echo bcrf_shell_notice('err', $error);
if ($todo) echo bcrf_shell_notice('info', $todo . ' page' . ($todo > 1 ? 's are' : ' is') . ' on the website but missing something (listed below).');
$list = function (array $rows, string $title, string $empty) use ($h, $card, $site, $slug, $btnSec): void { ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2"><?= $h($title) ?> (<?= count($rows) ?>)</h2>
    <?php if (!$rows): ?><p class="text-sm text-stone-500"><?= $h($empty) ?></p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rows as $r): ?>
      <li class="py-2 text-sm flex gap-3 items-start">
        <?php if (trim((string)$r['photo_url']) !== ''): ?><img src="<?= $h($r['photo_url']) ?>" alt="" class="w-14 h-14 object-cover rounded border border-stone-200" loading="lazy"><?php else: ?><span class="w-14 h-14 rounded border border-dashed border-stone-300 bg-stone-50 text-xs text-stone-400 flex items-center justify-center">No photo</span><?php endif; ?>
        <span class="flex-1">
          <a class="underline text-amber-800 font-semibold" href="animals.php?id=<?= (int)$r['id'] ?>&amp;tab=public"><?= $h($r['name']) ?></a>
          <span class="text-stone-500"> · <?= $h(trim(($r['class_name'] ?? '') . ' · ' . ($r['breed_name'] ?? ''), ' ·')) ?><?= (int)$r['photo_count'] ? ' · ' . (int)$r['photo_count'] . ' more photo' . ((int)$r['photo_count'] > 1 ? 's' : '') : '' ?></span>
          <?php if ($r['missing']): ?><span class="block text-xs text-amber-800">Missing <?= $h(implode(', ', $r['missing'])) ?>.</span><?php else: ?><span class="block text-xs text-green-700">Complete.</span><?php endif; ?>
        </span>
        <a class="<?= $btnSec ?> whitespace-nowrap" href="<?= $h($site . '/petting-zoo/animals/' . (int)$r['id'] . '/' . $slug($r['name']) . '/' . ((int)$r['is_active'] ? '' : '?preview=1')) ?>" target="_blank" rel="noopener"><?= (int)$r['is_active'] ? 'View' : 'Preview' ?></a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php };
$list($on, 'On the website', 'No animals are on the website yet.');
$list($off, 'Not on the website', 'Every animal on the farm has a page.');
echo '<p class="text-xs text-stone-500">Animals that have left the farm aren\'t listed; their pages came off the website when they left.</p>';
bcrf_shell_close();
