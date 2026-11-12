<?php
// includes/parties.php — Farm OS Phase 3: party packages, guest lists and assigned staff (Nov 12, 2026;
// migration 0035; 07 › Private events). A party is an event_inquiries row.
//   Packages   name, inclusions, base price for up to N guests, price for each guest beyond that, length. The quote's
//              starting price lines come from the party's package (includes/event_quotes.php); the capacity hold
//              and the calendar use its length. No package picked = the farm's per-guest rate, as before.
//   Guests     an optional list for the day: name, child or adult, a note, arrived or not.
//   Staff      who is working the party. Assigned staff see it on their calendar and phone calendar, and can open the
//              party page to check guests in, even without access to Private Events.
// Money is integer cents; times are UTC in the database.
if (!function_exists('bcrf_party_ready')) {

class BcrfPartyError extends RuntimeException {}

function bcrf_party_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM party_packages LIMIT 0"); $pdo->query("SELECT package_id FROM event_inquiries LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function bcrf_party_money(int $cents): string {
    return '$' . ($cents % 100 ? number_format($cents / 100, 2) : number_format(intdiv($cents, 100)));
}

// ── Packages ────────────────────────────────────────────────────────────────────────────────────

function bcrf_party_decode_pkg(array $p): array {
    foreach (['id', 'base_cents', 'included_guests', 'extra_guest_cents', 'duration_minutes', 'active', 'sort_order'] as $k) $p[$k] = (int)$p[$k];
    $p['items'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($p['inclusions'] ?? ''))), 'strlen'));
    return $p;
}

function bcrf_party_packages(PDO $pdo, bool $all = false): array {
    try {
        $rows = $pdo->query("SELECT * FROM party_packages" . ($all ? '' : ' WHERE active = 1') . " ORDER BY active DESC, sort_order, name, id")->fetchAll(PDO::FETCH_ASSOC);
        return array_map('bcrf_party_decode_pkg', $rows);
    } catch (Throwable $e) { return []; }
}

function bcrf_party_package(PDO $pdo, ?int $id): ?array {
    if (!$id) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM party_packages WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? bcrf_party_decode_pkg($r) : null;
    } catch (Throwable $e) { return null; }
}

/** "$10 a guest" · "$150 for up to 10 guests, then $12 a guest" · "$300 for up to 25 guests" */
function bcrf_party_price_text(array $p): string {
    $base = (int)$p['base_cents']; $inc = (int)$p['included_guests']; $extra = (int)$p['extra_guest_cents'];
    if ($base <= 0) return $extra > 0 ? bcrf_party_money($extra) . ' a guest' : 'Free';
    $out = bcrf_party_money($base) . ($inc > 0 ? ' for up to ' . $inc . ' guest' . ($inc === 1 ? '' : 's') : '');
    if ($extra > 0) $out .= $inc > 0 ? ', then ' . bcrf_party_money($extra) . ' a guest' : ', plus ' . bcrf_party_money($extra) . ' a guest';
    return $out;
}

/** Quote lines for a package and a guest count (the same shape as bcrf_quote_default_lines). */
function bcrf_party_price_lines(array $p, int $guests): array {
    $guests = max(1, $guests);
    $base = (int)$p['base_cents']; $inc = (int)$p['included_guests']; $extra = (int)$p['extra_guest_cents'];
    $lines = [];
    if ($base > 0) {
        $lines[] = ['label' => $p['name'] . ($inc > 0 ? ' (up to ' . $inc . ' guests)' : ''), 'qty' => 1, 'unit_cents' => $base];
        $over = $inc > 0 ? max(0, $guests - $inc) : $guests;
        if ($extra > 0 && $over > 0) $lines[] = ['label' => $inc > 0 ? 'Extra guests' : 'Guests', 'qty' => $over, 'unit_cents' => $extra];
    } elseif ($extra > 0) {
        $lines[] = ['label' => $p['name'] . ' (per guest)', 'qty' => $guests, 'unit_cents' => $extra];
    }
    return $lines;
}

/** Checks a package form. Returns the clean row, or throws BcrfPartyError with a message for staff. */
function bcrf_party_package_clean(array $in): array {
    $cents = function ($v, string $what): int {
        $v = trim(str_replace(['$', ','], '', (string)$v));
        if ($v === '') return 0;
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v)) throw new BcrfPartyError($what . ' must be an amount like 10 or 12.50.');
        return (int)round((float)$v * 100);
    };
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) throw new BcrfPartyError('Give the package a name (up to 80 letters).');
    $out = [
        'name' => $name,
        'inclusions' => trim(mb_substr((string)($in['inclusions'] ?? ''), 0, 2000)),
        'base_cents' => $cents($in['base'] ?? '', 'The package price'),
        'included_guests' => (int)($in['included_guests'] ?? 0),
        'extra_guest_cents' => $cents($in['extra'] ?? '', 'The price per guest'),
        'duration_minutes' => (int)($in['duration'] ?? 0),
        'active' => !empty($in['active']) ? 1 : 0,
        'sort_order' => (int)($in['sort_order'] ?? 0),
    ];
    if ($out['included_guests'] < 0 || $out['included_guests'] > 500) throw new BcrfPartyError('Guests included must be between 0 and 500.');
    if ($out['duration_minutes'] < 15 || $out['duration_minutes'] > 720) throw new BcrfPartyError('Length must be between 15 minutes and 12 hours.');
    if ($out['base_cents'] === 0 && $out['extra_guest_cents'] === 0) throw new BcrfPartyError('Set a package price, a price per guest, or both.');
    if ($out['base_cents'] === 0 && $out['included_guests'] > 0) throw new BcrfPartyError('Guests included only applies with a package price.');
    return $out;
}

function bcrf_party_package_save(PDO $pdo, ?int $id, array $in): int {
    $r = bcrf_party_package_clean($in);
    $cols = array_keys($r);
    if ($id) {
        if (!bcrf_party_package($pdo, $id)) throw new BcrfPartyError('That package is gone.');
        $pdo->prepare("UPDATE party_packages SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ", updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([...array_values($r), $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO party_packages (" . implode(', ', $cols) . ", created_at, updated_at) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute(array_values($r));
    return (int)$pdo->lastInsertId();
}

/** Minutes a party lasts: its package's length, or the farm's party_length_minutes. */
function bcrf_party_length(PDO $pdo, array $inq): int {
    $p = bcrf_party_package($pdo, (int)($inq['package_id'] ?? 0));
    if ($p) return max(15, $p['duration_minutes']);
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'party_length_minutes'");
        $st->execute();
        $v = $st->fetchColumn();
        return $v !== false && is_numeric($v) && (int)$v >= 15 ? (int)$v : 120;
    } catch (Throwable $e) { return 120; }
}

function bcrf_party_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM event_inquiries WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bcrf_party_set_package(PDO $pdo, int $inqId, ?int $pkgId): void {
    if ($pkgId) {
        $p = bcrf_party_package($pdo, $pkgId);
        if (!$p) throw new BcrfPartyError('Pick a package from the list.');
        $cur = bcrf_party_get($pdo, $inqId);
        if (!$p['active'] && (int)($cur['package_id'] ?? 0) !== $pkgId) throw new BcrfPartyError('That package is turned off.');
    }
    $pdo->prepare("UPDATE event_inquiries SET package_id = ? WHERE id = ?")->execute([$pkgId ?: null, $inqId]);
}

// ── Guest list ──────────────────────────────────────────────────────────────────────────────────

function bcrf_party_guests(PDO $pdo, int $inqId): array {
    $st = $pdo->prepare("SELECT * FROM party_guests WHERE event_inquiry_id = ? ORDER BY is_child, name, id");
    $st->execute([$inqId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Adds guests from typed lines: "Name" or "Name, note". Returns how many were added. */
function bcrf_party_guests_add(PDO $pdo, int $inqId, string $text, bool $children): int {
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), 'strlen'));
    if (!$lines) throw new BcrfPartyError('Type at least one name.');
    $have = (int)$pdo->query("SELECT COUNT(*) FROM party_guests WHERE event_inquiry_id = " . (int)$inqId)->fetchColumn();
    if ($have + count($lines) > 300) throw new BcrfPartyError('A guest list can hold up to 300 people.');
    $ins = $pdo->prepare("INSERT INTO party_guests (event_inquiry_id, name, is_child, note, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())");
    foreach ($lines as $l) {
        [$name, $note] = array_map('trim', array_pad(explode(',', $l, 2), 2, ''));
        if ($name === '') continue;
        $ins->execute([$inqId, mb_substr($name, 0, 120), $children ? 1 : 0, $note === '' ? null : mb_substr($note, 0, 255)]);
    }
    return count($lines);
}

function bcrf_party_guest_remove(PDO $pdo, int $inqId, int $guestId): void {
    $pdo->prepare("DELETE FROM party_guests WHERE id = ? AND event_inquiry_id = ?")->execute([$guestId, $inqId]);
}

function bcrf_party_guest_arrived(PDO $pdo, int $inqId, int $guestId, bool $arrived): void {
    $st = $pdo->prepare("UPDATE party_guests SET arrived_at = " . ($arrived ? 'COALESCE(arrived_at, UTC_TIMESTAMP())' : 'NULL') . " WHERE id = ? AND event_inquiry_id = ?");
    $st->execute([$guestId, $inqId]);
    if (!$st->rowCount() && !$pdo->query("SELECT 1 FROM party_guests WHERE id = " . (int)$guestId . " AND event_inquiry_id = " . (int)$inqId)->fetchColumn()) {
        throw new BcrfPartyError('That guest isn\'t on this party\'s list.');
    }
}

/** ['children' => n, 'adults' => n, 'arrived' => n, 'total' => n] */
function bcrf_party_guest_counts(array $guests): array {
    $c = ['children' => 0, 'adults' => 0, 'arrived' => 0, 'total' => count($guests)];
    foreach ($guests as $g) { $c[$g['is_child'] ? 'children' : 'adults']++; if ($g['arrived_at']) $c['arrived']++; }
    return $c;
}

// ── Staff ───────────────────────────────────────────────────────────────────────────────────────

function bcrf_party_staff(PDO $pdo, int $inqId): array {
    $st = $pdo->prepare("SELECT ps.user_id, ps.job, u.first_name, u.last_name, u.role FROM party_staff ps JOIN users u ON u.id = ps.user_id
                         WHERE ps.event_inquiry_id = ? ORDER BY ps.created_at, u.first_name");
    $st->execute([$inqId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_party_staff_add(PDO $pdo, int $inqId, int $userId, string $job): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role <> 'user' AND account_status = 'active'");
    $st->execute([$userId]);
    if (!$userId || !(int)$st->fetchColumn()) throw new BcrfPartyError('Pick someone on the staff list.');
    $job = trim(mb_substr($job, 0, 80));
    $pdo->prepare("INSERT INTO party_staff (event_inquiry_id, user_id, job, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE job = VALUES(job)")->execute([$inqId, $userId, $job === '' ? null : $job]);
}

function bcrf_party_staff_remove(PDO $pdo, int $inqId, int $userId): void {
    $pdo->prepare("DELETE FROM party_staff WHERE event_inquiry_id = ? AND user_id = ?")->execute([$inqId, $userId]);
}

function bcrf_party_is_staff(PDO $pdo, int $inqId, int $userId): bool {
    if ($userId <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM party_staff WHERE event_inquiry_id = ? AND user_id = ?");
        $st->execute([$inqId, $userId]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/** Package name, guest-list and staff summary for a set of parties (the Private Events list). */
function bcrf_party_summaries(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !bcrf_party_ready($pdo)) return [];
    $in = implode(',', $ids);
    $out = array_fill_keys($ids, ['package' => '', 'guests' => 0, 'arrived' => 0, 'staff' => []]);
    foreach ($pdo->query("SELECT e.id, p.name FROM event_inquiries e JOIN party_packages p ON p.id = e.package_id WHERE e.id IN ($in)") as $r) $out[(int)$r['id']]['package'] = (string)$r['name'];
    foreach ($pdo->query("SELECT event_inquiry_id AS id, COUNT(*) AS n, SUM(arrived_at IS NOT NULL) AS a FROM party_guests WHERE event_inquiry_id IN ($in) GROUP BY event_inquiry_id") as $r) {
        $out[(int)$r['id']]['guests'] = (int)$r['n']; $out[(int)$r['id']]['arrived'] = (int)$r['a'];
    }
    foreach ($pdo->query("SELECT ps.event_inquiry_id AS id, u.first_name FROM party_staff ps JOIN users u ON u.id = ps.user_id WHERE ps.event_inquiry_id IN ($in) ORDER BY ps.created_at") as $r) {
        $out[(int)$r['id']]['staff'][] = (string)$r['first_name'];
    }
    return $out;
}

}
