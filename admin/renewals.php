<?php
// admin/renewals.php — Renewals (Nov 15, 2026; includes/renewals.php, migration 0069). Team › Calendar › Renewals.
//   GET  renewals.php[?show=upcoming|finished|deleted&q=]   the list, soonest first
//   GET  renewals.php?id=N | ?new=1                          one renewal: details, Renewed, Delete / Restore, notes, tasks, changes
//   POST act=save                 add or change
//        act=renewed              moves the date on by its repeat (a one-time item is finished)
//        act=reopen               a finished one-time item back on the list
//        act=delete | restore     hide it / bring it back (nothing is erased)
// Permission: the 'renewals' view (Owners always; Managers by default). On the calendar as "Renewals & due dates";
// reminders (bell, email, a task) come from includes/renewals.php bcrf_renewals_sweep().
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'renewals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/tasks.php';
require_once __DIR__ . '/../includes/renewals.php';
require_once __DIR__ . '/../includes/record_tools.php';
require_once __DIR__ . '/../includes/admin_shell.php';
require_once __DIR__ . '/../includes/list_sort.php';      // Sort box on the list (Nov 15, 2026)
date_default_timezone_set(bcrf_renewals_tz()->getName());

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (!bcrf_renewals_ready($pdo)) {
    bcrf_shell_open('Renewals');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Renewals</h1>', bcrf_shell_notice('warning', 'Run migration 0069 in Settings › Database first.'),
         '<a href="/admin/migrations.php" class="text-sm text-amber-800 underline">Open Settings › Database</a>';
    bcrf_shell_close(); exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    $go = 'renewals.php' . ($id ? '?id=' . $id : '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfRenewalError('Security token expired; try again.');
        if ($act === 'save') {
            $saved = bcrf_renewal_save($pdo, $id, $_POST, $uid);
            $_SESSION['ren_flash'] = ['ok', $id ? 'Saved.' : 'Renewal added. It is on the calendar now.'];
            $go = 'renewals.php?id=' . $saved;
        } elseif ($act === 'renewed' && $id) {
            $next = bcrf_renewal_mark_renewed($pdo, $id, $uid);
            $_SESSION['ren_flash'] = ['ok', $next ? 'Marked renewed. Next date: ' . date('M j, Y', strtotime($next)) . '.' : 'Marked done. It is under Finished.'];
        } elseif ($act === 'reopen' && $id) {
            bcrf_renewal_reopen($pdo, $id, $uid);
            $_SESSION['ren_flash'] = ['ok', 'Back on the list.'];
        } elseif (($act === 'delete' || $act === 'restore') && $id) {
            bcrf_renewal_delete($pdo, $id, $act === 'delete', $uid);
            $_SESSION['ren_flash'] = ['ok', $act === 'delete' ? 'Deleted. You can restore it from the Deleted tab.' : 'Restored.'];
            if ($act === 'delete') $go = 'renewals.php';
        } else {
            throw new BcrfRenewalError('Nothing to do.');
        }
    } catch (BcrfRenewalError $e) {
        $_SESSION['ren_flash'] = ['err', $e->getMessage()];
        if ($act === 'save') { $_SESSION['ren_form'] = $_POST; $go = 'renewals.php' . ($id ? '?id=' . $id : '?new=1'); }
    } catch (Throwable $e) {
        error_log('[renewals] ' . $act . ': ' . $e->getMessage());
        $_SESSION['ren_flash'] = ['err', 'Could not save. Try again.'];
        if ($act === 'save') { $_SESSION['ren_form'] = $_POST; $go = 'renewals.php' . ($id ? '?id=' . $id : '?new=1'); }
    }
    header('Location: ' . $go); exit;
}

$flash = $_SESSION['ren_flash'] ?? null; unset($_SESSION['ren_flash']);
$typed = $_SESSION['ren_form'] ?? null; unset($_SESSION['ren_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$today = bcrf_renewals_today();

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-1.5 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-1.5 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100';
$badge = function (array $r) use ($today, $h): string {
    if ($r['deleted_at']) return '<span class="text-xs px-2 py-0.5 rounded-full bg-stone-200 text-stone-700">Deleted</span>';
    if ($r['finished_at']) return '<span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-800">Done</span>';
    $n = bcrf_renewal_days_left((string)$r['renews_on'], $today);
    $cls = $n < 0 ? 'bg-red-100 text-red-800' : ($n <= (int)$r['remind_days'] ? 'bg-amber-100 text-amber-900' : 'bg-stone-100 text-stone-700');
    return '<span class="text-xs px-2 py-0.5 rounded-full ' . $cls . '">' . $h(bcrf_renewal_when((string)$r['renews_on'], $today)) . '</span>';
};

// ── LIST ──────────────────────────────────────────────────────────────────────
if (!isset($_GET['id']) && !isset($_GET['new'])) {
    $show = in_array($_GET['show'] ?? '', ['finished', 'deleted'], true) ? (string)$_GET['show'] : 'upcoming';
    $q = trim((string)($_GET['q'] ?? ''));
    $rows = bcrf_renewal_list($pdo, $show, $q);
    $sortOpts = ['default' => [$show === 'upcoming' ? 'Due soonest' : ($show === 'deleted' ? 'Last deleted first' : 'Latest date first')],
                 'date_asc' => ['Date, soonest first', 'renews_on', 'asc'], 'date_desc' => ['Date, latest first', 'renews_on', 'desc'],
                 'az' => ['Name A to Z', 'title', 'asc'], 'za' => ['Name Z to A', 'title', 'desc'],
                 'cost_hi' => ['Cost high to low', 'cost_cents', 'desc'], 'cost_lo' => ['Cost low to high', 'cost_cents', 'asc'],
                 'kind' => ['By kind', fn($r) => BCRF_RENEWAL_CATEGORIES[$r['category']] ?? '', 'asc']];
    $sort = bcrf_list_sort_pick($pdo, 'renewals', $sortOpts);
    $rows = bcrf_list_sort_rows($rows, $sortOpts, $sort);
    bcrf_shell_open('Renewals', ['toast' => $toast, 'width' => 'max-w-3xl']);
    ?>
  <div class="flex flex-wrap items-end justify-between gap-3 mb-1">
    <h1 class="text-2xl font-bold text-stone-800">Renewals</h1>
    <a href="renewals.php?new=1" class="<?= $btnMain ?>">Add a renewal</a>
  </div>
  <p class="text-sm text-stone-500 mb-4">Domain, hosting, insurance, licenses and permits, subscriptions: anything that renews or runs out on a date. Each one is on the calendar, and the person who handles it gets a bell, an email and a task before it is due.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <div class="flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Show">
    <?php foreach (['upcoming' => 'Coming up', 'finished' => 'Finished', 'deleted' => 'Deleted'] as $k => $v): ?>
      <a href="renewals.php?show=<?= $k ?>" role="tab" aria-selected="<?= $show === $k ? 'true' : 'false' ?>" class="px-3 py-1.5 rounded-full text-sm border <?= $show === $k ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-700 border-stone-300 hover:bg-stone-100' ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <form method="GET" action="renewals.php" class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm mb-4 flex flex-wrap gap-2 items-end">
    <input type="hidden" name="show" value="<?= $h($show) ?>">
    <div class="flex-1 min-w-[12rem]"><label for="rq" class="<?= $label ?>">Search</label>
      <input id="rq" type="search" name="q" value="<?= $h($q) ?>" placeholder="Name, provider, account, notes" class="<?= $input ?>"></div>
    <div class="w-36"><label for="rsort" class="<?= $label ?>">Sort</label><?= bcrf_list_sort_select($sortOpts, $sort, ['id' => 'rsort', 'class' => $input]) ?></div>
    <button type="submit" class="<?= $btnSec ?>">Search</button>
  </form>

  <?php if (!$rows): ?>
    <div class="bg-white border border-stone-200 rounded-lg p-6 text-center text-sm text-stone-500">
      <?= $q !== '' ? 'Nothing matches.' : ($show === 'upcoming' ? 'Nothing yet. Add the domain, hosting, insurance and anything else that renews.' : ($show === 'finished' ? 'Nothing finished yet. One-time items land here when you mark them done.' : 'Nothing deleted.')) ?>
    </div>
  <?php else: ?>
    <div class="space-y-2">
      <?php foreach ($rows as $r):
          $meta = implode(' · ', array_filter([BCRF_RENEWAL_CATEGORIES[$r['category']] ?? '', bcrf_renewal_repeat_label((int)$r['repeat_months']), $r['provider'] ?? '', bcrf_renewal_money($r['cost_cents']), !empty($r['auto_renews']) ? 'renews by itself' : ''])); ?>
      <a href="renewals.php?id=<?= (int)$r['id'] ?>" class="flex items-center justify-between gap-3 bg-white border border-stone-200 rounded-lg p-3 shadow-sm hover:bg-stone-50">
        <div class="min-w-0">
          <div class="text-base font-semibold text-stone-800 truncate"><?= $h($r['title']) ?></div>
          <div class="text-xs text-stone-500 mt-0.5 truncate"><?= $h(date('D M j, Y', strtotime((string)$r['renews_on']))) ?><?= $meta !== '' ? ' · ' . $h($meta) : '' ?></div>
        </div>
        <div class="shrink-0"><?= $badge($r) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-stone-400 mt-3"><?= count($rows) ?> renewal<?= count($rows) === 1 ? '' : 's' ?>.</p>
  <?php endif;
    bcrf_shell_close();
    exit;
}

// ── ONE RENEWAL ───────────────────────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
$r = $id ? bcrf_renewal_get($pdo, $id) : null;
if ($id && !$r) {
    http_response_code(404);
    bcrf_shell_open('Renewal not found', ['width' => 'max-w-2xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Renewal not found</h1>', bcrf_shell_notice('info', "That renewal doesn't exist."),
         '<a href="renewals.php" class="text-sm text-amber-800 underline">All renewals</a>';
    bcrf_shell_close(); exit;
}
$d = $typed ?: ($r ?: ['category' => 'website', 'repeat_months' => 12, 'remind_days' => 30, 'renews_on' => '', 'assignee_role' => 'administrator']);
if ($typed) { $d['cost_cents'] = null; }
$val = fn(string $k) => $h($d[$k] ?? '');
$costVal = $typed ? (string)($typed['cost'] ?? '') : (isset($d['cost_cents']) && $d['cost_cents'] !== null ? number_format(((int)$d['cost_cents']) / 100, 2, '.', '') : '');
$whoVal = $typed ? (string)($typed['who'] ?? '') : bcrf_renewal_who_value($d);
$people = bcrf_renewal_people($pdo);
$vendors = [];
try { $vendors = $pdo->query("SELECT id, name FROM vendors WHERE is_active = 1 OR id = " . (int)($d['vendor_id'] ?? 0) . " ORDER BY name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$live = $r && !$r['deleted_at'];
$field = function (string $name, string $text, string $type = 'text', int $max = 120, string $extra = '', string $hint = '', ?string $value = null) use ($val, $input, $label, $h) {
    return '<div><label for="f_' . $name . '" class="' . $label . '">' . $text . ($hint !== '' ? ' <span class="font-normal text-stone-400">' . $hint . '</span>' : '') . '</label>'
         . '<input id="f_' . $name . '" type="' . $type . '" name="' . $name . '" value="' . ($value !== null ? $h($value) : $val($name)) . '"' . ($max ? ' maxlength="' . $max . '"' : '') . ' class="' . $input . '"' . $extra . '></div>';
};
$options = function (array $opts, $cur) use ($h): string {
    $o = '';
    foreach ($opts as $k => $v) $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . $h($v) . '</option>';
    return $o;
};

bcrf_shell_open($r ? $r['title'] . ' — Renewals' : 'Add a renewal', ['toast' => $toast, 'width' => 'max-w-2xl']);
?>
  <a href="renewals.php" class="inline-block text-sm text-stone-500 hover:text-stone-800 mb-2">← All renewals</a>
  <div class="flex flex-wrap items-center gap-2 mb-4">
    <h1 class="text-2xl font-bold text-stone-800"><?= $r ? $h($r['title']) : 'Add a renewal' ?></h1>
    <?= $r ? $badge($r) : '' ?>
  </div>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if ($r && $r['deleted_at']) echo bcrf_shell_notice('warning', 'This renewal is deleted: it is off the calendar and sends no reminders. Restore it to bring it back.'); ?>

  <?php if ($live && !$r['finished_at']):
      $m = (int)$r['repeat_months'];
      $nextTxt = $m > 0 ? date('M j, Y', strtotime((string)bcrf_renewal_next((string)$r['renews_on'], $m, max((string)$r['renews_on'], $today)))) : ''; ?>
  <div class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4">
    <p class="text-sm text-stone-700 mb-1"><b><?= !empty($r['auto_renews']) ? 'Renews by itself' : 'Due' ?> <?= $h(date('l, F j, Y', strtotime((string)$r['renews_on']))) ?></b> · <?= $h(bcrf_renewal_repeat_label($m)) ?> · <?= $h(bcrf_renewal_who($r)) ?></p>
    <p class="text-xs text-stone-500 mb-3">Reminders start <?= (int)$r['remind_days'] ?> day<?= (int)$r['remind_days'] === 1 ? '' : 's' ?> before<?= $r['last_renewed_on'] ? ' · last renewed ' . $h(date('M j, Y', strtotime((string)$r['last_renewed_on']))) : '' ?>.</p>
    <div class="flex flex-wrap gap-2">
      <form method="POST" action="renewals.php">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="act" value="renewed">
        <button class="<?= $btnMain ?>" data-confirm="<?= $h($m > 0 ? 'Mark it renewed? The next date becomes ' . $nextTxt . ' and its task closes.' : 'Mark it done? It moves to Finished and its task closes.') ?>"><?= $m > 0 ? 'Renewed' : 'Done' ?></button>
      </form>
      <?php if (!empty($r['link_url'])): ?><a href="<?= $h($r['link_url']) ?>" target="_blank" rel="noopener noreferrer" class="<?= $btnSec ?>">Open renewal link</a><?php endif; ?>
    </div>
  </div>
  <?php elseif ($live && $r['finished_at']): ?>
  <div class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4 flex flex-wrap items-center justify-between gap-2">
    <p class="text-sm text-stone-700">Finished<?= $r['last_renewed_on'] ? ' on ' . $h(date('M j, Y', strtotime((string)$r['last_renewed_on']))) : '' ?>. It is off the calendar.</p>
    <form method="POST" action="renewals.php"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="act" value="reopen">
      <button class="<?= $btnSec ?>">Put it back on the list</button></form>
  </div>
  <?php endif; ?>

  <?php if (!$r || $live): ?>
  <form method="POST" action="renewals.php" class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-3">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= $id ?: '' ?>"><input type="hidden" name="act" value="save">
    <?= $field('title', 'Name', 'text', 150, ' required placeholder="Domain: farm website"') ?>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="f_category" class="<?= $label ?>">Kind</label><select id="f_category" name="category" class="<?= $input ?>"><?= $options(BCRF_RENEWAL_CATEGORIES, $d['category'] ?? 'other') ?></select></div>
      <?= $field('renews_on', 'Renews or runs out on', 'date', 0, ' required') ?>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="f_repeat" class="<?= $label ?>">Comes back</label><select id="f_repeat" name="repeat_months" class="<?= $input ?>"><?= $options(BCRF_RENEWAL_REPEATS, (int)($d['repeat_months'] ?? 12)) ?></select></div>
      <?= $field('remind_days', 'Remind me', 'number', 0, ' min="0" max="365" inputmode="numeric"', 'days before') ?>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="f_who" class="<?= $label ?>">Who handles it</label><select id="f_who" name="who" class="<?= $input ?>">
        <optgroup label="A group"><?php foreach (BCRF_RENEWAL_WHO as $k => $v): ?><option value="role:<?= $h($k) ?>"<?= $whoVal === 'role:' . $k ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></optgroup>
        <?php if ($people): ?><optgroup label="A person"><?php foreach ($people as $p): ?><option value="user:<?= (int)$p['id'] ?>"<?= $whoVal === 'user:' . $p['id'] ? ' selected' : '' ?>><?= $h($p['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
      </select></div>
      <?= $field('cost', 'Cost each time', 'text', 12, ' inputmode="decimal" placeholder="0.00"', 'optional', $costVal) ?>
    </div>
    <label class="flex items-center gap-2 text-sm text-stone-700 min-h-11"><input type="checkbox" name="auto_renews" value="1"<?= !empty($d['auto_renews']) ? ' checked' : '' ?>> Renews by itself (the reminder becomes "check that it renewed")</label>
    <div class="grid gap-3 sm:grid-cols-2">
      <?= $field('provider', 'With', 'text', 120, ' placeholder="Registrar, insurer, county..."', 'optional') ?>
      <?= $field('account_ref', 'Account or policy number', 'text', 120, ' autocomplete="off"', 'never a password') ?>
    </div>
    <?= $field('link_url', 'Where to renew', 'text', 255, ' inputmode="url" autocomplete="off" placeholder="example.com/renew"', 'optional') ?>
    <?php if ($vendors): ?>
    <div><label for="f_vendor" class="<?= $label ?>">Vendor <span class="font-normal text-stone-400">optional</span></label><select id="f_vendor" name="vendor_id" class="<?= $input ?>"><option value="">None</option>
      <?php foreach ($vendors as $v): ?><option value="<?= (int)$v['id'] ?>"<?= (int)($d['vendor_id'] ?? 0) === (int)$v['id'] ? ' selected' : '' ?>><?= $h($v['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div><label for="f_notes" class="<?= $label ?>">Notes <span class="font-normal text-stone-400">what to check, who to call</span></label>
      <textarea id="f_notes" name="notes" rows="3" maxlength="5000" class="<?= $input ?>"><?= $val('notes') ?></textarea></div>
    <div class="flex flex-wrap gap-2 pt-1">
      <button type="submit" class="<?= $btnMain ?>"><?= $r ? 'Save' : 'Add renewal' ?></button>
      <a href="renewals.php" class="<?= $btnSec ?>">Cancel</a>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($r): ?>
  <div class="mt-4 flex flex-wrap gap-2">
    <form method="POST" action="renewals.php"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <?php if ($live): ?><input type="hidden" name="act" value="delete"><button class="<?= $btnSec ?>" data-confirm="Delete this renewal? It comes off the calendar and stops reminding. You can restore it from the Deleted tab.">Delete renewal</button>
      <?php else: ?><input type="hidden" name="act" value="restore"><button class="<?= $btnMain ?>">Restore renewal</button><?php endif; ?>
    </form>
  </div>
  <div class="mt-4"></div>
  <?= bcrf_record_notes_box_html($pdo, 'renewal', (int)$r['id'], '/admin/renewals.php?id=' . (int)$r['id'], $csrf) ?>
  <?= bcrf_record_tasks_box_html($pdo, 'renewal', (int)$r['id']) ?>
  <?= bcrf_record_changes_html($pdo, 'renewal', (int)$r['id']) ?>
  <?php endif;
bcrf_shell_close();
