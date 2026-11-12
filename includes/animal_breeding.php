<?php
// includes/animal_breeding.php — Farm OS Phase 5 slice 6: breeding (Nov 12, 2026; migration 0045; 08 › Breeding).
//   Parents     animals.dam_id and sire_id: set on an animal's Breeding tab, or automatically for animals born here.
//   Breeding    a female on the farm, a sire on the roster (same species, male) or an outside sire by name, the date,
//               the method and the expected birth date (worked out from the species' usual gestation when left blank).
//               A week before the expected date a task goes on the list ("Hazel due to give birth around Mar 3").
//   Outcome     confirmed pregnant, not pregnant (open), lost, or born. Recording a birth adds each kid to the roster
//               with its birth date, dam and sire, the dam's species and breed and her enclosure; kids start off the
//               website (is_active 0) until someone publishes them. The due task is closed.
// Breeding records are never deleted. Anyone who can see Animals can see them; changing them needs animals edit.
if (!function_exists('bcrf_breeding_ready')) {

require_once __DIR__ . '/animals.php';
if (is_file(__DIR__ . '/tasks.php')) require_once __DIR__ . '/tasks.php';

define('BCRF_BREED_METHODS', ['natural' => 'Natural', 'ai' => 'Artificial insemination', 'other' => 'Other']);
define('BCRF_BREED_STATUSES', ['bred' => 'Bred', 'confirmed' => 'Confirmed pregnant', 'born' => 'Born', 'open' => 'Not pregnant', 'lost' => 'Lost']);
define('BCRF_BREED_OPEN', ['bred', 'confirmed']);
// Usual gestation in days, by species name (lower case, singular). Used only to suggest the expected date.
define('BCRF_GESTATION_DAYS', ['goat' => 150, 'sheep' => 147, 'pig' => 114, 'cow' => 283, 'cattle' => 283, 'horse' => 340, 'pony' => 340,
                               'donkey' => 365, 'mule' => 360, 'alpaca' => 345, 'llama' => 350, 'rabbit' => 31, 'dog' => 63, 'cat' => 65]);
define('BCRF_BREED_MAX_KIDS', 8);

function bcrf_breeding_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT dam_id, sire_id FROM animals LIMIT 0"); $pdo->query("SELECT 1 FROM animal_breedings LIMIT 0"); return bcrf_animals_ready($pdo); }
    catch (Throwable $e) { return false; }
}

function bcrf_breeding_today(): string { return function_exists('bcrf_tasks_today') ? bcrf_tasks_today() : date('Y-m-d'); }

function bcrf_breeding_add_days(string $d, int $n): string { return (new DateTimeImmutable($d))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d'); }

/** Usual gestation for a species name ("Goats", "Nigerian goat" → 150), or null. */
function bcrf_gestation_days(?string $species): ?int {
    $s = strtolower(trim((string)$species));
    if ($s === '') return null;
    foreach ([$s, rtrim($s, 's'), preg_replace('/^.*\s/', '', $s), rtrim(preg_replace('/^.*\s/', '', $s), 's')] as $k) {
        if (isset(BCRF_GESTATION_DAYS[$k])) return BCRF_GESTATION_DAYS[$k];
    }
    return null;
}

function bcrf_breeding_date(string $v, string $what, bool $required = true): ?string {
    $v = trim($v);
    if ($v === '') { if ($required) throw new BcrfAnimalError('Pick the ' . $what . '.'); return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) throw new BcrfAnimalError('Pick a real ' . $what . '.');
    return $v;
}

function bcrf_breeding_animal(PDO $pdo, int $id, string $what): array {
    $a = bcrf_animal_get($pdo, $id);
    if (!$a) throw new BcrfAnimalError('That ' . $what . ' is not on the roster.');
    return $a;
}

/** Make (or move) the "due to give birth" task: a week before the expected date, or today if that has passed. */
function bcrf_breeding_task(PDO $pdo, int $breedingId): void {
    if (!function_exists('bcrf_task_create') || !bcrf_tasks_ready($pdo)) return;
    $st = $pdo->prepare("SELECT b.*, a.name AS dam_name, a.status AS dam_status FROM animal_breedings b JOIN animals a ON a.id = b.dam_id WHERE b.id = ?");
    $st->execute([$breedingId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) return;
    if ($b['due_task_id']) {
        $pdo->prepare("UPDATE tasks SET status = 'cancelled', completion_note = 'Breeding changed', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'")
            ->execute([(int)$b['due_task_id']]);
    }
    $tid = null;
    $today = bcrf_breeding_today();
    if (in_array($b['status'], BCRF_BREED_OPEN, true) && $b['expected_on'] && $b['expected_on'] >= $today && !bcrf_animal_is_leaving((string)$b['dam_status'])) {
        $on = max($today, bcrf_breeding_add_days($b['expected_on'], -7));
        $tid = bcrf_task_create($pdo, ['title' => mb_substr($b['dam_name'] . ' due to give birth around ' . date('M j', strtotime($b['expected_on'])), 0, 150),
            'description' => 'Bred ' . date('M j, Y', strtotime($b['bred_on'])) . '. Get the birthing area ready and check on her often. Record the birth on her Breeding tab.',
            'priority' => 'high', 'due_date' => $on, 'due_time' => '', 'who' => 'anyone', 'related_type' => 'animal', 'related_id' => (string)$b['dam_id']]);
    }
    $pdo->prepare("UPDATE animal_breedings SET due_task_id = ? WHERE id = ?")->execute([$tid, $breedingId]);
}

/** Record a breeding for a female. $in: sire_id, sire_outside, bred_on, method, expected_on, notes. Returns its id. */
function bcrf_breeding_add(PDO $pdo, int $damId, array $in, ?int $uid = null): int {
    $dam = bcrf_breeding_animal($pdo, $damId, 'dam');
    if (bcrf_animal_is_leaving((string)$dam['status'])) throw new BcrfAnimalError($dam['name'] . ' has left the farm.');
    if (($dam['sex'] ?? '') !== 'female') throw new BcrfAnimalError('Set ' . $dam['name'] . '\'s sex to female first (Overview).');
    $st = $pdo->prepare("SELECT COUNT(*) FROM animal_breedings WHERE dam_id = ? AND status IN ('bred','confirmed')");
    $st->execute([$damId]);
    if ((int)$st->fetchColumn()) throw new BcrfAnimalError($dam['name'] . ' already has an open breeding. Record how that one turned out first.');
    $sireId = (int)($in['sire_id'] ?? 0) ?: null;
    $outside = mb_substr(trim((string)($in['sire_outside'] ?? '')), 0, 120);
    if ($sireId) {
        $sire = bcrf_breeding_animal($pdo, $sireId, 'sire');
        if ($sireId === $damId || ($sire['sex'] ?? '') !== 'male') throw new BcrfAnimalError('Pick a male for the sire.');
        if ((int)$sire['classification_id'] !== (int)$dam['classification_id']) throw new BcrfAnimalError($sire['name'] . ' is a different species.');
        $outside = '';
    } elseif ($outside === '') {
        throw new BcrfAnimalError('Pick the sire, or type the name of an outside sire.');
    }
    $bred = bcrf_breeding_date((string)($in['bred_on'] ?? ''), 'breeding date');
    if ($bred > bcrf_breeding_today()) throw new BcrfAnimalError('The breeding date can\'t be in the future.');
    $method = (string)($in['method'] ?? 'natural');
    if (!isset(BCRF_BREED_METHODS[$method])) throw new BcrfAnimalError('Pick how she was bred.');
    $exp = bcrf_breeding_date((string)($in['expected_on'] ?? ''), 'expected date', false);
    if ($exp === null) {
        $g = bcrf_gestation_days($dam['class_name'] ?? '');
        $exp = $g ? bcrf_breeding_add_days($bred, $g) : null;
    }
    if ($exp !== null && $exp <= $bred) throw new BcrfAnimalError('The expected date has to be after the breeding date.');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO animal_breedings (dam_id, sire_id, sire_outside, bred_on, method, expected_on, status, notes, created_at, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, 'bred', ?, UTC_TIMESTAMP(), ?)")
            ->execute([$damId, $sireId, $outside ?: null, $bred, $method, $exp, mb_substr(trim((string)($in['notes'] ?? '')), 0, 1000) ?: null, $uid]);
        $id = (int)$pdo->lastInsertId();
        bcrf_breeding_task($pdo, $id);
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $id;
}

function bcrf_breeding_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT b.*, d.name AS dam_name, s.name AS sire_name FROM animal_breedings b JOIN animals d ON d.id = b.dam_id
                           LEFT JOIN animals s ON s.id = b.sire_id WHERE b.id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Change an open breeding: $status confirmed (with an optional new expected date), open (not pregnant) or lost.
 * $in: expected_on, outcome_on, notes.
 */
function bcrf_breeding_set(PDO $pdo, int $id, string $status, array $in, ?int $uid = null): void {
    $b = bcrf_breeding_get($pdo, $id);
    if (!$b) throw new BcrfAnimalError('That breeding doesn\'t exist.');
    if (!in_array($b['status'], BCRF_BREED_OPEN, true)) throw new BcrfAnimalError('That breeding is already closed (' . strtolower(BCRF_BREED_STATUSES[$b['status']]) . ').');
    if (!in_array($status, ['confirmed', 'open', 'lost'], true)) throw new BcrfAnimalError('Pick what happened.');
    $note = mb_substr(trim((string)($in['notes'] ?? '')), 0, 1000);
    $notes = $note !== '' ? trim(($b['notes'] ? $b['notes'] . "\n" : '') . $note) : $b['notes'];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($status === 'confirmed') {
            $exp = bcrf_breeding_date((string)($in['expected_on'] ?? ''), 'expected date', false) ?? $b['expected_on'];
            if ($exp !== null && $exp <= $b['bred_on']) throw new BcrfAnimalError('The expected date has to be after the breeding date.');
            $pdo->prepare("UPDATE animal_breedings SET status = 'confirmed', expected_on = ?, notes = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
                ->execute([$exp, $notes ?: null, $uid, $id]);
        } else {
            $on = bcrf_breeding_date((string)($in['outcome_on'] ?? ''), 'date', false) ?? bcrf_breeding_today();
            if ($on < $b['bred_on'] || $on > bcrf_breeding_today()) throw new BcrfAnimalError('Pick a date between the breeding and today.');
            $pdo->prepare("UPDATE animal_breedings SET status = ?, outcome_on = ?, notes = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
                ->execute([$status, $on, $notes ?: null, $uid, $id]);
        }
        bcrf_breeding_task($pdo, $id);
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/**
 * Record the birth. $in: born_on, kids => [['name' => 'Pip', 'sex' => 'female'], ...] (blank names skipped), lost (born dead),
 * notes. Adds each kid to the roster. Returns the new animal ids.
 */
function bcrf_breeding_birth(PDO $pdo, int $id, array $in, ?int $uid = null): array {
    $b = bcrf_breeding_get($pdo, $id);
    if (!$b) throw new BcrfAnimalError('That breeding doesn\'t exist.');
    if (!in_array($b['status'], BCRF_BREED_OPEN, true)) throw new BcrfAnimalError('That breeding is already closed (' . strtolower(BCRF_BREED_STATUSES[$b['status']]) . ').');
    $dam = bcrf_breeding_animal($pdo, (int)$b['dam_id'], 'dam');
    $born = bcrf_breeding_date((string)($in['born_on'] ?? ''), 'birth date');
    if ($born <= $b['bred_on'] || $born > bcrf_breeding_today()) throw new BcrfAnimalError('Pick a birth date after the breeding and not in the future.');
    $kids = [];
    foreach ((array)($in['kids'] ?? []) as $k) {
        $name = mb_substr(trim((string)($k['name'] ?? '')), 0, 100);
        if ($name === '') continue;
        $sex = (string)($k['sex'] ?? 'unknown');
        if (!isset(BCRF_ANIMAL_SEXES[$sex])) $sex = 'unknown';
        $kids[] = [$name, $sex];
    }
    if (count($kids) > BCRF_BREED_MAX_KIDS) throw new BcrfAnimalError('That\'s more than ' . BCRF_BREED_MAX_KIDS . '. Add the rest from the Full editor.');
    $lost = trim((string)($in['lost'] ?? ''));
    if ($lost !== '' && (!ctype_digit($lost) || (int)$lost > 20)) throw new BcrfAnimalError('How many were born dead? Enter a number.');
    $lost = (int)$lost;
    if (!$kids && !$lost) throw new BcrfAnimalError('Name at least one kid, or enter how many were born dead.');
    $note = mb_substr(trim((string)($in['notes'] ?? '')), 0, 1000);
    $notes = $note !== '' ? trim(($b['notes'] ? $b['notes'] . "\n" : '') . $note) : $b['notes'];
    $ids = [];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active, status, sex, dam_id, sire_id, status_changed_at)
                              VALUES (?, ?, ?, ?, 0, 'active', ?, ?, ?, UTC_TIMESTAMP())");
        $hist = $pdo->prepare("INSERT INTO animal_status_history (animal_id, from_status, to_status, reason, changed_by, changed_at) VALUES (?, NULL, 'active', ?, ?, UTC_TIMESTAMP())");
        foreach ($kids as [$name, $sex]) {
            $ins->execute([$name, (int)$dam['breed_id'], (int)$dam['classification_id'], $born, $sex, (int)$b['dam_id'], $b['sire_id'] ? (int)$b['sire_id'] : null]);
            $kid = (int)$pdo->lastInsertId();
            $hist->execute([$kid, 'Born on the farm to ' . $dam['name'], $uid]);
            $ids[] = $kid;
        }
        if ($ids && $dam['enclosure_id'] && !bcrf_animal_is_leaving((string)$dam['status'])) {
            bcrf_animals_move($pdo, $ids, (int)$dam['enclosure_id'], 'Born with ' . $dam['name'], $uid);
        }
        $pdo->prepare("UPDATE animal_breedings SET status = ?, outcome_on = ?, born_alive = ?, born_lost = ?, notes = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([$ids ? 'born' : 'lost', $born, count($ids), $lost, $notes ?: null, $uid, $id]);
        if ($b['due_task_id']) {
            $pdo->prepare("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), completed_by = ?, completion_note = ?, updated_at = UTC_TIMESTAMP(), updated_by = ?
                           WHERE id = ? AND status IN ('open','in_progress')")->execute([$uid, 'Born ' . date('M j', strtotime($born)), $uid, (int)$b['due_task_id']]);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $ids;
}

/** Breedings an animal is in, as dam or sire, newest first. */
function bcrf_breeding_list(PDO $pdo, int $animalId): array {
    $st = $pdo->prepare("SELECT b.*, d.name AS dam_name, s.name AS sire_name FROM animal_breedings b JOIN animals d ON d.id = b.dam_id
                           LEFT JOIN animals s ON s.id = b.sire_id WHERE b.dam_id = ? OR b.sire_id = ? ORDER BY b.bred_on DESC, b.id DESC");
    $st->execute([$animalId, $animalId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Open breedings across the farm (dams still here), soonest expected first. */
function bcrf_breeding_open(PDO $pdo): array {
    return $pdo->query("SELECT b.*, d.name AS dam_name, s.name AS sire_name FROM animal_breedings b JOIN animals d ON d.id = b.dam_id
                          LEFT JOIN animals s ON s.id = b.sire_id
                         WHERE b.status IN ('bred','confirmed') AND d.status NOT IN ('sold','transferred','adopted','deceased')
                         ORDER BY b.expected_on IS NULL, b.expected_on, d.name")->fetchAll(PDO::FETCH_ASSOC);
}

/** Births and other outcomes in the last $days days. */
function bcrf_breeding_recent(PDO $pdo, int $days = 90): array {
    $st = $pdo->prepare("SELECT b.*, d.name AS dam_name, s.name AS sire_name FROM animal_breedings b JOIN animals d ON d.id = b.dam_id
                           LEFT JOIN animals s ON s.id = b.sire_id WHERE b.status NOT IN ('bred','confirmed') AND b.outcome_on >= ?
                          ORDER BY b.outcome_on DESC, b.id DESC");
    $st->execute([bcrf_breeding_add_days(bcrf_breeding_today(), -$days)]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Kids of an animal (as dam or sire), youngest first. */
function bcrf_animal_offspring(PDO $pdo, int $animalId): array {
    $st = $pdo->prepare("SELECT id, name, sex, status, date_of_birth FROM animals WHERE dam_id = ? OR sire_id = ? ORDER BY date_of_birth DESC, name");
    $st->execute([$animalId, $animalId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Set (or clear, with 0) an animal's dam and sire. */
function bcrf_animal_set_parents(PDO $pdo, int $id, int $damId, int $sireId): void {
    $a = bcrf_breeding_animal($pdo, $id, 'animal');
    foreach ([[$damId, 'female', 'dam'], [$sireId, 'male', 'sire']] as [$pid, $sex, $what]) {
        if (!$pid) continue;
        if ($pid === $id) throw new BcrfAnimalError('An animal can\'t be its own ' . $what . '.');
        $p = bcrf_breeding_animal($pdo, $pid, $what);
        if (($p['sex'] ?? '') !== $sex) throw new BcrfAnimalError($p['name'] . ' isn\'t ' . $sex . '. Pick a ' . $sex . ' for the ' . $what . '.');
        if ((int)$p['classification_id'] !== (int)$a['classification_id']) throw new BcrfAnimalError($p['name'] . ' is a different species.');
        if ((int)($p['dam_id'] ?? 0) === $id || (int)($p['sire_id'] ?? 0) === $id) throw new BcrfAnimalError($p['name'] . ' is ' . $a['name'] . '\'s own kid.');
    }
    $pdo->prepare("UPDATE animals SET dam_id = ?, sire_id = ? WHERE id = ?")->execute([$damId ?: null, $sireId ?: null, $id]);
}

/** "Due to give birth around Mar 3" for an animal's Overview, when an open breeding is expected within 30 days. */
function bcrf_breeding_alert(PDO $pdo, int $animalId): string {
    if (!bcrf_breeding_ready($pdo)) return '';
    $st = $pdo->prepare("SELECT expected_on FROM animal_breedings WHERE dam_id = ? AND status IN ('bred','confirmed') AND expected_on IS NOT NULL AND expected_on <= ? ORDER BY expected_on LIMIT 1");
    $st->execute([$animalId, bcrf_breeding_add_days(bcrf_breeding_today(), 30)]);
    $e = $st->fetchColumn();
    if (!$e) return '';
    return ($e < bcrf_breeding_today() ? 'Was due to give birth ' : 'Due to give birth around ') . date('M j', strtotime($e));
}

/** One line about a breeding: "Bred Oct 1 to Buck (natural) · expected Feb 28". */
function bcrf_breeding_line(array $b, bool $showDam = false): string {
    $sire = $b['sire_name'] ?: ($b['sire_outside'] ? $b['sire_outside'] . ' (outside)' : 'unknown sire');
    $s = ($showDam ? $b['dam_name'] . ': bred ' : 'Bred ') . date('M j, Y', strtotime($b['bred_on'])) . ' to ' . $sire;
    if ($b['method'] !== 'natural') $s .= ' (' . strtolower(BCRF_BREED_METHODS[$b['method']] ?? $b['method']) . ')';
    if (in_array($b['status'], BCRF_BREED_OPEN, true)) return $s . ($b['expected_on'] ? ' · expected ' . date('M j', strtotime($b['expected_on'])) : '');
    if ($b['status'] === 'born') return $s . ' · born ' . date('M j', strtotime($b['outcome_on'])) . ': ' . (int)$b['born_alive'] . ' alive' . ((int)$b['born_lost'] ? ', ' . (int)$b['born_lost'] . ' lost' : '');
    return $s . ' · ' . strtolower(BCRF_BREED_STATUSES[$b['status']] ?? $b['status']) . ($b['outcome_on'] ? ' ' . date('M j', strtotime($b['outcome_on'])) : '');
}

}
