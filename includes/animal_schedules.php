<?php
// includes/animal_schedules.php — Farm OS Phase 5 slice 5: care schedules (Nov 12, 2026; migration 0044; 08 › Reference data,
// Tasks that create records).
//   Schedule   a species' routine: a care type and how often, e.g. goats: hoof trim every 56 days. Set up under
//              Animals › Care › Setup. One per species and care type.
//   Due date   for each animal of that species still on the farm: the day its last logged care of that type was done
//              (farm date) plus the interval, or the schedule's first due date if it has never been logged.
//   Tasks      when an animal is due within 7 days (or overdue), it gets one task, "Hoof trim — Hazel", for anyone on the
//              farm. Finishing the task logs the care for that animal, which moves its next due date on. Logging the care
//              another way (Care page) moves the due date past the task's, which closes the task as done. Skipping or
//              cancelling it leaves that due date alone (no new task until the care is logged). Turning a schedule off
//              cancels its tasks nobody has started.
//   Runs       nightly with the chores (admin/cron/generate_tasks.php), on saving a schedule, after logging care, and
//              as a backstop when the Care page loads.
if (!function_exists('bcrf_sched_ready')) {

require_once __DIR__ . '/animal_care.php';
require_once __DIR__ . '/tasks.php';

define('BCRF_SCHED_LEAD_DAYS', 7);

function bcrf_sched_ready(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM animal_care_schedules LIMIT 0");
        $pdo->query("SELECT 1 FROM animal_care_schedule_tasks LIMIT 0");
        return bcrf_care_ready($pdo) && bcrf_tasks_ready($pdo);
    } catch (Throwable $e) { return false; }
}

/** "every 8 weeks", "every 10 days", "every day". */
function bcrf_sched_every_text(int $days): string {
    if ($days === 1) return 'every day';
    if ($days % 7 === 0) return $days === 7 ? 'every week' : 'every ' . ($days / 7) . ' weeks';
    return 'every ' . $days . ' days';
}

/** Species that have animals or a schedule, for the pickers. */
function bcrf_sched_species(PDO $pdo): array {
    return $pdo->query("SELECT id, class_name FROM classifications ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_sched_list(PDO $pdo, bool $all = true): array {
    return $pdo->query("SELECT s.*, c.class_name, t.name AS type_name, t.active AS type_active,
                               (SELECT COUNT(*) FROM animals a WHERE a.classification_id = s.classification_id
                                   AND a.status NOT IN ('sold','transferred','adopted','deceased')) AS animal_count
                          FROM animal_care_schedules s
                          JOIN classifications c ON c.id = s.classification_id
                          JOIN animal_care_types t ON t.id = s.care_type_id"
                       . ($all ? '' : ' WHERE s.active = 1') . " ORDER BY c.class_name, t.sort_order, t.name")->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_sched_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT s.*, c.class_name, t.name AS type_name FROM animal_care_schedules s
                           JOIN classifications c ON c.id = s.classification_id JOIN animal_care_types t ON t.id = s.care_type_id WHERE s.id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create (id 0) or change a schedule. $in: classification_id, care_type_id, every (number), unit (days|weeks), start_date,
 * note, active. Returns the id. Makes any tasks now due.
 */
function bcrf_sched_save(PDO $pdo, int $id, array $in, ?int $uid = null): int {
    $old = $id ? bcrf_sched_get($pdo, $id) : null;
    if ($id && !$old) throw new BcrfCareError('That schedule doesn\'t exist.');
    $cls = (int)($in['classification_id'] ?? ($old['classification_id'] ?? 0));
    $st = $pdo->prepare("SELECT class_name FROM classifications WHERE id = ?");
    $st->execute([$cls]);
    $clsName = $st->fetchColumn();
    if ($clsName === false) throw new BcrfCareError('Pick the species.');
    $type = bcrf_care_type($pdo, (int)($in['care_type_id'] ?? ($old['care_type_id'] ?? 0)));
    if (!$type || !$type['active']) throw new BcrfCareError('Pick what needs doing.');
    $n = trim((string)($in['every'] ?? ''));
    $unit = (string)($in['unit'] ?? 'days');
    if (!ctype_digit($n) || (int)$n < 1) throw new BcrfCareError('How often? Enter a whole number.');
    $days = (int)$n * ($unit === 'weeks' ? 7 : 1);
    if ($days > 730) throw new BcrfCareError('Pick 2 years or less.');
    $start = trim((string)($in['start_date'] ?? ''));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    if (!$d || $d->format('Y-m-d') !== $start) throw new BcrfCareError('Pick when animals never done are first due.');
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 500) ?: null;
    $active = !empty($in['active']) || !$id ? 1 : 0;
    try {
        if ($id) {
            $pdo->prepare("UPDATE animal_care_schedules SET classification_id = ?, care_type_id = ?, every_days = ?, start_date = ?, note = ?, active = ?,
                           updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute([$cls, (int)$type['id'], $days, $start, $note, $active, $uid, $id]);
        } else {
            $pdo->prepare("INSERT INTO animal_care_schedules (classification_id, care_type_id, every_days, start_date, note, active, created_at, created_by)
                           VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?)")->execute([$cls, (int)$type['id'], $days, $start, $note, $uid]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new BcrfCareError($clsName . ' already have a ' . strtolower($type['name']) . ' schedule. Change that one instead.');
        throw $e;
    }
    // A change of species, type, interval or first date: tasks nobody started are rebuilt; turned off: they're cancelled.
    if ($old && (!$active || (int)$old['classification_id'] !== $cls || (int)$old['care_type_id'] !== (int)$type['id']
                 || (int)$old['every_days'] !== $days || $old['start_date'] !== $start)) {
        bcrf_sched_cancel_open($pdo, $id, $active ? 'Schedule changed' : 'Schedule turned off');
    }
    bcrf_sched_generate($pdo);
    return $id;
}

/** Cancel a schedule's tasks nobody has started. Returns how many. */
function bcrf_sched_cancel_open(PDO $pdo, int $schedId, string $why): int {
    $st = $pdo->prepare("UPDATE tasks t JOIN animal_care_schedule_tasks s ON s.task_id = t.id
                            SET t.status = 'cancelled', t.completion_note = ?, t.completed_at = UTC_TIMESTAMP(), t.updated_at = UTC_TIMESTAMP()
                          WHERE s.schedule_id = ? AND t.status = 'open'");
    $st->execute([$why, $schedId]);
    $n = $st->rowCount();
    // Free those due dates so the rebuilt schedule can make them again.
    $pdo->prepare("DELETE s FROM animal_care_schedule_tasks s JOIN tasks t ON t.id = s.task_id WHERE s.schedule_id = ? AND t.status = 'cancelled' AND t.completion_note = ?")
        ->execute([$schedId, $why]);
    return $n;
}

/** When each animal last had this care (farm date and UTC time), not voided: [animal_id => ['date', 'at']]. */
function bcrf_sched_last_done(PDO $pdo, int $typeId): array {
    $st = $pdo->prepare("SELECT la.animal_id, MAX(l.logged_at) AS last_at FROM animal_care_logs l JOIN animal_care_log_animals la ON la.log_id = l.id
                          WHERE l.care_type_id = ? AND l.voided_at IS NULL GROUP BY la.animal_id");
    $st->execute([$typeId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['animal_id']] = ['at' => $r['last_at'],
            'date' => (new DateTimeImmutable($r['last_at'], new DateTimeZone('UTC')))->setTimezone(bcrf_tasks_tz())->format('Y-m-d')];
    }
    return $out;
}

/** Next due date for one animal on a schedule. */
function bcrf_sched_next_due(array $s, ?string $lastDate): string {
    return $lastDate !== null ? bcrf_tasks_add_days($lastDate, (int)$s['every_days']) : (string)$s['start_date'];
}

/**
 * Make tasks for animals due within 7 days (or overdue) and close open ones whose care was logged another way.
 * Safe to repeat. Returns ['made' => n, 'closed' => n].
 */
function bcrf_sched_generate(PDO $pdo, ?string $today = null): array {
    $out = ['made' => 0, 'closed' => 0];
    if (!bcrf_sched_ready($pdo)) return $out;
    $today = $today ?? bcrf_tasks_today();
    $until = bcrf_tasks_add_days($today, BCRF_SCHED_LEAD_DAYS);
    $openQ = $pdo->prepare("SELECT s.due_date, t.id FROM animal_care_schedule_tasks s JOIN tasks t ON t.id = s.task_id
                             WHERE s.schedule_id = ? AND s.animal_id = ? AND t.status IN ('open','in_progress')");
    $haveQ = $pdo->prepare("SELECT 1 FROM animal_care_schedule_tasks WHERE schedule_id = ? AND animal_id = ? AND due_date = ?");
    $link = $pdo->prepare("INSERT IGNORE INTO animal_care_schedule_tasks (schedule_id, animal_id, due_date, task_id) VALUES (?, ?, ?, ?)");
    $close = $pdo->prepare("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), completion_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('open','in_progress')");
    $animalsQ = $pdo->prepare("SELECT id, name FROM animals WHERE classification_id = ? AND status NOT IN ('sold','transferred','adopted','deceased') ORDER BY name");
    foreach (bcrf_sched_list($pdo, false) as $s) {
        if (!(int)$s['type_active']) continue;
        $last = bcrf_sched_last_done($pdo, (int)$s['care_type_id']);
        $animalsQ->execute([(int)$s['classification_id']]);
        foreach ($animalsQ->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $aid = (int)$a['id'];
            $l = $last[$aid] ?? null;
            $openQ->execute([(int)$s['id'], $aid]);
            $open = $openQ->fetch(PDO::FETCH_ASSOC);
            if ($open && $l && bcrf_sched_next_due($s, $l['date']) > $open['due_date']) {   // done since, and logged on the Care page instead
                $close->execute(['Logged in care ' . date('M j', strtotime($l['date'])), (int)$open['id']]);
                $out['closed']++;
                $open = null;
            }
            if ($open) continue;
            $due = bcrf_sched_next_due($s, $l['date'] ?? null);
            if ($due > $until) continue;
            $haveQ->execute([(int)$s['id'], $aid, $due]);
            if ($haveQ->fetchColumn()) continue;   // already made for that date (maybe skipped or cancelled)
            $desc = ucfirst(bcrf_sched_every_text((int)$s['every_days'])) . ' for ' . strtolower($s['class_name']) . '. '
                  . ($l ? 'Last done ' . date('M j, Y', strtotime($l['date'])) . '. ' : 'Not logged yet. ')
                  . 'Finishing this task logs it in ' . $a['name'] . '\'s care.' . ($s['note'] ? "\n\n" . $s['note'] : '');
            $tid = bcrf_task_create($pdo, ['title' => mb_substr($s['type_name'] . ' — ' . $a['name'], 0, 150), 'description' => $desc, 'priority' => 'normal',
                                           'due_date' => $due, 'due_time' => '', 'who' => 'anyone', 'related_type' => 'animal', 'related_id' => (string)$aid]);
            $link->execute([(int)$s['id'], $aid, $due, $tid]);
            $out['made']++;
        }
    }
    return $out;
}

/**
 * Called by bcrf_task_set() inside its transaction when a task about an animal is finished: if it came from a
 * schedule, log the care for that animal. Never blocks finishing the task. Returns the care log id or 0.
 */
function bcrf_sched_on_task_done(PDO $pdo, array $t, ?int $uid = null): int {
    if (!bcrf_sched_ready($pdo)) return 0;
    $st = $pdo->prepare("SELECT s.animal_id, c.care_type_id FROM animal_care_schedule_tasks s JOIN animal_care_schedules c ON c.id = s.schedule_id WHERE s.task_id = ?");
    $st->execute([(int)$t['id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 0;
    try {
        $note = 'From task: ' . $t['title'];
        return bcrf_care_log($pdo, ['type' => (int)$r['care_type_id'], 'animals' => [(int)$r['animal_id']], 'note' => $note], $uid)['id'];
    } catch (BcrfCareError $e) {
        return 0;   // the care type was turned off or the animal left; the task still finishes
    }
}

/** Schedules that apply to one animal, with its next due date. */
function bcrf_sched_for_animal(PDO $pdo, int $animalId): array {
    if (!bcrf_sched_ready($pdo)) return [];
    $st = $pdo->prepare("SELECT classification_id FROM animals WHERE id = ?");
    $st->execute([$animalId]);
    $cls = (int)$st->fetchColumn();
    $out = [];
    foreach (bcrf_sched_list($pdo, false) as $s) {
        if ((int)$s['classification_id'] !== $cls || !(int)$s['type_active']) continue;
        $l = bcrf_sched_last_done($pdo, (int)$s['care_type_id'])[$animalId] ?? null;
        $out[] = ['type_name' => $s['type_name'], 'every_days' => (int)$s['every_days'], 'last' => $l['date'] ?? null,
                  'next' => bcrf_sched_next_due($s, $l['date'] ?? null)];
    }
    return $out;
}

}
