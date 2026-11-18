<?php
// includes/search.php — Farm OS global search (02 › Search; Nov 13, 2026).
// One search over the pages and records a staff member can open, grouped by type. A type the user can't open is never
// searched, so its records never come back (02: "Never returns records the user cannot open"). Ticket codes, receipt and
// refund numbers, gift card codes, pass links and lot codes jump straight to the record.
// Nov 14, 2026 (universal search): pages and settings come first ("weather" → Weather day, "database" → Database), taken
// from the menu map in includes/admin_nav.php, so new menu pages are searchable with no extra step. Added tasks, applicants,
// subscribers, comments, animal health and care notes, ingredients, social posts and newsletters, and staff notes on
// customers, bookings and parties. Every searchable type is listed on purpose below; tables are never searched blindly.
//   bcrf_search($pdo, 'hazel', $can, $me)   →  ['jump' => null | [label, url], 'groups' => [[key, label, icon, rows, more], ...]]
//   $can(['view', 'customers']) / $can(['table', 'inventory', 'edit']) / $can(['admin']) / $can(['always'])
//       / $can(['pinrole']) / $can(['and', [check, check]])  →  bool
//   $me = ['id' => user id, 'role' => role]  (who is searching; only used to limit Tasks for non-managers)
// Page: admin/search.php. Nothing searched is stored on the server (02: no server-side search history).
if (!function_exists('bcrf_is_owner')) require_once __DIR__ . '/roles.php';   // bcrf_is_owner() (Nov 18, 2026)

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
        'documents'  => ['Invoices, receipts & papers', '📎', [['table', 'inventory', 'edit'], ['view', 'animal_expenses'], ['table', 'animals']]],   // Nov 14, 2026
        'notes'      => ['Notes on records', '🗒️', [['view', 'customers'], ['table', 'inventory', 'edit'], ['table', 'animals'], ['view', 'event_inquiries']]],   // Nov 14, 2026 (record_notes)
        'staff'      => ['Staff',            '🧑‍🌾', [['admin']]],
        // Nov 14, 2026
        'tasks'      => ['Tasks',            '✅', [['view', 'tasks']]],
        'applicants' => ['Applicants',       '📥', [['view', 'jobs']]],
        'health'     => ['Animal health',    '🩺', [['and', [['table', 'animals'], ['view', 'animal_health']]]]],
        'care'       => ['Care notes',       '🥕', [['table', 'animals']]],
        'ingredients'=> ['Ingredients',      '🌿', [['table', 'ingredients']]],
        'social'     => ['Social posts',     '📣', [['view', 'social']]],
        'newsletter' => ['Newsletters',      '📧', [['view', 'newsletter']]],
        'subscribers'=> ['Subscribers',      '💌', [['table', 'mailing_list']]],
        'comments'   => ['Comments',         '💬', [['view', 'comment_moderation']]],
    ];
}

/** True when any one of the checks passes. ['and', [c1, c2]] passes only when every check inside it does. */
function bcrf_search_allowed(array $anyOf, callable $can): bool {
    foreach ($anyOf as $c) {
        if (($c[0] ?? '') === 'and') {
            $all = true;
            foreach ((array)($c[1] ?? []) as $sub) if (!bcrf_search_allowed([$sub], $can)) { $all = false; break; }
            if ($all && !empty($c[1])) return true;
            continue;
        }
        if ($can($c)) return true;
    }
    return false;
}

/** The standard $can for the signed-in staff member (needs admin/includes/permission_checks.php loaded). */
function bcrf_search_can_for(string $role): callable {
    return function (array $c) use ($role): bool {
        if (bcrf_is_owner($role)) return true;
        if ($role === '' || $role === 'user') return false;
        $type = $c[0] ?? '';
        if ($type === 'always') return true;
        if ($type === 'pinrole') return in_array($role, function_exists('bcrf_pin_roles') ? bcrf_pin_roles() : ['administrator', 'moderator', 'Shopkeep'], true);
        if ($type === 'and') return bcrf_search_allowed([$c], fn(array $x) => bcrf_search_can_for($role)($x));
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
    catch (Throwable $e) { $GLOBALS['bcrf_search_query_failed'] = true; error_log('[search] ' . $e->getMessage()); return []; }   // a module not installed yet
}

if (!function_exists('bcrf_tz')) require_once __DIR__ . '/farm_helpers.php';   // one farm time zone / today / money (Nov 18, 2026)
function bcrf_search_money(int $c): string { return bcrf_money($c); }

/** About 70 characters of $text around the first match of $q, on one line ('' when it isn't there). */
function bcrf_search_snip(?string $text, string $q, int $width = 70): string {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if ($t === '' || $q === '') return '';
    $at = mb_stripos($t, $q);
    if ($at === false) return '';
    $from = max(0, $at - (int)(($width - mb_strlen($q)) / 2));
    $out = mb_substr($t, $from, $width);
    return ($from > 0 ? '…' : '') . $out . ($from + $width < mb_strlen($t) ? '…' : '');
}

/** True when $q is in any of the strings (case-insensitive). */
function bcrf_search_in(string $q, ...$texts): bool {
    foreach ($texts as $x) if ($x !== null && $x !== '' && mb_stripos((string)$x, $q) !== false) return true;
    return false;
}

/**
 * Pages and settings the user can open whose name, place in the menu, or extra words match the search.
 * Rows: ['title', 'sub', 'url'], best match first. Every word typed must match the start of a word.
 */
function bcrf_search_pages(string $q, callable $can): array {
    if (!function_exists('bcrf_admin_nav_sections')) require_once __DIR__ . '/admin_nav.php';
    $ql = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q)));
    $words = $ql === '' ? [] : explode(' ', $ql);
    if (!$words) return [];
    $kw = bcrf_admin_nav_keywords();
    $cands = [];
    foreach (bcrf_admin_nav_sections() as $sec) {
        foreach ($sec['items'] as $it) {
            foreach ($it['tabs'] as [$tl, $th, $tt, $tv]) {
                $check = $tt === 'always' ? ['always'] : ($tt === 'pinrole' ? ['pinrole'] : [$tt, $tv]);
                $cands[] = [$tl, $sec['label'] . ($tl !== $it['label'] ? ' › ' . $it['label'] : ''), $th, [$check], $it['label'] . ' ' . $sec['label']];
            }
        }
    }
    foreach (bcrf_admin_nav_extra_pages() as [$l, $h, $checks, $where]) $cands[] = [$l, $where, $h, $checks, ''];

    $starts = fn(string $hay, string $w): bool => (bool)preg_match('/(?:^|[^\p{L}\p{N}])' . preg_quote($w, '/') . '/u', $hay);
    $hits = []; $seen = [];
    foreach ($cands as $i => [$title, $sub, $href, $checks, $ctx]) {
        $url = str_starts_with($href, '/') ? $href : '/admin/admin.php' . $href;
        if (isset($seen[$url])) continue;
        $t = mb_strtolower($title);
        $all = $t . ' ' . mb_strtolower($ctx) . ' ' . mb_strtolower($kw[$href] ?? '');
        foreach ($words as $w) if (!$starts($all, $w)) continue 2;
        if (!bcrf_search_allowed($checks, $can)) continue;
        $inTitle = true;
        foreach ($words as $w) if (!$starts($t, $w)) { $inTitle = false; break; }
        $inCtx = true;
        foreach ($words as $w) if (!$starts($t . ' ' . mb_strtolower($ctx), $w)) { $inCtx = false; break; }
        $score = $t === $ql ? 100 : (str_starts_with($t, $ql) ? 80 : ($inTitle ? 60 : ($inCtx ? 40 : 20)));
        $seen[$url] = true;
        $hits[] = [$score, $i, [$title, $sub, $url]];
    }
    usort($hits, fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
    return array_map(fn($h) => $h[2], $hits);
}

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
function bcrf_search(PDO $pdo, string $q, callable $can, array $me = []): array {
    $q = trim(mb_substr($q, 0, 100));
    $GLOBALS['bcrf_search_query_failed'] = false;
    $out = ['q' => $q, 'jump' => null, 'groups' => [], 'partial' => false];
    if (mb_strlen($q) < 2) return $out;
    $out['jump'] = bcrf_search_jump($pdo, $q, $can);
    $meId   = (int)($me['id'] ?? ($_SESSION['user_id'] ?? 0));
    $meRole = (string)($me['role'] ?? ($_SESSION['user_role'] ?? ''));

    // Pages and settings first
    $pages = bcrf_search_pages($q, $can);
    if ($pages) $out['groups'][] = [
        'key' => 'pages', 'label' => 'Pages & settings', 'icon' => '🧭',
        'rows' => array_map(fn($r) => ['title' => (string)$r[0], 'sub' => (string)$r[1], 'url' => (string)$r[2]], $pages),
        'more' => null,
    ];

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
                foreach (bcrf_search_rows($pdo, "SELECT id, first_name, last_name, email, staff_notes FROM customers
                        WHERE merged_into_id IS NULL AND (CONCAT_WS(' ', first_name, last_name) LIKE ? OR email LIKE ? OR staff_notes LIKE ?$ph)
                        ORDER BY (CONCAT_WS(' ', first_name, last_name) LIKE ? OR email LIKE ?) DESC, last_name, first_name LIMIT $n",
                        array_merge([$like, $like, $like], $dig !== '' ? [$dl] : [], [$like, $like])) as $r) {
                    $byNote = !bcrf_search_in($q, $nm($r), $r['email']) && $dig === '';
                    $rows[] = [$nm($r) ?: $r['email'], $byNote ? 'Note: ' . bcrf_search_snip($r['staff_notes'], $q) : $r['email'], '/admin/customers.php?id=' . (int)$r['id']];
                }
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
                foreach (bcrf_search_rows($pdo, "SELECT customer_name, email, booking_date, party_size, status, notes, admin_note FROM bookings
                        WHERE customer_name LIKE ? OR email LIKE ? OR notes LIKE ? OR admin_note LIKE ?$ph ORDER BY booking_date DESC LIMIT $n",
                        array_merge([$like, $like, $like, $like], $dig !== '' ? [$dl] : [])) as $r) {
                    $note = (!bcrf_search_in($q, $r['customer_name'], $r['email']) && $dig === '') ? (bcrf_search_snip($r['admin_note'], $q) ?: bcrf_search_snip($r['notes'], $q)) : '';
                    $rows[] = [$r['customer_name'], $date($r['booking_date']) . ' · ' . (int)$r['party_size'] . ' people · ' . $r['status'] . ($note !== '' ? ' · Note: ' . $note : ''),
                               '/admin/admin.php?view=bookings&day=' . rawurlencode((string)$r['booking_date'])];
                }
                break;
            case 'parties':
                $ph = $dig !== '' ? ' OR ' . bcrf_search_phone_sql('phone') . ' LIKE ?' : '';
                foreach (bcrf_search_rows($pdo, "SELECT id, customer_name, email, event_type, preferred_date, status, special_requests, admin_notes FROM event_inquiries
                        WHERE customer_name LIKE ? OR email LIKE ? OR special_requests LIKE ? OR admin_notes LIKE ?$ph ORDER BY preferred_date DESC LIMIT $n",
                        array_merge([$like, $like, $like, $like], $dig !== '' ? [$dl] : [])) as $r) {
                    $note = (!bcrf_search_in($q, $r['customer_name'], $r['email']) && $dig === '') ? (bcrf_search_snip($r['admin_notes'], $q) ?: bcrf_search_snip($r['special_requests'], $q)) : '';
                    $rows[] = [$r['customer_name'], $join(ucfirst(str_replace('_', ' ', (string)$r['event_type'])), $date($r['preferred_date']), $r['status'], $note !== '' ? 'Note: ' . $note : ''),
                               '/admin/party.php?id=' . (int)$r['id']];
                }
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
            case 'documents':
                // Vendor, invoice #, note, file name or an amount ("212.40"). Animal-expense receipts only for people who see animal costs.
                // Vendor papers go with stock; animal papers (vet, registration) with the animals table (includes/documents.php bcrf_doc_visible_sql).
                $stockOk = bcrf_search_allowed([['table', 'inventory', 'edit']], $can); $animalOk = bcrf_search_allowed([['view', 'animal_expenses']], $can);
                $papersOk = bcrf_search_allowed([['table', 'animals']], $can);
                $or = [];
                if ($stockOk) $or[] = "(d.link_type IS NULL OR d.link_type NOT IN ('animal_expense', 'animal'))";
                if ($animalOk) $or[] = "d.link_type = 'animal_expense'";
                if ($papersOk) $or[] = "d.link_type = 'animal'";
                $vis = ($stockOk && $animalOk && $papersOk) ? '' : ' AND (' . implode(' OR ', $or ?: ['1=0']) . ')';
                $money = str_replace([',', '$', ' '], '', $q);
                $amt = preg_match('/^\d+(\.\d{1,2})?$/', $money) ? ' OR d.total_cents = ?' : '';
                $types = ['invoice' => 'Invoice', 'receipt' => 'Receipt', 'packing' => 'Packing slip', 'statement' => 'Statement', 'other' => 'Document',
                          'insurance' => 'Insurance', 'w9' => 'W-9', 'contract' => 'Contract', 'vet' => 'Vet paper', 'registration' => 'Registration'];
                foreach (bcrf_search_rows($pdo, "SELECT d.id, d.doc_type, d.doc_date, d.total_cents, d.ref, d.note, d.vendor_name, v.name AS vendor FROM purchase_docs d
                        LEFT JOIN vendors v ON v.id = d.vendor_id WHERE d.deleted_at IS NULL$vis AND (v.name LIKE ? OR d.vendor_name LIKE ? OR d.ref LIKE ? OR d.note LIKE ?
                        OR EXISTS (SELECT 1 FROM purchase_doc_files f WHERE f.doc_id = d.id AND f.original_name LIKE ?)$amt)
                        ORDER BY d.doc_date DESC, d.id DESC LIMIT $n", array_merge([$like, $like, $like, $like, $like], $amt ? [(int)round((float)$money * 100)] : [])) as $r) {
                    $who = $r['vendor'] ?: $r['vendor_name'];
                    $rows[] = [$join($types[$r['doc_type']] ?? 'Document', $who, $r['ref'] ? '#' . $r['ref'] : ''),
                               $join($date($r['doc_date']), $r['total_cents'] !== null ? bcrf_search_money((int)$r['total_cents']) : '', bcrf_search_in($q, $who, $r['ref']) ? '' : bcrf_search_snip($r['note'], $q)),
                               '/admin/documents.php?id=' . (int)$r['id']];
                }
                $more = '/admin/documents.php?q=' . $enc;
                break;
            case 'notes':
                // Dated notes (record_notes, migration 0067) on the kinds of record this person can open. Hidden notes never show.
                if (!function_exists('bcrf_record_kinds')) require_once __DIR__ . '/record_tools.php';
                $okTypes = [];
                foreach (bcrf_record_kinds() as $t => $k) if (!empty($k['notes']) && bcrf_search_allowed($k['need'], $can)) $okTypes[] = $t;
                if (!$okTypes) break;
                $in = implode(',', array_fill(0, count($okTypes), '?'));
                foreach (bcrf_search_rows($pdo, "SELECT id, record_type, record_id, body, created_at FROM record_notes
                        WHERE deleted_at IS NULL AND record_type IN ($in) AND body LIKE ? ORDER BY created_at DESC, id DESC LIMIT $n",
                        array_merge($okTypes, [$like])) as $r) {
                    $url = bcrf_record_url($pdo, (string)$r['record_type'], (int)$r['record_id']);
                    if ($url === '') continue;
                    $rows[] = [bcrf_record_label($pdo, (string)$r['record_type'], (int)$r['record_id']), $join($date($r['created_at']), bcrf_search_snip($r['body'], $q)),
                               $url . (str_contains($url, '#') ? '' : '#notes')];
                }
                break;
            case 'staff':
                if (!function_exists('bcrf_role_label')) require_once __DIR__ . '/roles.php';
                foreach (bcrf_search_rows($pdo, "SELECT id, first_name, last_name, email, role, account_status FROM users
                        WHERE role IN ('administrator','moderator','Shopkeep','Media','Staff') AND (CONCAT_WS(' ', first_name, last_name) LIKE ? OR email LIKE ?)
                        ORDER BY first_name, last_name LIMIT $n", [$like, $like]) as $r)
                    $rows[] = [$nm($r) ?: $r['email'], bcrf_role_label($r['role']) . ' · ' . $r['email'], '/admin/admin.php?view=jobs_staff&user=' . (int)$r['id']];
                break;
            case 'tasks':
                // Managers see every task; everyone else only what Tasks shows them (includes/tasks.php bcrf_tasks_can_see)
                $mine = ''; $mineArgs = [];
                if (!$can(['view', 'tasks_manage'])) {
                    $mine = ' AND (t.assignee_user_id = ? OR t.created_by = ? OR (t.assignee_user_id IS NULL AND (t.assignee_role IS NULL OR t.assignee_role = ?)))';
                    $mineArgs = [$meId, $meId, $meRole];
                }
                foreach (bcrf_search_rows($pdo, "SELECT t.id, t.title, t.description, t.completion_note, t.status, t.due_date FROM tasks t
                        WHERE (t.title LIKE ? OR t.description LIKE ? OR t.completion_note LIKE ?)$mine
                        ORDER BY (t.status IN ('open','in_progress')) DESC, t.due_date IS NULL, t.due_date DESC, t.id DESC LIMIT $n",
                        array_merge([$like, $like, $like], $mineArgs)) as $r) {
                    $note = bcrf_search_in($q, $r['title']) ? '' : (bcrf_search_snip($r['description'], $q) ?: bcrf_search_snip($r['completion_note'], $q));
                    $rows[] = [$r['title'], $join(ucfirst(str_replace('_', ' ', (string)$r['status'])), $r['due_date'] ? 'due ' . $date($r['due_date']) : '', $note),
                               '/admin/tasks.php?id=' . (int)$r['id']];
                }
                break;
            case 'applicants':
                $ph = $dig !== '' ? ' OR ' . bcrf_search_phone_sql('a.phone') . ' LIKE ?' : '';
                foreach (bcrf_search_rows($pdo, "SELECT a.id, a.first_name, a.last_name, a.email, a.status, a.submitted_at, a.cover_note, j.title AS job FROM applications a
                        LEFT JOIN jobs j ON j.id = a.job_id
                        WHERE a.anonymized_at IS NULL AND a.is_spam = 0 AND (CONCAT_WS(' ', a.first_name, a.last_name) LIKE ? OR a.email LIKE ? OR a.cover_note LIKE ?
                              OR a.id IN (SELECT application_id FROM application_notes WHERE body LIKE ?)$ph)
                        ORDER BY a.id DESC LIMIT $n", array_merge([$like, $like, $like, $like], $dig !== '' ? [$dl] : [])) as $r)
                    $rows[] = [$nm($r) ?: $r['email'], $join($r['job'] ?? '', ucfirst((string)$r['status']), $date($r['submitted_at'])),
                               '/admin/admin.php?view=jobs&app=' . (int)$r['id']];
                break;
            case 'health':
                foreach (bcrf_search_rows($pdo, "SELECT m.animal_id, m.title, m.kind, m.record_date, m.vet_name, m.diagnosis, m.treatment, a.name FROM animal_medical_records m
                        JOIN animals a ON a.id = m.animal_id
                        WHERE m.title LIKE ? OR m.vet_name LIKE ? OR m.diagnosis LIKE ? OR m.treatment LIKE ?
                        ORDER BY m.record_date DESC, m.id DESC LIMIT $n", [$like, $like, $like, $like]) as $r) {
                    $note = bcrf_search_in($q, $r['title']) ? '' : (bcrf_search_snip($r['diagnosis'], $q) ?: bcrf_search_snip($r['treatment'], $q) ?: ($r['vet_name'] ? 'Vet ' . $r['vet_name'] : ''));
                    $rows[] = [$r['name'] . ' · ' . $r['title'], $join(ucfirst((string)$r['kind']), $date($r['record_date']), $note),
                               '/admin/animals.php?id=' . (int)$r['animal_id'] . '&tab=health'];
                }
                break;
            case 'care':
                foreach (bcrf_search_rows($pdo, "SELECT l.id, l.note, l.logged_at, ct.name AS type,
                            (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM animal_care_log_animals la JOIN animals a ON a.id = la.animal_id WHERE la.log_id = l.id) AS animals,
                            (SELECT MIN(la.animal_id) FROM animal_care_log_animals la WHERE la.log_id = l.id) AS animal_id
                        FROM animal_care_logs l LEFT JOIN animal_care_types ct ON ct.id = l.care_type_id
                        WHERE l.voided_at IS NULL AND l.note LIKE ? ORDER BY l.logged_at DESC LIMIT $n", [$like]) as $r)
                    $rows[] = [$join($r['type'] ?? 'Care', mb_strimwidth((string)($r['animals'] ?? ''), 0, 40, '…')), $join($date($r['logged_at']), bcrf_search_snip($r['note'], $q)),
                               $r['animal_id'] ? '/admin/animals.php?id=' . (int)$r['animal_id'] . '&tab=care' : '/admin/animal_care.php?tab=log'];
                break;
            case 'ingredients':
                foreach (bcrf_search_rows($pdo, "SELECT id, singular_name, allergens FROM ingredients WHERE singular_name LIKE ? OR plural_name LIKE ? ORDER BY singular_name LIMIT $n", [$like, $like]) as $r)
                    $rows[] = [$r['singular_name'], $r['allergens'] ? 'Allergens: ' . $r['allergens'] : '', '/admin/kitchen_ingredients.php?id=' . (int)$r['id']];
                $more = '/admin/kitchen_ingredients.php?q=' . $enc;
                break;
            case 'social':
                foreach (bcrf_search_rows($pdo, "SELECT id, fb_caption, ig_caption, gbp_caption, status, created_at FROM social_queue
                        WHERE fb_caption LIKE ? OR ig_caption LIKE ? OR gbp_caption LIKE ? ORDER BY id DESC LIMIT $n", [$like, $like, $like]) as $r) {
                    $cap = bcrf_search_snip($r['fb_caption'], $q) ?: bcrf_search_snip($r['ig_caption'], $q) ?: bcrf_search_snip($r['gbp_caption'], $q);
                    $rows[] = [$cap, $join(ucfirst((string)$r['status']), $date($r['created_at'])), '/admin/admin.php?view=social'];
                }
                break;
            case 'newsletter':
                foreach (bcrf_search_rows($pdo, "SELECT id, subject, body, created_at, emails_sent FROM newsletter_campaigns
                        WHERE subject LIKE ? OR body LIKE ? ORDER BY id DESC LIMIT $n", [$like, $like]) as $r)
                    $rows[] = [$r['subject'], $join($date($r['created_at']), (int)$r['emails_sent'] . ' sent', bcrf_search_in($q, $r['subject']) ? '' : bcrf_search_snip($r['body'], $q)),
                               '/admin/admin.php?view=newsletter'];
                break;
            case 'subscribers':
                foreach (bcrf_search_rows($pdo, "SELECT id, email, is_subscribed FROM mailing_list WHERE email LIKE ? ORDER BY email LIMIT $n", [$like]) as $r)
                    $rows[] = [$r['email'], (int)$r['is_subscribed'] ? 'Subscribed' : 'Unsubscribed', '/admin/admin.php?view=schema&table=mailing_list&edit_id=' . (int)$r['id']];
                break;
            case 'comments':
                foreach (bcrf_search_rows($pdo, "SELECT c.id, c.comment_text, c.status, c.created_at, r.title FROM recipe_comments c LEFT JOIN recipes r ON r.id = c.recipe_id
                        WHERE c.comment_text LIKE ? ORDER BY c.id DESC LIMIT $n", [$like]) as $r)
                    $rows[] = [bcrf_search_snip($r['comment_text'], $q), $join($r['title'] ?? '', ucfirst((string)$r['status']), $date($r['created_at'])),
                               '/admin/admin.php?view=comment_moderation'];
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
    $out['partial'] = !empty($GLOBALS['bcrf_search_query_failed']);
    unset($GLOBALS['bcrf_search_query_failed']);
    return $out;
}

}
