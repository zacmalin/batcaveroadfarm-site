<?php
// includes/animal_expenses.php — Farm OS Phase 5 slice 7: expenses (Nov 12, 2026; migration 0046; 08 › Expenses, 13).
//   Vet     the cost on each medical record (Health), on its record date, for that animal.
//   Feed    the cost of each feeding that named a stocked product (Care), split evenly across the animals it covered
//           (leftover cents go to the first animals). Voided feedings don't count.
//   Other   animal_expenses rows: supplies, bedding, farrier, a vet bill with no medical record, buying an animal,
//           equipment. Each is for one animal, a whole species, or all animals. Never deleted; voided with a reason.
// Reported by month, species and animal for a date range, with a CSV of every line. Money is in cents.
// Seeing costs needs the 'animal_expenses' view (Owners always; Managers by default). Adding and voiding also need
// animals edit. Vet lines show the record's title only to people who can see Health.
if (!function_exists('bcrf_expenses_ready')) {

require_once __DIR__ . '/animals.php';

define('BCRF_EXPENSE_CATEGORIES', ['supplies' => 'Supplies', 'bedding' => 'Bedding', 'farrier' => 'Farrier', 'vet' => 'Vet bill',
                                   'purchase' => 'Buying an animal', 'equipment' => 'Equipment', 'other' => 'Other']);
define('BCRF_EXPENSE_SOURCES', ['vet' => 'Vet', 'feed' => 'Feed', 'other' => 'Other']);

function bcrf_expenses_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM animal_expenses LIMIT 0"); return bcrf_animals_ready($pdo); }
    catch (Throwable $e) { return false; }
}

function bcrf_expenses_can(): bool {
    $role = (string)($_SESSION['user_role'] ?? '');
    return function_exists('hasViewAccess') && hasViewAccess($role, 'animal_expenses');
}

function bcrf_expense_today(): string { return function_exists('bcrf_tasks_today') ? bcrf_tasks_today() : date('Y-m-d'); }

function bcrf_expense_tz(): DateTimeZone { return new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York'); }

function bcrf_expense_date(string $v, string $what = 'date'): string {
    $v = trim($v);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($v === '' || !$d || $d->format('Y-m-d') !== $v) throw new BcrfAnimalError('Pick a real ' . $what . '.');
    return $v;
}

/** "85", "$1,200.50" → cents. */
function bcrf_expense_money(string $v): int {
    $v = trim(str_replace([',', '$'], '', $v));
    if ($v === '' || !is_numeric($v) || (float)$v <= 0 || (float)$v > 1000000) throw new BcrfAnimalError('Enter the amount in dollars, like 85 or 85.50.');
    return (int)round((float)$v * 100);
}

function bcrf_expense_fmt(int $cents): string { return ($cents < 0 ? '-$' : '$') . number_format(abs($cents) / 100, 2); }

/**
 * Add an expense. $in: spent_on, amount, category, description, vendor, for = 'animal' | 'species' | 'all',
 * animal_id, classification_id. Returns its id.
 */
function bcrf_expense_add(PDO $pdo, array $in, ?int $uid = null): int {
    $on = bcrf_expense_date((string)($in['spent_on'] ?? ''));
    if ($on > bcrf_expense_today()) throw new BcrfAnimalError('The date can\'t be in the future.');
    $cents = bcrf_expense_money((string)($in['amount'] ?? ''));
    $cat = (string)($in['category'] ?? '');
    if (!isset(BCRF_EXPENSE_CATEGORIES[$cat])) throw new BcrfAnimalError('Pick what it was for.');
    $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 200);
    if ($desc === '') throw new BcrfAnimalError('Say what it was (e.g. "Bag of pine shavings").');
    $vendor = mb_substr(trim((string)($in['vendor'] ?? '')), 0, 120);
    $for = (string)($in['for'] ?? 'animal');
    $animal = null; $species = null;
    if ($for === 'animal') {
        $a = bcrf_animal_get($pdo, (int)($in['animal_id'] ?? 0));
        if (!$a) throw new BcrfAnimalError('Pick the animal.');
        $animal = (int)$a['id'];
    } elseif ($for === 'species') {
        $st = $pdo->prepare("SELECT id FROM classifications WHERE id = ?");
        $st->execute([(int)($in['classification_id'] ?? 0)]);
        $species = (int)$st->fetchColumn() ?: null;
        if (!$species) throw new BcrfAnimalError('Pick the species.');
    } elseif ($for !== 'all') {
        throw new BcrfAnimalError('Pick who it was for.');
    }
    $pdo->prepare("INSERT INTO animal_expenses (spent_on, category, description, vendor, amount_cents, animal_id, classification_id, created_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([$on, $cat, $desc, $vendor ?: null, $cents, $animal, $species, $uid]);
    return (int)$pdo->lastInsertId();
}

function bcrf_expense_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM animal_expenses WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bcrf_expense_void(PDO $pdo, int $id, string $reason, ?int $uid = null): void {
    $e = bcrf_expense_get($pdo, $id);
    if (!$e) throw new BcrfAnimalError('That expense doesn\'t exist.');
    if ($e['voided_at']) throw new BcrfAnimalError('That expense is already voided.');
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new BcrfAnimalError('Say why you are voiding it.');
    $pdo->prepare("UPDATE animal_expenses SET voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL")
        ->execute([$uid, $reason, $id]);
}

/** UTC bounds [start, end) for farm dates $from..$to. */
function bcrf_expense_utc_bounds(string $from, string $to): array {
    $tz = bcrf_expense_tz(); $utc = new DateTimeZone('UTC');
    $a = (new DateTimeImmutable($from . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $b = (new DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    return [$a, $b];
}

/**
 * Every cost line from $from to $to (farm dates), oldest first. $animalId limits to one animal (its vet, feed share and
 * its own other expenses). Each line: date, source (vet|feed|other), category, animal_id, animal, classification_id,
 * species, description, cents, ref (e.g. "expense 12"), expense_id (other only).
 * $showVetTitle false hides medical record titles ("Vet visit" instead).
 */
function bcrf_expense_lines(PDO $pdo, string $from, string $to, int $animalId = 0, bool $showVetTitle = true): array {
    $lines = [];
    $names = []; $species = [];
    foreach ($pdo->query("SELECT a.id, a.name, a.classification_id, c.class_name FROM animals a LEFT JOIN classifications c ON c.id = a.classification_id") as $r) {
        $names[(int)$r['id']] = [$r['name'], $r['classification_id'] !== null ? (int)$r['classification_id'] : null, (string)($r['class_name'] ?? '')];
    }
    foreach ($pdo->query("SELECT id, class_name FROM classifications") as $r) $species[(int)$r['id']] = (string)$r['class_name'];
    $line = function (string $date, string $src, string $cat, ?int $aid, ?int $cid, string $desc, int $cents, string $ref, ?int $eid = null) use (&$lines, $names, $species) {
        if ($aid !== null && isset($names[$aid])) $cid = $names[$aid][1];
        $lines[] = ['date' => $date, 'source' => $src, 'category' => $cat, 'animal_id' => $aid, 'animal' => $aid !== null ? ($names[$aid][0] ?? 'animal #' . $aid) : '',
                    'classification_id' => $cid, 'species' => $cid !== null ? ($species[$cid] ?? '') : '', 'description' => $desc, 'cents' => $cents, 'ref' => $ref, 'expense_id' => $eid];
    };
    // Vet
    try {
        $sql = "SELECT id, animal_id, record_date, title, vet_name, cost_cents FROM animal_medical_records WHERE cost_cents > 0 AND record_date BETWEEN ? AND ?";
        $args = [$from, $to];
        if ($animalId) { $sql .= " AND animal_id = ?"; $args[] = $animalId; }
        $st = $pdo->prepare($sql); $st->execute($args);
        foreach ($st as $r) {
            $desc = $showVetTitle ? $r['title'] . ($r['vet_name'] ? ' (' . $r['vet_name'] . ')' : '') : 'Vet visit';
            $line($r['record_date'], 'vet', 'vet', (int)$r['animal_id'], null, $desc, (int)$r['cost_cents'], 'medical record ' . $r['id']);
        }
    } catch (PDOException $e) { /* health not set up */ }
    // Feed
    try {
        [$ua, $ub] = bcrf_expense_utc_bounds($from, $to);
        $sql = "SELECT l.id, l.logged_at, l.feed_qty, l.feed_cost_cents, i.name AS product FROM animal_care_logs l LEFT JOIN inventory i ON i.id = l.feed_product_id
                 WHERE l.voided_at IS NULL AND l.feed_cost_cents > 0 AND l.logged_at >= ? AND l.logged_at < ?";
        $args = [$ua, $ub];
        if ($animalId) { $sql .= " AND EXISTS (SELECT 1 FROM animal_care_log_animals x WHERE x.log_id = l.id AND x.animal_id = ?)"; $args[] = $animalId; }
        $st = $pdo->prepare($sql . " ORDER BY l.logged_at, l.id"); $st->execute($args);
        $logs = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($logs) {
            $who = [];
            $ids = implode(',', array_map(fn($l) => (int)$l['id'], $logs));
            foreach ($pdo->query("SELECT log_id, animal_id FROM animal_care_log_animals WHERE log_id IN ($ids) ORDER BY log_id, animal_id") as $r) $who[(int)$r['log_id']][] = (int)$r['animal_id'];
            $tz = bcrf_expense_tz();
            foreach ($logs as $l) {
                $animals = $who[(int)$l['id']] ?? [];
                if (!$animals) continue;
                $total = (int)$l['feed_cost_cents']; $n = count($animals);
                $base = intdiv($total, $n); $extra = $total - $base * $n;
                $date = (new DateTimeImmutable($l['logged_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
                $qty = rtrim(rtrim((string)$l['feed_qty'], '0'), '.');
                foreach ($animals as $i => $aid) {
                    $share = $base + ($i < $extra ? 1 : 0);
                    if ($animalId && $aid !== $animalId) continue;
                    if ($share <= 0) continue;
                    $line($date, 'feed', 'feed', $aid, null, ($l['product'] ?: 'Feed') . ($qty !== '' ? ' × ' . $qty : '') . ($n > 1 ? ', shared by ' . $n : ''), $share, 'care log ' . $l['id']);
                }
            }
        }
    } catch (PDOException $e) { /* care not set up */ }
    // Other
    $sql = "SELECT * FROM animal_expenses WHERE voided_at IS NULL AND spent_on BETWEEN ? AND ?";
    $args = [$from, $to];
    if ($animalId) { $sql .= " AND animal_id = ?"; $args[] = $animalId; }
    $st = $pdo->prepare($sql); $st->execute($args);
    foreach ($st as $r) {
        $line($r['spent_on'], 'other', $r['category'], $r['animal_id'] !== null ? (int)$r['animal_id'] : null, $r['classification_id'] !== null ? (int)$r['classification_id'] : null,
              $r['description'] . ($r['vendor'] ? ' (' . $r['vendor'] . ')' : ''), (int)$r['amount_cents'], 'expense ' . $r['id'], (int)$r['id']);
    }
    usort($lines, fn($a, $b) => [$a['date'], $a['source'], $a['ref']] <=> [$b['date'], $b['source'], $b['ref']]);
    return $lines;
}

/**
 * Totals of lines: ['total' => [vet, feed, other, all], 'month' => ['2026-11' => [...]], 'species' => [name => [...]],
 * 'animal' => [id => ['name', 'species', vet, feed, other, all]]]. Lines for a whole species count under the species;
 * lines for all animals count under "All animals". Only lines for one animal appear under 'animal'.
 */
function bcrf_expense_totals(array $lines): array {
    $zero = ['vet' => 0, 'feed' => 0, 'other' => 0, 'all' => 0];
    $out = ['total' => $zero, 'month' => [], 'species' => [], 'animal' => []];
    foreach ($lines as $l) {
        $s = $l['source']; $c = $l['cents'];
        $m = substr($l['date'], 0, 7);
        $sp = $l['species'] !== '' ? $l['species'] : ($l['animal_id'] !== null ? 'No species set' : 'All animals');
        foreach ([&$out['total'], &$out['month'][$m], &$out['species'][$sp]] as &$bucket) {
            $bucket ??= $zero;
            $bucket[$s] += $c; $bucket['all'] += $c;
        }
        unset($bucket);
        if ($l['animal_id'] !== null) {
            $out['animal'][$l['animal_id']] ??= ['name' => $l['animal'], 'species' => $l['species']] + $zero;
            $out['animal'][$l['animal_id']][$s] += $c; $out['animal'][$l['animal_id']]['all'] += $c;
        }
    }
    ksort($out['month']);
    uasort($out['species'], fn($a, $b) => $b['all'] <=> $a['all']);
    uasort($out['animal'], fn($a, $b) => [$b['all'], $a['name']] <=> [$a['all'], $b['name']]);
    return $out;
}

/** Other expenses (including voided), newest first, for the list with the Void button. */
function bcrf_expense_recent(PDO $pdo, int $animalId = 0, int $limit = 50): array {
    $sql = "SELECT e.*, a.name AS animal_name, c.class_name, CONCAT(u.first_name, ' ', u.last_name) AS by_name FROM animal_expenses e
              LEFT JOIN animals a ON a.id = e.animal_id LEFT JOIN classifications c ON c.id = e.classification_id
              LEFT JOIN users u ON u.id = e.created_by";
    $args = [];
    if ($animalId) { $sql .= " WHERE e.animal_id = ?"; $args[] = $animalId; }
    $st = $pdo->prepare($sql . " ORDER BY e.spent_on DESC, e.id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Who an expense was for: "Hazel", "All Goat", "All animals". */
function bcrf_expense_for_text(array $e): string {
    if ($e['animal_id']) return (string)($e['animal_name'] ?? 'animal #' . $e['animal_id']);
    if ($e['classification_id']) return 'All ' . ($e['class_name'] ?? 'of a species');
    return 'All animals';
}

/** CSV rows for lines (fixed columns, dollars with two decimals). */
function bcrf_expense_csv_rows(array $lines): array {
    return array_map(fn($l) => [$l['date'], BCRF_EXPENSE_SOURCES[$l['source']], $l['source'] === 'other' ? (BCRF_EXPENSE_CATEGORIES[$l['category']] ?? $l['category']) : '',
                                $l['species'], $l['animal'], $l['description'], number_format($l['cents'] / 100, 2, '.', ''), $l['ref']], $lines);
}
define('BCRF_EXPENSE_CSV_COLUMNS', ['date', 'source', 'category', 'species', 'animal', 'description', 'amount', 'reference']);

/** Send a CSV download (written to the audit log like the other report exports) and stop. */
function bcrf_expense_send_csv(PDO $pdo, string $filename, array $rows, string $what): void {
    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$type, $uid, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $type, 'actor_id' => $uid, 'actor_label' => $name, 'ip' => $ip,
                                'action' => 'export', 'object_type' => 'report', 'object_id' => null, 'object_label' => $what . ' (' . count($rows) . ' rows)']);
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Cache-Control: no-store');
    $f = fopen('php://output', 'w');
    fputcsv($f, BCRF_EXPENSE_CSV_COLUMNS);
    foreach ($rows as $r) {
        $r = array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v) ? "'" . $v : $v, $r);
        fputcsv($f, $r);
    }
    fclose($f);
    exit;
}

}
