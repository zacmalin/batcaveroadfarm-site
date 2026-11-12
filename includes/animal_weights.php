<?php
// includes/animal_weights.php — Farm OS Phase 5 slice 4: weights (Nov 12, 2026; migration 0043; 08 › Weight).
//   A weighing is a farm date, a weight in pounds and an optional body condition score (1 to 5, halves allowed).
//   Several animals can be weighed in one go (Animals › Weights: an enclosure or a species at a time).
//   A change from the animal's previous weighing of the alert percentage or more (site setting animal_weight_alert_pct,
//   default 10) raises an alert on the animal's Overview and on the Weights page. Mistakes are voided, never deleted.
//   Weights are private: they are only shown in the admin.
if (!function_exists('bcrf_weights_ready')) {

require_once __DIR__ . '/animals.php';
if (is_file(__DIR__ . '/tasks.php')) require_once __DIR__ . '/tasks.php';

class BcrfWeightError extends RuntimeException {}

define('BCRF_WEIGHT_ALERT_DAYS', 60);   // only the latest weighing in this many days can raise an alert

function bcrf_weights_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM animal_weights LIMIT 0"); return bcrf_animals_ready($pdo); }
    catch (Throwable $e) { return false; }
}

function bcrf_weight_today(): string {
    return function_exists('bcrf_tasks_today') ? bcrf_tasks_today() : date('Y-m-d');
}

function bcrf_weight_alert_pct(PDO $pdo): float {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'animal_weight_alert_pct'");
        $st->execute();
        $v = $st->fetchColumn();
    } catch (Throwable $e) { $v = false; }
    $v = is_numeric($v) ? (float)$v : 10.0;
    return ($v >= 1 && $v <= 100) ? $v : 10.0;
}

function bcrf_weight_set_alert_pct(PDO $pdo, string $v): float {
    $v = trim(str_replace('%', '', $v));
    if (!is_numeric($v) || (float)$v < 1 || (float)$v > 100) throw new BcrfWeightError('Pick a percentage from 1 to 100.');
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('animal_weight_alert_pct', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.')]);
    return (float)$v;
}

/** "48.5", "48.5 lb", "48.5lbs" -> 48.5. Throws on anything else. */
function bcrf_weight_parse(string $v, string $who = ''): float {
    $raw = trim($v);
    $v = trim(preg_replace('/\s*(lbs?|pounds?)\.?$/i', '', str_replace(',', '', $raw)));
    if (!is_numeric($v) || (float)$v <= 0 || (float)$v > 5000) {
        throw new BcrfWeightError(($who !== '' ? $who . ': ' : '') . 'Enter the weight in pounds, like 48 or 48.5.');
    }
    return round((float)$v, 2);
}

function bcrf_weight_bcs(string $v, string $who = ''): ?float {
    $v = trim($v);
    if ($v === '') return null;
    if (!is_numeric($v) || (float)$v < 1 || (float)$v > 5 || fmod((float)$v * 2, 1.0) !== 0.0) {
        throw new BcrfWeightError(($who !== '' ? $who . ': ' : '') . 'Body condition is 1 to 5 (halves are fine, like 3.5).');
    }
    return (float)$v;
}

function bcrf_weight_fmt($lb): string {
    return rtrim(rtrim(number_format((float)$lb, 2, '.', ','), '0'), '.') . ' lb';
}

/** Percentage change from $prev to $cur, and whether it reaches the alert level. */
function bcrf_weight_change(float $prev, float $cur, float $alertPct): array {
    $pct = $prev > 0 ? ($cur - $prev) / $prev * 100 : 0.0;
    return ['pct' => round($pct, 1), 'flag' => abs($pct) >= $alertPct - 0.00001];
}

function bcrf_weight_date(string $v): string {
    $v = trim($v);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) throw new BcrfWeightError('Pick the date it was weighed.');
    if ($v > bcrf_weight_today()) throw new BcrfWeightError('The date can\'t be in the future.');
    return $v;
}

/** The weighing before $date (or before this one on the same date) for an animal, not voided. */
function bcrf_weight_previous(PDO $pdo, int $animalId, string $date, int $beforeId = 0): ?array {
    $st = $pdo->prepare("SELECT id, weighed_on, weight_lb FROM animal_weights WHERE animal_id = ? AND voided_at IS NULL
                           AND (weighed_on < ? OR (weighed_on = ? AND id < ?)) ORDER BY weighed_on DESC, id DESC LIMIT 1");
    $st->execute([$animalId, $date, $date, $beforeId ?: PHP_INT_MAX]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Weigh several animals on one date. $rows = [animalId => ['weight' => '48.5', 'body_condition' => '3', 'note' => '']];
 * rows with a blank weight are skipped. All or nothing. Returns ['saved' => n, 'ids' => [...], 'flagged' => [['name', 'pct'], ...]].
 */
function bcrf_weight_add_many(PDO $pdo, string $date, array $rows, ?int $uid = null): array {
    $date = bcrf_weight_date($date);
    $alert = bcrf_weight_alert_pct($pdo);
    $todo = [];
    foreach ($rows as $animalId => $r) {
        $w = trim((string)($r['weight'] ?? ''));
        if ($w === '') continue;
        $a = bcrf_animal_get($pdo, (int)$animalId);
        if (!$a) throw new BcrfWeightError('That animal is not on the roster.');
        if (bcrf_animal_is_leaving((string)$a['status'])) throw new BcrfWeightError($a['name'] . ' has left the farm.');
        $todo[] = [(int)$animalId, $a['name'], bcrf_weight_parse($w, $a['name']), bcrf_weight_bcs((string)($r['body_condition'] ?? ''), $a['name']),
                   mb_substr(trim((string)($r['note'] ?? '')), 0, 255) ?: null];
    }
    if (!$todo) throw new BcrfWeightError('Enter at least one weight.');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    $out = ['saved' => 0, 'ids' => [], 'flagged' => []];
    try {
        $ins = $pdo->prepare("INSERT INTO animal_weights (animal_id, weighed_on, weight_lb, body_condition, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)");
        foreach ($todo as [$aid, $name, $lb, $bcs, $note]) {
            $prev = bcrf_weight_previous($pdo, $aid, $date);
            $ins->execute([$aid, $date, $lb, $bcs, $note, $uid]);
            $out['ids'][] = (int)$pdo->lastInsertId();
            $out['saved']++;
            if ($prev) {
                $c = bcrf_weight_change((float)$prev['weight_lb'], $lb, $alert);
                if ($c['flag']) $out['flagged'][] = [$name, $c['pct']];
            }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/** One animal. Returns the same shape as bcrf_weight_add_many. */
function bcrf_weight_add(PDO $pdo, int $animalId, array $in, ?int $uid = null): array {
    return bcrf_weight_add_many($pdo, (string)($in['weighed_on'] ?? ''), [$animalId => $in], $uid);
}

function bcrf_weight_void(PDO $pdo, int $id, string $reason, ?int $uid = null): int {
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new BcrfWeightError('Say why (e.g. wrong animal).');
    $st = $pdo->prepare("SELECT animal_id, voided_at FROM animal_weights WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new BcrfWeightError('That weighing doesn\'t exist.');
    if ($r['voided_at']) throw new BcrfWeightError('It is already voided.');
    $pdo->prepare("UPDATE animal_weights SET voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ?")->execute([$uid, $reason, $id]);
    return (int)$r['animal_id'];
}

/**
 * An animal's weighings, newest first. Each row has 'change' (percent from the weighing before, or null) and 'flag'.
 * Voided rows are included (marked) but never count as the weighing before.
 */
function bcrf_weight_history(PDO $pdo, int $animalId): array {
    $st = $pdo->prepare("SELECT w.*, CONCAT(u.first_name, ' ', u.last_name) AS by_name FROM animal_weights w LEFT JOIN users u ON u.id = w.created_by
                          WHERE w.animal_id = ? ORDER BY w.weighed_on, w.id");
    $st->execute([$animalId]);
    $alert = bcrf_weight_alert_pct($pdo);
    $prev = null; $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['change'] = null; $r['flag'] = false;
        if (!$r['voided_at']) {
            if ($prev !== null) { $c = bcrf_weight_change($prev, (float)$r['weight_lb'], $alert); $r['change'] = $c['pct']; $r['flag'] = $c['flag']; }
            $prev = (float)$r['weight_lb'];
        }
        $rows[] = $r;
    }
    return array_reverse($rows);
}

/** Latest weighing per animal: [animal_id => ['weight_lb', 'weighed_on', 'body_condition']]. */
function bcrf_weight_latest(PDO $pdo): array {
    $rows = $pdo->query("SELECT animal_id, weighed_on, weight_lb, body_condition FROM animal_weights WHERE voided_at IS NULL ORDER BY animal_id, weighed_on, id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['animal_id']] = $r;
    return $out;
}

/**
 * Animals still on the farm whose latest weighing (in the last 60 days) changed by the alert percentage or more.
 * Rows: animal_id, animal_name, from_lb, from_on, to_lb, to_on, pct. Biggest change first.
 */
function bcrf_weight_alerts(PDO $pdo, int $animalId = 0, ?string $today = null): array {
    if (!bcrf_weights_ready($pdo)) return [];
    $today = $today ?? bcrf_weight_today();
    $since = (new DateTimeImmutable($today))->modify('-' . BCRF_WEIGHT_ALERT_DAYS . ' days')->format('Y-m-d');
    $alert = bcrf_weight_alert_pct($pdo);
    $sql = "SELECT w.animal_id, a.name AS animal_name, w.weighed_on, w.weight_lb FROM animal_weights w JOIN animals a ON a.id = w.animal_id
             WHERE w.voided_at IS NULL AND a.status NOT IN ('sold','transferred','adopted','deceased')" . ($animalId ? ' AND w.animal_id = ?' : '') . "
             ORDER BY w.animal_id, w.weighed_on, w.id";
    $st = $pdo->prepare($sql);
    $st->execute($animalId ? [$animalId] : []);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(int)$r['animal_id']][] = $r;
    $out = [];
    foreach ($by as $aid => $ws) {
        $n = count($ws);
        if ($n < 2) continue;
        [$p, $c] = [$ws[$n - 2], $ws[$n - 1]];
        if ($c['weighed_on'] < $since) continue;
        $ch = bcrf_weight_change((float)$p['weight_lb'], (float)$c['weight_lb'], $alert);
        if (!$ch['flag']) continue;
        $out[] = ['animal_id' => $aid, 'animal_name' => $c['animal_name'], 'from_lb' => (float)$p['weight_lb'], 'from_on' => $p['weighed_on'],
                  'to_lb' => (float)$c['weight_lb'], 'to_on' => $c['weighed_on'], 'pct' => $ch['pct']];
    }
    usort($out, fn($x, $y) => abs($y['pct']) <=> abs($x['pct']));
    return $out;
}

function bcrf_weight_alert_text(array $al): string {
    return 'Weight ' . ($al['pct'] < 0 ? 'down ' : 'up ') . abs($al['pct']) . '% since ' . date('M j', strtotime($al['from_on']))
         . ' (' . rtrim(bcrf_weight_fmt($al['from_lb']), ' lb') . ' to ' . bcrf_weight_fmt($al['to_lb']) . ')';
}

/** A simple line chart of weight over time (oldest to newest), as inline SVG. Needs 2+ weighings. */
function bcrf_weight_chart_svg(array $history): string {
    $pts = array_values(array_reverse(array_filter($history, fn($r) => !$r['voided_at'])));
    if (count($pts) < 2) return '';
    $W = 400; $H = 200; $L = 40; $R = 12; $T = 12; $B = 28;
    $t0 = strtotime($pts[0]['weighed_on']); $t1 = strtotime($pts[count($pts) - 1]['weighed_on']);
    $span = max(1, $t1 - $t0);
    $ws = array_map(fn($r) => (float)$r['weight_lb'], $pts);
    $lo = min($ws); $hi = max($ws);
    $pad = max(1.0, ($hi - $lo) * 0.15);
    $lo = max(0, floor($lo - $pad)); $hi = ceil($hi + $pad);
    $x = fn($r) => round($L + (strtotime($r['weighed_on']) - $t0) / $span * ($W - $L - $R), 1);
    $y = fn($w) => round($T + ($hi - $w) / max(0.01, $hi - $lo) * ($H - $T - $B), 1);
    $line = implode(' ', array_map(fn($r) => $x($r) . ',' . $y((float)$r['weight_lb']), $pts));
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="w-full h-auto" role="img" aria-label="Weight from ' . $e(date('M j, Y', $t0)) . ' to ' . $e(date('M j, Y', $t1)) . '">';
    foreach ([$lo, ($lo + $hi) / 2, $hi] as $g) {
        $gy = $y($g);
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $gy . '" y2="' . $gy . '" class="stroke-stone-200" stroke-width="1"/>'
              . '<text x="' . ($L - 6) . '" y="' . ($gy + 4) . '" text-anchor="end" class="fill-stone-500" font-size="13">' . $e(rtrim(rtrim(number_format($g, 1, '.', ''), '0'), '.')) . '</text>';
    }
    $svg .= '<text x="' . $L . '" y="' . ($H - 8) . '" class="fill-stone-500" font-size="13">' . $e(date('M j, Y', $t0)) . '</text>'
          . '<text x="' . ($W - $R) . '" y="' . ($H - 8) . '" text-anchor="end" class="fill-stone-500" font-size="13">' . $e(date('M j, Y', $t1)) . '</text>'
          . '<polyline points="' . $line . '" fill="none" class="stroke-amber-700" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
    foreach ($pts as $r) {
        $svg .= '<circle cx="' . $x($r) . '" cy="' . $y((float)$r['weight_lb']) . '" r="3.5" class="fill-amber-700"><title>' . $e(date('M j, Y', strtotime($r['weighed_on'])) . ': ' . bcrf_weight_fmt($r['weight_lb'])) . '</title></circle>';
    }
    return $svg . '</svg>';
}

}
