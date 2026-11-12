<?php
// admin/tasks.php — Tasks and chores (Farm OS Phase 3 slice 1, Nov 11, 2026; includes/tasks.php, migration 0029)
//   GET  tasks.php                 My Tasks: late, today, next 7 days, no date, done today; quick add
//   GET  tasks.php?id=N            one task: checklist, start / done / skip / cancel / reopen
//   GET  tasks.php?new=1[&tpl=N]   new task (optionally from a saved checklist);  ?edit=N  change a task
//   GET  tasks.php?tab=all         everyone's tasks (managers)
//   GET  tasks.php?tab=chores      chores: list, ?rec=N | ?rec=new to change one (managers)
//   GET  tasks.php?tab=templates   saved checklists, ?tpl_edit=N | new (managers)
//   POST tasks.php  act=add | edit | check | set | rec_save | rec_active | tpl_save     (check also answers JSON for tasks.js)
//                   set to=done takes answers (ans[k]) and photos (photos[]); to=photo only saves photos   (slice 2, Nov 11)
//                   act=photo_del | prefs (morning list / late emails)
//   GET  tasks.php?tab=events      party and booking checklists, ?step=N | ?step=new&plan=party (managers; slice 3)
//   POST act=step_save | step_active;  GET tasks.php?tab=all&related=booking:12 lists one event's tasks
// Permission: view 'tasks' to see and do tasks; 'tasks_manage' (managers) for chores, checklists and everyone's tasks.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'tasks']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/tasks.php';
require_once __DIR__ . '/../includes/task_notify.php';
require_once __DIR__ . '/../includes/event_tasks.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$mgr  = bcrf_tasks_is_manager();
$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_tasks_ready($pdo)) {
    bcrf_shell_open('Tasks');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Tasks</h1>',
         bcrf_shell_notice('warning', 'Run migration 0029 in Settings › Database first.'),
         '<a href="/admin/migrations.php" class="text-sm text-amber-800 underline">Open Settings › Database</a>';
    bcrf_shell_close();
    exit;
}

// Make sure this week's chore days exist (the nightly job does this too; this is a cheap backstop).
try { bcrf_tasks_generate($pdo); } catch (Throwable $e) { error_log('[tasks] generate: ' . $e->getMessage()); }
try { bcrf_event_tasks_sync($pdo); } catch (Throwable $e) { error_log('[tasks] event checklists: ' . $e->getMessage()); }   // same backstop for party/booking checklists

$today = bcrf_tasks_today();
$v2 = bcrf_tasks_v2($pdo);   // migration 0030: questions, photos, follow-ups, emails
$people = bcrf_tasks_people($pdo);
$names = array_column($people, 'name', 'id');

// ── Actions ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act  = (string)($_POST['act'] ?? '');
    $id   = (int)($_POST['id'] ?? 0);
    $ajax = !empty($_POST['ajax']);
    $back = (string)($_POST['back'] ?? '');
    if (!preg_match('#^tasks\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $back)) $back = $id ? 'tasks.php?id=' . $id : 'tasks.php';
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {   // over the server's upload limit: PHP drops the whole form
        $_SESSION['task_flash'] = ['err', 'Those photos were too big to send together. Try one photo at a time.'];
        header('Location: ' . (preg_match('#/tasks\.php\?id=(\d+)#', (string)($_SERVER['HTTP_REFERER'] ?? ''), $m) ? 'tasks.php?id=' . $m[1] : 'tasks.php'));
        exit;
    }
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        if ($ajax) { header('Content-Type: application/json'); http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Security token expired; reload the page.']); exit; }
        $_SESSION['task_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: ' . $back); exit;
    }
    try {
        $need = fn() => $mgr ?: throw new BcrfTaskError('Only an Owner or Manager can do that.');
        switch ($act) {
            case 'add':
                $new = bcrf_task_create($pdo, $_POST);
                $_SESSION['task_flash'] = ['ok', 'Task added.'];
                $back = !empty($_POST['quick']) ? $back : 'tasks.php?id=' . $new;
                break;
            case 'edit':
                bcrf_task_update($pdo, $id, $_POST);
                $_SESSION['task_flash'] = ['ok', 'Task saved.'];
                $back = 'tasks.php?id=' . $id;
                break;
            case 'check':
                $t = bcrf_task_check($pdo, $id, (int)($_POST['idx'] ?? -1), !empty($_POST['done']));
                if ($ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'checked' => $t['checked'], 'total' => count($t['checklist']), 'status' => $t['status'],
                                      'status_label' => BCRF_TASK_STATUSES[$t['status']]]);
                    exit;
                }
                break;
            case 'set':
                $a = (string)($_POST['to'] ?? '');
                // Photos first (finishing a photo-required task needs one saved), then the status change.
                $added = 0;
                if ($v2 && in_array($a, ['done', 'photo'], true) && !empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
                    foreach (array_keys($_FILES['photos']['name']) as $i) {
                        if ((int)$_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE || (string)$_FILES['photos']['name'][$i] === '') continue;
                        bcrf_task_photo_add($pdo, $id, ['name' => $_FILES['photos']['name'][$i], 'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                                                        'error' => $_FILES['photos']['error'][$i], 'size' => $_FILES['photos']['size'][$i]]);
                        $added++;
                    }
                }
                if ($a === 'photo') {
                    if (!$added) throw new BcrfTaskError('Pick a photo first.');
                    $_SESSION['task_flash'] = ['ok', $added === 1 ? 'Photo saved.' : $added . ' photos saved.'];
                    $_SESSION['task_form'] = ['note' => (string)($_POST['note'] ?? ''), 'ans' => (array)($_POST['ans'] ?? [])];   // keep what was typed
                    $back = 'tasks.php?id=' . $id;
                    break;
                }
                $GLOBALS['bcrf_task_followups_made'] = [];
                $t = bcrf_task_set($pdo, $id, $a, (string)($_POST['note'] ?? ''), !empty($_POST['force']), (array)($_POST['ans'] ?? []));
                $fu = count($GLOBALS['bcrf_task_followups_made'] ?? []);
                $_SESSION['task_flash'] = ['ok', (['start' => 'Started.', 'done' => 'Done. Nice work.', 'skip' => 'Skipped for today.', 'cancel' => 'Task cancelled.', 'reopen' => 'Task reopened.'][$a] ?? 'Saved.')
                                                 . ($fu ? ' ' . $fu . ' follow-up task' . ($fu === 1 ? '' : 's') . ' added.' : '')];
                if (in_array($a, ['done', 'skip'], true) && !empty($_POST['next'])) $back = 'tasks.php';
                break;
            case 'photo_del':
                $tid = bcrf_task_photo_delete($pdo, (int)($_POST['photo_id'] ?? 0));
                $_SESSION['task_flash'] = ['ok', 'Photo removed.'];
                $back = 'tasks.php?id=' . $tid;
                break;
            case 'prefs':
                if (!bcrf_tasks_uid()) throw new BcrfTaskError('Sign in again first.');
                bcrf_task_prefs_save($pdo, (int)bcrf_tasks_uid(), !empty($_POST['digest']), !empty($_POST['late_alerts']));
                $_SESSION['task_flash'] = ['ok', 'Email choices saved.'];
                $back = 'tasks.php';
                break;
            case 'rec_save':
                $need();
                $rid = bcrf_rec_save($pdo, $_POST, $id ?: null);
                $_SESSION['task_flash'] = ['ok', 'Chore saved. The next few days were updated.'];
                $back = 'tasks.php?tab=chores';
                break;
            case 'rec_active':
                $need();
                bcrf_rec_set_active($pdo, $id, !empty($_POST['on']));
                $_SESSION['task_flash'] = ['ok', !empty($_POST['on']) ? 'Chore restarted.' : 'Chore paused. Its days ahead were removed.'];
                $back = 'tasks.php?tab=chores';
                break;
            case 'step_save':
                $need();
                $sid = bcrf_event_step_save($pdo, $_POST, $id ?: null);
                $_SESSION['task_flash'] = ['ok', 'Step saved. Upcoming events were updated.'];
                $back = 'tasks.php?tab=events#plan-' . rawurlencode((string)bcrf_event_step_get($pdo, $sid)['plan']);
                break;
            case 'step_active':
                $need();
                $stp = bcrf_event_step_get($pdo, $id);
                $pdo->prepare("UPDATE event_task_steps SET active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([!empty($_POST['on']) ? 1 : 0, $id]);
                if (empty($_POST['on'])) $pdo->prepare("DELETE FROM tasks WHERE event_step_id = ? AND status = 'open' AND updated_at IS NULL AND due_date >= ?")->execute([$id, $today]);
                else bcrf_event_tasks_sync($pdo);
                bcrf_tasks_audit($pdo, empty($_POST['on']) ? 'disable' : 'update', 'event_task_steps', $id, (empty($_POST['on']) ? 'Paused' : 'Restarted') . ' event step: ' . $stp['title']);
                $_SESSION['task_flash'] = ['ok', empty($_POST['on']) ? 'Step paused. Its upcoming tasks nobody started were removed.' : 'Step restarted.'];
                $back = 'tasks.php?tab=events';
                break;
            case 'tpl_save':
                $need();
                bcrf_tpl_save($pdo, $_POST, $id ?: null);
                $_SESSION['task_flash'] = ['ok', 'Checklist saved.'];
                $back = 'tasks.php?tab=templates';
                break;
            default:
                throw new BcrfTaskError('Unknown action.');
        }
    } catch (BcrfTaskError $e) {
        if ($ajax) { header('Content-Type: application/json'); http_response_code(422); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit; }
        $_SESSION['task_flash'] = ['err', $e->getMessage()];
        $_SESSION['task_form'] = $_POST;
        if ($act === 'add' && empty($_POST['quick'])) $back = 'tasks.php?new=1';
        if ($act === 'edit') $back = 'tasks.php?edit=' . $id;
        if ($act === 'rec_save') $back = 'tasks.php?tab=chores&rec=' . ($id ?: 'new');
        if ($act === 'tpl_save') $back = 'tasks.php?tab=templates&tpl_edit=' . ($id ?: 'new');
        if ($act === 'step_save') $back = 'tasks.php?tab=events&step=' . ($id ?: 'new&plan=' . rawurlencode((string)($_POST['plan'] ?? '')));
    } catch (Throwable $e) {
        error_log('[tasks] ' . $act . ': ' . $e->getMessage());
        if ($ajax) { header('Content-Type: application/json'); http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Could not save. Try again.']); exit; }
        $_SESSION['task_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back);
    exit;
}

$flash = $_SESSION['task_flash'] ?? null; unset($_SESSION['task_flash']);
$typed = $_SESSION['task_form'] ?? null;  unset($_SESSION['task_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

// ── Look ──
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base sm:text-sm w-full min-h-11';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'inline-flex items-center justify-center min-h-11 px-4 rounded-lg text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'inline-flex items-center justify-center min-h-11 px-4 rounded-lg text-sm border border-stone-300 bg-white text-stone-700 hover:bg-stone-100 font-semibold';
$btnGo   = 'inline-flex items-center justify-center min-h-11 px-4 rounded-lg text-sm bg-emerald-700 hover:bg-emerald-800 text-white font-semibold';
$card    = 'bg-white border border-stone-200 rounded-xl shadow-sm';
$prioChip = [
    'urgent' => 'bg-red-100 text-red-800', 'high' => 'bg-amber-100 text-amber-800',
    'normal' => '', 'low' => 'bg-stone-100 text-stone-500',
];
$statusChip = [
    'open' => 'bg-stone-100 text-stone-600', 'in_progress' => 'bg-sky-100 text-sky-800', 'done' => 'bg-emerald-100 text-emerald-800',
    'skipped' => 'bg-stone-100 text-stone-500', 'cancelled' => 'bg-stone-100 text-stone-500',
];
$dayLabel = function (?string $d) use ($today): string {
    if (!$d) return 'No date';
    if ($d === $today) return 'Today';
    if ($d === bcrf_tasks_add_days($today, 1)) return 'Tomorrow';
    if ($d === bcrf_tasks_add_days($today, -1)) return 'Yesterday';
    return date($d >= bcrf_tasks_add_days($today, -6) && $d <= bcrf_tasks_add_days($today, 6) ? 'l, M j' : 'D, M j, Y', strtotime($d));
};
$local = function (?string $utc, string $fmt = 'M j, g:i A'): string {
    if (!$utc) return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($fmt);
};
$whoOptions = function (string $sel) use ($people, $h): string {
    $o = '<option value="anyone"' . ($sel === 'anyone' ? ' selected' : '') . '>Anyone on the farm</option><optgroup label="A group">';
    foreach (BCRF_TASK_ROLES as $k => $v) $o .= '<option value="role:' . $h($k) . '"' . ($sel === 'role:' . $k ? ' selected' : '') . '>' . $h($v) . '</option>';
    $o .= '</optgroup><optgroup label="A person">';
    foreach ($people as $p) $o .= '<option value="user:' . (int)$p['id'] . '"' . ($sel === 'user:' . $p['id'] ? ' selected' : '') . '>' . $h($p['name']) . '</option>';
    return $o . '</optgroup>';
};
$prioOptions = function (string $sel) use ($h): string {
    $o = '';
    foreach (BCRF_TASK_PRIORITIES as $k => $v) $o .= '<option value="' . $k . '"' . ($sel === $k ? ' selected' : '') . '>' . $h($v) . '</option>';
    return $o;
};
$photoOptions = function (string $sel) use ($h): string {
    $o = '';
    foreach (BCRF_TASK_PHOTO_RULES as $k => $v) $o .= '<option value="' . $k . '"' . ($sel === $k ? ' selected' : '') . '>' . $h($v) . '</option>';
    return $o;
};
/** Questions, photo and follow-up fields for the chore and saved-checklist forms (slice 2). */
$extras = function (array $d, string $px) use ($h, $label, $input, $photoOptions, $v2): string {
    if (!$v2) return '';
    $q = is_array($d['questions'] ?? null) ? implode("\n", $d['questions']) : (string)($d['questions'] ?? '');
    $open = $q !== '' || ($d['photo_rule'] ?? 'none') !== 'none' || !empty($d['followup_unticked']);
    return '<details class="text-sm border border-stone-200 rounded-lg p-3"' . ($open ? ' open' : '') . '><summary class="cursor-pointer min-h-10 flex items-center font-semibold text-stone-700">When it\'s finished: questions, photo, follow-ups</summary><div class="space-y-3 mt-2">'
        . '<div><label for="' . $px . '_q" class="' . $label . '">Questions <span class="font-normal text-stone-400">one per line, answered when finishing</span></label>'
        . '<textarea id="' . $px . '_q" name="questions" rows="4" class="' . $input . ' font-mono text-sm" placeholder="Any animal off feed or limping? (yes/no, follow up if yes)&#10;Water trough temperature (number, °F)&#10;Gate condition (choice: good, needs repair)*">' . $h($q) . '</textarea>'
        . '<p class="text-xs text-stone-500 mt-1">After each question, in brackets: <b>yes/no</b> (the default), <b>number</b> with an optional unit, <b>text</b>, or <b>choice:</b> a list. '
        . 'Add <b>follow up</b> to a yes/no to make a follow-up task when the answer is No (or <b>follow up if yes</b>). End a line with <b>*</b> if it must be answered.</p></div>'
        . '<div class="grid gap-3 sm:grid-cols-2"><div><label for="' . $px . '_ph" class="' . $label . '">Photo when finished</label><select id="' . $px . '_ph" name="photo_rule" class="' . $input . '">' . $photoOptions((string)($d['photo_rule'] ?? 'none')) . '</select></div>'
        . '<label class="flex items-center gap-2 text-sm text-stone-700 min-h-11 sm:mt-5"><input type="checkbox" name="followup_unticked" value="1"' . (!empty($d['followup_unticked']) ? ' checked' : '') . '> Steps left unticked become follow-up tasks</label></div>'
        . '</div></details>';
};
$hidden = fn(string $act, int $id = 0) => '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="act" value="' . $h($act) . '">'
                                         . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : '');
$open = function (string $title, string $tab, string $width = 'max-w-3xl') use ($toast) {
    $_GET['tab'] = $tab;   // marks the right tab in the shell
    bcrf_shell_open($title . ' — Tasks', ['toast' => $toast, 'width' => $width, 'js' => ['tasks.js']]);
};

/** One task as a row in a list. */
$row = function (array $t, bool $showDate = false) use ($h, $card, $prioChip, $statusChip, $dayLabel): string {
    $total = count($t['checklist']);
    $closed = in_array($t['status'], ['done', 'skipped', 'cancelled'], true);
    $bits = [];
    if ($showDate) $bits[] = $dayLabel($t['due_date']);
    if ($t['due_time']) $bits[] = bcrf_tasks_fmt_time($t['due_time']);
    $bits[] = bcrf_tasks_who($t);
    if ($t['recurrence_id']) $bits[] = '🔁 chore';
    if (($t['photo_rule'] ?? 'none') === 'required') $bits[] = '📷 photo';
    if (!empty($t['form'])) $bits[] = count($t['form']) . ' question' . (count($t['form']) === 1 ? '' : 's');
    if (!empty($t['followup_of'])) $bits[] = '⚑ follow-up';
    $s = '<a href="tasks.php?id=' . (int)$t['id'] . '" class="' . $card . ' flex items-center gap-3 p-3 min-h-16 hover:border-stone-400' . ($closed ? ' opacity-70' : '') . '">';
    $s .= '<span class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-base ' . ($closed ? 'bg-emerald-100' : ($t['overdue'] ? 'bg-red-100' : 'bg-stone-100')) . '" aria-hidden="true">'
        . ($t['status'] === 'done' ? '✓' : ($t['status'] === 'skipped' ? '↷' : ($t['status'] === 'cancelled' ? '✕' : ($t['overdue'] ? '!' : '○')))) . '</span>';
    $s .= '<span class="flex-1 min-w-0"><span class="flex flex-wrap items-center gap-1.5">';
    $s .= '<span class="text-sm font-bold text-stone-900' . ($closed ? ' line-through decoration-stone-400' : '') . '">' . $h($t['title']) . '</span>';
    if ($t['overdue']) $s .= '<span class="text-[10px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-red-100 text-red-800">Late</span>';
    if ($prioChip[$t['priority']] ?? '') $s .= '<span class="text-[10px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded ' . $prioChip[$t['priority']] . '">' . $h(BCRF_TASK_PRIORITIES[$t['priority']]) . '</span>';
    if ($t['status'] === 'in_progress') $s .= '<span class="text-[10px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded ' . $statusChip['in_progress'] . '">Started</span>';
    $s .= '</span><span class="block text-xs text-stone-500 mt-0.5">' . $h(implode(' · ', $bits)) . '</span></span>';
    if ($total) $s .= '<span class="shrink-0 text-xs font-bold ' . ($t['checked'] === $total ? 'text-emerald-700' : 'text-stone-500') . '">' . $t['checked'] . '/' . $total . '</span>';
    return $s . '</a>';
};

$notFound = function (string $what) use ($open) {
    http_response_code(404);
    $open($what . ' not found', 'my');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">' . htmlspecialchars($what) . ' not found</h1>',
         '<a href="tasks.php" class="text-sm text-amber-800 underline">Back to tasks</a>';
    bcrf_shell_close();
    exit;
};

// ── ONE TASK ──
if (isset($_GET['id'])) {
    try { $t = bcrf_task_get($pdo, (int)$_GET['id']); } catch (BcrfTaskError $e) { $notFound('Task'); }
    if (!bcrf_tasks_can_see($t)) $notFound('Task');
    $total = count($t['checklist']); $left = $total - $t['checked'];
    $closed = in_array($t['status'], ['done', 'skipped', 'cancelled'], true);
    $creator = $t['created_by'] ? ($names[$t['created_by']] ?? 'someone') : null;
    $rec = null;
    if ($t['recurrence_id']) { try { $rec = bcrf_rec_get($pdo, (int)$t['recurrence_id']); } catch (BcrfTaskError $e) {} }
    $photos = $v2 ? bcrf_task_photos($pdo, (int)$t['id']) : [];
    $followups = $v2 ? bcrf_task_followups_of($pdo, (int)$t['id']) : [];
    $parent = null;
    if ($v2 && !empty($t['followup_of'])) { try { $parent = bcrf_task_get($pdo, (int)$t['followup_of']); if (!bcrf_tasks_can_see($parent)) $parent = null; } catch (BcrfTaskError $e) {} }
    $ansIn = is_array($typed['ans'] ?? null) ? $typed['ans'] : $t['answers'];   // typed answers come back after an error
    $open($t['title'], 'my', 'max-w-2xl');
    ?>
  <a href="tasks.php" class="inline-flex items-center min-h-10 text-sm text-stone-500 hover:text-stone-800 mb-1">← My tasks</a>
  <div class="flex flex-wrap items-center gap-2 mb-1">
    <h1 class="text-2xl font-bold text-stone-800"><?= $h($t['title']) ?></h1>
    <span id="tStatus" class="text-xs font-semibold px-2 py-1 rounded <?= $statusChip[$t['status']] ?>"><?= $h(BCRF_TASK_STATUSES[$t['status']]) ?></span>
    <?php if ($t['overdue']): ?><span class="text-xs font-semibold px-2 py-1 rounded bg-red-100 text-red-800">Late</span><?php endif; ?>
    <?php if ($prioChip[$t['priority']] ?? ''): ?><span class="text-xs font-semibold px-2 py-1 rounded <?= $prioChip[$t['priority']] ?>"><?= $h(BCRF_TASK_PRIORITIES[$t['priority']]) ?></span><?php endif; ?>
  </div>
  <p class="text-sm text-stone-500 mb-4">
    <?= $h($dayLabel($t['due_date'])) ?><?= $t['due_time'] ? ' at ' . $h(bcrf_tasks_fmt_time($t['due_time'])) : '' ?> · <?= $h(bcrf_tasks_who($t)) ?>
    <?php if ($rec): ?> · 🔁 <?= $h(bcrf_rec_describe($rec)) ?><?php endif; ?>
    <?php if ($parent): ?> · follow-up of <a class="underline" href="tasks.php?id=<?= (int)$parent['id'] ?>"><?= $h($parent['title']) ?></a>
    <?php elseif ($t['related_type'] === 'booking'): ?> · <a class="underline" href="/admin/admin.php?view=bookings">booking #<?= $h($t['related_id']) ?></a> · <a class="underline" href="tasks.php?tab=all&amp;status=all&amp;related=booking:<?= $h($t['related_id']) ?>">its checklist</a>
    <?php elseif ($t['related_type'] === 'event_inquiry'): ?> · <a class="underline" href="/admin/admin.php?view=event_inquiries&amp;status=confirmed">party #<?= $h($t['related_id']) ?></a> · <a class="underline" href="tasks.php?tab=all&amp;status=all&amp;related=event_inquiry:<?= $h($t['related_id']) ?>">its checklist</a>
    <?php elseif ($t['related_type']): ?> · linked to <?= $h($t['related_type']) ?> <?= $h($t['related_id']) ?><?php endif; ?>
  </p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if ($t['description']): ?><div class="<?= $card ?> p-4 mb-4 text-sm text-stone-700 whitespace-pre-line"><?= $h($t['description']) ?></div><?php endif; ?>

  <?php if ($total): ?>
  <div class="flex items-center justify-between mb-2">
    <h2 class="text-lg font-semibold text-stone-800">Checklist</h2>
    <span id="tCount" class="text-sm font-bold text-stone-500"><?= $t['checked'] ?> of <?= $total ?></span>
  </div>
  <ul class="<?= $card ?> divide-y divide-stone-100 mb-4" id="tList">
    <?php foreach ($t['checklist'] as $i => $it): $done = !empty($it['done']); ?>
    <li>
      <form method="POST" action="tasks.php" data-check>
        <?= $hidden('check', (int)$t['id']) ?><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="done" value="<?= $done ? '' : '1' ?>">
        <button type="submit" <?= $closed ? 'disabled' : '' ?> aria-pressed="<?= $done ? 'true' : 'false' ?>"
                class="w-full flex items-start gap-3 p-3 min-h-14 text-left hover:bg-stone-50 disabled:hover:bg-transparent disabled:cursor-default">
          <span data-box class="shrink-0 mt-0.5 w-6 h-6 rounded border-2 flex items-center justify-center text-sm font-black <?= $done ? 'bg-emerald-600 border-emerald-600 text-white' : 'border-stone-300 text-transparent' ?>" aria-hidden="true">✓</span>
          <span class="flex-1">
            <span data-text class="block text-base sm:text-sm text-stone-800 <?= $done ? 'line-through decoration-stone-400 text-stone-500' : '' ?>"><?= $h($it['t']) ?></span>
            <span data-by class="block text-xs text-stone-400"><?= $done && !empty($it['at']) ? $h(($names[$it['by']] ?? 'Someone') . ' · ' . $local($it['at'], 'g:i A')) : '' ?></span>
          </span>
        </button>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if ($photos): ?>
  <h2 class="text-lg font-semibold text-stone-800 mb-2">Photos</h2>
  <div class="grid grid-cols-3 gap-2 mb-4">
    <?php foreach ($photos as $ph): ?>
    <div class="relative">
      <a href="task_photo.php?id=<?= (int)$ph['id'] ?>" target="_blank" rel="noopener" class="block aspect-square rounded-lg overflow-hidden border border-stone-200 bg-stone-100">
        <img src="task_photo.php?id=<?= (int)$ph['id'] ?>&amp;thumb=1" alt="Photo <?= $h($ph['by_name'] ? 'by ' . $ph['by_name'] : '') ?>" loading="lazy" class="w-full h-full object-cover"></a>
      <div class="text-[11px] text-stone-400 mt-0.5 truncate"><?= $h(trim(($ph['by_name'] ?? '') . ' · ' . $local($ph['created_at'], 'g:i A'), ' ·')) ?></div>
      <?php if (!$closed && ($mgr || (int)$ph['created_by'] === (int)bcrf_tasks_uid())): ?>
      <form method="POST" action="tasks.php" class="absolute top-1 right-1"><?= $hidden('photo_del') ?><input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
        <button type="submit" data-confirm="Remove this photo?" class="w-8 h-8 rounded-full bg-white/90 border border-stone-300 text-stone-600 text-sm font-bold" aria-label="Remove photo">✕</button></form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($followups): ?>
  <h2 class="text-lg font-semibold text-stone-800 mb-2">Follow-ups from this task</h2>
  <div class="space-y-2 mb-4"><?php foreach ($followups as $fu) echo $row($fu, true); ?></div>
  <?php endif; ?>

  <?php if ($closed): ?>
  <div class="<?= $card ?> p-4 mb-4 text-sm text-stone-700">
    <div><span class="font-semibold"><?= $h(BCRF_TASK_STATUSES[$t['status']]) ?></span> <?= $h($local($t['completed_at'])) ?><?= $t['completed_name'] ? ' by ' . $h($t['completed_name']) : '' ?></div>
    <?php if ($t['completion_note']): ?><div class="mt-1 whitespace-pre-line">“<?= $h($t['completion_note']) ?>”</div><?php endif; ?>
    <?php if ($t['form']): ?>
    <dl class="mt-3 divide-y divide-stone-100 border-t border-stone-100">
      <?php foreach ($t['form'] as $f): $v = $t['answers'][$f['k']] ?? null; $flag = bcrf_task_answer_flags($f, $v); ?>
      <div class="py-2 flex flex-wrap gap-x-3"><dt class="flex-1 min-w-40 text-stone-600"><?= $h($f['q']) ?></dt>
        <dd class="font-semibold <?= $flag ? 'text-red-700' : 'text-stone-900' ?>"><?= $h(bcrf_task_answer_label($f, $v)) ?><?= $flag ? ' ⚑' : '' ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php endif; ?>
  </div>
  <form method="POST" action="tasks.php" class="mb-4"><?= $hidden('set', (int)$t['id']) ?><input type="hidden" name="to" value="reopen">
    <button type="submit" class="<?= $btnSec ?>">Reopen</button></form>
  <?php else: ?>
  <form method="POST" action="tasks.php" enctype="multipart/form-data" class="<?= $card ?> p-4 mb-4 space-y-3">
    <?= $hidden('set', (int)$t['id']) ?><input type="hidden" name="next" value="1"><input type="hidden" name="force" value="1">
    <?php if ($t['form']): ?>
    <fieldset class="space-y-3">
      <legend class="text-sm font-semibold text-stone-800 mb-1">Before you finish</legend>
      <?php foreach ($t['form'] as $f): $k = $h($f['k']); $val = (string)($ansIn[$f['k']] ?? ''); $req = !empty($f['required']); ?>
      <div>
        <?php if ($f['type'] === 'yesno'): ?>
        <span class="<?= $label ?>"><?= $h($f['q']) ?><?= $req ? ' <span class="text-red-700">*</span>' : '' ?></span>
        <div class="flex gap-2" role="radiogroup" aria-label="<?= $h($f['q']) ?>">
          <?php foreach (['yes' => 'Yes', 'no' => 'No'] as $ov => $ol): ?>
          <label class="flex-1 inline-flex items-center justify-center gap-2 min-h-11 px-3 rounded-lg border border-stone-300 bg-white text-sm font-semibold cursor-pointer has-[:checked]:bg-stone-800 has-[:checked]:text-white has-[:checked]:border-stone-800">
            <input type="radio" name="ans[<?= $k ?>]" value="<?= $ov ?>"<?= $val === $ov ? ' checked' : '' ?> class="sr-only"> <?= $ol ?></label>
          <?php endforeach; ?>
        </div>
        <?php if (($f['followup'] ?? '') !== ''): ?><p class="text-xs text-stone-400 mt-1">“<?= $f['followup'] === 'yes' ? 'Yes' : 'No' ?>” adds a follow-up task.</p><?php endif; ?>
        <?php elseif ($f['type'] === 'choice'): ?>
        <label for="a_<?= $k ?>" class="<?= $label ?>"><?= $h($f['q']) ?><?= $req ? ' <span class="text-red-700">*</span>' : '' ?></label>
        <select id="a_<?= $k ?>" name="ans[<?= $k ?>]" class="<?= $input ?>"><option value="">Pick one…</option>
          <?php foreach ($f['options'] as $o): ?><option value="<?= $h($o) ?>"<?= $val === $o ? ' selected' : '' ?>><?= $h($o) ?></option><?php endforeach; ?></select>
        <?php elseif ($f['type'] === 'number'): ?>
        <label for="a_<?= $k ?>" class="<?= $label ?>"><?= $h($f['q']) ?><?= $req ? ' <span class="text-red-700">*</span>' : '' ?></label>
        <div class="flex items-center gap-2"><input id="a_<?= $k ?>" name="ans[<?= $k ?>]" inputmode="decimal" value="<?= $h($val) ?>" class="<?= $input ?> sm:w-40"><?php if ($f['unit'] !== ''): ?><span class="text-sm text-stone-500"><?= $h($f['unit']) ?></span><?php endif; ?></div>
        <?php else: ?>
        <label for="a_<?= $k ?>" class="<?= $label ?>"><?= $h($f['q']) ?><?= $req ? ' <span class="text-red-700">*</span>' : '' ?></label>
        <textarea id="a_<?= $k ?>" name="ans[<?= $k ?>]" rows="2" maxlength="500" class="<?= $input ?>"><?= $h($val) ?></textarea>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
    <?php if ($v2 && ($t['photo_rule'] !== 'none' || $photos) && count($photos) < BCRF_TASK_PHOTO_MAX): ?>
    <div>
      <label for="t_photos" class="<?= $label ?>"><?= $t['photo_rule'] === 'required' ? ($photos ? 'Add another photo' : 'Photo <span class="text-red-700">*</span> <span class="font-normal text-stone-400">needed to finish</span>') : 'Photo <span class="font-normal text-stone-400">optional</span>' ?></label>
      <input id="t_photos" name="photos[]" type="file" accept="image/*" multiple class="block w-full text-sm text-stone-600 file:mr-3 file:min-h-11 file:px-4 file:rounded-lg file:border-0 file:bg-stone-800 file:text-white file:font-semibold">
      <button type="submit" name="to" value="photo" class="mt-2 text-sm text-amber-800 underline min-h-10">Save photo without finishing</button>
    </div>
    <?php endif; ?>
    <div>
      <label for="t_note" class="<?= $label ?>">Note <span class="font-normal text-stone-400">optional: anything the next person should know</span></label>
      <textarea id="t_note" name="note" rows="2" maxlength="500" class="<?= $input ?>"><?= $h((string)($typed['note'] ?? '')) ?></textarea>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php if ($left > 0): ?>
        <button type="submit" name="to" value="done" class="<?= $btnSec ?>" data-go="<?= $h($btnGo) ?>" id="tDone">Finish anyway (<?= $left ?> not ticked)</button>
      <?php else: ?>
        <button type="submit" name="to" value="done" class="<?= $btnGo ?>" id="tDone">✓ Done</button>
      <?php endif; ?>
      <?php if ($t['status'] === 'open'): ?><button type="submit" name="to" value="start" id="tStart" class="<?= $btnSec ?>">Start</button><?php endif; ?>
      <?php if ($t['recurrence_id']): ?><button type="submit" name="to" value="skip" class="<?= $btnSec ?>">Skip today (say why)</button><?php endif; ?>
      <?php if (bcrf_tasks_can_edit($t)): ?><button type="submit" name="to" value="cancel" class="inline-flex items-center justify-center min-h-11 px-4 rounded-lg text-sm border border-red-300 bg-white text-red-700 hover:bg-red-50 font-semibold" data-confirm="Cancel this task?">Cancel task</button><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>

  <p class="text-xs text-stone-400">
    <?= $t['recurrence_id'] ? 'Made by the chore schedule' : 'Added ' . $h($local($t['created_at'])) . ($creator ? ' by ' . $h($creator) : '') ?>
    <?php if (bcrf_tasks_can_edit($t) && !$closed): ?> · <a class="underline hover:text-stone-700" href="tasks.php?edit=<?= (int)$t['id'] ?>">Change this task</a><?php endif; ?>
    <?php if ($rec && $mgr): ?> · <a class="underline hover:text-stone-700" href="tasks.php?tab=chores&amp;rec=<?= (int)$rec['id'] ?>">Change the chore</a><?php endif; ?>
  </p>
    <?php
    bcrf_shell_close();
    exit;
}

// ── NEW / CHANGE A TASK ──
if (isset($_GET['new']) || isset($_GET['edit'])) {
    $t = null;
    if (isset($_GET['edit'])) {
        try { $t = bcrf_task_get($pdo, (int)$_GET['edit']); } catch (BcrfTaskError $e) { $notFound('Task'); }
        if (!bcrf_tasks_can_edit($t)) $notFound('Task');
    }
    $tpl = null;
    if (!$t && isset($_GET['tpl'])) { try { $tpl = bcrf_tpl_get($pdo, (int)$_GET['tpl']); } catch (BcrfTaskError $e) {} }
    $d = $typed ?: ($t ? ['title' => $t['title'], 'description' => $t['description'], 'checklist' => implode("\n", array_column($t['checklist'], 't')),
                          'priority' => $t['priority'], 'due_date' => $t['due_date'], 'due_time' => $t['due_time'] ? substr($t['due_time'], 0, 5) : '',
                          'who' => bcrf_tasks_who_value($t), 'related_type' => $t['related_type'], 'related_id' => $t['related_id'], 'photo_rule' => $t['photo_rule']]
                    : ($tpl ? ['title' => $tpl['title'], 'description' => $tpl['description'], 'checklist' => implode("\n", $tpl['lines']), 'priority' => $tpl['priority'],
                               'who' => bcrf_tasks_who_value($tpl), 'due_date' => $today, 'template_id' => $tpl['id'], 'photo_rule' => $tpl['photo_rule'] ?? 'none']
                            : ['due_date' => $today, 'who' => 'anyone', 'priority' => 'normal']));
    $tpls = $t ? [] : bcrf_tpl_list($pdo, true);
    $open($t ? 'Change task' : 'New task', 'my', 'max-w-2xl');
    ?>
  <a href="<?= $t ? 'tasks.php?id=' . (int)$t['id'] : 'tasks.php' ?>" class="inline-flex items-center min-h-10 text-sm text-stone-500 hover:text-stone-800 mb-1">← <?= $t ? 'Back to the task' : 'My tasks' ?></a>
  <h1 class="text-2xl font-bold text-stone-800 mb-3"><?= $t ? 'Change task' : 'New task' ?></h1>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if ($tpls): ?>
  <div class="flex flex-wrap items-center gap-2 mb-3 text-sm"><span class="text-stone-500">Start from a saved checklist:</span>
    <?php foreach ($tpls as $tp): ?><a href="tasks.php?new=1&amp;tpl=<?= (int)$tp['id'] ?>" class="px-2 py-1 rounded border border-stone-300 bg-white hover:bg-stone-100 <?= ($tpl['id'] ?? 0) == $tp['id'] ? 'font-bold' : '' ?>"><?= $h($tp['title']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <form method="POST" action="tasks.php" class="<?= $card ?> p-4 space-y-3">
    <?= $hidden($t ? 'edit' : 'add', $t ? (int)$t['id'] : 0) ?>
    <?php if (!empty($d['template_id'])): ?><input type="hidden" name="template_id" value="<?= (int)$d['template_id'] ?>"><?php endif; ?>
    <div><label for="f_title" class="<?= $label ?>">What needs doing</label>
      <input id="f_title" name="title" maxlength="150" required value="<?= $h($d['title'] ?? '') ?>" class="<?= $input ?>" placeholder="e.g. Trim goat hooves"></div>
    <div class="grid gap-3 sm:grid-cols-3">
      <div><label for="f_date" class="<?= $label ?>">Due</label><input id="f_date" name="due_date" type="date" value="<?= $h($d['due_date'] ?? '') ?>" class="<?= $input ?>"></div>
      <div><label for="f_time" class="<?= $label ?>">By <span class="font-normal text-stone-400">optional</span></label><input id="f_time" name="due_time" type="time" value="<?= $h($d['due_time'] ?? '') ?>" class="<?= $input ?>"></div>
      <div><label for="f_prio" class="<?= $label ?>">Priority</label><select id="f_prio" name="priority" class="<?= $input ?>"><?= $prioOptions((string)($d['priority'] ?? 'normal')) ?></select></div>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="f_who" class="<?= $label ?>">Who</label><select id="f_who" name="who" class="<?= $input ?>"><?= $whoOptions((string)($d['who'] ?? 'anyone')) ?></select></div>
      <?php if ($v2): ?><div><label for="f_photo" class="<?= $label ?>">Photo when finished</label><select id="f_photo" name="photo_rule" class="<?= $input ?>"><?= $photoOptions((string)($d['photo_rule'] ?? 'none')) ?></select></div><?php endif; ?>
    </div>
    <?php if (!empty($tpl['form_json'])): ?><p class="text-xs text-stone-500">This saved checklist also asks <?= count(json_decode((string)$tpl['form_json'], true) ?: []) ?> question(s) when the task is finished.</p><?php endif; ?>
    <div><label for="f_list" class="<?= $label ?>">Checklist <span class="font-normal text-stone-400">one step per line, optional</span></label>
      <textarea id="f_list" name="checklist" rows="6" class="<?= $input ?>"><?= $h(is_array($d['checklist'] ?? '') ? implode("\n", $d['checklist']) : ($d['checklist'] ?? '')) ?></textarea></div>
    <div><label for="f_desc" class="<?= $label ?>">Notes <span class="font-normal text-stone-400">optional</span></label>
      <textarea id="f_desc" name="description" rows="3" class="<?= $input ?>"><?= $h($d['description'] ?? '') ?></textarea></div>
    <details class="text-sm"><summary class="cursor-pointer text-stone-500">Link to a record (optional)</summary>
      <div class="grid gap-3 sm:grid-cols-2 mt-2">
        <div><label for="f_rt" class="<?= $label ?>">Kind</label><select id="f_rt" name="related_type" class="<?= $input ?>">
          <?php foreach (['' => 'Nothing', 'animal' => 'Animal', 'booking' => 'Booking', 'inventory' => 'Product', 'event' => 'Event', 'vendor' => 'Vendor'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= (string)($d['related_type'] ?? '') === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        <div><label for="f_ri" class="<?= $label ?>">Number or name</label><input id="f_ri" name="related_id" maxlength="64" value="<?= $h($d['related_id'] ?? '') ?>" class="<?= $input ?>"></div>
      </div>
    </details>
    <button type="submit" class="<?= $btnMain ?>"><?= $t ? 'Save' : 'Add task' ?></button>
  </form>
    <?php
    bcrf_shell_close();
    exit;
}

$tab = (string)($_GET['tab'] ?? 'my');
if (!$mgr && $tab !== 'my') $tab = 'my';

// ── CHORES ──
if ($tab === 'chores') {
    if (isset($_GET['rec'])) {
        $r = null;
        if ($_GET['rec'] !== 'new') { try { $r = bcrf_rec_get($pdo, (int)$_GET['rec']); } catch (BcrfTaskError $e) { $notFound('Chore'); } }
        $d = $typed ?: ($r ? ['title' => $r['title'], 'description' => $r['description'], 'checklist' => implode("\n", $r['lines']), 'priority' => $r['priority'],
                              'who' => bcrf_tasks_who_value($r), 'freq' => $r['freq'], 'interval_n' => $r['interval_n'], 'weekdays' => explode(',', (string)$r['weekdays']),
                              'month_day' => $r['month_day'], 'open_days_only' => $r['open_days_only'], 'season_start' => $r['season_start'], 'season_end' => $r['season_end'],
                              'due_anchor' => $r['due_anchor'], 'due_time' => $r['due_time'] ? substr($r['due_time'], 0, 5) : '', 'start_date' => $r['start_date'],
                              'end_date' => $r['end_date'], 'horizon_days' => $r['horizon_days'],
                              'questions' => bcrf_task_form_text(json_decode((string)($r['form_json'] ?? ''), true) ?: []), 'photo_rule' => $r['photo_rule'] ?? 'none',
                              'followup_unticked' => $r['followup_unticked'] ?? 0]
                        : ['freq' => 'daily', 'interval_n' => 2, 'who' => 'anyone', 'priority' => 'normal', 'due_anchor' => 'time', 'start_date' => $today, 'horizon_days' => 7, 'weekdays' => []]);
        $wd = array_map('strval', (array)($d['weekdays'] ?? []));
        $open($r ? 'Change chore' : 'New chore', 'chores', 'max-w-2xl');
        ?>
  <a href="tasks.php?tab=chores" class="inline-flex items-center min-h-10 text-sm text-stone-500 hover:text-stone-800 mb-1">← Chores</a>
  <h1 class="text-2xl font-bold text-stone-800 mb-1"><?= $r ? 'Change chore' : 'New chore' ?></h1>
  <p class="text-sm text-stone-500 mb-3">Each day it falls on gets its own task, made a week ahead. Saving updates the days ahead that nobody has started; finished days and history stay as they were.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <form method="POST" action="tasks.php" class="<?= $card ?> p-4 space-y-3">
    <?= $hidden('rec_save', $r ? (int)$r['id'] : 0) ?>
    <div><label for="c_title" class="<?= $label ?>">Chore</label><input id="c_title" name="title" maxlength="150" required value="<?= $h($d['title'] ?? '') ?>" class="<?= $input ?>"></div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="c_who" class="<?= $label ?>">Who</label><select id="c_who" name="who" class="<?= $input ?>"><?= $whoOptions((string)($d['who'] ?? 'anyone')) ?></select></div>
      <div><label for="c_prio" class="<?= $label ?>">Priority</label><select id="c_prio" name="priority" class="<?= $input ?>"><?= $prioOptions((string)($d['priority'] ?? 'normal')) ?></select></div>
    </div>
    <fieldset class="border border-stone-200 rounded-lg p-3 space-y-3">
      <legend class="px-1 text-xs font-semibold text-stone-600">How often</legend>
      <select name="freq" id="c_freq" class="<?= $input ?>" aria-label="How often">
        <?php foreach (BCRF_TASK_FREQS as $k => $v): ?><option value="<?= $k ?>"<?= ($d['freq'] ?? '') === $k ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?>
      </select>
      <div data-freq="every_n weekly"><label for="c_n" class="<?= $label ?>">Every how many <span data-freq="every_n">days</span><span data-freq="weekly">weeks</span>?</label>
        <input id="c_n" name="interval_n" type="number" min="1" max="365" value="<?= (int)($d['interval_n'] ?? 1) ?>" class="<?= $input ?> sm:w-32"></div>
      <div data-freq="weekly"><span class="<?= $label ?>">On</span>
        <div class="flex flex-wrap gap-1">
          <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $nm): ?>
          <label class="inline-flex items-center gap-1 px-3 min-h-11 rounded-lg border border-stone-300 bg-white text-sm"><input type="checkbox" name="weekdays[]" value="<?= $i ?>"<?= in_array((string)$i, $wd, true) ? ' checked' : '' ?>> <?= $nm ?></label>
          <?php endforeach; ?>
        </div></div>
      <div data-freq="monthly"><label for="c_md" class="<?= $label ?>">Day of the month</label>
        <input id="c_md" name="month_day" type="number" min="1" max="31" value="<?= $h($d['month_day'] ?? '') ?>" class="<?= $input ?> sm:w-32"><span class="text-xs text-stone-500"> 29–31 fall on the last day in shorter months</span></div>
      <label class="flex items-center gap-2 text-sm text-stone-700 min-h-11"><input type="checkbox" name="open_days_only" value="1"<?= !empty($d['open_days_only']) ? ' checked' : '' ?>> Only on days the farm is open (Hours, and booking blackout dates)</label>
    </fieldset>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="c_anchor" class="<?= $label ?>">Due</label>
        <select id="c_anchor" name="due_anchor" class="<?= $input ?>">
          <?php foreach (['time' => 'At a time', 'opening' => "At opening (that day's hours)", 'closing' => "At closing (that day's hours)"] as $k => $v): ?>
          <option value="<?= $k ?>"<?= ($d['due_anchor'] ?? 'time') === $k ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select></div>
      <div data-anchor="time"><label for="c_time" class="<?= $label ?>">Time <span class="font-normal text-stone-400">optional</span></label><input id="c_time" name="due_time" type="time" value="<?= $h($d['due_time'] ?? '') ?>" class="<?= $input ?>"></div>
    </div>
    <div><label for="c_list" class="<?= $label ?>">Checklist <span class="font-normal text-stone-400">one step per line</span></label>
      <textarea id="c_list" name="checklist" rows="9" class="<?= $input ?>"><?= $h(is_array($d['checklist'] ?? '') ? implode("\n", $d['checklist']) : ($d['checklist'] ?? '')) ?></textarea></div>
    <div><label for="c_desc" class="<?= $label ?>">Notes <span class="font-normal text-stone-400">optional, shown on every day</span></label>
      <textarea id="c_desc" name="description" rows="2" class="<?= $input ?>"><?= $h($d['description'] ?? '') ?></textarea></div>
    <?= $extras($d, 'c') ?>
    <details class="text-sm"<?= !empty($d['season_start']) || !empty($d['end_date']) ? ' open' : '' ?>><summary class="cursor-pointer text-stone-500 min-h-10 flex items-center">Season, start and end (optional)</summary>
      <div class="grid gap-3 sm:grid-cols-2 mt-2">
        <div><label for="c_ss" class="<?= $label ?>">Season from (MM-DD)</label><input id="c_ss" name="season_start" pattern="\d{2}-\d{2}" placeholder="04-01" value="<?= $h($d['season_start'] ?? '') ?>" class="<?= $input ?>"></div>
        <div><label for="c_se" class="<?= $label ?>">Season to (MM-DD)</label><input id="c_se" name="season_end" pattern="\d{2}-\d{2}" placeholder="10-31" value="<?= $h($d['season_end'] ?? '') ?>" class="<?= $input ?>"></div>
        <div><label for="c_sd" class="<?= $label ?>">Starts</label><input id="c_sd" name="start_date" type="date" value="<?= $h($d['start_date'] ?? $today) ?>" class="<?= $input ?>"></div>
        <div><label for="c_ed" class="<?= $label ?>">Ends <span class="font-normal text-stone-400">empty = keeps going</span></label><input id="c_ed" name="end_date" type="date" value="<?= $h($d['end_date'] ?? '') ?>" class="<?= $input ?>"></div>
        <div><label for="c_hz" class="<?= $label ?>">Days ahead to show</label><input id="c_hz" name="horizon_days" type="number" min="1" max="30" value="<?= (int)($d['horizon_days'] ?? 7) ?>" class="<?= $input ?>"></div>
      </div>
      <p class="text-xs text-stone-500 mt-1">A season like 11-15 to 02-28 runs over New Year.</p>
    </details>
    <button type="submit" class="<?= $btnMain ?>">Save chore</button>
  </form>
  <?php if ($r): ?>
  <form method="POST" action="tasks.php" class="mt-3"><?= $hidden('rec_active', (int)$r['id']) ?><input type="hidden" name="on" value="<?= $r['active'] ? '' : '1' ?>">
    <button type="submit" class="<?= $btnSec ?>"><?= $r['active'] ? 'Pause this chore' : 'Restart this chore' ?></button>
    <span class="text-xs text-stone-500 ml-2"><?= $r['active'] ? 'Removes the days ahead nobody started. Nothing is deleted from history.' : 'Fills the next days again.' ?></span></form>
  <?php endif;
        bcrf_shell_close();
        exit;
    }
    $recs = bcrf_rec_list($pdo);
    $open('Chores', 'chores');
    ?>
  <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
    <h1 class="text-2xl font-bold text-stone-800">Chores</h1>
    <a href="tasks.php?tab=chores&amp;rec=new" class="<?= $btnMain ?>">+ New chore</a>
  </div>
  <p class="text-sm text-stone-500 mb-3">Jobs that come round on a schedule. Each day gets its own task on everyone's list, a week ahead.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <div class="space-y-2">
    <?php foreach ($recs as $r): ?>
    <a href="tasks.php?tab=chores&amp;rec=<?= (int)$r['id'] ?>" class="<?= $card ?> block p-3 hover:border-stone-400<?= $r['active'] ? '' : ' opacity-60' ?>">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-bold text-stone-900"><?= $h($r['title']) ?></span>
        <?php if (!$r['active']): ?><span class="text-[10px] font-black uppercase px-1.5 py-0.5 rounded bg-stone-100 text-stone-500">Paused</span><?php endif; ?>
        <span class="ml-auto text-xs text-stone-500"><?= count($r['lines']) ?> step<?= count($r['lines']) === 1 ? '' : 's' ?></span>
      </div>
      <div class="text-xs text-stone-500 mt-0.5"><?= $h(bcrf_rec_describe($r)) ?> · <?= $h(bcrf_tasks_who($r)) ?><?= $r['active'] && $r['next_due'] ? ' · next ' . $h($dayLabel($r['next_due'])) : '' ?></div>
    </a>
    <?php endforeach; ?>
    <?php if (!$recs): ?><p class="text-sm text-stone-500">No chores yet.</p><?php endif; ?>
  </div>
    <?php
    bcrf_shell_close();
    exit;
}

// ── EVENT CHECKLISTS (parties and bookings) ──
if ($tab === 'events') {
    if (!bcrf_event_tasks_ready($pdo)) {
        $open('Event checklists', 'events');
        echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Event checklists</h1>', bcrf_shell_notice('warning', 'Run migration 0031 in Settings › Database first.');
        bcrf_shell_close();
        exit;
    }
    $plans = bcrf_event_plans($pdo);
    if (isset($_GET['step'])) {
        $r = null;
        if ($_GET['step'] !== 'new') { try { $r = bcrf_event_step_get($pdo, (int)$_GET['step']); } catch (BcrfTaskError $e) { $notFound('Step'); } }
        $plan = $r ? (string)$r['plan'] : (string)($_GET['plan'] ?? 'party');
        if (!isset($plans[$plan]) && !$r) $plan = 'party';
        $isBooking = str_starts_with($plan, 'booking:');
        $d = $typed ?: ($r ? ['title' => $r['title'], 'description' => $r['description'], 'checklist' => implode("\n", $r['lines']), 'priority' => $r['priority'],
                              'who' => bcrf_tasks_who_value($r), 'questions' => bcrf_task_form_text($r['form']), 'photo_rule' => $r['photo_rule'], 'followup_unticked' => $r['followup_unticked'],
                              'day_offset' => $r['day_offset'], 'anchor' => $r['anchor'], 'at_time' => $r['at_time'] ? substr($r['at_time'], 0, 5) : '', 'minute_offset' => $r['minute_offset'],
                              'sort_order' => $r['sort_order'], 'active' => $r['active']]
                           : ['who' => 'anyone', 'priority' => 'normal', 'day_offset' => -1, 'anchor' => 'time', 'at_time' => '10:00', 'minute_offset' => 0, 'active' => 1]);
        $off = (int)($d['day_offset'] ?? 0);
        $open($r ? 'Change step' : 'New step', 'events', 'max-w-2xl');
        ?>
  <a href="tasks.php?tab=events" class="inline-flex items-center min-h-10 text-sm text-stone-500 hover:text-stone-800 mb-1">← Event checklists</a>
  <h1 class="text-2xl font-bold text-stone-800 mb-1"><?= $r ? 'Change step' : 'New step' ?></h1>
  <p class="text-sm text-stone-500 mb-3"><?= $h($plans[$plan] ?? $plan) ?>. Every confirmed <?= $isBooking ? 'booking of this kind' : 'party' ?> gets this as a task, with the <?= $isBooking ? 'booking' : 'party' ?> details in its notes. Saving updates upcoming tasks nobody has started.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <form method="POST" action="tasks.php" class="<?= $card ?> p-4 space-y-3">
    <?= $hidden('step_save', $r ? (int)$r['id'] : 0) ?><input type="hidden" name="plan" value="<?= $h($plan) ?>">
    <div><label for="e_title" class="<?= $label ?>">Step</label><input id="e_title" name="title" maxlength="120" required value="<?= $h($d['title'] ?? '') ?>" class="<?= $input ?>" placeholder="e.g. Party prep">
      <p class="text-xs text-stone-500 mt-1">The task is named “<?= $h(($d['title'] ?? '') ?: 'Step') ?>: <i>customer name</i>”.</p></div>
    <fieldset class="border border-stone-200 rounded-lg p-3 space-y-3">
      <legend class="px-1 text-xs font-semibold text-stone-600">When</legend>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label for="e_off" class="<?= $label ?>">Day</label>
          <div class="flex items-center gap-2">
            <input id="e_off" name="day_offset" type="number" min="-60" max="30" value="<?= $off ?>" class="<?= $input ?> w-24">
            <span class="text-sm text-stone-600">days from the <?= $isBooking ? 'booking' : 'party' ?> (−1 = day before, 0 = on the day)</span></div></div>
        <div><label for="e_anchor" class="<?= $label ?>">Due</label>
          <select id="e_anchor" name="anchor" class="<?= $input ?>">
            <?php foreach (BCRF_EVENT_ANCHORS as $k => $v): if (!$isBooking && in_array($k, ['start', 'end'], true)) continue; ?>
            <option value="<?= $k ?>"<?= ($d['anchor'] ?? 'time') === $k ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select></div>
        <div data-anchor="time"><label for="e_time" class="<?= $label ?>">Time <span class="font-normal text-stone-400">optional</span></label><input id="e_time" name="at_time" type="time" value="<?= $h($d['at_time'] ?? '') ?>" class="<?= $input ?>"></div>
        <?php if ($isBooking): ?>
        <div data-anchor="start end"><label for="e_min" class="<?= $label ?>">Minutes <span class="font-normal text-stone-400">before (−) or after</span></label><input id="e_min" name="minute_offset" type="number" min="-720" max="720" value="<?= (int)($d['minute_offset'] ?? 0) ?>" class="<?= $input ?>"></div>
        <?php endif; ?>
      </div>
      <p class="text-xs text-stone-500">If the day has already passed when the <?= $isBooking ? 'booking' : 'party' ?> is confirmed, the task is due right away.</p>
    </fieldset>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="e_who" class="<?= $label ?>">Who</label><select id="e_who" name="who" class="<?= $input ?>"><?= $whoOptions((string)($d['who'] ?? 'anyone')) ?></select></div>
      <div><label for="e_prio" class="<?= $label ?>">Priority</label><select id="e_prio" name="priority" class="<?= $input ?>"><?= $prioOptions((string)($d['priority'] ?? 'normal')) ?></select></div>
    </div>
    <div><label for="e_list" class="<?= $label ?>">Checklist <span class="font-normal text-stone-400">one step per line</span></label>
      <textarea id="e_list" name="checklist" rows="6" class="<?= $input ?>"><?= $h(is_array($d['checklist'] ?? '') ? implode("\n", $d['checklist']) : ($d['checklist'] ?? '')) ?></textarea></div>
    <div><label for="e_desc" class="<?= $label ?>">Notes <span class="font-normal text-stone-400">optional, shown above the event details</span></label>
      <textarea id="e_desc" name="description" rows="2" class="<?= $input ?>"><?= $h($d['description'] ?? '') ?></textarea></div>
    <?= $extras($d, 'e') ?>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="e_sort" class="<?= $label ?>">Order <span class="font-normal text-stone-400">within the same day</span></label><input id="e_sort" name="sort_order" type="number" min="0" max="99" value="<?= (int)($d['sort_order'] ?? 0) ?>" class="<?= $input ?> w-24"></div>
    </div>
    <input type="hidden" name="active" value="<?= !empty($d['active']) ? '1' : '' ?>">
    <button type="submit" class="<?= $btnMain ?>">Save step</button>
  </form>
  <?php if ($r): ?>
  <form method="POST" action="tasks.php" class="mt-3"><?= $hidden('step_active', (int)$r['id']) ?><input type="hidden" name="on" value="<?= $r['active'] ? '' : '1' ?>">
    <button type="submit" class="<?= $btnSec ?>"><?= $r['active'] ? 'Pause this step' : 'Restart this step' ?></button>
    <span class="text-xs text-stone-500 ml-2"><?= $r['active'] ? 'Upcoming tasks nobody started are removed; history stays.' : 'Adds it back to upcoming events.' ?></span></form>
  <?php endif;
        bcrf_shell_close();
        exit;
    }
    $all = bcrf_event_steps($pdo);
    $by = []; foreach ($all as $st) $by[$st['plan']][] = $st;
    $upcoming = bcrf_event_list($pdo, $today);
    usort($upcoming, fn($a, $b) => strcmp($a['date'] . ($a['start'] ?? ''), $b['date'] . ($b['start'] ?? '')));
    $open('Event checklists', 'events', 'max-w-3xl');
    ?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Event checklists</h1>
  <p class="text-sm text-stone-500 mb-4">When a party or a booking is confirmed, these steps become tasks dated around it. If it's declined or cancelled, the tasks nobody finished are cancelled too.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <?php if ($upcoming): ?>
  <section class="mb-6">
    <h2 class="text-xs font-black uppercase tracking-widest text-stone-500 mb-2">Coming up · <?= count($upcoming) ?></h2>
    <div class="space-y-2">
      <?php foreach (array_slice($upcoming, 0, 12) as $ev): $cnt = bcrf_event_task_counts($pdo, $ev['type'], $ev['id']); ?>
      <a href="tasks.php?tab=all&amp;status=all&amp;related=<?= $h($ev['type'] . ':' . $ev['id']) ?>" class="<?= $card ?> flex items-center gap-3 p-3 hover:border-stone-400">
        <span class="flex-1 min-w-0"><span class="block text-sm font-bold text-stone-900"><?= $h($ev['name']) ?> <span class="font-normal text-stone-500">· <?= $h($plans[$ev['plan']] ?? $ev['plan']) ?></span></span>
          <span class="block text-xs text-stone-500"><?= $h($dayLabel($ev['date'])) ?><?= $ev['start'] ? ' · ' . $h(bcrf_tasks_fmt_time($ev['start'])) : '' ?> · <?= (int)$ev['guests'] ?> guests</span></span>
        <span class="shrink-0 text-xs font-bold <?= $cnt['total'] && $cnt['done'] === $cnt['total'] ? 'text-emerald-700' : 'text-stone-500' ?>"><?= $cnt['total'] ? $cnt['done'] . '/' . $cnt['total'] . ' done' : 'no steps' ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php foreach ($plans as $pk => $pl): $steps = $by[$pk] ?? []; ?>
  <section class="mb-5" id="plan-<?= $h($pk) ?>">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
      <h2 class="text-lg font-semibold text-stone-800"><?= $h($pl) ?></h2>
      <a href="tasks.php?tab=events&amp;step=new&amp;plan=<?= $h(rawurlencode($pk)) ?>" class="<?= $btnSec ?>">+ Step</a>
    </div>
    <div class="space-y-2">
      <?php foreach ($steps as $st): ?>
      <a href="tasks.php?tab=events&amp;step=<?= (int)$st['id'] ?>" class="<?= $card ?> block p-3 hover:border-stone-400<?= $st['active'] ? '' : ' opacity-60' ?>">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-sm font-bold text-stone-900"><?= $h($st['title']) ?></span>
          <?php if (!$st['active']): ?><span class="text-[10px] font-black uppercase px-1.5 py-0.5 rounded bg-stone-100 text-stone-500">Paused</span><?php endif; ?>
          <span class="ml-auto text-xs text-stone-500"><?= count($st['lines']) ?> step<?= count($st['lines']) === 1 ? '' : 's' ?><?= $st['form'] ? ' · ' . count($st['form']) . ' question' . (count($st['form']) === 1 ? '' : 's') : '' ?><?= $st['photo_rule'] === 'required' ? ' · 📷' : '' ?></span>
        </div>
        <div class="text-xs text-stone-500 mt-0.5"><?= $h(bcrf_event_step_when($st)) ?> · <?= $h(bcrf_tasks_who($st)) ?></div>
      </a>
      <?php endforeach; ?>
      <?php if (!$steps): ?><p class="text-sm text-stone-500">No steps<?= str_contains(strtolower($pl), 'admission') ? ' (day tickets don\'t need any)' : '' ?>.</p><?php endif; ?>
    </div>
  </section>
  <?php endforeach;
    bcrf_shell_close();
    exit;
}

// ── SAVED CHECKLISTS ──
if ($tab === 'templates') {
    if (isset($_GET['tpl_edit'])) {
        $r = null;
        if ($_GET['tpl_edit'] !== 'new') { try { $r = bcrf_tpl_get($pdo, (int)$_GET['tpl_edit']); } catch (BcrfTaskError $e) { $notFound('Checklist'); } }
        $d = $typed ?: ($r ? ['title' => $r['title'], 'description' => $r['description'], 'checklist' => implode("\n", $r['lines']), 'priority' => $r['priority'], 'who' => bcrf_tasks_who_value($r), 'active' => $r['active'],
                              'questions' => bcrf_task_form_text(json_decode((string)($r['form_json'] ?? ''), true) ?: []), 'photo_rule' => $r['photo_rule'] ?? 'none', 'followup_unticked' => $r['followup_unticked'] ?? 0]
                           : ['who' => 'anyone', 'priority' => 'normal', 'active' => 1]);
        $open($r ? 'Change checklist' : 'New checklist', 'templates', 'max-w-2xl');
        ?>
  <a href="tasks.php?tab=templates" class="inline-flex items-center min-h-10 text-sm text-stone-500 hover:text-stone-800 mb-1">← Checklists</a>
  <h1 class="text-2xl font-bold text-stone-800 mb-1"><?= $r ? 'Change checklist' : 'New checklist' ?></h1>
  <p class="text-sm text-stone-500 mb-3">A saved checklist for jobs that come up now and then (a vet visit, a birthday party setup). Pick it when adding a task.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <form method="POST" action="tasks.php" class="<?= $card ?> p-4 space-y-3">
    <?= $hidden('tpl_save', $r ? (int)$r['id'] : 0) ?>
    <div><label for="p_title" class="<?= $label ?>">Name</label><input id="p_title" name="title" maxlength="150" required value="<?= $h($d['title'] ?? '') ?>" class="<?= $input ?>"></div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="p_who" class="<?= $label ?>">Usually for</label><select id="p_who" name="who" class="<?= $input ?>"><?= $whoOptions((string)($d['who'] ?? 'anyone')) ?></select></div>
      <div><label for="p_prio" class="<?= $label ?>">Priority</label><select id="p_prio" name="priority" class="<?= $input ?>"><?= $prioOptions((string)($d['priority'] ?? 'normal')) ?></select></div>
    </div>
    <div><label for="p_list" class="<?= $label ?>">Checklist <span class="font-normal text-stone-400">one step per line</span></label>
      <textarea id="p_list" name="checklist" rows="9" class="<?= $input ?>"><?= $h(is_array($d['checklist'] ?? '') ? implode("\n", $d['checklist']) : ($d['checklist'] ?? '')) ?></textarea></div>
    <div><label for="p_desc" class="<?= $label ?>">Notes <span class="font-normal text-stone-400">optional</span></label><textarea id="p_desc" name="description" rows="2" class="<?= $input ?>"><?= $h($d['description'] ?? '') ?></textarea></div>
    <?= $extras($d, 'p') ?>
    <?php if ($r): ?><label class="flex items-center gap-2 text-sm text-stone-700 min-h-11"><input type="checkbox" name="active" value="1"<?= !empty($d['active']) ? ' checked' : '' ?>> Offer it when adding a task</label><?php endif; ?>
    <button type="submit" class="<?= $btnMain ?>">Save checklist</button>
  </form>
        <?php
        bcrf_shell_close();
        exit;
    }
    $tpls = bcrf_tpl_list($pdo);
    $open('Checklists', 'templates');
    ?>
  <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
    <h1 class="text-2xl font-bold text-stone-800">Saved checklists</h1>
    <a href="tasks.php?tab=templates&amp;tpl_edit=new" class="<?= $btnMain ?>">+ New checklist</a>
  </div>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <div class="space-y-2">
    <?php foreach ($tpls as $r): ?>
    <div class="<?= $card ?> p-3 flex flex-wrap items-center gap-2<?= $r['active'] ? '' : ' opacity-60' ?>">
      <div class="flex-1 min-w-0">
        <div class="text-sm font-bold text-stone-900"><?= $h($r['title']) ?><?= $r['active'] ? '' : ' <span class="text-[10px] font-black uppercase px-1.5 py-0.5 rounded bg-stone-100 text-stone-500">Hidden</span>' ?></div>
        <div class="text-xs text-stone-500"><?= count($r['lines']) ?> steps · <?= $h(bcrf_tasks_who($r)) ?></div>
      </div>
      <a href="tasks.php?new=1&amp;tpl=<?= (int)$r['id'] ?>" class="<?= $btnSec ?>">Use</a>
      <a href="tasks.php?tab=templates&amp;tpl_edit=<?= (int)$r['id'] ?>" class="<?= $btnSec ?>">Change</a>
    </div>
    <?php endforeach; ?>
    <?php if (!$tpls): ?><p class="<?= $card ?> p-4 text-sm text-stone-500">None yet. Save one for jobs that come up now and then, like getting ready for the vet or setting up a party.</p><?php endif; ?>
  </div>
    <?php
    bcrf_shell_close();
    exit;
}

// ── EVERYONE'S TASKS ──
if ($tab === 'all') {
    $f = ['status' => (string)($_GET['status'] ?? 'open'), 'who' => (string)($_GET['who'] ?? ''), 'from' => (string)($_GET['from'] ?? ''),
          'to' => (string)($_GET['to'] ?? ''), 'q' => (string)($_GET['q'] ?? ''), 'related' => (string)($_GET['related'] ?? '')];
    if (!preg_match('/^(booking|event_inquiry):\d+$/', $f['related'])) $f['related'] = '';
    foreach (['from', 'to'] as $k) if ($f[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';
    if ($f['status'] === 'open' && $f['to'] === '' && !isset($_GET['to']) && $f['related'] === '') $f['to'] = bcrf_tasks_add_days($today, 7);
    $list = bcrf_tasks_list($pdo, $f);
    $open('Everyone', 'all', 'max-w-4xl');
    ?>
  <h1 class="text-2xl font-bold text-stone-800 mb-3">Everyone's tasks</h1>
  <?php if ($f['related'] !== ''): [$rt, $rid] = explode(':', $f['related']); ?>
  <p class="text-sm text-stone-600 mb-3">Checklist for <?= $rt === 'booking' ? 'booking' : 'party' ?> #<?= (int)$rid ?> · <a class="underline" href="tasks.php?tab=all">show everything</a></p>
  <?php endif; ?>
  <form method="GET" action="tasks.php" class="<?= $card ?> p-3 mb-3 grid gap-2 sm:grid-cols-6 items-end">
    <input type="hidden" name="tab" value="all"><?php if ($f['related'] !== ''): ?><input type="hidden" name="related" value="<?= $h($f['related']) ?>"><?php endif; ?>
    <div class="sm:col-span-2"><label for="a_q" class="<?= $label ?>">Search</label><input id="a_q" name="q" value="<?= $h($f['q']) ?>" class="<?= $input ?>"></div>
    <div><label for="a_s" class="<?= $label ?>">Status</label><select id="a_s" name="status" class="<?= $input ?>">
      <?php foreach (['open' => 'Still to do', 'closed' => 'Finished', 'all' => 'All'] + BCRF_TASK_STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select></div>
    <div><label for="a_w" class="<?= $label ?>">Who</label><select id="a_w" name="who" class="<?= $input ?>"><option value="">Everyone</option><?= $whoOptions($f['who']) ?></select></div>
    <div><label for="a_f" class="<?= $label ?>">Due from</label><input id="a_f" type="date" name="from" value="<?= $h($f['from']) ?>" class="<?= $input ?>"></div>
    <div><label for="a_t" class="<?= $label ?>">to</label><input id="a_t" type="date" name="to" value="<?= $h($f['to']) ?>" class="<?= $input ?>"></div>
    <div class="sm:col-span-6"><button type="submit" class="<?= $btnSec ?>">Show</button></div>
  </form>
  <div class="space-y-2">
    <?php foreach ($list as $t) echo $row($t, true); ?>
    <?php if (!$list): ?><p class="<?= $card ?> p-4 text-sm text-stone-500">Nothing matches.</p><?php endif; ?>
  </div>
  <?php if (count($list) >= 300): ?><p class="text-xs text-stone-500 mt-2">Showing the first 300. Narrow the dates to see more.</p><?php endif; ?>
    <?php
    bcrf_shell_close();
    exit;
}

// ── MY TASKS ──
$mine = bcrf_tasks_mine($pdo);
$open('My tasks', 'my');
$nOpen = count($mine['overdue']) + count($mine['today']);
?>
  <div class="flex flex-wrap items-end justify-between gap-2 mb-3">
    <div>
      <h1 class="text-2xl font-bold text-stone-800">My tasks</h1>
      <div class="text-sm text-stone-500"><?= $h(date('l, F j', strtotime($today))) ?> · <?= $nOpen ? $nOpen . ' to do today' . ($mine['overdue'] ? ' (' . count($mine['overdue']) . ' late)' : '') : 'all caught up' ?></div>
    </div>
    <a href="tasks.php?new=1" class="<?= $btnSec ?>">+ Task with details</a>
  </div>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <form method="POST" action="tasks.php" class="<?= $card ?> p-3 mb-4 flex flex-wrap gap-2 items-end">
    <?= $hidden('add') ?><input type="hidden" name="quick" value="1"><input type="hidden" name="back" value="tasks.php">
    <div class="flex-1 min-w-48"><label for="q_title" class="sr-only">Quick add</label>
      <input id="q_title" name="title" maxlength="150" required placeholder="Add a task…" class="<?= $input ?>"></div>
    <div><label for="q_date" class="sr-only">Due</label><input id="q_date" name="due_date" type="date" value="<?= $h($today) ?>" class="<?= $input ?>"></div>
    <div><label for="q_who" class="sr-only">Who</label>
      <select id="q_who" name="who" class="<?= $input ?>"><option value="anyone">Anyone</option><?php if (bcrf_tasks_uid()): ?><option value="user:<?= (int)bcrf_tasks_uid() ?>">Me</option><?php endif; ?></select></div>
    <button type="submit" class="<?= $btnMain ?>">Add</button>
  </form>

  <?php
  $section = function (string $title, array $items, string $tone = 'stone', bool $showDate = false, string $empty = '') use ($row, $h) {
      if (!$items && $empty === '') return;
      echo '<section class="mb-5"><h2 class="text-xs font-black uppercase tracking-widest mb-2 ', $tone === 'red' ? 'text-red-700' : 'text-stone-500', '">', $h($title),
           $items ? ' <span class="font-bold">· ' . count($items) . '</span>' : '', '</h2><div class="space-y-2">';
      foreach ($items as $t) echo $row($t, $showDate);
      if (!$items) echo '<p class="text-sm text-stone-500">', $h($empty), '</p>';
      echo '</div></section>';
  };
  $section('Late', $mine['overdue'], 'red', true);
  $section('Today', $mine['today'], 'stone', false, $mine['overdue'] ? 'Nothing else due today.' : 'Nothing due today. 🐐');
  $tmr = bcrf_tasks_add_days($today, 1);
  $section('Tomorrow', array_values(array_filter($mine['upcoming'], fn($t) => $t['due_date'] === $tmr)));
  $later = array_values(array_filter($mine['upcoming'], fn($t) => $t['due_date'] !== $tmr));
  if ($later) {
      echo '<details class="mb-5"><summary class="cursor-pointer min-h-11 flex items-center text-xs font-black uppercase tracking-widest text-stone-500">Later this week · ', count($later), '</summary><div class="space-y-2 mt-2">';
      foreach ($later as $t) echo $row($t, true);
      echo '</div></details>';
  }
  $section('No date', $mine['someday']);
  $section('Done today', $mine['done']);
  if ($v2 && bcrf_tasks_uid()):
      $pf = bcrf_task_prefs($pdo, (int)bcrf_tasks_uid());
  ?>
  <details class="<?= $card ?> p-3 mt-6 text-sm" id="email-me">
    <summary class="cursor-pointer min-h-10 flex items-center font-semibold text-stone-700">✉ Email me</summary>
    <form method="POST" action="tasks.php" class="space-y-2 mt-2">
      <?= $hidden('prefs') ?>
      <label class="flex items-start gap-2 min-h-11"><input type="checkbox" name="digest" value="1" class="mt-1"<?= $pf['digest'] ? ' checked' : '' ?>>
        <span><b>Morning list</b>, 6:30 AM: my late tasks and today's. No email on days with nothing due.</span></label>
      <label class="flex items-start gap-2 min-h-11"><input type="checkbox" name="late_alerts" value="1" class="mt-1"<?= $pf['late_alerts'] ? ' checked' : '' ?>>
        <span><b>Late tasks</b>: when a task with a due time is <?= (int)bcrf_task_setting_int($pdo, 'task_late_after_min', 30) ?> minutes late<?= $mgr ? ', and (as a manager) when anyone\'s is ' . (int)bcrf_task_setting_int($pdo, 'task_escalate_after_min', 120) . ' minutes late' : '' ?>. Only 7 AM to 9 PM.</span></label>
      <button type="submit" class="<?= $btnSec ?>">Save</button>
    </form>
  </details>
  <?php
  endif;
  bcrf_shell_close();
