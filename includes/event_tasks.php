<?php
// includes/event_tasks.php — Farm OS Phase 3 slice 3 (Nov 11, 2026; 12 › Starter rules "Event checklist", 16 › Phase 3 exit):
// checklist tasks for confirmed private events (parties) and bookings.
//
//   Plan      the steps for one kind of event: 'party' (private event inquiries) or 'booking:<booking_types.id>'.
//   Step      event_task_steps row: title, checklist, questions, photo rule (same as a chore), who, and when:
//             day_offset days from the event (−7 = a week before), at a time / opening / closing that day, or (bookings)
//             the booking's start or end ± minute_offset.
//   Sync      bcrf_event_tasks_sync(): every confirmed party (event_inquiries.status = confirmed, with a date) and
//             booking (bookings.status = confirmed) from yesterday on gets one task per step (unique per step and event;
//             safe to repeat). A step whose day has already passed is due today instead. Untouched tasks follow a date
//             change. When the event is declined, cancelled, archived or a no-show its open tasks are cancelled with a
//             note; finished ones stay. A booking marked completed keeps its tasks (the wrap-up may still be to do). Runs every 15 minutes (admin/cron/event_tasks.php), right after a
//             status change in the admin, and when Tasks opens.
// Tasks link back with related_type 'event_inquiry' / 'booking' and related_id; event_step_id says which step made them.
if (!function_exists('bcrf_event_tasks_ready')) {

require_once __DIR__ . '/tasks.php';

define('BCRF_EVENT_ANCHORS', ['time' => 'At a time', 'opening' => 'At opening', 'closing' => 'At closing', 'start' => 'Booking start', 'end' => 'Booking end']);

function bcrf_event_tasks_ready(PDO $pdo): bool {
    static $ok = [];
    $k = spl_object_id($pdo);
    if (isset($ok[$k]) && empty($GLOBALS['bcrf_tasks_nocache'])) return $ok[$k];
    try { $pdo->query("SELECT 1 FROM event_task_steps LIMIT 0"); $pdo->query("SELECT event_step_id FROM tasks LIMIT 0"); return $ok[$k] = bcrf_tasks_v2($pdo); }
    catch (Throwable $e) { return $ok[$k] = false; }
}

/** ['party' => 'Private events & parties', 'booking:3' => 'Farm Visit (groups of 6+)', ...] — active booking types only. */
function bcrf_event_plans(PDO $pdo): array {
    $out = ['party' => 'Private events & parties'];
    try {
        foreach ($pdo->query("SELECT id, label FROM booking_types WHERE is_active = 1 ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $out['booking:' . (int)$b['id']] = (string)$b['label'];
        }
    } catch (Throwable $e) { /* no bookings table */ }
    return $out;
}

function bcrf_event_steps(PDO $pdo, ?string $plan = null, bool $activeOnly = false): array {
    $w = []; $a = [];
    if ($plan !== null) { $w[] = 'plan = ?'; $a[] = $plan; }
    if ($activeOnly) $w[] = 'active = 1';
    $st = $pdo->prepare("SELECT s.*, CONCAT(u.first_name, ' ', u.last_name) AS assignee_name FROM event_task_steps s LEFT JOIN users u ON u.id = s.assignee_user_id"
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY s.plan, s.day_offset, s.sort_order, s.id");
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['lines'] = json_decode((string)$r['checklist_json'], true) ?: []; $r['form'] = json_decode((string)$r['form_json'], true) ?: []; }
    return $rows;
}

function bcrf_event_step_get(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM event_task_steps WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new BcrfTaskError('That step was not found.');
    $r['lines'] = json_decode((string)$r['checklist_json'], true) ?: [];
    $r['form'] = json_decode((string)$r['form_json'], true) ?: [];
    return $r;
}

/** "A week before, at 10:00 AM" / "On the day, 30 min before the start" */
function bcrf_event_step_when(array $s): string {
    $d = (int)$s['day_offset'];
    $day = $d === 0 ? 'On the day' : ($d === -1 ? 'The day before' : ($d === 1 ? 'The day after' : ($d === -7 ? 'A week before'
         : (abs($d) . ' days ' . ($d < 0 ? 'before' : 'after')))));
    $m = (int)$s['minute_offset'];
    $mins = $m === 0 ? '' : (abs($m) >= 60 && abs($m) % 60 === 0 ? (abs($m) / 60) . ' h' : abs($m) . ' min');
    switch ((string)$s['anchor']) {
        case 'opening': return $day . ', at opening';
        case 'closing': return $day . ', at closing';
        case 'start':   return $day . ', ' . ($m ? $mins . ($m < 0 ? ' before' : ' after') . ' the start' : 'at the start');
        case 'end':     return $day . ', ' . ($m ? $mins . ($m < 0 ? ' before' : ' after') . ' the end' : 'at the end');
        default:        return $day . (!empty($s['at_time']) ? ', ' . bcrf_tasks_fmt_time($s['at_time']) : '');
    }
}

/** Save a step from form fields (managers). Returns its id. */
function bcrf_event_step_save(PDO $pdo, array $d, ?int $id = null): int {
    if (!bcrf_tasks_is_manager()) throw new BcrfTaskError('Only an Owner or Manager can change event checklists.');
    if ($id) $plan = (string)bcrf_event_step_get($pdo, $id)['plan'];   // a step stays on its plan
    else {
        $plan = (string)($d['plan'] ?? '');
        if (!array_key_exists($plan, bcrf_event_plans($pdo))) throw new BcrfTaskError('Pick which kind of event this is for.');
    }
    $c = bcrf_tasks_clean($pdo, ['title' => $d['title'] ?? '', 'description' => $d['description'] ?? '', 'checklist' => $d['checklist'] ?? '',
                                 'priority' => $d['priority'] ?? 'normal', 'who' => $d['who'] ?? 'anyone', 'photo_rule' => $d['photo_rule'] ?? 'none']);
    $form = bcrf_task_form_parse((string)($d['questions'] ?? ''));
    $off = (int)($d['day_offset'] ?? 0);
    if ($off < -60 || $off > 30) throw new BcrfTaskError('Days before or after: between 60 before and 30 after.');
    $anchor = (string)($d['anchor'] ?? 'time');
    if (!isset(BCRF_EVENT_ANCHORS[$anchor])) $anchor = 'time';
    if (in_array($anchor, ['start', 'end'], true) && !str_starts_with($plan, 'booking:'))
        throw new BcrfTaskError('Parties have no start time; pick a time, opening or closing.');
    $time = trim((string)($d['at_time'] ?? ''));
    $time = ($anchor === 'time' && $time !== '') ? bcrf_tasks_parse_time($time) : null;
    $min = in_array($anchor, ['start', 'end'], true) ? max(-720, min(720, (int)($d['minute_offset'] ?? 0))) : 0;
    $args = [$c['title'], $c['description'], json_encode($c['lines'], JSON_UNESCAPED_UNICODE), $form ? json_encode($form, JSON_UNESCAPED_UNICODE) : null,
             $c['photo_rule'], !empty($d['followup_unticked']) ? 1 : 0, $c['priority'], $c['assignee_user_id'], $c['assignee_role'],
             $off, $anchor, $time, $min, (int)($d['sort_order'] ?? 0), !empty($d['active']) || !$id ? 1 : 0];
    if ($id) {
        bcrf_event_step_get($pdo, $id);
        $pdo->prepare("UPDATE event_task_steps SET title = ?, description = ?, checklist_json = ?, form_json = ?, photo_rule = ?, followup_unticked = ?, priority = ?,
                           assignee_user_id = ?, assignee_role = ?, day_offset = ?, anchor = ?, at_time = ?, minute_offset = ?, sort_order = ?, active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute(array_merge($args, [$id]));
        // Tasks this step made that nobody has touched yet are rebuilt with the new wording and timing on the next sync.
        $pdo->prepare("DELETE FROM tasks WHERE event_step_id = ? AND status = 'open' AND updated_at IS NULL AND due_date >= ?")->execute([$id, bcrf_tasks_today()]);
    } else {
        $pdo->prepare("INSERT INTO event_task_steps (title, description, checklist_json, form_json, photo_rule, followup_unticked, priority, assignee_user_id, assignee_role,
                           day_offset, anchor, at_time, minute_offset, sort_order, active, plan, created_at, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")->execute(array_merge($args, [$plan, bcrf_tasks_uid()]));
        $id = (int)$pdo->lastInsertId();
    }
    bcrf_tasks_audit($pdo, 'update', 'event_task_steps', $id, 'Event checklist step: ' . $c['title']);
    bcrf_event_tasks_sync($pdo);
    return $id;
}

// ── Events ───────────────────────────────────────────────────────────────────────────────────────

/** Confirmed events from $from on: [['type' => 'event_inquiry'|'booking', 'id', 'plan', 'date', 'start', 'end', 'name', 'summary']] */
function bcrf_event_list(PDO $pdo, string $from, ?string $type = null, ?int $id = null): array {
    $out = [];
    if ($type === null || $type === 'event_inquiry') {
        $sql = "SELECT * FROM event_inquiries WHERE status = 'confirmed' AND preferred_date IS NOT NULL AND preferred_date >= ?" . ($id ? ' AND id = ' . (int)$id : '');
        $st = $pdo->prepare($sql); $st->execute([$from]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $guests = (int)$r['adult_count'] + (int)$r['child_count'];
            $kind = ucfirst(str_replace(['_', '-'], ' ', (string)$r['event_type'] ?: 'private event'));
            $s = $kind . ' for ' . $r['customer_name'] . ' on ' . date('D, M j', strtotime((string)$r['preferred_date'])) . '. '
               . $guests . ' guests (' . (int)$r['adult_count'] . ' adults, ' . (int)$r['child_count'] . ' children)'
               . ($r['birthday_age'] ? '; birthday child turns ' . (int)$r['birthday_age'] : '') . '.'
               . "\nContact: " . trim($r['email'] . ' ' . ($r['phone'] ?? ''))
               . ($r['special_requests'] ? "\nRequests: " . $r['special_requests'] : '')
               . ($r['admin_notes'] ? "\nNotes: " . $r['admin_notes'] : '');
            $out[] = ['type' => 'event_inquiry', 'id' => (int)$r['id'], 'plan' => 'party', 'date' => (string)$r['preferred_date'], 'start' => null, 'end' => null,
                      'name' => (string)$r['customer_name'], 'children' => (int)$r['child_count'], 'guests' => $guests, 'summary' => $s];
        }
    }
    if ($type === null || $type === 'booking') {
        $sql = "SELECT b.*, t.label AS type_label FROM bookings b JOIN booking_types t ON t.id = b.type_id
                WHERE b.status = 'confirmed' AND b.booking_date >= ?" . ($id ? ' AND b.id = ' . (int)$id : '');
        $st = $pdo->prepare($sql); $st->execute([$from]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = $r['type_label'] . ' for ' . $r['customer_name'] . ' on ' . date('D, M j', strtotime((string)$r['booking_date'])) . ', '
               . bcrf_tasks_fmt_time($r['start_time']) . '–' . bcrf_tasks_fmt_time($r['end_time']) . '. Party of ' . (int)$r['party_size'] . '.'
               . "\nContact: " . trim($r['email'] . ' ' . $r['phone'])
               . ($r['notes'] ? "\nTheir note: " . $r['notes'] : '') . ($r['admin_note'] ? "\nAdmin note: " . $r['admin_note'] : '')
               . ($r['amount_total'] !== null ? "\nPayment: $" . $r['amount_total'] . ' (' . $r['payment_status'] . ')' : '');
            $out[] = ['type' => 'booking', 'id' => (int)$r['id'], 'plan' => 'booking:' . (int)$r['type_id'], 'date' => (string)$r['booking_date'],
                      'start' => (string)$r['start_time'], 'end' => (string)$r['end_time'], 'name' => (string)$r['customer_name'], 'guests' => (int)$r['party_size'], 'summary' => $s];
        }
    }
    return $out;
}

/** Due date and time for a step of an event. A step whose day has passed is due today (time kept if still ahead). */
function bcrf_event_step_due(PDO $pdo, array $s, array $e): array {
    $date = bcrf_tasks_add_days($e['date'], (int)$s['day_offset']);
    $time = null;
    switch ((string)$s['anchor']) {
        case 'opening': case 'closing':
            $time = bcrf_rec_due_time($pdo, ['due_anchor' => $s['anchor']], $date);
            break;
        case 'start': case 'end':
            $base = $s['anchor'] === 'start' ? ($e['start'] ?? null) : ($e['end'] ?? null);
            if ($base) $time = (new DateTimeImmutable('2000-01-01 ' . $base))->modify(((int)$s['minute_offset'] >= 0 ? '+' : '') . (int)$s['minute_offset'] . ' minutes')->format('H:i:s');
            break;
        default:
            $time = $s['at_time'] ?: null;
    }
    $today = bcrf_tasks_today();
    if ($date < $today) { $date = $today; $time = null; }
    return [$date, $time];
}

/**
 * Make, move and cancel event checklist tasks. $type/$id limit it to one event (after a status change); null = all.
 * Returns ['made' => n, 'moved' => n, 'cancelled' => n].
 */
function bcrf_event_tasks_sync(PDO $pdo, ?string $type = null, ?int $id = null): array {
    $res = ['made' => 0, 'moved' => 0, 'cancelled' => 0];
    if (!bcrf_event_tasks_ready($pdo)) return $res;
    $today = bcrf_tasks_today();
    $steps = [];
    foreach (bcrf_event_steps($pdo, null, true) as $s) $steps[$s['plan']][] = $s;
    $events = bcrf_event_list($pdo, bcrf_tasks_add_days($today, -1), $type, $id);

    $find = $pdo->prepare("SELECT id, status, due_date, due_time, updated_at FROM tasks WHERE event_step_id = ? AND related_type = ? AND related_id = ?");
    $ins = $pdo->prepare("INSERT IGNORE INTO tasks (title, description, checklist_json, form_json, photo_rule, followup_unticked, priority, status, due_date, due_time,
                              assignee_user_id, assignee_role, related_type, related_id, event_step_id, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $move = $pdo->prepare("UPDATE tasks SET due_date = ?, due_time = ?, description = ? WHERE id = ? AND status = 'open' AND updated_at IS NULL");
    $live = [];
    foreach ($events as $e) {
        $live[$e['type'] . ':' . $e['id']] = true;
        foreach ($steps[$e['plan']] ?? [] as $s) {
            [$date, $time] = bcrf_event_step_due($pdo, $s, $e);
            $desc = trim(($s['description'] ? $s['description'] . "\n\n" : '') . $e['summary']);
            $find->execute([(int)$s['id'], $e['type'], (string)$e['id']]);
            $have = $find->fetch(PDO::FETCH_ASSOC);
            if ($have) {
                // Follow a date change, but only on tasks nobody has touched (a started one keeps what the person saw).
                if ($have['status'] === 'open' && $have['updated_at'] === null && ($have['due_date'] !== $date || (string)$have['due_time'] !== (string)$time)) {
                    $move->execute([$date, $time, $desc, (int)$have['id']]);
                    $res['moved'] += $move->rowCount();
                }
                continue;
            }
            if ($e['date'] < $today && bcrf_tasks_add_days($e['date'], (int)$s['day_offset']) < $today) continue;   // event over: only its after-steps still to come
            $list = bcrf_tasks_checklist_new($s['lines']);
            $ins->execute([mb_substr($s['title'] . ': ' . $e['name'], 0, 150), $desc, $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null, $s['form_json'] ?: null,
                           $s['photo_rule'] ?: 'none', (int)$s['followup_unticked'], $s['priority'], $date, $time, $s['assignee_user_id'], $s['assignee_role'],
                           $e['type'], (string)$e['id'], (int)$s['id']]);
            if ($ins->rowCount()) {
                $res['made']++;
                if (function_exists('bcrf_event')) bcrf_event('task.created', 'tasks', (int)$pdo->lastInsertId(), ['event' => $e['type'] . ':' . $e['id']]);
            }
        }
    }

    // Events no longer confirmed: cancel their open checklist tasks (finished ones stay as history).
    $w = "t.event_step_id IS NOT NULL AND t.status IN ('open', 'in_progress')";
    $a = [];
    if ($type !== null) { $w .= ' AND t.related_type = ?'; $a[] = $type; }
    if ($id !== null)   { $w .= ' AND t.related_id = ?';   $a[] = (string)$id; }
    $st = $pdo->prepare("SELECT t.id, t.related_type, t.related_id, t.due_date FROM tasks t WHERE $w");
    $st->execute($a);
    $cancel = $pdo->prepare("UPDATE tasks SET status = 'cancelled', completed_at = UTC_TIMESTAMP(), completion_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('open', 'in_progress')");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (isset($live[$t['related_type'] . ':' . $t['related_id']])) continue;
        $why = bcrf_event_status_now($pdo, (string)$t['related_type'], (int)$t['related_id']);
        if (in_array($why, ['confirmed', 'completed'], true)) continue;   // an old confirmed event, or one marked done: leave its tasks
        $cancel->execute(['Event ' . ($why ?: 'removed') . ', so this checklist step was cancelled automatically.', (int)$t['id']]);
        $res['cancelled'] += $cancel->rowCount();
    }
    return $res;
}

/** Current status of the event a task is linked to ('' if it no longer exists). */
function bcrf_event_status_now(PDO $pdo, string $type, int $id): string {
    $sql = $type === 'booking' ? "SELECT status FROM bookings WHERE id = ?" : ($type === 'event_inquiry' ? "SELECT status FROM event_inquiries WHERE id = ?" : null);
    if (!$sql) return 'confirmed';
    $st = $pdo->prepare($sql); $st->execute([$id]);
    return (string)($st->fetchColumn() ?: '');
}

/** Checklist tasks for one event, for its admin screen: ['total' => n, 'done' => n, 'open' => n]. */
function bcrf_event_task_counts(PDO $pdo, string $type, int $id): array {
    if (!bcrf_event_tasks_ready($pdo)) return ['total' => 0, 'done' => 0, 'open' => 0];
    $st = $pdo->prepare("SELECT COUNT(*) total, SUM(status = 'done') done, SUM(status IN ('open', 'in_progress')) open FROM tasks WHERE related_type = ? AND related_id = ? AND status <> 'cancelled'");
    $st->execute([$type, (string)$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['total' => (int)($r['total'] ?? 0), 'done' => (int)($r['done'] ?? 0), 'open' => (int)($r['open'] ?? 0)];
}

}
