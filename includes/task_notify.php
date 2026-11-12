<?php
// includes/task_notify.php — Farm OS Phase 3 slice 2 (Nov 11, 2026; 12 › Notifications, Recurring tasks): task emails.
//
//   Morning list   bcrf_task_digest_run(): one email per person with their late tasks and today's, at 6:30 AM farm time
//                  (admin/cron/task_digest.php). Nobody gets an empty email. Once per farm day per person.
//   Late tasks     bcrf_task_alerts_run(): every 15 minutes (admin/cron/task_alerts.php). A task with a due time that
//                  is still open task_late_after_min (30) minutes later emails whoever it is for; still open after
//                  task_escalate_after_min (120) it emails the managers. Each goes out once per task, only between
//                  7 AM and 9 PM farm time, and only for tasks due today or yesterday (no flood of old ones).
//                  Tasks with a date but no time are covered by the morning list and reach the managers the next morning.
//   Who            "for a person" = that person; "a group" = everyone active in that role; "anyone" = all staff who can
//                  open Tasks. Managers = Owners plus anyone with the Tasks › manage permission.
//   Opt out        task_user_prefs (My tasks › Email me): morning list and late emails, each on by default.
// Uses includes/tasks.php and bcf_send_mail() (admin/includes/email_template.php).
if (!function_exists('bcrf_task_digest_run')) {

require_once __DIR__ . '/tasks.php';

function bcrf_task_setting_int(PDO $pdo, string $key, int $default): int {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v !== false && is_numeric($v)) ? max(0, (int)$v) : $default;
    } catch (Throwable $e) { return $default; }
}

/** Can this role open a view? Same rule as hasViewAccess() (Owners always), without needing the console loaded. */
function bcrf_task_role_can(PDO $pdo, string $role, string $view): bool {
    if ($role === 'administrator') return true;
    if ($role === '' || $role === 'user') return false;
    static $cache = [];
    $k = $role . '|' . $view;
    if (!isset($cache[$k]) || !empty($GLOBALS['bcrf_tasks_nocache'])) {
        try {
            $st = $pdo->prepare("SELECT can_access FROM view_permissions WHERE role = ? AND view_name = ?");
            $st->execute([$role, $view]);
            $cache[$k] = (int)$st->fetchColumn() === 1;
        } catch (Throwable $e) { $cache[$k] = false; }
    }
    return $cache[$k];
}

/** Active staff with an email who can open Tasks: [id => [id, name, first_name, email, role]]. */
function bcrf_task_staff(PDO $pdo): array {
    $st = $pdo->prepare("SELECT id, first_name, CONCAT(first_name, ' ', last_name) AS name, email, role FROM users
                         WHERE role <> 'user' AND account_status = 'active' AND (date_terminated IS NULL OR date_terminated > ?)
                           AND email IS NOT NULL AND email <> '' ORDER BY id");
    $st->execute([bcrf_tasks_today()]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (bcrf_task_role_can($pdo, (string)$u['role'], 'tasks') && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) $out[(int)$u['id']] = $u;
    }
    return $out;
}

/** ['digest' => 1, 'late_alerts' => 1, 'last_digest_date' => ?] — on by default. */
function bcrf_task_prefs(PDO $pdo, int $uid): array {
    $d = ['digest' => 1, 'late_alerts' => 1, 'last_digest_date' => null];
    try {
        $st = $pdo->prepare("SELECT digest, late_alerts, last_digest_date FROM task_user_prefs WHERE user_id = ?");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $d = ['digest' => (int)$r['digest'], 'late_alerts' => (int)$r['late_alerts'], 'last_digest_date' => $r['last_digest_date']];
    } catch (Throwable $e) { /* table missing: defaults */ }
    return $d;
}

function bcrf_task_prefs_save(PDO $pdo, int $uid, bool $digest, bool $late): void {
    $pdo->prepare("INSERT INTO task_user_prefs (user_id, digest, late_alerts, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE digest = VALUES(digest), late_alerts = VALUES(late_alerts), updated_at = UTC_TIMESTAMP()")
        ->execute([$uid, $digest ? 1 : 0, $late ? 1 : 0]);
}

/** Open tasks for one person (theirs, their role's, anyone's) due on or before $through, oldest first. */
function bcrf_task_open_for(PDO $pdo, array $u, string $through): array {
    $st = $pdo->prepare(bcrf_tasks_select() . " WHERE (t.assignee_user_id = ? OR (t.assignee_user_id IS NULL AND (t.assignee_role IS NULL OR t.assignee_role = ?)))
                           AND t.status IN ('open', 'in_progress') AND t.due_date IS NOT NULL AND t.due_date <= ?
                         ORDER BY t.due_date, t.due_time IS NULL, t.due_time, FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), t.id LIMIT 100");
    $st->execute([(int)$u['id'], (string)$u['role'], $through]);
    return array_map('bcrf_tasks_decode', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Who a task is for, as staff rows (see the header). */
function bcrf_task_recipients(PDO $pdo, array $t, array $staff): array {
    if (!empty($t['assignee_user_id'])) return isset($staff[(int)$t['assignee_user_id']]) ? [(int)$t['assignee_user_id'] => $staff[(int)$t['assignee_user_id']]] : [];
    if (!empty($t['assignee_role'])) return array_filter($staff, fn($u) => $u['role'] === $t['assignee_role']);
    return $staff;
}

function bcrf_task_managers(PDO $pdo, array $staff): array {
    return array_filter($staff, fn($u) => bcrf_task_role_can($pdo, (string)$u['role'], 'tasks_manage'));
}

function bcrf_task_link(int $id = 0): string {
    $base = function_exists('bcrf_farm_url') ? bcrf_farm_url() : '';
    return $base . '/admin/tasks.php' . ($id ? '?id=' . $id : '');
}

/** One task as an email list line. */
function bcrf_task_email_line(array $t, bool $withDate): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $when = [];
    if ($withDate && !empty($t['due_date'])) $when[] = date('D, M j', strtotime((string)$t['due_date']));
    if (!empty($t['due_time'])) $when[] = bcrf_tasks_fmt_time($t['due_time']);
    $extra = [];
    if (!empty($t['checklist'])) $extra[] = count($t['checklist']) . ' steps';
    if (($t['photo_rule'] ?? 'none') === 'required') $extra[] = 'photo needed';
    if (in_array($t['priority'], ['urgent', 'high'], true)) $extra[] = strtolower(BCRF_TASK_PRIORITIES[$t['priority']]);
    return '<li style="margin:0 0 10px;"><a href="' . $h(bcrf_task_link((int)$t['id'])) . '" style="color:#7c2d12;font-weight:bold;text-decoration:none;">' . $h($t['title']) . '</a>'
         . ($when ? ' <span style="color:#57534e;">· ' . $h(implode(' ', $when)) . '</span>' : '')
         . '<br><span style="color:#78716c;font-size:13px;">' . $h(bcrf_tasks_who($t)) . ($extra ? ' · ' . $h(implode(', ', $extra)) : '') . '</span></li>';
}

function bcrf_task_send(string $to, string $subject, string $html): bool {
    if (!function_exists('bcf_send_mail')) {
        $f = dirname(__DIR__) . '/admin/includes/email_template.php';
        if (is_file($f)) require_once $f;
    }
    if (!function_exists('bcf_send_mail')) { error_log('[task_notify] bcf_send_mail missing'); return false; }
    return bcf_send_mail($to, $subject, $html, ['subtitle' => 'Farm tasks', 'unsubscribe' => false]);
}

/** "now" on the farm (tests can pin it with $GLOBALS['bcrf_tasks_now'] = 'Y-m-d H:i:s' farm time). */
function bcrf_task_now(): DateTimeImmutable {
    if (!empty($GLOBALS['bcrf_tasks_now'])) return new DateTimeImmutable((string)$GLOBALS['bcrf_tasks_now'], bcrf_tasks_tz());
    return new DateTimeImmutable('now', bcrf_tasks_tz());
}

// ── Morning list ─────────────────────────────────────────────────────────────────────────────────

/** Send the morning list to everyone who has something due today or late. Returns [sent, skipped_empty, failed]. */
function bcrf_task_digest_run(PDO $pdo): array {
    $res = ['sent' => 0, 'empty' => 0, 'failed' => 0];
    if (!bcrf_tasks_ready($pdo) || !bcrf_tasks_v2($pdo)) return $res;
    $today = bcrf_tasks_today();
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $mark = $pdo->prepare("INSERT INTO task_user_prefs (user_id, last_digest_date, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
                           ON DUPLICATE KEY UPDATE last_digest_date = VALUES(last_digest_date)");
    foreach (bcrf_task_staff($pdo) as $u) {
        $p = bcrf_task_prefs($pdo, (int)$u['id']);
        if (!$p['digest'] || (string)$p['last_digest_date'] === $today) continue;
        $list = bcrf_task_open_for($pdo, $u, $today);
        $late = array_values(array_filter($list, fn($t) => $t['due_date'] < $today));
        $now  = array_values(array_filter($list, fn($t) => $t['due_date'] === $today));
        if (!$list) { $res['empty']++; continue; }
        $html = '<p style="margin:0 0 14px;">Good morning' . ($u['first_name'] ? ', ' . $h($u['first_name']) : '') . '. Here is what is on the list for '
              . $h(date('l, F j', strtotime($today))) . '.</p>';
        if ($late) $html .= '<h3 style="margin:16px 0 8px;color:#b91c1c;font-size:15px;">Late · ' . count($late) . '</h3><ul style="padding-left:18px;margin:0;">'
                          . implode('', array_map(fn($t) => bcrf_task_email_line($t, true), $late)) . '</ul>';
        if ($now)  $html .= '<h3 style="margin:16px 0 8px;font-size:15px;">Today · ' . count($now) . '</h3><ul style="padding-left:18px;margin:0;">'
                          . implode('', array_map(fn($t) => bcrf_task_email_line($t, false), $now)) . '</ul>';
        $html .= '<p style="margin:18px 0 0;"><a href="' . $h(bcrf_task_link()) . '" style="color:#7c2d12;font-weight:bold;">Open My tasks</a></p>'
               . '<p style="margin:14px 0 0;color:#a8a29e;font-size:12px;">Turn this email off on My tasks › Email me.</p>';
        $subject = 'Today: ' . count($list) . ' farm task' . (count($list) === 1 ? '' : 's') . ($late ? ' (' . count($late) . ' late)' : '');
        if (bcrf_task_send((string)$u['email'], $subject, $html)) { $mark->execute([(int)$u['id'], $today]); $res['sent']++; }
        else { $res['failed']++; error_log('[task_notify] morning list to user ' . (int)$u['id'] . ' failed: ' . ($GLOBALS['bcf_mail_last_error'] ?? '')); }
    }
    return $res;
}

// ── Late tasks ───────────────────────────────────────────────────────────────────────────────────

/** When a task counts as late: its due date and time on the farm (no time = the end of that day). */
function bcrf_task_deadline(array $t): ?DateTimeImmutable {
    if (empty($t['due_date'])) return null;
    return new DateTimeImmutable($t['due_date'] . ' ' . ($t['due_time'] ?: '23:59:59'), bcrf_tasks_tz());
}

/** Email late tasks to whoever they are for, then to the managers. Returns ['late' => tasks, 'escalated' => tasks, 'emails' => n]. */
function bcrf_task_alerts_run(PDO $pdo): array {
    $res = ['late' => 0, 'escalated' => 0, 'emails' => 0];
    if (!bcrf_tasks_ready($pdo) || !bcrf_tasks_v2($pdo)) return $res;
    $now = bcrf_task_now();
    $hour = (int)$now->format('G');
    if ($hour < 7 || $hour >= 21) return $res;   // quiet hours: wait for the morning
    $lateMin = bcrf_task_setting_int($pdo, 'task_late_after_min', 30);
    $escMin  = bcrf_task_setting_int($pdo, 'task_escalate_after_min', 120);
    $today = bcrf_tasks_today();
    $st = $pdo->prepare(bcrf_tasks_select() . " WHERE t.status IN ('open', 'in_progress') AND t.due_date BETWEEN ? AND ?
                           AND (t.late_notified_at IS NULL OR t.escalated_at IS NULL) ORDER BY t.due_date, t.due_time, t.id LIMIT 300");
    $st->execute([bcrf_tasks_add_days($today, -1), $today]);
    $tasks = array_map('bcrf_tasks_decode', $st->fetchAll(PDO::FETCH_ASSOC));
    if (!$tasks) return $res;

    $staff = bcrf_task_staff($pdo);
    $wants = fn(array $u) => bcrf_task_prefs($pdo, (int)$u['id'])['late_alerts'] === 1;
    $claimLate = $pdo->prepare("UPDATE tasks SET late_notified_at = UTC_TIMESTAMP() WHERE id = ? AND late_notified_at IS NULL");
    $claimEsc  = $pdo->prepare("UPDATE tasks SET escalated_at = UTC_TIMESTAMP() WHERE id = ? AND escalated_at IS NULL");
    $toPerson = []; $toMgr = [];
    foreach ($tasks as $t) {
        $dl = bcrf_task_deadline($t);
        if (!$dl) continue;
        $past = intdiv($now->getTimestamp() - $dl->getTimestamp(), 60);
        if ($past < 0) continue;
        // First nudge: tasks with a due time only (a date-only task is in the morning list). Claimed before sending,
        // so a second run at the same moment can't send it again.
        if (empty($t['late_notified_at']) && !empty($t['due_time']) && $past >= $lateMin && $past < 24 * 60) {
            $claimLate->execute([(int)$t['id']]);
            if ($claimLate->rowCount()) {
                $res['late']++;
                foreach (bcrf_task_recipients($pdo, $t, $staff) as $uid => $u) if ($wants($u)) $toPerson[$uid][] = $t;
            }
        }
        if (empty($t['escalated_at']) && $past >= $escMin && $past < 24 * 60 + $escMin) {
            $claimEsc->execute([(int)$t['id']]);
            if ($claimEsc->rowCount()) {
                $res['escalated']++;
                foreach (bcrf_task_managers($pdo, $staff) as $uid => $u) if ($wants($u)) $toMgr[$uid][] = $t;
            }
        }
    }
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $send = function (array $u, array $list, bool $mgr) use ($h, $today, &$res) {
        $n = count($list);
        $html = '<p style="margin:0 0 14px;">' . ($mgr
                ? ($n === 1 ? 'This task is still not done' : "These $n tasks are still not done") . ', well past when they were due:'
                : ($n === 1 ? 'This task is past its due time and still open:' : "These $n tasks are past their due time and still open:")) . '</p>'
              . '<ul style="padding-left:18px;margin:0;">' . implode('', array_map(fn($t) => bcrf_task_email_line($t, $t['due_date'] !== $today), $list)) . '</ul>'
              . '<p style="margin:16px 0 0;color:#57534e;font-size:13px;">If it was done, tick it off so nobody is chased again. If it can\'t happen, skip or cancel it with a note.</p>'
              . '<p style="margin:14px 0 0;color:#a8a29e;font-size:12px;">Turn late-task emails off on My tasks › Email me.</p>';
        $subject = ($mgr ? 'Still not done: ' : 'Late: ') . ($n === 1 ? $list[0]['title'] : "$n farm tasks");
        if (bcrf_task_send((string)$u['email'], mb_substr($subject, 0, 120), $html)) $res['emails']++;
        else error_log('[task_notify] late email to user ' . (int)$u['id'] . ' failed: ' . ($GLOBALS['bcf_mail_last_error'] ?? ''));
    };
    foreach ($toPerson as $uid => $list) $send($staff[$uid], $list, false);
    foreach ($toMgr as $uid => $list) $send($staff[$uid], $list, true);
    return $res;
}

}
