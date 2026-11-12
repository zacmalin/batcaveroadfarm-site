<?php
// includes/animal_health.php — Farm OS Phase 5 slice 3: health (Nov 12, 2026; migration 0042; 08 › Health).
//   Medical      exams, treatments, injuries, illnesses with vet, diagnosis, treatment, cost and an optional follow-up
//                date. A follow-up date makes a task for that day ("Follow-up: … — Hazel"); changing the date moves the
//                task if nobody has touched it.
//   Medications  name, dose, route, how often (daily, twice a day, every N days, weekly, as needed), start and end.
//                Active medications make one task per dose for today and tomorrow (the nightly chore run and saving a
//                medication keep that topped up). Stopping a medication, its end date passing, or the animal leaving
//                cancels the dose tasks nobody has started.
//   Vaccinations vaccine, date given, by whom, lot, next due. A next-due date makes a task on that date; the Health
//                page lists everything due in the next 14 days. Recording the same vaccine again closes the old
//                due task.
//   Privacy      health records need the 'animal_health' view (Owners always, Managers by default). Dose and
//                follow-up tasks are ordinary tasks for anyone on the farm and say only what to do.
// Times are UTC in the database; dates are farm dates; money is integer cents.
if (!function_exists('bcrf_health_ready')) {

require_once __DIR__ . '/animals.php';
require_once __DIR__ . '/tasks.php';

class BcrfHealthError extends RuntimeException {}

define('BCRF_MED_KINDS', ['exam' => 'Exam / check-up', 'treatment' => 'Treatment', 'injury' => 'Injury', 'illness' => 'Illness', 'other' => 'Other']);
define('BCRF_MED_ROUTES', ['mouth' => 'By mouth', 'injection' => 'Injection', 'topical' => 'On the skin', 'eye' => 'Eye', 'ear' => 'Ear', 'other' => 'Other']);
define('BCRF_MED_FREQS', ['daily' => 'Once a day', 'twice_daily' => 'Twice a day', 'every_n' => 'Every few days', 'weekly' => 'Once a week', 'as_needed' => 'As needed (no reminders)']);
define('BCRF_MED_SLOT_TIMES', [1 => '08:00', 2 => '17:00']);
define('BCRF_HEALTH_DUE_DAYS', 14);

/** How often, in words: 'twice a day', 'every 3 days', 'as needed'. */
function bcrf_med_freq_text(array $m): string {
    if (($m['frequency'] ?? '') === 'every_n') return 'every ' . (int)$m['interval_days'] . ' days';
    if (($m['frequency'] ?? '') === 'as_needed') return 'as needed';
    return strtolower(BCRF_MED_FREQS[$m['frequency']] ?? (string)$m['frequency']);
}

function bcrf_health_ready(PDO $pdo): bool {
    try {
        foreach (['animal_medical_records', 'animal_medications', 'animal_med_doses', 'animal_vaccinations'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 0");
        return bcrf_animals_ready($pdo) && bcrf_tasks_ready($pdo);
    } catch (Throwable $e) { return false; }
}

function bcrf_health_can(): bool {
    $role = (string)($_SESSION['user_role'] ?? '');
    return function_exists('hasViewAccess') && hasViewAccess($role, 'animal_health');
}

function bcrf_health_date(string $v, string $what, bool $required = true): ?string {
    $v = trim($v);
    if ($v === '') { if ($required) throw new BcrfHealthError('Pick the ' . $what . '.'); return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) throw new BcrfHealthError('Pick a real ' . $what . '.');
    return $v;
}

function bcrf_health_money(string $v): ?int {
    $v = trim(str_replace([',', '$'], '', $v));
    if ($v === '') return null;
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100000) throw new BcrfHealthError('Enter the cost in dollars, like 85 or 85.50.');
    return (int)round((float)$v * 100);
}

function bcrf_health_animal(PDO $pdo, int $id): array {
    $a = bcrf_animal_get($pdo, $id);
    if (!$a) throw new BcrfHealthError('That animal is not on the roster.');
    return $a;
}

/** A task for an animal, made as the farm (not tied to whoever is signed in). */
function bcrf_health_task(PDO $pdo, string $title, string $date, ?string $time, int $animalId, string $note = '', string $priority = 'normal'): int {
    return bcrf_task_create($pdo, ['title' => mb_substr($title, 0, 150), 'description' => $note, 'priority' => $priority, 'due_date' => $date,
                                   'due_time' => $time ?? '', 'who' => 'anyone', 'related_type' => 'animal', 'related_id' => (string)$animalId]);
}

/** Cancel a task nobody has started. Returns true if it was cancelled. */
function bcrf_health_cancel_task(PDO $pdo, ?int $taskId, string $why): bool {
    if (!$taskId) return false;
    $st = $pdo->prepare("UPDATE tasks SET status = 'cancelled', completion_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'");
    $st->execute([mb_substr($why, 0, 500), $taskId]);
    return $st->rowCount() > 0;
}

// ── Medical records ─────────────────────────────────────────────────────────────────────────────

/** Create (id 0) or update a medical record. Returns its id. */
function bcrf_medical_save(PDO $pdo, int $id, int $animalId, array $in, ?int $uid = null): int {
    $a = bcrf_health_animal($pdo, $animalId);
    $kind = (string)($in['kind'] ?? '');
    if (!isset(BCRF_MED_KINDS[$kind])) throw new BcrfHealthError('Pick what kind of record it is.');
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 150);
    if ($title === '') throw new BcrfHealthError('Give it a short title, like "Limping, back left".');
    $date = bcrf_health_date((string)($in['record_date'] ?? ''), 'date');
    if ($date > bcrf_tasks_today()) throw new BcrfHealthError('The date can\'t be in the future.');
    $fu = bcrf_health_date((string)($in['follow_up_date'] ?? ''), 'follow-up date', false);
    if ($fu !== null && $fu < $date) throw new BcrfHealthError('The follow-up can\'t be before the record date.');
    $vals = [$date, $kind, $title, mb_substr(trim((string)($in['vet_name'] ?? '')), 0, 120) ?: null,
             trim((string)($in['diagnosis'] ?? '')) ?: null, trim((string)($in['treatment'] ?? '')) ?: null,
             bcrf_health_money((string)($in['cost'] ?? '')), $fu];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($id) {
            $st = $pdo->prepare("SELECT * FROM animal_medical_records WHERE id = ? AND animal_id = ? FOR UPDATE");
            $st->execute([$id, $animalId]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new BcrfHealthError('That record doesn\'t exist.');
            $pdo->prepare("UPDATE animal_medical_records SET record_date = ?, kind = ?, title = ?, vet_name = ?, diagnosis = ?, treatment = ?, cost_cents = ?,
                           follow_up_date = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute(array_merge($vals, [$uid, $id]));
            $taskId = $old['follow_up_task_id'] ? (int)$old['follow_up_task_id'] : null;
            if ($old['follow_up_date'] !== $fu || $old['title'] !== $title) {
                bcrf_health_cancel_task($pdo, $taskId, 'Follow-up changed');
                $taskId = null;
            }
        } else {
            $pdo->prepare("INSERT INTO animal_medical_records (animal_id, record_date, kind, title, vet_name, diagnosis, treatment, cost_cents, follow_up_date, created_at, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")->execute(array_merge([$animalId], $vals, [$uid]));
            $id = (int)$pdo->lastInsertId();
            $taskId = null;
        }
        if ($fu !== null && !$taskId && $fu >= bcrf_tasks_today()) {
            $taskId = bcrf_health_task($pdo, 'Follow-up: ' . $title . ' — ' . $a['name'], $fu, null, $animalId, 'Check how ' . $a['name'] . ' is doing and add a note to the health record.');
        }
        $pdo->prepare("UPDATE animal_medical_records SET follow_up_task_id = ? WHERE id = ?")->execute([$taskId, $id]);
        if ($own) $pdo->commit();
    } catch (BcrfTaskError $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw new BcrfHealthError($e->getMessage());
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function bcrf_medical_list(PDO $pdo, int $animalId): array {
    $st = $pdo->prepare("SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS by_name FROM animal_medical_records m LEFT JOIN users u ON u.id = m.created_by
                          WHERE m.animal_id = ? ORDER BY m.record_date DESC, m.id DESC");
    $st->execute([$animalId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Medications ─────────────────────────────────────────────────────────────────────────────────

function bcrf_med_add(PDO $pdo, int $animalId, array $in, ?int $uid = null): int {
    $a = bcrf_health_animal($pdo, $animalId);
    if (bcrf_animal_is_leaving((string)$a['status'])) throw new BcrfHealthError($a['name'] . ' has left the farm.');
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $dose = mb_substr(trim((string)($in['dose'] ?? '')), 0, 60);
    if ($name === '') throw new BcrfHealthError('Which medication?');
    if ($dose === '') throw new BcrfHealthError('How much is one dose (e.g. 2 ml, 1 tablet)?');
    $route = (string)($in['route'] ?? '');
    if ($route !== '' && !isset(BCRF_MED_ROUTES[$route])) throw new BcrfHealthError('Pick how it is given.');
    $freq = (string)($in['frequency'] ?? '');
    if (!isset(BCRF_MED_FREQS[$freq])) throw new BcrfHealthError('Pick how often.');
    $n = null;
    if ($freq === 'every_n') { $n = (int)($in['interval_days'] ?? 0); if ($n < 2 || $n > 60) throw new BcrfHealthError('Every how many days (2 to 60)?'); }
    $start = bcrf_health_date((string)($in['start_date'] ?? ''), 'start date');
    $end = bcrf_health_date((string)($in['end_date'] ?? ''), 'last day', false);
    if ($end !== null && $end < $start) throw new BcrfHealthError('The last day can\'t be before the first.');
    $pdo->prepare("INSERT INTO animal_medications (animal_id, name, dose, route, frequency, interval_days, start_date, end_date, notes, created_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([$animalId, $name, $dose, $route ?: null, $freq, $n, $start, $end, mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null, $uid]);
    $id = (int)$pdo->lastInsertId();
    bcrf_med_generate($pdo, $id);
    return $id;
}

/** Stop a medication now; dose tasks nobody started are cancelled. */
function bcrf_med_stop(PDO $pdo, int $medId, string $reason, ?int $uid = null): int {
    $st = $pdo->prepare("SELECT * FROM animal_medications WHERE id = ?");
    $st->execute([$medId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) throw new BcrfHealthError('That medication doesn\'t exist.');
    if ($m['stopped_at']) throw new BcrfHealthError('It is already stopped.');
    $pdo->prepare("UPDATE animal_medications SET stopped_at = UTC_TIMESTAMP(), stopped_by = ?, stop_reason = ? WHERE id = ?")
        ->execute([$uid, mb_substr(trim($reason), 0, 255) ?: null, $medId]);
    return bcrf_med_cancel_doses($pdo, $medId, 'Medication stopped');
}

function bcrf_med_cancel_doses(PDO $pdo, int $medId, string $why): int {
    $st = $pdo->prepare("UPDATE tasks t JOIN animal_med_doses d ON d.task_id = t.id SET t.status = 'cancelled', t.completion_note = ?, t.updated_at = UTC_TIMESTAMP()
                          WHERE d.medication_id = ? AND t.status = 'open'");
    $st->execute([$why, $medId]);
    return $st->rowCount();
}

/** True if the medication has a dose on $date (before stop/end checks). */
function bcrf_med_falls_on(array $m, string $date): bool {
    if ($date < $m['start_date'] || ($m['end_date'] && $date > $m['end_date'])) return false;
    $days = (int)(new DateTimeImmutable($m['start_date']))->diff(new DateTimeImmutable($date))->days;
    return match ($m['frequency']) {
        'daily', 'twice_daily' => true,
        'every_n' => $days % max(1, (int)$m['interval_days']) === 0,
        'weekly' => $days % 7 === 0,
        default => false,
    };
}

/**
 * Make dose tasks for today and tomorrow for active medications (one, or all). Animals that have left get none.
 * Safe to repeat: each dose is made once. Returns how many tasks were made.
 */
function bcrf_med_generate(PDO $pdo, ?int $medId = null, ?string $today = null): int {
    $today = $today ?? bcrf_tasks_today();
    $days = [$today, bcrf_tasks_add_days($today, 1)];
    $sql = "SELECT m.*, a.name AS animal_name, a.status AS animal_status FROM animal_medications m JOIN animals a ON a.id = m.animal_id
             WHERE m.stopped_at IS NULL AND m.frequency <> 'as_needed' AND (m.end_date IS NULL OR m.end_date >= ?)";
    $args = [$today];
    if ($medId) { $sql .= ' AND m.id = ?'; $args[] = $medId; }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $made = 0;
    $have = $pdo->prepare("SELECT 1 FROM animal_med_doses WHERE medication_id = ? AND dose_date = ? AND slot = ?");
    $rec = $pdo->prepare("INSERT INTO animal_med_doses (medication_id, dose_date, slot, task_id) VALUES (?, ?, ?, ?)");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if (bcrf_animal_is_leaving((string)$m['animal_status'])) continue;
        $slots = $m['frequency'] === 'twice_daily' ? [1, 2] : [1];
        foreach ($days as $d) {
            if (!bcrf_med_falls_on($m, $d)) continue;
            foreach ($slots as $s) {
                $have->execute([(int)$m['id'], $d, $s]);
                if ($have->fetchColumn()) continue;
                $route = $m['route'] ? ' (' . strtolower(BCRF_MED_ROUTES[$m['route']] ?? $m['route']) . ')' : '';
                $tid = bcrf_health_task($pdo, 'Give ' . $m['name'] . ' ' . $m['dose'] . $route . ' — ' . $m['animal_name'], $d,
                                        count($slots) > 1 || $m['frequency'] !== 'weekly' ? BCRF_MED_SLOT_TIMES[$s] : null, (int)$m['animal_id'],
                                        $m['notes'] ? (string)$m['notes'] : '', 'high');
                $rec->execute([(int)$m['id'], $d, $s, $tid]);
                $made++;
            }
        }
    }
    return $made;
}

/** Medications for an animal; each row has 'active' (bool) worked out for today. */
function bcrf_med_list(PDO $pdo, int $animalId): array {
    $st = $pdo->prepare("SELECT * FROM animal_medications WHERE animal_id = ? ORDER BY stopped_at IS NULL DESC, start_date DESC, id DESC");
    $st->execute([$animalId]);
    $today = bcrf_tasks_today();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['active'] = !$r['stopped_at'] && (!$r['end_date'] || $r['end_date'] >= $today) && $r['start_date'] <= $today;
    return $rows;
}

/** Called when an animal leaves the farm: stop its medications. */
function bcrf_med_stop_all_for(PDO $pdo, int $animalId, string $why, ?int $uid = null): int {
    $st = $pdo->prepare("SELECT id FROM animal_medications WHERE animal_id = ? AND stopped_at IS NULL");
    $st->execute([$animalId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mid) { bcrf_med_stop($pdo, (int)$mid, $why, $uid); $n++; }
    return $n;
}

// ── Vaccinations ────────────────────────────────────────────────────────────────────────────────

function bcrf_vacc_add(PDO $pdo, int $animalId, array $in, ?int $uid = null): int {
    $a = bcrf_health_animal($pdo, $animalId);
    $vaccine = mb_substr(trim((string)($in['vaccine'] ?? '')), 0, 120);
    if ($vaccine === '') throw new BcrfHealthError('Which vaccine?');
    $given = bcrf_health_date((string)($in['given_on'] ?? ''), 'date given');
    if ($given > bcrf_tasks_today()) throw new BcrfHealthError('The date given can\'t be in the future.');
    $next = bcrf_health_date((string)($in['next_due'] ?? ''), 'next due date', false);
    if ($next !== null && $next <= $given) throw new BcrfHealthError('The next due date has to be after the date given.');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // The same vaccine given again closes the earlier due task and clears its reminder.
        $st = $pdo->prepare("SELECT id, due_task_id FROM animal_vaccinations WHERE animal_id = ? AND vaccine = ? AND due_task_id IS NOT NULL");
        $st->execute([$animalId, $vaccine]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $old) {
            $pdo->prepare("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), completed_by = ?, completion_note = ?, updated_at = UTC_TIMESTAMP(), updated_by = ?
                           WHERE id = ? AND status IN ('open','in_progress')")->execute([$uid, 'Given ' . $given, $uid, (int)$old['due_task_id']]);
            $pdo->prepare("UPDATE animal_vaccinations SET due_task_id = NULL, next_due = IF(next_due > ?, next_due, NULL) WHERE id = ?")->execute([$given, (int)$old['id']]);
        }
        $pdo->prepare("INSERT INTO animal_vaccinations (animal_id, vaccine, given_on, given_by, lot, next_due, notes, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
            ->execute([$animalId, $vaccine, $given, mb_substr(trim((string)($in['given_by'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($in['lot'] ?? '')), 0, 60) ?: null,
                       $next, mb_substr(trim((string)($in['notes'] ?? '')), 0, 500) ?: null, $uid]);
        $id = (int)$pdo->lastInsertId();
        if ($next !== null && !bcrf_animal_is_leaving((string)$a['status'])) {
            $tid = bcrf_health_task($pdo, $vaccine . ' vaccine — ' . $a['name'], $next, null, $animalId, 'Due ' . date('M j, Y', strtotime($next)) . '. Record it on ' . $a['name'] . '\'s Health tab when it is given.', 'high');
            $pdo->prepare("UPDATE animal_vaccinations SET due_task_id = ? WHERE id = ?")->execute([$tid, $id]);
        }
        if ($own) $pdo->commit();
    } catch (BcrfTaskError $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw new BcrfHealthError($e->getMessage());
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function bcrf_vacc_list(PDO $pdo, int $animalId): array {
    $st = $pdo->prepare("SELECT * FROM animal_vaccinations WHERE animal_id = ? ORDER BY given_on DESC, id DESC");
    $st->execute([$animalId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * What needs doing soon across the farm, for the Health page and the animal list: vaccinations due within 14 days
 * (or overdue), medical follow-ups in that window, and active medications. Animals that left are skipped.
 */
function bcrf_health_due(PDO $pdo, ?string $today = null): array {
    $today = $today ?? bcrf_tasks_today();
    $until = bcrf_tasks_add_days($today, BCRF_HEALTH_DUE_DAYS);
    $leaving = "a.status NOT IN ('sold','transferred','adopted','deceased')";
    $st = $pdo->prepare("SELECT v.id, v.animal_id, a.name AS animal_name, v.vaccine, v.next_due FROM animal_vaccinations v JOIN animals a ON a.id = v.animal_id
                          WHERE v.next_due IS NOT NULL AND v.next_due <= ? AND $leaving
                            AND NOT EXISTS (SELECT 1 FROM animal_vaccinations v2 WHERE v2.animal_id = v.animal_id AND v2.vaccine = v.vaccine AND (v2.given_on > v.given_on OR (v2.given_on = v.given_on AND v2.id > v.id)))
                          ORDER BY v.next_due, a.name");
    $st->execute([$until]);
    $vacc = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT m.id, m.animal_id, a.name AS animal_name, m.title, m.follow_up_date FROM animal_medical_records m JOIN animals a ON a.id = m.animal_id
                          LEFT JOIN tasks t ON t.id = m.follow_up_task_id
                          WHERE m.follow_up_date IS NOT NULL AND m.follow_up_date BETWEEN ? AND ? AND $leaving AND (t.id IS NULL OR t.status IN ('open','in_progress'))
                          ORDER BY m.follow_up_date, a.name");
    $st->execute([bcrf_tasks_add_days($today, -7), $until]);
    $follow = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT m.id, m.animal_id, a.name AS animal_name, m.name, m.dose, m.frequency, m.interval_days, m.end_date FROM animal_medications m JOIN animals a ON a.id = m.animal_id
                          WHERE m.stopped_at IS NULL AND m.start_date <= ? AND (m.end_date IS NULL OR m.end_date >= ?) AND $leaving ORDER BY a.name, m.name");
    $st->execute([$today, $today]);
    $meds = $st->fetchAll(PDO::FETCH_ASSOC);
    return ['vaccinations' => $vacc, 'follow_ups' => $follow, 'medications' => $meds, 'today' => $today];
}

/** Alerts for one animal's Overview: vaccination due or overdue within 14 days, medication active. */
function bcrf_health_alerts(PDO $pdo, int $animalId): array {
    if (!bcrf_health_ready($pdo)) return [];
    $d = bcrf_health_due($pdo);
    $out = [];
    foreach ($d['vaccinations'] as $v) if ((int)$v['animal_id'] === $animalId)
        $out[] = $v['vaccine'] . ' vaccine ' . ($v['next_due'] < $d['today'] ? 'overdue since ' : 'due ') . date('M j', strtotime($v['next_due']));
    foreach ($d['medications'] as $m) if ((int)$m['animal_id'] === $animalId)
        $out[] = 'On ' . $m['name'] . ' (' . $m['dose'] . ', ' . bcrf_med_freq_text($m) . ')';
    return $out;
}

}
