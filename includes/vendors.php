<?php
// includes/vendors.php — vendors and product cost (Farm OS Phase 2, slice 2.1, Nov 9, 2026; 06 Purchasing & Inventory)
//   bcrf_vendors_ready($pdo)
//   bcrf_vendor_list / get / save
//   bcrf_vendors_has_web($pdo) — website + address columns exist (migration 0065, Nov 14, 2026)
//   bcrf_vendor_web_url / bcrf_vendor_tel / bcrf_vendor_map_url — links for the Call, Open and Map buttons
//   bcrf_vendor_phone_pretty / bcrf_vendor_web_label — how the list's contact buttons read (Nov 15, 2026)
//   bcrf_product_set_cost — set avg_cost_cents and append product_cost_history
//   bcrf_product_apply_receive_cost — moving-average update used by receiving (slice 2.2+)
declare(strict_types=1);

if (!function_exists('bcrf_vendors_ready')) {

function bcrf_vendors_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $t = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'vendors'")->fetchColumn();
        $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'avg_cost_cents'")->fetchColumn();
        return $ready = ($t === 1 && $c === 1);
    } catch (Throwable $e) { return $ready = false; }
}

/** Website and address columns (migration 0065). Before it runs, the page hides those boxes and Save skips them. */
function bcrf_vendors_has_web(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'vendors' AND column_name IN ('website','address')")->fetchColumn() === 2;
    } catch (Throwable $e) { $has = false; }
    return $has;
}

/** Tidy a typed website into a full http(s) link: "tractorsupply.com" -> "https://tractorsupply.com". '' stays ''. Throws on anything that isn't a web link. */
function bcrf_vendor_web_url(string $raw): string {
    $u = trim($raw);
    if ($u === '') return '';
    if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $u)) $u = 'https://' . ltrim($u, '/');
    $scheme = strtolower((string)parse_url($u, PHP_URL_SCHEME));
    $host = (string)parse_url($u, PHP_URL_HOST);
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !str_contains($host, '.') || !filter_var($u, FILTER_VALIDATE_URL) || preg_match('/\s/', $u)) {
        throw new InvalidArgumentException('Check the website (for example tractorsupply.com).');
    }
    return $u;
}

/** tel: link target: digits and a leading +, '' when there are fewer than 7 digits.
 *  An extension ("x12", "ext. 12") is dialled after a pause: "828-555-0100 x12" -> "8285550100,12". */
function bcrf_vendor_tel(?string $phone): string {
    $parts = preg_split('/\s*(?:x|ext\.?|extension)\s*/i', (string)$phone, 2);
    $d = preg_replace('/[^0-9+]/', '', $parts[0]);
    $d = ($d !== '' && $d[0] === '+' ? '+' : '') . str_replace('+', '', $d);
    if (strlen(ltrim($d, '+')) < 7) return '';
    $ext = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : '';
    return $ext !== '' ? $d . ',' . $ext : $d;
}

/** Google Maps search link for an address (opens the Maps app on iPhone and Android). */
function bcrf_vendor_map_url(?string $address): string {
    $a = trim(preg_replace('/\s+/', ' ', (string)$address));
    return $a === '' ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($a);
}

/** US numbers shown as (828) 555-0100; anything else (extensions, +44 …) exactly as saved. */
function bcrf_vendor_phone_pretty(?string $phone): string {
    $s = trim((string)$phone);
    if ($s === '' || preg_match('/[a-z]/i', $s)) return $s;
    $d = preg_replace('/\D/', '', $s);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    elseif ($s[0] === '+') return $s;
    return strlen($d) === 10 ? '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6) : $s;
}

/** "https://www.tractorsupply.com/store/x" -> "tractorsupply.com" for the Website button. */
function bcrf_vendor_web_label(string $url): string {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\./', '', $host);
}

function bcrf_vendor_list(PDO $pdo, array $f = []): array {
    $w = ['archived_at IS NULL']; $a = [];
    $status = $f['status'] ?? 'active';
    if ($status === 'active') $w[] = 'is_active = 1';
    if ($status === 'off') $w[] = 'is_active = 0';
    if (($f['q'] ?? '') !== '') {
        $w[] = '(name LIKE ? OR contact_name LIKE ? OR account_number LIKE ? OR email LIKE ?)';
        $q = '%' . $f['q'] . '%';
        array_push($a, $q, $q, $q, $q);
    }
    $web = bcrf_vendors_has_web($pdo) ? ', website, address' : '';
    $st = $pdo->prepare('SELECT id, name, contact_name, email, phone' . $web . ', account_number, payment_terms, is_active
                         FROM vendors WHERE ' . implode(' AND ', $w) . ' ORDER BY name LIMIT 500');
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_vendor_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM vendors WHERE id = ? AND archived_at IS NULL');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Active vendors for <select>: id => name. */
function bcrf_vendor_options(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT id, name FROM vendors WHERE is_active = 1 AND archived_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = $r['name'];
    }
    return $out;
}

/**
 * Create or update a vendor. Throws InvalidArgumentException.
 * $in: name, contact_name, email, phone, website, address, account_number, payment_terms, notes, is_active
 * website/address are skipped (left as they are) until migration 0065 has run.
 */
function bcrf_vendor_save(PDO $pdo, ?int $id, array $in, ?int $uid): int {
    $bad = fn(string $m) => throw new InvalidArgumentException($m);
    $str = fn(string $k, int $max) => mb_substr(trim(str_replace("\r", '', (string)($in[$k] ?? ''))), 0, $max);

    $name = $str('name', 120);
    if ($name === '') $bad('Give the vendor a name.');
    $email = $str('email', 190);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $bad('Check the email address.');
    $v = [
        'name'           => $name,
        'contact_name'   => $str('contact_name', 120) ?: null,
        'email'          => $email ?: null,
        'phone'          => $str('phone', 40) ?: null,
        'website'        => bcrf_vendor_web_url($str('website', 255)) ?: null,
        'address'        => trim(preg_replace('/\s+/', ' ', $str('address', 255))) ?: null,
        'account_number' => $str('account_number', 80) ?: null,
        'payment_terms'  => $str('payment_terms', 80) ?: null,
        'notes'          => $str('notes', 5000) ?: null,
        'is_active'      => !empty($in['is_active']) ? 1 : 0,
    ];

    $old = $id ? bcrf_vendor_get($pdo, $id) : null;
    if ($id && !$old) $bad('That vendor no longer exists.');

    if (strlen((string)$v['website']) > 255) $bad('That website link is too long.');
    if (!bcrf_vendors_has_web($pdo)) unset($v['website'], $v['address']);
    $cols = array_keys($v);
    if ($old) {
        $set = implode(', ', array_map(fn($c) => "$c=?", $cols));
        $pdo->prepare("UPDATE vendors SET $set, updated_at=UTC_TIMESTAMP(), updated_by=? WHERE id=?")
            ->execute([...array_values($v), $uid, $id]);
    } else {
        $pdo->prepare('INSERT INTO vendors (' . implode(', ', $cols) . ', created_at, created_by) VALUES (' . str_repeat('?,', count($cols)) . 'UTC_TIMESTAMP(),?)')
            ->execute([...array_values($v), $uid]);
        $id = (int)$pdo->lastInsertId();
    }

    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$t, $aid, $label, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, [
            'actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $label, 'ip' => $ip,
            'action' => $old ? 'update' : 'create', 'object_type' => 'vendors', 'object_id' => (string)$id,
            'object_label' => $v['name'], 'before' => $old ?: null, 'after' => $v,
        ]);
    }
    return $id;
}

/**
 * Set product average cost (cents) and append history. Call inside or outside a transaction.
 * $source: manual | receive | opening
 */
function bcrf_product_set_cost(PDO $pdo, int $productId, int $costCents, ?int $uid, string $source = 'manual', ?int $sourceId = null, ?string $note = null): void {
    if ($costCents < 0 || $costCents > 100000000) throw new InvalidArgumentException('Cost must be between $0 and $1,000,000.');
    if (!bcrf_vendors_ready($pdo)) return;

    $st = $pdo->prepare('SELECT id, name, avg_cost_cents, stock_quantity FROM inventory WHERE id = ? FOR UPDATE');
    $st->execute([$productId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new InvalidArgumentException('That product no longer exists.');

    $prev = $p['avg_cost_cents'] !== null ? (int)$p['avg_cost_cents'] : null;
    if ($prev !== null && $prev === $costCents) return; // no change

    $pdo->prepare('UPDATE inventory SET avg_cost_cents = ? WHERE id = ?')->execute([$costCents, $productId]);
    $pdo->prepare('INSERT INTO product_cost_history (product_id, cost_cents, previous_cents, quantity_on_hand, source, source_id, note, created_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?)')
        ->execute([$productId, $costCents, $prev, $p['stock_quantity'] !== null ? (float)$p['stock_quantity'] : null, $source, $sourceId, $note !== null ? mb_substr($note, 0, 255) : null, $uid]);

    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor') && $source === 'manual') {
        [$t, $aid, $label, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, [
            'actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $label, 'ip' => $ip,
            'action' => 'update', 'object_type' => 'inventory', 'object_id' => (string)$productId,
            'object_label' => $p['name'] . ' (cost $' . number_format(($prev ?? 0) / 100, 2) . ' → $' . number_format($costCents / 100, 2) . ')',
            'before' => ['avg_cost_cents' => $prev], 'after' => ['avg_cost_cents' => $costCents],
        ]);
    }
}

/**
 * Apply a receive quantity at a unit cost into the moving average.
 * new_avg = (on_hand * old_avg + qty * unit_cost) / (on_hand + qty)
 * Call after stock has been increased, or pass $onHandBeforeReceive.
 */
function bcrf_product_apply_receive_cost(PDO $pdo, int $productId, float $qtyReceived, int $unitCostCents, float $onHandBefore, ?int $uid, ?int $receiveId = null): void {
    if ($qtyReceived <= 0) return;
    if (!bcrf_vendors_ready($pdo)) return;
    $st = $pdo->prepare('SELECT avg_cost_cents FROM inventory WHERE id = ? FOR UPDATE');
    $st->execute([$productId]);
    $old = $st->fetchColumn();
    $oldCents = $old !== false && $old !== null ? (int)$old : 0;
    $before = max(0.0, $onHandBefore);
    $totalQty = $before + $qtyReceived;
    if ($totalQty <= 0) return;
    // Work in milli-units to reduce float drift, then cents.
    $newAvg = (int)round((($before * $oldCents) + ($qtyReceived * $unitCostCents)) / $totalQty);
    bcrf_product_set_cost($pdo, $productId, max(0, $newAvg), $uid, 'receive', $receiveId, 'Received ' . rtrim(rtrim(number_format($qtyReceived, 3, '.', ''), '0'), '.') . ' @ $' . number_format($unitCostCents / 100, 2));
}

}
