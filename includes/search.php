<?php
// includes/search.php — Farm OS global search (02 › Search; Nov 13, 2026).
// One search over the records a staff member can open, grouped by type. A type the user can't open is never searched,
// so its records never come back (02: "Never returns records the user cannot open"). Ticket codes, receipt and refund
// numbers, gift card codes, pass links and lot codes jump straight to the record.
//   bcrf_search($pdo, 'hazel', $can)   →  ['jump' => null | [label, url], 'groups' => [[key, label, icon, rows, more], ...]]
//   $can(['view', 'customers']) / $can(['table', 'inventory', 'edit']) / $can(['admin'])  →  bool
// Page: admin/search.php. Nothing searched is stored on the server (02: no server-side search history).

if (!function_exists('bcrf_search')) {

if (!defined('BCRF_SEARCH_PER_GROUP')) define('BCRF_SEARCH_PER_GROUP', 5);

/** Who can see each kind of record. Any one check passing is enough (same shape as require_permission()). */
function bcrf_search_kinds(): array {
    return [
        'customers'  => ['Customers',        '👤', [['view', 'customers']]],
        'orgs'       => ['Organizations',    '🏫', [['view', 'customers']]],
        'tickets'    => ['Tickets',          '🎟️', [['view', 'pos'], ['view', 'passes']]],
        'passes'     => ['Annual passes',    '🪪', [['view', 'passes']]],
        'bookings'   => ['Bookings',         '📋', [['view', 'bookings']]],
        'parties'    => ['Private events',   '🎉', [['view', 'event_inquiries']]],
        'sales'      => ['Sales',            '🧾', [['view', 'receipts']]],
        'gift_cards' => ['Gift cards',       '🎁', [['view', 'pos']]],
        'products'   => ['Products',         '🛍️', [['table', 'inventory', 'edit'], ['view', 'inventory_update']]],
        'animals'    => ['Animals',          '🐐', [['table', 'animals']]],
        'incidents'  => ['Incidents',        '🩹', [['view', 'incidents']]],
        'recipes'    => ['Recipes',          '🍳', [['table', 'recipes']]],
        'lots'       => ['Kitchen lots',     '🏷️', [['table', 'recipes']]],
        'blog'       => ['Blog posts',       '📝', [['table', 'blog_posts']]],
        'events'     => ['Public events',    '📅', [['view', 'events']]],
        'waivers'    => ['Waivers',          '✍️', [['view', 'customers']]],
        'vendors'    => ['Vendors',          '🚚', [['table', 'inventory', 'edit']]],
        'pos'        => ['Purchase orders',  '📦', [['table', 'inventory', 'edit']]],
        'staff'      => ['Staff',            '🧑‍🌾', [['admin']]],
    ];
}

/** True when any one of the checks passes. */
function bcrf_search_allowed(array $anyOf, callable $can): bool {
    foreach ($anyOf as $c) if ($can($c)) return true;
    return false;
}

/** The standard $can for the signed-in staff member (needs admin/includes/permission_checks.php loaded). */
function bcrf_search_can_for(string $role): callable {
    return function (array $c) use ($role): bool {
        if ($role === 'administrator') return true;
        if ($role === '' || $role === 'user') return false;
        $type = $c[0] ?? '';
        if ($type === 'view')  return function_exists('hasViewAccess') && hasViewAccess($role, (string)$c[1]);
        if ($type === 'table') return function_exists('hasTableAccess') && hasTableAccess($role, (string)$c[1], (string)($c[2] ?? 'view'));
        return false;   // 'admin'
    };
}

function bcrf_search_like(string $q): string { return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'; }

/** Digits only, when the search looks like a phone number (4 or more digits). */
function bcrf_search_digits(string $q): string {
    $d = preg_replace('/\D/', '', $q);
    return (strlen($d) >= 4 && preg_match('/^[\d\s().+-]+$/', $q)) ? $d : '';
}

/** SQL that strips the usual phone punctuation from a column. */
function bcrf_search_phone_sql(string $col): string {
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col,''),'-',''),' ',''),'(',''),')',''),'.','')";
}

function bcrf_search_rows(PDO $pdo, string $sql, array $args): array {
    try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('[search] ' . $e->getMessage()); return []; }   // a module not installed yet
}

function bcrf_search_money(int $c): string { return '$' . number_format($c / 100, 2); }

/**
 * A code that names exactly one record: ['label' => ..., 'url' => ...], or null.
 * Only kinds the user can open are tried.
 */
function bcrf_search_jump(PDO $pdo, string $q, callable $can): ?array {
    $kinds = bcrf_search_kinds();
    $ok = fn(string $k) => bcrf_search_allowed($kinds[$k][2], $can);
    $t = trim($q);
    if ($t === '') return null;

    // Receipt R-2026-000012, or refund RF-2026-000003 (opens its sale)
    if ($ok('sales') && preg_match('/^(RF?)-(\d{4})-(\d{1,6})$/i', $t, $m)) {
        $num = sprintf('%s-%s-%06d', strtoupper($m[1]), $m[2], (int)$m[3]);
        $sql = strtoupper($m[1]) === 'RF'
            ? "SELECT s.sale_number FROM refunds r JOIN sales s ON s.id = r.sale_id WHERE r.refund_number = ?"
            : "SELECT sale_number FROM sales WHERE sale_number = ?";
        $row = bcrf_search_rows($pdo, $sql, [$num])[0] ?? null;
        if ($row) return ['label' => (strtoupper($m[1]) === 'RF' ? "Refund $num on sale " : 'Sale ') . $row['sale_number'],
                          'url' => '/admin/admin.php?view=receipts&search_q=' . rawurlencode($row['sale_number'])];
    }
    // Pass link (passes/verify.php?token=…) or a bare pass token
    if ($ok('tickets') && (preg_match('/[?&]token=([A-Za-z0-9]{16,64})/', $t, $m) || preg_match('/^[a-f0-9]{48}$/i', $t))) {
        $tok = $m[1] ?? $t;
        $row = bcrf_search_rows($pdo, "SELECT holder_name FROM passes WHERE token = ?", [$tok])[0] ?? null;
        if ($row) return ['label' => 'Pass: ' . $row['holder_name'], 'url' => '/admin/gate.php?q=' . rawurlencode($tok)];
    }
    // Gift card code (16 characters, or the card link …?g=CODE). Checked before tickets: a ticket code is 10.
    if ($ok('gift_cards')) {
        $g = $t;
        if (preg_match('/[?&]g=([A-Za-z0-9-]+)/', $g, $m)) $g = $m[1];
        $g = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $g));
        if (preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{16}$/', $g)) {
            $row = bcrf_search_rows($pdo, "SELECT id, balance_cents FROM gift_cards WHERE code = ?", [$g])[0] ?? null;
            if ($row) return ['label' => 'Gift card ····' . substr($g, -4) . ' (' . bcrf_search_money((int)$row['balance_cents']) . ' left)',
                              'url' => '/admin/gift_cards.php?id=' . (int)$row['id']];
        }
    }
    // Ticket code XXXX-XXXX-XX (also inside a ticket link …?c=CODE)
    if ($ok('tickets')) {
        $c = preg_match('/[?&]c=([A-Za-z0-9-]{10,14})/', $t, $m) ? $m[1] : $t;
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
        if (strlen($c) === 10 && preg_match('/^[A-Z2-9]{10}$/', $c)) {
            $row = bcrf_search_rows($pdo, "SELECT purchaser_name FROM tickets WHERE public_code = ?", [$c])[0] ?? null;
            if ($row) return ['label' => 'Ticket ' . substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8) . ' (' . $row['purchaser_name'] . ')',
                              'url' => '/admin/gate.php?q=' . rawurlencode($c)];
        }
    }
    // Kitchen lot code 261112-JAM-1
    if ($ok('lots') && preg_match('/^\d{6}-[A-Z0-9]+-\d+$/i', $t)) {
        $row = bcrf_search_rows($pdo, "SELECT lot_code FROM kitchen_batches WHERE lot_code = ?", [strtoupper($t)])[0] ?? null;
        if ($row) return ['label' => 'Lot ' . $row['lot_code'], 'url' => '/admin/kitchen_trace.php?lot=' . rawurlencode($row['lot_code'])];
    }
    return null;
}

/** Search every kind the user can open. */
function bcrf_search(PDO $pdo, string $q, callable $can): array {
    $q = trim(mb_substr($q, 0, 100));
    $out = ['q' => $q, 'jump' => null, 'groups' => []];
    if (mb_strlen($q) < 2) return $out;
    $out['jump'] = bcrf_search_jump($pdo, $q, $can);

    $like = bcrf_search_like($q);
    $dig  = bcrf_search_digits($q);
    $dl   = '%' . $dig . '%';
    $n    = BCRF_SEARCH_PER_GROUP + 1;   // one extra tells us there are more
    $enc  = rawurlencode($q);
    $date = fn($d, string $f = 'M j, Y') => $d ? date($f, strtotime((string)$d)) : '';
    $nm   = fn(array $r) => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $join = fn(...$p) => implode(' · ', array_filter(array_map('strval', $p), fn($x) => trim($x) !== ''));

    foreach (bcrf_search_kinds() as $key => [$label, $icon, $need]) {
        if (!bcrf_search_allowed($need, $can)) continue;
        $rows = []; $more = null;
        switch ($key) {
            case 'customers':
                $ph = $dig !== '' ? ' OR ' . bcrf_search_phone_sql('phone_number') . ' LIKE ?' : '';
                foreach (bcrf_search_rows($pdo, "SELECT id, first_name, last_name, email FROM customers
                        WHERE merged_into_id IS NULL AND (CONCAT_WS(' ', first_name, last_name) LIKE ? OR email LIKE ?$ph)
                        ORDER BY last_name, first_name LIMIT $n", array_merge([$like, $like], $dig !== '' ? [$dl] : [])) as $r)
                    $rows[] = [$nm($r) ?: $r['email'], $r['email'], '/admin/customers.php?id=' . (int)$r['id']];
                $more = '/admin/customers.php?q=' . $enc;
                break;
            case 'orgs':
                foreach (bcrf_search_rows($pdo, "SELECT id, name, kind, email FROM organizations WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT $n", [$like, $like]) as $r)
                    $rows[] = [$r['name'], trim(ucfirst((string)$r['kind']) . ' ' . ($r['email'] ? '· ' . $r['email'] : '')), '/admin/organizations.php?id=' . (int)$r['id']];
                $more = '/admin/organizations.php?q=' . $enc;
                break;
            case 'tickets':
                $ph = $dig !== '' ? ' OR ' . bcrf_search_phone_sql('purchaser_phone') . ' LIKE ?' : '';
                foreach (bcrf_search_rows($pdo, "SELECT public_code, purchaser_name, quantity, quantity_redeemed, status FROM tickets
                        WHERE status <> 'void' AND source = 'online' AND (purchaser_name LIKE ? OR purchaser_email LIKE ?$ph)
                        ORDER BY (quantity_redeemed < quantity) DESC, id DESC LIMIT $n", array_merge([$like, $like], $dig !== '' ? [$dl] : [])) as $r) {
                    $c = (string)$r['public_code'];
                    $rows[] = [$r['purchaser_name'], substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8) . ' · ' . (int)$r['quantity_redeemed'] . ' of ' . (int)$r['quantity'] . ' used',
                               '/admin/gate.php?q=' . rawurlencode($c)];
                }
                $more = '/admin/gate.php?q=' . $enc;
                break;
            case 'passes':
                $ph = $dig !== '' ? ' OR ' . bcrf_search_phone_sql('phone') . ' LIKE ?' : '';
                foreach (bcrf_search_rows($pdo, "SELECT holder_name, email, status, valid_until FROM passes
                        WHERE holder_name LIKE ? OR email LIKE ?$ph ORDER BY (status = 'active') DESC, id DESC LIMIT $n",
                        array_merge([$like, $like], $dig !== '' ? [$dl] : [])) as $r)
                    $rows[] = [$r['holder_name'], ucfirst((string)$r['status']) . ($r['valid_until'] ? ' · until ' . $date($r['valid_until']) : ''),
                               '/admin/admin.php?view=passes&search=' . rawurlencode((string)$r['holder_name'])];
                $more = '/admin/admin.php?view=passes&search=' . $enc;
                break;
            case 'bookings':
                $ph = $dig !== '' ? ' OR ' . bcrf_search_phone_sql('phone') . ' LIKE ?' : '';
                foreach (bcrf_search_rows($pdo, "SELECT customer_name, booking_date, party_size, status FROM bookings
                        WHERE customer_name LIKE ? OR email LIKE ?$ph ORDER BY booking_date DESC LIMIT $n",
                        array_merge([$like, $like], $dig !== '' ? [$dl] : [])) as $r)
                    $rows[] = [$r['customer_name'], $date($r['booking_date']) . ' · ' . (int)$r['party_size'] . ' people · ' . $r['status'],
                               '/admin/admin.php?view=bookings&day=' . rawurlencode((string)$r['booking_date'])];
                break;
            case 'parties':
                $ph = $dig !== '' ? ' OR ' . bcrf_search_phone_sql('phone') . ' LIKE ?' : '';
                foreach (bcrf_search_rows($pdo, "SELECT id, customer_name, event_type, preferred_date, status FROM event_inquiries
                        WHERE customer_name LIKE ? OR email LIKE ?$ph ORDER BY preferred_date DESC LIMIT $n",
                        array_merge([$like, $like], $dig !== '' ? [$dl] : [])) as $r)
                    $rows[] = [$r['customer_name'], $join(ucfirst(str_replace('_', ' ', (string)$r['event_type'])), $date($r['preferred_date']), $r['status']),
                               '/admin/party.php?id=' . (int)$r['id']];
                break;
            case 'sales':
                // By customer name or email (receipt numbers jump)
                foreach (bcrf_search_rows($pdo, "SELECT s.sale_number, s.total_cents, s.business_date, s.status, c.first_name, c.last_name
                        FROM sales s JOIN customers c ON c.id = s.customer_id
                        WHERE CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR c.email LIKE ?
                        ORDER BY s.id DESC LIMIT $n", [$like, $like]) as $r)
                    $rows[] = [$r['sale_number'] . ' · ' . $nm($r), $date($r['business_date']) . ' · ' . bcrf_search_money((int)$r['total_cents']) . ($r['status'] !== 'completed' ? ' · ' . $r['status'] : ''),
                               '/admin/admin.php?view=receipts&search_q=' . rawurlencode((string)$r['sale_number'])];
                $more = '/admin/admin.php?view=receipts&search_q=' . $enc;
                break;
            case 'gift_cards':
                foreach (bcrf_search_rows($pdo, "SELECT id, code, balance_cents, recipient_name, purchaser_name FROM gift_cards
                        WHERE recipient_name LIKE ? OR purchaser_name LIKE ? OR recipient_email LIKE ? OR purchaser_email LIKE ?
                        ORDER BY id DESC LIMIT $n", [$like, $like, $like, $like]) as $r)
                    $rows[] = [($r['recipient_name'] ?: $r['purchaser_name']) ?: 'Gift card', '····' . substr((string)$r['code'], -4) . ' · ' . bcrf_search_money((int)$r['balance_cents']) . ' left',
                               '/admin/gift_cards.php?id=' . (int)$r['id']];
                $more = '/admin/gift_cards.php?q=' . $enc;
                break;
            case 'products':
                $edit = $can(['table', 'inventory', 'edit']);
                foreach (bcrf_search_rows($pdo, "SELECT id, name, sku, price, stock_quantity, track_inventory, thrift_status FROM inventory
                        WHERE name LIKE ? OR sku LIKE ? ORDER BY is_active DESC, name LIMIT $n", [$like, $like]) as $r) {
                    $thrift = $r['thrift_status'] !== null && $r['thrift_status'] !== '';
                    $url = !$edit ? '/admin/stock.php?id=' . (int)$r['id'] : ($thrift ? '/admin/thrift.php?id=' . (int)$r['id'] : '/admin/products.php?id=' . (int)$r['id']);
                    $rows[] = [$r['name'], trim(($r['sku'] ? $r['sku'] . ' · ' : '') . '$' . number_format((float)$r['price'], 2)
                               . ((int)$r['track_inventory'] ? ' · ' . rtrim(rtrim(number_format((float)$r['stock_quantity'], 2, '.', ''), '0'), '.') . ' in stock' : '')), $url];
                }
                $more = $edit ? '/admin/products.php?q=' . $enc : '/admin/stock.php?q=' . $enc;
                break;
            case 'animals':
                foreach (bcrf_search_rows($pdo, "SELECT a.id, a.name, a.tag, a.status, b.breed_name AS breed FROM animals a LEFT JOIN breeds b ON b.id = a.breed_id
                        WHERE a.name LIKE ? OR a.tag LIKE ? OR a.microchip LIKE ? ORDER BY (a.status IN ('active','resting','quarantined')) DESC, a.name LIMIT $n",
                        [$like, $like, $like]) as $r)
                    $rows[] = [$r['name'], trim(($r['breed'] ? $r['breed'] . ' · ' : '') . ($r['tag'] ? 'Tag ' . $r['tag'] . ' · ' : '') . ucfirst((string)$r['status'])),
                               '/admin/animals.php?id=' . (int)$r['id']];
                $more = '/admin/animals.php?f=all&q=' . $enc;
                break;
            case 'incidents':
                foreach (bcrf_search_rows($pdo, "SELECT id, type, location, description, happened_at, status FROM incidents
                        WHERE description LIKE ? OR location LIKE ? ORDER BY happened_at DESC LIMIT $n", [$like, $like]) as $r)
                    $rows[] = [ucfirst(str_replace('_', ' ', (string)$r['type'])) . ' · ' . $r['location'], $date($r['happened_at']) . ' · ' . $r['status'],
                               '/admin/incidents.php?id=' . (int)$r['id']];
                break;
            case 'recipes':
                foreach (bcrf_search_rows($pdo, "SELECT id, title, status FROM recipes WHERE title LIKE ? ORDER BY title LIMIT $n", [$like]) as $r)
                    $rows[] = [$r['title'], ucfirst((string)$r['status']), '/admin/kitchen_recipes.php?id=' . (int)$r['id']];
                $more = '/admin/kitchen_recipes.php?q=' . $enc;
                break;
            case 'lots':
                foreach (bcrf_search_rows($pdo, "SELECT b.lot_code, b.made_on, b.units_made, i.name FROM kitchen_batches b LEFT JOIN inventory i ON i.id = b.inventory_id
                        WHERE b.lot_code LIKE ? ORDER BY b.id DESC LIMIT $n", [$like]) as $r)
                    $rows[] = [$r['lot_code'], $join($r['name'] ?? '', $date($r['made_on']), (int)$r['units_made'] . ' made'),
                               '/admin/kitchen_trace.php?lot=' . rawurlencode((string)$r['lot_code'])];
                break;
            case 'blog':
                foreach (bcrf_search_rows($pdo, "SELECT id, title, status, date_published FROM blog_posts WHERE title LIKE ? ORDER BY id DESC LIMIT $n", [$like]) as $r)
                    $rows[] = [$r['title'], ucfirst((string)$r['status']) . ($r['date_published'] ? ' · ' . $date($r['date_published']) : ''),
                               '/admin/admin.php?view=schema&table=blog_posts&edit_id=' . (int)$r['id']];
                break;
            case 'events':
                foreach (bcrf_search_rows($pdo, "SELECT id, title, start_datetime, status FROM events WHERE title LIKE ? ORDER BY start_datetime DESC LIMIT $n", [$like]) as $r)
                    $rows[] = [$r['title'], $date($r['start_datetime']) . ' · ' . $r['status'], '/admin/admin.php?view=events&edit=' . (int)$r['id']];
                break;
            case 'waivers':
                foreach (bcrf_search_rows($pdo, "SELECT id, signer_name, signer_email, signed_at, expires_at, voided_at FROM waivers
                        WHERE signer_name LIKE ? OR signer_email LIKE ? OR minors_json LIKE ? ORDER BY signed_at DESC LIMIT $n", [$like, $like, $like]) as $r)
                    $rows[] = [$r['signer_name'], 'Signed ' . $date($r['signed_at']) . ($r['voided_at'] ? ' · voided' : ($r['expires_at'] ? ' · until ' . $date($r['expires_at']) : '')),
                               '/admin/waivers.php?id=' . (int)$r['id']];
                $more = '/admin/waivers.php?q=' . $enc;
                break;
            case 'vendors':
                foreach (bcrf_search_rows($pdo, "SELECT id, name, contact_name FROM vendors WHERE archived_at IS NULL AND (name LIKE ? OR contact_name LIKE ? OR email LIKE ?)
                        ORDER BY name LIMIT $n", [$like, $like, $like]) as $r)
                    $rows[] = [$r['name'], (string)$r['contact_name'], '/admin/vendors.php?id=' . (int)$r['id']];
                $more = '/admin/vendors.php?q=' . $enc;
                break;
            case 'pos':
                foreach (bcrf_search_rows($pdo, "SELECT p.id, p.po_ref, p.status, v.name FROM purchase_orders p LEFT JOIN vendors v ON v.id = p.vendor_id
                        WHERE p.po_ref LIKE ? OR v.name LIKE ? ORDER BY p.id DESC LIMIT $n", [$like, $like]) as $r)
                    $rows[] = [($r['po_ref'] ?: 'PO #' . (int)$r['id']) . ' · ' . $r['name'], ucfirst((string)$r['status']), '/admin/purchase_orders.php?id=' . (int)$r['id']];
                break;
            case 'staff':
                if (!function_exists('bcrf_role_label')) require_once __DIR__ . '/roles.php';
                foreach (bcrf_search_rows($pdo, "SELECT id, first_name, last_name, email, role, account_status FROM users
                        WHERE role IN ('administrator','moderator','Shopkeep','Media','Staff') AND (CONCAT_WS(' ', first_name, last_name) LIKE ? OR email LIKE ?)
                        ORDER BY first_name, last_name LIMIT $n", [$like, $like]) as $r)
                    $rows[] = [$nm($r) ?: $r['email'], bcrf_role_label($r['role']) . ' · ' . $r['email'], '/admin/admin.php?view=jobs_staff&user=' . (int)$r['id']];
                break;
        }
        if (!$rows) continue;
        $hasMore = count($rows) > BCRF_SEARCH_PER_GROUP;
        $out['groups'][] = [
            'key' => $key, 'label' => $label, 'icon' => $icon,
            'rows' => array_map(fn($r) => ['title' => (string)$r[0], 'sub' => (string)$r[1], 'url' => (string)$r[2]], array_slice($rows, 0, BCRF_SEARCH_PER_GROUP)),
            'more' => $hasMore ? $more : null,
        ];
    }
    return $out;
}

}
