<?php
// includes/animals.php — Farm OS Phase 5 slice 1: roster, status and movement (Nov 12, 2026; migration 0040;
// 08 › Animal profile, Status and disposition, Movement).
//   Status     active, resting (off visitor contact for now), quarantined, or a leaving status: sold, transferred,
//              adopted, deceased. Every change is written to animal_status_history with a reason. Leaving ends the
//              animal's stay in its enclosure, cancels its open tasks, stops its medications (slice 3) and takes its
//              public profile off the website.
//              Resting and quarantined animals are listed on the gate so staff keep visitors away; their public
//              profile can stay up.
//   Movement   moving an animal ends its open animal_locations row and starts a new one. Several animals can move
//              together. Going over an enclosure's capacity is a warning, never a block.
// Times are UTC in the database.
if (!function_exists('bcrf_animals_ready')) {

class BcrfAnimalError extends RuntimeException {}

define('BCRF_ANIMAL_STATUSES', [
    'active'      => 'Active',
    'resting'     => 'Resting',
    'quarantined' => 'Quarantined',
    'sold'        => 'Sold',
    'transferred' => 'Transferred',
    'adopted'     => 'Adopted',
    'deceased'    => 'Deceased',
]);
define('BCRF_ANIMAL_LEAVING', ['sold', 'transferred', 'adopted', 'deceased']);
define('BCRF_ANIMAL_OFF_CONTACT', ['resting', 'quarantined']);
define('BCRF_ANIMAL_SEXES', ['female' => 'Female', 'male' => 'Male', 'wether' => 'Wether / castrated', 'unknown' => 'Unknown']);

function bcrf_animals_ready(PDO $pdo): bool {
    try {
        $pdo->query("SELECT status, enclosure_id FROM animals LIMIT 0");
        $pdo->query("SELECT 1 FROM animal_locations LIMIT 0");
        $pdo->query("SELECT 1 FROM animal_status_history LIMIT 0");
        return true;
    } catch (Throwable $e) { return false; }
}

function bcrf_animal_is_leaving(string $status): bool { return in_array($status, BCRF_ANIMAL_LEAVING, true); }

/** Age as "2 yrs 3 mos" / "5 wks", from a birth date (Y-m-d) to $today. */
function bcrf_animal_age(?string $dob, ?string $today = null): string {
    if (!$dob || $dob === '0000-00-00') return '';
    try { $a = new DateTimeImmutable($dob); $b = new DateTimeImmutable($today ?? date('Y-m-d')); }
    catch (Throwable $e) { return ''; }
    if ($a > $b) return '';
    $d = $a->diff($b);
    if ($d->y >= 1) return $d->y . ' yr' . ($d->y > 1 ? 's' : '') . ($d->m ? ' ' . $d->m . ' mo' . ($d->m > 1 ? 's' : '') : '');
    if ($d->m >= 1) return $d->m . ' mo' . ($d->m > 1 ? 's' : '');
    $w = intdiv($d->days, 7);
    return $w >= 1 ? $w . ' wk' . ($w > 1 ? 's' : '') : $d->days . ' day' . ($d->days === 1 ? '' : 's');
}

// ── Enclosures ──────────────────────────────────────────────────────────────────────────────────

/** Enclosures with how many animals are in each now (leaving animals never count). */
function bcrf_enclosures(PDO $pdo, bool $all = false): array {
    $rows = $pdo->query("SELECT e.*, (SELECT COUNT(*) FROM animals a WHERE a.enclosure_id = e.id AND a.status NOT IN ('sold','transferred','adopted','deceased')) AS animal_count
                           FROM enclosures e" . ($all ? '' : ' WHERE e.active = 1') . " ORDER BY e.active DESC, e.sort_order, e.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['animal_count'] = (int)$r['animal_count']; $r['capacity'] = $r['capacity'] === null ? null : (int)$r['capacity']; $r['active'] = (int)$r['active']; }
    return $rows;
}

function bcrf_enclosure_get(PDO $pdo, int $id): ?array {
    foreach (bcrf_enclosures($pdo, true) as $e) if ($e['id'] === $id) return $e;
    return null;
}

/** Create (id 0) or update an enclosure. Returns its id. */
function bcrf_enclosure_save(PDO $pdo, int $id, string $name, ?int $capacity, string $notes, bool $active, int $sort = 0): int {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80) throw new BcrfAnimalError('Give the enclosure a name (up to 80 characters).');
    if ($capacity !== null && ($capacity < 1 || $capacity > 9999)) throw new BcrfAnimalError('Capacity is a number of animals, or blank for no limit.');
    $st = $pdo->prepare("SELECT id FROM enclosures WHERE name = ? AND id <> ?");
    $st->execute([$name, $id]);
    if ($st->fetchColumn()) throw new BcrfAnimalError('There is already an enclosure called ' . $name . '.');
    $notes = mb_substr(trim($notes), 0, 500);
    if ($id) {
        $pdo->prepare("UPDATE enclosures SET name = ?, capacity = ?, notes = ?, active = ?, sort_order = ? WHERE id = ?")
            ->execute([$name, $capacity, $notes !== '' ? $notes : null, $active ? 1 : 0, $sort, $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO enclosures (name, capacity, notes, active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
        ->execute([$name, $capacity, $notes !== '' ? $notes : null, $active ? 1 : 0, $sort]);
    return (int)$pdo->lastInsertId();
}

// ── Roster ──────────────────────────────────────────────────────────────────────────────────────

/**
 * Animals for the roster. $filter: '' = on the farm (not leaving), 'off' = resting or quarantined, 'gone' = leaving
 * statuses, 'all'. $enclosure limits to one enclosure. $q matches name, tag or microchip.
 */
function bcrf_animals_list(PDO $pdo, string $filter = '', int $enclosure = 0, string $q = ''): array {
    $where = []; $args = [];
    if ($filter === 'off') $where[] = "a.status IN ('resting','quarantined')";
    elseif ($filter === 'gone') $where[] = "a.status IN ('sold','transferred','adopted','deceased')";
    elseif ($filter !== 'all') $where[] = "a.status NOT IN ('sold','transferred','adopted','deceased')";
    if ($enclosure) { $where[] = 'a.enclosure_id = ?'; $args[] = $enclosure; }
    if (($q = trim($q)) !== '') { $where[] = '(a.name LIKE ? OR a.tag LIKE ? OR a.microchip LIKE ?)'; $like = '%' . addcslashes($q, '%_\\') . '%'; array_push($args, $like, $like, $like); }
    $st = $pdo->prepare("SELECT a.id, a.name, a.status, a.sex, a.date_of_birth, a.birth_estimated, a.tag, a.microchip, a.enclosure_id,
                                a.is_active, a.photo_url, b.breed_name, c.class_name, e.name AS enclosure_name
                           FROM animals a
                           LEFT JOIN breeds b ON b.id = a.breed_id
                           LEFT JOIN classifications c ON c.id = a.classification_id
                           LEFT JOIN enclosures e ON e.id = a.enclosure_id"
                       . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY c.class_name, a.name");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_animal_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT a.*, b.breed_name, c.class_name, e.name AS enclosure_name
                           FROM animals a
                           LEFT JOIN breeds b ON b.id = a.breed_id
                           LEFT JOIN classifications c ON c.id = a.classification_id
                           LEFT JOIN enclosures e ON e.id = a.enclosure_id
                          WHERE a.id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Resting and quarantined animals, for the gate and the roster banner. */
function bcrf_animals_off_contact(PDO $pdo): array {
    try {
        return $pdo->query("SELECT a.id, a.name, a.status, e.name AS enclosure_name FROM animals a LEFT JOIN enclosures e ON e.id = a.enclosure_id
                             WHERE a.status IN ('resting','quarantined') ORDER BY a.name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Save the identity fields staff keep here (the public bio and photo stay in the full editor). */
function bcrf_animal_save_identity(PDO $pdo, int $id, array $in): void {
    $sex = (string)($in['sex'] ?? '');
    if ($sex !== '' && !isset(BCRF_ANIMAL_SEXES[$sex])) throw new BcrfAnimalError('Pick a sex from the list.');
    $tag = mb_substr(trim((string)($in['tag'] ?? '')), 0, 60);
    $chip = mb_substr(trim((string)($in['microchip'] ?? '')), 0, 60);
    $dob = trim((string)($in['date_of_birth'] ?? ''));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
    if (!$d || $d->format('Y-m-d') !== $dob) throw new BcrfAnimalError('Enter the birth date (a guess is fine; tick "estimated").');
    if ($dob > date('Y-m-d')) throw new BcrfAnimalError('The birth date can\'t be in the future.');
    $pdo->prepare("UPDATE animals SET sex = ?, tag = ?, microchip = ?, date_of_birth = ?, birth_estimated = ? WHERE id = ?")
        ->execute([$sex !== '' ? $sex : null, $tag !== '' ? $tag : null, $chip !== '' ? $chip : null, $dob, empty($in['birth_estimated']) ? 0 : 1, $id]);
}

// ── Status ──────────────────────────────────────────────────────────────────────────────────────

/**
 * Change an animal's status. Leaving statuses need a reason, close the enclosure stay, cancel open tasks for the
 * animal and switch the public profile off. Coming back from a leaving status (a mistake) is allowed with a reason;
 * the public profile stays off until someone turns it on again. Returns what else changed, for the flash message.
 */
function bcrf_animal_set_status(PDO $pdo, int $id, string $to, string $reason, string $otherParty = '', string $saleRef = '', ?int $uid = null): array {
    if (!isset(BCRF_ANIMAL_STATUSES[$to])) throw new BcrfAnimalError('Pick a status from the list.');
    $a = bcrf_animal_get($pdo, $id);
    if (!$a) throw new BcrfAnimalError('That animal is not on the roster.');
    $from = (string)$a['status'];
    if ($from === $to) throw new BcrfAnimalError('It is already ' . strtolower(BCRF_ANIMAL_STATUSES[$to]) . '.');
    $reason = mb_substr(trim($reason), 0, 255);
    $otherParty = mb_substr(trim($otherParty), 0, 160);
    $saleRef = mb_substr(trim($saleRef), 0, 60);
    $leaving = bcrf_animal_is_leaving($to);
    if (($leaving || bcrf_animal_is_leaving($from)) && $reason === '') throw new BcrfAnimalError('Say why (it goes in the animal\'s history).');
    if (in_array($to, ['sold', 'transferred', 'adopted'], true) && $otherParty === '') throw new BcrfAnimalError('Who did it go to?');
    $done = ['tasks' => 0, 'unpublished' => false, 'left_enclosure' => false];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO animal_status_history (animal_id, from_status, to_status, reason, other_party, sale_ref, changed_by, changed_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
            ->execute([$id, $from, $to, $reason !== '' ? $reason : null, $otherParty !== '' ? $otherParty : null, $saleRef !== '' ? $saleRef : null, $uid]);
        $pdo->prepare("UPDATE animals SET status = ?, status_changed_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$to, $id]);
        if ($leaving) {
            if ((int)$a['is_active'] === 1) {
                $pdo->prepare("UPDATE animals SET is_active = 0 WHERE id = ?")->execute([$id]);
                $done['unpublished'] = true;
            }
            if ($a['enclosure_id']) {
                $pdo->prepare("UPDATE animal_locations SET ended_at = UTC_TIMESTAMP() WHERE animal_id = ? AND ended_at IS NULL")->execute([$id]);
                $pdo->prepare("UPDATE animals SET enclosure_id = NULL WHERE id = ?")->execute([$id]);
                $done['left_enclosure'] = true;
            }
            try {
                $st = $pdo->prepare("UPDATE tasks SET status = 'cancelled', completion_note = ?, updated_at = UTC_TIMESTAMP(), updated_by = ?
                                      WHERE related_type = 'animal' AND related_id = ? AND status IN ('open','in_progress')");
                $st->execute(['Animal ' . strtolower(BCRF_ANIMAL_STATUSES[$to]), $uid, (string)$id]);
                $done['tasks'] = $st->rowCount();
            } catch (PDOException $e) { /* tasks table not there yet */ }
            try {   // slice 3 (migration 0042): leaving stops its medications; their dose tasks were cancelled just above
                $pdo->prepare("UPDATE animal_medications SET stopped_at = UTC_TIMESTAMP(), stopped_by = ?, stop_reason = ? WHERE animal_id = ? AND stopped_at IS NULL")
                    ->execute([$uid, 'Animal ' . strtolower(BCRF_ANIMAL_STATUSES[$to]), $id]);
            } catch (PDOException $e) { /* health tables not there yet */ }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $done;
}

function bcrf_animal_status_history(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT h.*, CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name FROM animal_status_history h LEFT JOIN users u ON u.id = h.changed_by
                          WHERE h.animal_id = ? ORDER BY h.changed_at DESC, h.id DESC");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Movement ────────────────────────────────────────────────────────────────────────────────────

/**
 * Move animals into an enclosure (0 = take them out of any enclosure). Animals already there are skipped; leaving
 * animals can't be moved. Returns ['moved' => n, 'warning' => text or ''] — the warning is set when the enclosure
 * ends up over its capacity.
 */
function bcrf_animals_move(PDO $pdo, array $animalIds, int $enclosureId, string $note = '', ?int $uid = null): array {
    $animalIds = array_values(array_unique(array_filter(array_map('intval', $animalIds))));
    if (!$animalIds) throw new BcrfAnimalError('Pick at least one animal to move.');
    $enc = null;
    if ($enclosureId) {
        $enc = bcrf_enclosure_get($pdo, $enclosureId);
        if (!$enc || !$enc['active']) throw new BcrfAnimalError('Pick an enclosure that is in use.');
    }
    $note = mb_substr(trim($note), 0, 255);
    $moved = 0;
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        foreach ($animalIds as $aid) {
            $a = bcrf_animal_get($pdo, $aid);
            if (!$a) throw new BcrfAnimalError('One of those animals is not on the roster.');
            if (bcrf_animal_is_leaving((string)$a['status'])) throw new BcrfAnimalError($a['name'] . ' has left the farm and can\'t be moved.');
            if ((int)$a['enclosure_id'] === $enclosureId) continue;
            $pdo->prepare("UPDATE animal_locations SET ended_at = UTC_TIMESTAMP() WHERE animal_id = ? AND ended_at IS NULL")->execute([$aid]);
            if ($enclosureId) {
                $pdo->prepare("INSERT INTO animal_locations (animal_id, enclosure_id, started_at, moved_by, note) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)")
                    ->execute([$aid, $enclosureId, $uid, $note !== '' ? $note : null]);
            }
            $pdo->prepare("UPDATE animals SET enclosure_id = ? WHERE id = ?")->execute([$enclosureId ?: null, $aid]);
            $moved++;
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $warning = '';
    if ($enc && $enc['capacity'] !== null) {
        $now = bcrf_enclosure_get($pdo, $enclosureId);
        if ($now && $now['animal_count'] > $now['capacity']) $warning = $now['name'] . ' now has ' . $now['animal_count'] . ' animals; its capacity is ' . $now['capacity'] . '.';
    }
    return ['moved' => $moved, 'warning' => $warning];
}

function bcrf_animal_locations(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT l.*, e.name AS enclosure_name, CONCAT(u.first_name, ' ', u.last_name) AS moved_by_name FROM animal_locations l
                           LEFT JOIN enclosures e ON e.id = l.enclosure_id LEFT JOIN users u ON u.id = l.moved_by
                          WHERE l.animal_id = ? ORDER BY l.started_at DESC, l.id DESC");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

}
