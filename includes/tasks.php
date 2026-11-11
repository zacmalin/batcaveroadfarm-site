<?php
// includes/tasks.php — Farm OS Phase 3 slice 1: tasks and chores (Nov 11, 2026; 04 › Tasks; migration 0029)
//
//   Task      one job: title, notes, checklist, priority, due date/time (farm time), who (a person, a role, or
//             anyone on the farm), optional link to a record, status open → in_progress → done | skipped | cancelled.
//   Chore     a task_recurrences row. Each day it falls on gets its own task (unique per chore and day), created up
//             to horizon_days ahead by bcrf_tasks_generate() — nightly from the runner, and on page loads as a backstop.
//             Finishing or skipping one day never changes the chore. Editing a chore rebuilds only days nobody touched.
//   Template  a reusable checklist for one-off tasks.
//
// Screens: admin/tasks.php. Nightly: admin/cron/generate_tasks.php. Dashboard: bcrf_tasks_counts().
if (!function_exists('bcrf_tasks_ready')) {

class BcrfTaskError extends RuntimeException {}

define('BCRF_TASK_PRIORITIES', ['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low']);
define('BCRF_TASK_STATUSES', ['open' => 'To do', 'in_progress' => 'Started', 'done' => 'Done', 'skipped' => 'Skipped', 'cancelled' => 'Cancelled']);
define('BCRF_TASK_ROLES', ['Staff' => 'Farm staff', 'Shopkeep' => 'Shopkeepers', 'moderator' => 'Managers', 'administrator' => 'Owners']);
define('BCRF_TASK_FREQS', ['daily' => 'Every day', 'every_n' => 'Every few days', 'weekly' => 'Certain weekdays', 'monthly' => 'Once a month']);

function bcrf_tasks_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM tasks LIMIT 0"); $pdo->query("SELECT 1 FROM task_recurrences LIMIT 0"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}

function bcrf_tasks_tz(): DateTimeZone {
    try { return new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York'); }
    catch (Throwable $e) { return new DateTimeZone('America/New_York'); }
}

/** Today on the farm, Y-m-d. Tests can pin it with $GLOBALS['bcrf_tasks_today']. */
function bcrf_tasks_today(): string {
    if (!empty($GLOBALS['bcrf_tasks_today'])) return (string)$GLOBALS['bcrf_tasks_today'];
    return (new DateTimeImmutable('now', bcrf_tasks_tz()))->format('Y-m-d');
}

function bcrf_tasks_add_days(string $date, int $n): string {
    return (new DateTimeImmutable($date))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

/** Owner or Manager: edit chores and checklists, see and change everyone's tasks. */
function bcrf_tasks_is_manager(): bool {
    $r = (string)($_SESSION['user_role'] ?? '');
    if ($r === 'administrator') return true;
    return function_exists('hasViewAccess') ? hasViewAccess($r, 'tasks_manage') : $r === 'moderator';
}

function bcrf_tasks_uid(): ?int { $u = (int)($_SESSION['user_id'] ?? 0); return $u ?: null; }

// ── Farm days ────────────────────────────────────────────────────────────────────────────────────

/** [0..6 => [open 'HH:MM:SS', close] or null when closed], from Settings › Hours (general). Cached per request. */
function bcrf_tasks_week(PDO $pdo): array {
    static $cache = [];
    $k = spl_object_id($pdo);
    if (isset($cache[$k]) && empty($GLOBALS['bcrf_tasks_nocache'])) return $cache[$k];
    $w = array_fill(0, 7, null);
    try {
        $rows = $pdo->query("SELECT day_of_week d, open_time o, close_time c, is_closed x FROM site_hours WHERE scope = 'general' ORDER BY day_of_week, block_order")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if ((int)$r['x'] === 1 || empty($r['o']) || empty($r['c'])) continue;
            $d = (int)$r['d'];
            if ($d < 0 || $d > 6) continue;
            $w[$d] = $w[$d] ? [min($w[$d][0], $r['o']), max($w[$d][1], $r['c'])] : [(string)$r['o'], (string)$r['c']];
        }
    } catch (Throwable $e) { /* no hours table: every day closed for open-days-only chores */ }
    return $cache[$k] = $w;
}

/** Is the farm open to visitors on this date? (weekly hours, not a booking blackout date) */
function bcrf_tasks_open_day(PDO $pdo, string $date): bool {
    $dow = (int)(new DateTimeImmutable($date))->format('w');
    if (!bcrf_tasks_week($pdo)[$dow]) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM booking_blackouts WHERE d_date = ?");
        $st->execute([$date]);
        if ($st->fetchColumn()) return false;
    } catch (Throwable $e) { /* no blackouts table */ }
    return true;
}

// ── Chores: when do they fall? ───────────────────────────────────────────────────────────────────

/** Does chore $r fall on $date (Y-m-d)? */
function bcrf_rec_falls_on(PDO $pdo, array $r, string $date): bool {
    if ($date < (string)$r['start_date']) return false;
    if (!empty($r['end_date']) && $date > (string)$r['end_date']) return false;
    $d = new DateTimeImmutable($date);
    $n = max(1, (int)($r['interval_n'] ?? 1));
    $days = (int)(new DateTimeImmutable((string)$r['start_date']))->diff($d)->days;
    switch ((string)$r['freq']) {
        case 'daily':   break;
        case 'every_n': if ($days % $n !== 0) return false; break;
        case 'weekly':
            $wd = array_map('intval', array_filter(explode(',', (string)$r['weekdays']), 'strlen'));
            if (!in_array((int)$d->format('w'), $wd, true)) return false;
            if ($n > 1) {   // every N weeks, counted from the week (Sunday) the chore started
                $w0 = (new DateTimeImmutable((string)$r['start_date']))->modify('-' . (new DateTimeImmutable((string)$r['start_date']))->format('w') . ' days');
                $w1 = $d->modify('-' . $d->format('w') . ' days');
                if (intdiv((int)$w0->diff($w1)->days, 7) % $n !== 0) return false;
            }
            break;
        case 'monthly':
            $md = max(1, min(31, (int)$r['month_day']));
            if ((int)$d->format('j') !== min($md, (int)$d->format('t'))) return false;
            break;
        default: return false;
    }
    $s = (string)($r['season_start'] ?? ''); $e = (string)($r['season_end'] ?? '');
    if ($s !== '' && $e !== '') {
        $md = $d->format('m-d');
        $in = $s <= $e ? ($md >= $s && $md <= $e) : ($md >= $s || $md <= $e);   // a window may wrap the new year
        if (!$in) return false;
    }
    if (!empty($r['open_days_only']) && !bcrf_tasks_open_day($pdo, $date)) return false;
    return true;
}

/** The time a chore's task is due on $date, or null. */
function bcrf_rec_due_time(PDO $pdo, array $r, string $date): ?string {
    $a = (string)($r['due_anchor'] ?? 'time');
    if ($a === 'opening' || $a === 'closing') {
        $h = bcrf_tasks_week($pdo)[(int)(new DateTimeImmutable($date))->format('w')];
        return $h ? ($a === 'opening' ? $h[0] : $h[1]) : null;
    }
    return $r['due_time'] ?: null;
}

function bcrf_tasks_fmt_time(?string $t): string {
    if (!$t) return '';
    $ts = strtotime('2000-01-01 ' . $t);
    return $ts ? date('g:i A', $ts) : '';
}

/** "Every day at 7:30 AM, open days only, Apr 1 – Oct 31" */
function bcrf_rec_describe(array $r): string {
    $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $n = max(1, (int)($r['interval_n'] ?? 1));
    switch ((string)$r['freq']) {
        case 'every_n': $s = $n === 1 ? 'Every day' : "Every $n days"; break;
        case 'weekly':
            $wd = array_map('intval', array_filter(explode(',', (string)$r['weekdays']), 'strlen'));
            sort($wd);
            $s = ($n > 1 ? "Every $n weeks on " : 'Every ') . ($wd ? implode(', ', array_map(fn($i) => $names[$i] ?? '?', $wd)) : '(no days picked)');
            break;
        case 'monthly':
            $md = (int)$r['month_day'];
            $sfx = ($md % 100 >= 11 && $md % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$md % 10] ?? 'th');
            $s = 'Monthly on the ' . $md . $sfx;
            break;
        default: $s = 'Every day';
    }
    $a = (string)($r['due_anchor'] ?? 'time');
    if ($a === 'opening') $s .= ', at opening';
    elseif ($a === 'closing') $s .= ', at closing';
    elseif (!empty($r['due_time'])) $s .= ' at ' . bcrf_tasks_fmt_time($r['due_time']);
    if (!empty($r['open_days_only'])) $s .= ', open days only';
    if (!empty($r['season_start']) && !empty($r['season_end'])) {
        $f = fn($md) => date('M j', strtotime('2001-' . $md));
        $s .= ', ' . $f($r['season_start']) . ' – ' . $f($r['season_end']);
    }
    if (!empty($r['end_date'])) $s .= ', until ' . date('M j, Y', strtotime((string)$r['end_date']));
    return $s;
}

// ── Making each day's task ───────────────────────────────────────────────────────────────────────

/**
 * Create chore tasks from today through each chore's horizon. Safe to run any number of times
 * (unique per chore and day; INSERT IGNORE). Past days are never filled in. Returns tasks created.
 * $force: look again even when generated_through already reaches the horizon (after an edit).
 */
function bcrf_tasks_generate(PDO $pdo, ?int $recId = null, bool $force = false): int {
    if (!bcrf_tasks_ready($pdo)) return 0;
    $today = bcrf_tasks_today();
    $sql = "SELECT * FROM task_recurrences WHERE active = 1" . ($recId ? " AND id = " . (int)$recId : '');
    $made = 0;
    $ins = $pdo->prepare("INSERT IGNORE INTO tasks (title, description, checklist_json, priority, status, due_date, due_time,
                              assignee_user_id, assignee_role, recurrence_id, occurrence_date, created_at)
                          VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $mark = $pdo->prepare("UPDATE task_recurrences SET generated_through = ? WHERE id = ?");
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $to = bcrf_tasks_add_days($today, max(1, min(60, (int)$r['horizon_days'])) - 1);
        if (!empty($r['end_date']) && $r['end_date'] < $to) $to = (string)$r['end_date'];
        if (!$force && !empty($r['generated_through']) && $r['generated_through'] >= $to) continue;
        $from = max($today, (string)$r['start_date']);
        if (!$force && !empty($r['generated_through']) && $r['generated_through'] >= $from) $from = bcrf_tasks_add_days((string)$r['generated_through'], 1);
        $list = bcrf_tasks_checklist_new(json_decode((string)$r['checklist_json'], true) ?: []);
        for ($d = $from; $d <= $to; $d = bcrf_tasks_add_days($d, 1)) {
            if (!bcrf_rec_falls_on($pdo, $r, $d)) continue;
            $ins->execute([$r['title'], $r['description'], $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null, $r['priority'],
                           $d, bcrf_rec_due_time($pdo, $r, $d), $r['assignee_user_id'], $r['assignee_role'], (int)$r['id'], $d]);
            $made += $ins->rowCount();
        }
        if ($to >= $today) $mark->execute([$to, (int)$r['id']]);
    }
    return $made;
}

/** Drop chore days from today on that nobody has touched (so an edit or a pause takes effect). */
function bcrf_tasks_clear_untouched(PDO $pdo, int $recId): int {
    $st = $pdo->prepare("DELETE FROM tasks WHERE recurrence_id = ? AND occurrence_date >= ? AND status = 'open' AND updated_at IS NULL");
    $st->execute([$recId, bcrf_tasks_today()]);
    return $st->rowCount();
}

// ── Checklists ───────────────────────────────────────────────────────────────────────────────────

/** Lines of text (array or newline string) → clean list of up to 40 items. */
function bcrf_tasks_lines($in): array {
    if (is_string($in)) $in = preg_split('/\R/', $in);
    $out = [];
    foreach ((array)$in as $l) {
        $l = trim(preg_replace('/^\s*(?:[-*•]|\[\s?[xX]?\s?\])\s*/u', '', (string)$l));
        if ($l !== '') $out[] = mb_substr($l, 0, 200);
        if (count($out) >= 40) break;
    }
    return $out;
}

/** Strings → task checklist items. */
function bcrf_tasks_checklist_new(array $lines): array {
    return array_map(fn($t) => ['t' => (string)$t, 'done' => false, 'by' => null, 'at' => null], bcrf_tasks_lines($lines));
}

// ── Reading ──────────────────────────────────────────────────────────────────────────────────────

/** Can the signed-in user see (and work) this task? Managers see all. */
function bcrf_tasks_can_see(array $t): bool {
    if (bcrf_tasks_is_manager()) return true;
    $uid = bcrf_tasks_uid(); $role = (string)($_SESSION['user_role'] ?? '');
    if (!empty($t['assignee_user_id'])) return (int)$t['assignee_user_id'] === (int)$uid || (int)($t['created_by'] ?? 0) === (int)$uid;
    if (!empty($t['assignee_role'])) return $t['assignee_role'] === $role || (int)($t['created_by'] ?? 0) === (int)$uid;
    return true;   // anyone on the farm
}

/** SQL for "mine": assigned to me, to my role, or to anyone. */
function bcrf_tasks_mine_where(string $alias = 't'): array {
    $uid = (int)bcrf_tasks_uid(); $role = (string)($_SESSION['user_role'] ?? '');
    return ["($alias.assignee_user_id = ? OR ($alias.assignee_user_id IS NULL AND ($alias.assignee_role IS NULL OR $alias.assignee_role = ?)))", [$uid, $role]];
}

function bcrf_tasks_decode(array $t): array {
    $t['checklist'] = json_decode((string)($t['checklist_json'] ?? ''), true) ?: [];
    $t['checked'] = count(array_filter($t['checklist'], fn($i) => !empty($i['done'])));
    $t['overdue'] = in_array($t['status'], ['open', 'in_progress'], true) && bcrf_tasks_is_late($t);
    return $t;
}

/** Past its due date, or due today at a time already gone by. */
function bcrf_tasks_is_late(array $t, ?DateTimeImmutable $now = null): bool {
    if (empty($t['due_date'])) return false;
    $today = bcrf_tasks_today();
    if ($t['due_date'] < $today) return true;
    if ($t['due_date'] > $today || empty($t['due_time'])) return false;
    $hms = $now ? $now->format('H:i:s') : (string)($GLOBALS['bcrf_tasks_time'] ?? (new DateTimeImmutable('now', bcrf_tasks_tz()))->format('H:i:s'));
    return $hms > (string)$t['due_time'];
}

function bcrf_tasks_select(): string {
    return "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS assignee_name, CONCAT(c.first_name, ' ', c.last_name) AS completed_name
            FROM tasks t LEFT JOIN users u ON u.id = t.assignee_user_id LEFT JOIN users c ON c.id = t.completed_by";
}

function bcrf_task_get(PDO $pdo, int $id, bool $lock = false): array {
    $st = $pdo->prepare(bcrf_tasks_select() . " WHERE t.id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new BcrfTaskError('That task was not found.');
    return bcrf_tasks_decode($t);
}

/** My Tasks: ['overdue' => [], 'today' => [], 'upcoming' => [] (next 7 days), 'someday' => [] (no date), 'done' => [] (done today)] */
function bcrf_tasks_mine(PDO $pdo, bool $everyone = false): array {
    $today = bcrf_tasks_today(); $end = bcrf_tasks_add_days($today, 7);
    [$w, $a] = $everyone ? ['1=1', []] : bcrf_tasks_mine_where();
    $order = " ORDER BY t.due_date IS NULL, t.due_date, t.due_time IS NULL, t.due_time, FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), t.id";
    $st = $pdo->prepare(bcrf_tasks_select() . " WHERE $w AND t.status IN ('open', 'in_progress') AND (t.due_date IS NULL OR t.due_date <= ?)" . $order . " LIMIT 500");
    $st->execute(array_merge($a, [$end]));
    $out = ['overdue' => [], 'today' => [], 'upcoming' => [], 'someday' => [], 'done' => []];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $t = bcrf_tasks_decode($t);
        if (empty($t['due_date'])) $out['someday'][] = $t;
        elseif ($t['due_date'] < $today) $out['overdue'][] = $t;
        elseif ($t['due_date'] === $today) $out['today'][] = $t;
        else $out['upcoming'][] = $t;
    }
    // Finished today (farm day), newest first
    $start = (new DateTimeImmutable($today, bcrf_tasks_tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $st = $pdo->prepare(bcrf_tasks_select() . " WHERE $w AND t.status IN ('done', 'skipped') AND t.completed_at >= ? ORDER BY t.completed_at DESC LIMIT 50");
    $st->execute(array_merge($a, [$start]));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $out['done'][] = bcrf_tasks_decode($t);
    return $out;
}

/** For the dashboard: ['overdue' => n, 'today' => n] of the signed-in user's open tasks. */
function bcrf_tasks_counts(PDO $pdo): array {
    if (!bcrf_tasks_ready($pdo)) return ['overdue' => 0, 'today' => 0];
    $today = bcrf_tasks_today();
    [$w, $a] = bcrf_tasks_mine_where();
    $st = $pdo->prepare("SELECT SUM(t.due_date < ?) o, SUM(t.due_date = ?) d FROM tasks t WHERE $w AND t.status IN ('open', 'in_progress') AND t.due_date <= ?");
    $st->execute(array_merge([$today, $today], $a, [$today]));
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['overdue' => (int)($r['o'] ?? 0), 'today' => (int)($r['d'] ?? 0)];
}

/** Everyone's tasks (managers). $f: status (open|done|all|<status>), who (anyone|role:X|user:N), from, to, q */
function bcrf_tasks_list(PDO $pdo, array $f = [], int $limit = 300): array {
    $w = []; $a = [];
    $s = (string)($f['status'] ?? 'open');
    if ($s === 'open') $w[] = "t.status IN ('open', 'in_progress')";
    elseif ($s === 'closed') $w[] = "t.status IN ('done', 'skipped', 'cancelled')";
    elseif (isset(BCRF_TASK_STATUSES[$s])) { $w[] = 't.status = ?'; $a[] = $s; }
    $who = (string)($f['who'] ?? '');
    if ($who === 'anyone') $w[] = 't.assignee_user_id IS NULL AND t.assignee_role IS NULL';
    elseif (str_starts_with($who, 'role:')) { $w[] = 't.assignee_user_id IS NULL AND t.assignee_role = ?'; $a[] = substr($who, 5); }
    elseif (str_starts_with($who, 'user:')) { $w[] = 't.assignee_user_id = ?'; $a[] = (int)substr($who, 5); }
    if (!empty($f['from'])) { $w[] = 't.due_date >= ?'; $a[] = $f['from']; }
    if (!empty($f['to']))   { $w[] = 't.due_date <= ?'; $a[] = $f['to']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(t.title LIKE ? OR t.description LIKE ?)'; $a[] = "%$q%"; $a[] = "%$q%"; }
    $st = $pdo->prepare(bcrf_tasks_select() . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
        . " ORDER BY t.due_date IS NULL, t.due_date " . ($s === 'open' ? 'ASC' : 'DESC') . ", t.due_time, t.id LIMIT " . max(1, min(2000, $limit)));
    $st->execute($a);
    return array_map('bcrf_tasks_decode', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Staff who can be given a task: active accounts, not public "user" logins. */
function bcrf_tasks_people(PDO $pdo): array {
    try {
        $st = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name, role FROM users
                             WHERE role <> 'user' AND account_status = 'active' AND (date_terminated IS NULL OR date_terminated > ?)
                             ORDER BY first_name, last_name");
        $st->execute([bcrf_tasks_today()]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** "Anyone on the farm" / "Farm staff" / "Sam Jones" */
function bcrf_tasks_who(array $t): string {
    if (!empty($t['assignee_user_id'])) return trim((string)($t['assignee_name'] ?? '')) ?: 'Person #' . (int)$t['assignee_user_id'];
    if (!empty($t['assignee_role'])) return BCRF_TASK_ROLES[$t['assignee_role']] ?? (string)$t['assignee_role'];
    return 'Anyone on the farm';
}

/** Form value "anyone" | "role:Staff" | "user:12" → [user_id, role]. */
function bcrf_tasks_parse_who(PDO $pdo, string $v): array {
    if (str_starts_with($v, 'user:')) {
        $id = (int)substr($v, 5);
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role <> 'user'");
        $st->execute([$id]);
        if (!$id || !(int)$st->fetchColumn()) throw new BcrfTaskError('Pick someone on the staff list.');
        return [$id, null];
    }
    if (str_starts_with($v, 'role:')) {
        $r = substr($v, 5);
        if (!isset(BCRF_TASK_ROLES[$r])) throw new BcrfTaskError('Pick a group from the list.');
        return [null, $r];
    }
    return [null, null];
}

function bcrf_tasks_who_value(array $t): string {
    if (!empty($t['assignee_user_id'])) return 'user:' . (int)$t['assignee_user_id'];
    if (!empty($t['assignee_role'])) return 'role:' . $t['assignee_role'];
    return 'anyone';
}

// ── Changing tasks ───────────────────────────────────────────────────────────────────────────────

function bcrf_tasks_audit(PDO $pdo, string $action, string $type, int $id, string $label): void {
    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$t, $aid, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => $action,
            'object_type' => $type, 'object_id' => (string)$id, 'object_label' => mb_substr($label, 0, 250)]);
    }
}

/** "14:30", "2:30 PM", "2:30pm" → "14:30:00" */
function bcrf_tasks_parse_time(string $v): string {
    if (!preg_match('/^\s*(\d{1,2}):([0-5]\d)(?::[0-5]\d)?\s*([ap]\.?m\.?)?\s*$/i', $v, $m)) throw new BcrfTaskError('Pick a due time.');
    $h = (int)$m[1];
    if (!empty($m[3])) {
        if ($h < 1 || $h > 12) throw new BcrfTaskError('Pick a due time.');
        $h = $h % 12 + (stripos($m[3], 'p') === 0 ? 12 : 0);
    } elseif ($h > 23) throw new BcrfTaskError('Pick a due time.');
    return sprintf('%02d:%s:00', $h, $m[2]);
}

/** Validate task fields from a form. $d: title, description, checklist (text), priority, due_date, due_time, who, related_type, related_id */
function bcrf_tasks_clean(PDO $pdo, array $d): array {
    $title = mb_substr(trim((string)($d['title'] ?? '')), 0, 150);
    if ($title === '') throw new BcrfTaskError('Give the task a name.');
    $prio = (string)($d['priority'] ?? 'normal');
    if (!isset(BCRF_TASK_PRIORITIES[$prio])) $prio = 'normal';
    $date = trim((string)($d['due_date'] ?? ''));
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new BcrfTaskError('Pick a due date.');
    if ($date !== '' && !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) throw new BcrfTaskError('That due date is not a real day.');
    $time = trim((string)($d['due_time'] ?? ''));
    if ($time !== '') {
        $time = bcrf_tasks_parse_time($time);
        if ($date === '') throw new BcrfTaskError('A due time needs a due date.');
    }
    [$uid, $role] = bcrf_tasks_parse_who($pdo, (string)($d['who'] ?? 'anyone'));
    $rt = trim((string)($d['related_type'] ?? '')); $ri = trim((string)($d['related_id'] ?? ''));
    return ['title' => $title, 'description' => trim((string)($d['description'] ?? '')) ?: null, 'priority' => $prio,
            'due_date' => $date ?: null, 'due_time' => $time ?: null, 'assignee_user_id' => $uid, 'assignee_role' => $role,
            'related_type' => $rt !== '' ? mb_substr(preg_replace('/[^a-z_]/', '', strtolower($rt)), 0, 40) : null,
            'related_id' => $ri !== '' ? mb_substr($ri, 0, 64) : null,
            'lines' => bcrf_tasks_lines($d['checklist'] ?? [])];
}

/** New one-off task. Returns its id. */
function bcrf_task_create(PDO $pdo, array $d): int {
    $c = bcrf_tasks_clean($pdo, $d);
    $tpl = (int)($d['template_id'] ?? 0) ?: null;
    $list = bcrf_tasks_checklist_new($c['lines']);
    $pdo->prepare("INSERT INTO tasks (title, description, checklist_json, priority, status, due_date, due_time, assignee_user_id, assignee_role,
                       related_type, related_id, template_id, created_at, created_by, updated_at, updated_by)
                   VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), ?)")
        ->execute([$c['title'], $c['description'], $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null, $c['priority'], $c['due_date'], $c['due_time'],
                   $c['assignee_user_id'], $c['assignee_role'], $c['related_type'], $c['related_id'], $tpl, bcrf_tasks_uid(), bcrf_tasks_uid()]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('bcrf_event')) bcrf_event('task.created', 'tasks', $id, ['title' => $c['title'], 'due' => $c['due_date']]);
    return $id;
}

/** Edit a task's details. Checklist lines that stay keep their ticks. */
function bcrf_task_update(PDO $pdo, int $id, array $d): void {
    $t = bcrf_task_get($pdo, $id);
    if (!bcrf_tasks_can_edit($t)) throw new BcrfTaskError('Only a manager or whoever made this task can change it.');
    $c = bcrf_tasks_clean($pdo, $d);
    $old = []; foreach ($t['checklist'] as $i) $old[$i['t']][] = $i;
    $list = [];
    foreach ($c['lines'] as $l) $list[] = !empty($old[$l]) ? array_shift($old[$l]) : ['t' => $l, 'done' => false, 'by' => null, 'at' => null];
    $pdo->prepare("UPDATE tasks SET title = ?, description = ?, checklist_json = ?, priority = ?, due_date = ?, due_time = ?, assignee_user_id = ?,
                       assignee_role = ?, related_type = ?, related_id = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
        ->execute([$c['title'], $c['description'], $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null, $c['priority'], $c['due_date'], $c['due_time'],
                   $c['assignee_user_id'], $c['assignee_role'], $c['related_type'], $c['related_id'], bcrf_tasks_uid(), $id]);
}

function bcrf_tasks_can_edit(array $t): bool {
    return bcrf_tasks_is_manager() || (!empty($t['created_by']) && (int)$t['created_by'] === (int)bcrf_tasks_uid());
}

/** Tick or untick checklist item $idx. Starts the task if it was still "to do". Returns the task. */
function bcrf_task_check(PDO $pdo, int $id, int $idx, bool $done): array {
    $pdo->beginTransaction();
    try {
        $t = bcrf_task_get($pdo, $id, true);
        if (!bcrf_tasks_can_see($t)) throw new BcrfTaskError('That task is not yours.');
        if (in_array($t['status'], ['done', 'skipped', 'cancelled'], true)) throw new BcrfTaskError('Reopen the task first.');
        if (!isset($t['checklist'][$idx])) throw new BcrfTaskError('That checklist item is gone; reload the page.');
        $t['checklist'][$idx]['done'] = $done;
        $t['checklist'][$idx]['by']   = $done ? bcrf_tasks_uid() : null;
        $t['checklist'][$idx]['at']   = $done ? gmdate('Y-m-d H:i:s') : null;
        $status = $t['status'] === 'open' && $done ? 'in_progress' : $t['status'];
        $pdo->prepare("UPDATE tasks SET checklist_json = ?, status = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([json_encode($t['checklist'], JSON_UNESCAPED_UNICODE), $status, bcrf_tasks_uid(), $id]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return bcrf_task_get($pdo, $id);
}

/**
 * Move a task: start | done | skip | cancel | reopen. $note goes on done/skip/cancel.
 * Done with checklist items left needs $force (the screen asks first). Skip is for chore days.
 */
function bcrf_task_set(PDO $pdo, int $id, string $act, string $note = '', bool $force = false): array {
    $note = mb_substr(trim($note), 0, 500);
    $pdo->beginTransaction();
    try {
        $t = bcrf_task_get($pdo, $id, true);
        if (!bcrf_tasks_can_see($t)) throw new BcrfTaskError('That task is not yours.');
        $closed = in_array($t['status'], ['done', 'skipped', 'cancelled'], true);
        $uid = bcrf_tasks_uid();
        switch ($act) {
            case 'start':
                if ($closed) throw new BcrfTaskError('Reopen the task first.');
                $sql = "status = 'in_progress'"; $args = [];
                break;
            case 'done':
                if ($closed) throw new BcrfTaskError('That task is already ' . strtolower(BCRF_TASK_STATUSES[$t['status']]) . '.');
                $left = count($t['checklist']) - $t['checked'];
                if ($left > 0 && !$force) throw new BcrfTaskError($left . ' checklist item' . ($left === 1 ? ' is' : 's are') . ' not ticked. Tick them, or finish anyway.');
                $sql = "status = 'done', completed_at = UTC_TIMESTAMP(), completed_by = ?, completion_note = ?"; $args = [$uid, $note ?: null];
                break;
            case 'skip':
                if ($closed) throw new BcrfTaskError('That task is already closed.');
                if (empty($t['recurrence_id'])) throw new BcrfTaskError('Only a chore day can be skipped; cancel a one-off task instead.');
                if ($note === '') throw new BcrfTaskError('Say why it was skipped.');
                $sql = "status = 'skipped', completed_at = UTC_TIMESTAMP(), completed_by = ?, completion_note = ?"; $args = [$uid, $note];
                break;
            case 'cancel':
                if ($closed) throw new BcrfTaskError('That task is already closed.');
                if (!bcrf_tasks_can_edit($t)) throw new BcrfTaskError('Only a manager or whoever made this task can cancel it.');
                $sql = "status = 'cancelled', completed_at = UTC_TIMESTAMP(), completed_by = ?, completion_note = ?"; $args = [$uid, $note ?: null];
                break;
            case 'reopen':
                if (!$closed) throw new BcrfTaskError('That task is still open.');
                $sql = "status = IF(checklist_json LIKE '%\"done\":true%', 'in_progress', 'open'), completed_at = NULL, completed_by = NULL, completion_note = NULL"; $args = [];
                break;
            default: throw new BcrfTaskError('Unknown action.');
        }
        $pdo->prepare("UPDATE tasks SET $sql, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute(array_merge($args, [$uid, $id]));
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    if (in_array($act, ['done', 'skip', 'cancel'], true) && function_exists('bcrf_event')) {
        bcrf_event('task.' . ['done' => 'completed', 'skip' => 'skipped', 'cancel' => 'cancelled'][$act], 'tasks', $id,
                   ['title' => $t['title'], 'recurrence_id' => $t['recurrence_id'] ? (int)$t['recurrence_id'] : null, 'due' => $t['due_date']]);
    }
    return bcrf_task_get($pdo, $id);
}

// ── Chores and checklists (managers) ─────────────────────────────────────────────────────────────

function bcrf_rec_get(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT r.*, CONCAT(u.first_name, ' ', u.last_name) AS assignee_name FROM task_recurrences r LEFT JOIN users u ON u.id = r.assignee_user_id WHERE r.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new BcrfTaskError('That chore was not found.');
    $r['lines'] = json_decode((string)$r['checklist_json'], true) ?: [];
    return $r;
}

function bcrf_rec_list(PDO $pdo): array {
    $rows = $pdo->prepare("SELECT r.*, CONCAT(u.first_name, ' ', u.last_name) AS assignee_name,
                                (SELECT MIN(t.due_date) FROM tasks t WHERE t.recurrence_id = r.id AND t.status IN ('open', 'in_progress') AND t.due_date >= ?) AS next_due
                         FROM task_recurrences r LEFT JOIN users u ON u.id = r.assignee_user_id ORDER BY r.active DESC, r.due_time IS NULL, r.due_time, r.title");
    $rows->execute([bcrf_tasks_today()]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['lines'] = json_decode((string)$r['checklist_json'], true) ?: [];
    return $rows;
}

/** Create or change a chore from form fields. Rebuilds the untouched days ahead. Returns its id. */
function bcrf_rec_save(PDO $pdo, array $d, ?int $id = null): int {
    if (!bcrf_tasks_is_manager()) throw new BcrfTaskError('Only an Owner or Manager can change chores.');
    $c = bcrf_tasks_clean($pdo, ['title' => $d['title'] ?? '', 'description' => $d['description'] ?? '', 'checklist' => $d['checklist'] ?? '',
                                 'priority' => $d['priority'] ?? 'normal', 'who' => $d['who'] ?? 'anyone']);
    $freq = (string)($d['freq'] ?? 'daily');
    if (!isset(BCRF_TASK_FREQS[$freq])) throw new BcrfTaskError('Pick how often.');
    $n = (int)($d['interval_n'] ?? 1);
    if ($freq === 'every_n' && ($n < 2 || $n > 365)) throw new BcrfTaskError('Every how many days? (2 to 365)');
    if ($freq === 'weekly' && ($n < 1 || $n > 52)) throw new BcrfTaskError('Every how many weeks? (1 to 52)');
    if (!in_array($freq, ['every_n', 'weekly'], true)) $n = 1;
    $wd = null;
    if ($freq === 'weekly') {
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($d['weekdays'] ?? [])), fn($x) => $x >= 0 && $x <= 6)));
        if (!$days) throw new BcrfTaskError('Pick at least one weekday.');
        sort($days); $wd = implode(',', $days);
    }
    $md = null;
    if ($freq === 'monthly') {
        $md = (int)($d['month_day'] ?? 0);
        if ($md < 1 || $md > 31) throw new BcrfTaskError('Pick a day of the month (1 to 31).');
    }
    $ss = trim((string)($d['season_start'] ?? '')); $se = trim((string)($d['season_end'] ?? ''));
    $mmdd = fn($v) => preg_match('/^(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[1], (int)$m[2], 2000);
    if ($ss !== '' || $se !== '') {
        if (!$mmdd($ss) || !$mmdd($se)) throw new BcrfTaskError('Pick both ends of the season, or leave both empty.');
    }
    $anchor = (string)($d['due_anchor'] ?? 'time');
    if (!in_array($anchor, ['time', 'opening', 'closing'], true)) $anchor = 'time';
    $time = trim((string)($d['due_time'] ?? ''));
    if ($anchor === 'time' && $time !== '') {
        $time = bcrf_tasks_parse_time($time);
    } else { $time = null; }
    $start = trim((string)($d['start_date'] ?? '')) ?: bcrf_tasks_today();
    $end = trim((string)($d['end_date'] ?? '')) ?: null;
    foreach (array_filter([$start, $end]) as $x) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $x)) throw new BcrfTaskError('Dates look like 2026-11-12.');
    if ($end && $end < $start) throw new BcrfTaskError('The end date is before the start date.');
    $open = !empty($d['open_days_only']) ? 1 : 0;
    $hz = max(1, min(30, (int)($d['horizon_days'] ?? 7)));
    $args = [$c['title'], $c['description'], json_encode($c['lines'], JSON_UNESCAPED_UNICODE), $c['priority'], $c['assignee_user_id'], $c['assignee_role'],
             $freq, $n, $wd, $md, $open, $ss ?: null, $se ?: null, $anchor, $time, $start, $end, $hz];
    if ($id) {
        bcrf_rec_get($pdo, $id);
        $pdo->prepare("UPDATE task_recurrences SET title = ?, description = ?, checklist_json = ?, priority = ?, assignee_user_id = ?, assignee_role = ?,
                           freq = ?, interval_n = ?, weekdays = ?, month_day = ?, open_days_only = ?, season_start = ?, season_end = ?, due_anchor = ?, due_time = ?,
                           start_date = ?, end_date = ?, horizon_days = ?, generated_through = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute(array_merge($args, [$id]));
        bcrf_tasks_clear_untouched($pdo, $id);
        bcrf_tasks_audit($pdo, 'update', 'task_recurrences', $id, 'Chore: ' . $c['title']);
    } else {
        $pdo->prepare("INSERT INTO task_recurrences (title, description, checklist_json, priority, assignee_user_id, assignee_role, freq, interval_n, weekdays,
                           month_day, open_days_only, season_start, season_end, due_anchor, due_time, start_date, end_date, horizon_days, active, created_at, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?)")
            ->execute(array_merge($args, [bcrf_tasks_uid()]));
        $id = (int)$pdo->lastInsertId();
        bcrf_tasks_audit($pdo, 'create', 'task_recurrences', $id, 'Chore: ' . $c['title']);
    }
    bcrf_tasks_generate($pdo, $id, true);
    return $id;
}

/** Pause (removes the untouched days ahead) or restart a chore. */
function bcrf_rec_set_active(PDO $pdo, int $id, bool $on): void {
    if (!bcrf_tasks_is_manager()) throw new BcrfTaskError('Only an Owner or Manager can change chores.');
    $r = bcrf_rec_get($pdo, $id);
    $pdo->prepare("UPDATE task_recurrences SET active = ?, generated_through = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$on ? 1 : 0, $id]);
    if ($on) bcrf_tasks_generate($pdo, $id, true); else bcrf_tasks_clear_untouched($pdo, $id);
    bcrf_tasks_audit($pdo, $on ? 'update' : 'disable', 'task_recurrences', $id, ($on ? 'Restarted' : 'Paused') . ' chore: ' . $r['title']);
}

function bcrf_tpl_list(PDO $pdo, bool $activeOnly = false): array {
    $rows = $pdo->query("SELECT * FROM task_templates" . ($activeOnly ? ' WHERE active = 1' : '') . " ORDER BY active DESC, title")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['lines'] = json_decode((string)$r['checklist_json'], true) ?: [];
    return $rows;
}

function bcrf_tpl_get(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM task_templates WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new BcrfTaskError('That checklist was not found.');
    $r['lines'] = json_decode((string)$r['checklist_json'], true) ?: [];
    return $r;
}

function bcrf_tpl_save(PDO $pdo, array $d, ?int $id = null): int {
    if (!bcrf_tasks_is_manager()) throw new BcrfTaskError('Only an Owner or Manager can change saved checklists.');
    $c = bcrf_tasks_clean($pdo, ['title' => $d['title'] ?? '', 'description' => $d['description'] ?? '', 'checklist' => $d['checklist'] ?? '',
                                 'priority' => $d['priority'] ?? 'normal', 'who' => $d['who'] ?? 'anyone']);
    if (!$c['lines']) throw new BcrfTaskError('Add at least one checklist line.');
    $args = [$c['title'], $c['description'], json_encode($c['lines'], JSON_UNESCAPED_UNICODE), $c['priority'], $c['assignee_user_id'], $c['assignee_role'], !empty($d['active']) || !$id ? 1 : 0];
    if ($id) {
        bcrf_tpl_get($pdo, $id);
        $pdo->prepare("UPDATE task_templates SET title = ?, description = ?, checklist_json = ?, priority = ?, assignee_user_id = ?, assignee_role = ?, active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute(array_merge($args, [$id]));
    } else {
        $pdo->prepare("INSERT INTO task_templates (title, description, checklist_json, priority, assignee_user_id, assignee_role, active, created_at, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")->execute(array_merge($args, [bcrf_tasks_uid()]));
        $id = (int)$pdo->lastInsertId();
    }
    bcrf_tasks_audit($pdo, 'update', 'task_templates', $id, 'Checklist: ' . $c['title']);
    return $id;
}

}
