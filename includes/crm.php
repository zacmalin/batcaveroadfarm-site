<?php
// includes/crm.php — Farm OS Phase 3: one customer record across the farm, households, organizations, and merging
// duplicates (Nov 12, 2026; migration 0036; 07 › Customer model, Matching and duplicates, CRM extension Phase 1).
//   Linking   bookings, parties (event_inquiries), passes and online tickets carry the customer they belong to.
//             bcrf_crm_sync() links any that don't yet: it matches by email, and makes a "guest" customer (no password,
//             not verified) for an email with no customer yet. Phone and name are never used to link, only to suggest
//             duplicates. The website portal still shows records by verified email, so linking exposes nothing new.
//   Merging   staff pick which record to keep; every reference moves to it, the other is switched off and points at it,
//             and the merge is recorded with the rows it moved so it can be undone.
//   Households  group family members; children without their own record are first name and age range only.
//   Organizations  schools, troops, churches and businesses, with contacts, and the bookings and parties they made.
// Times are UTC in the database.
if (!function_exists('bcrf_crm_ready')) {

class BcrfCrmError extends RuntimeException {}

/** Records that point at a customer: table => [customer column, email, name, phone] (email/name/phone only where synced by email). */
define('BCRF_CRM_SOURCES', [
    'bookings'        => ['customer_id', 'email', 'customer_name', 'phone'],
    'event_inquiries' => ['customer_id', 'email', 'customer_name', 'phone'],
    'passes'          => ['customer_id', 'email', 'holder_name', 'phone'],
    'tickets'         => ['customer_id', 'purchaser_email', 'purchaser_name', 'purchaser_phone'],
]);
/** Every column that holds a customers.id and moves in a merge: table => column. */
define('BCRF_CRM_REFS', [
    ['bookings', 'customer_id'], ['event_inquiries', 'customer_id'], ['passes', 'customer_id'], ['tickets', 'customer_id'],
    ['sales', 'customer_id'], ['sale_holds', 'customer_id'], ['inventory', 'consignor_customer_id'],
    ['consignor_settlements', 'consignor_customer_id'], ['recipe_comments', 'user_id'], ['recipe_ratings', 'user_id'],
]);
define('BCRF_CRM_AGES', ['under2' => 'Under 2', '2-4' => '2–4', '5-8' => '5–8', '9-12' => '9–12', '13-17' => '13–17']);
define('BCRF_CRM_ORG_KINDS', ['school' => 'School', 'scouts' => 'Scout troop', 'church' => 'Church', 'business' => 'Business', 'other' => 'Other group']);
define('BCRF_CRM_CONTACT_ROLES', ['booking' => 'Books visits', 'billing' => 'Pays', 'viewer' => 'Kept informed']);

function bcrf_crm_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT household_id, merged_into_id FROM customers LIMIT 0"); $pdo->query("SELECT 1 FROM organizations LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function bcrf_crm_has(PDO $pdo, string $table, string $col): bool {
    static $c = [];
    $k = $table . '.' . $col;
    if (!isset($c[$k]) || !empty($GLOBALS['bcrf_crm_nocache'])) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $col]);
        $c[$k] = (int)$st->fetchColumn() > 0;
    }
    return $c[$k];
}

function bcrf_crm_email(?string $e): string {
    $e = strtolower(trim((string)$e));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

/** Digits only, US numbers as 10 digits; '' when too short to mean anything. */
function bcrf_crm_phone_key(?string $p): string {
    $d = preg_replace('/\D+/', '', (string)$p);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return strlen($d) >= 10 ? $d : '';
}

/** "Emily Jenkins" → ['Emily', 'Jenkins']; "Cher" → ['Cher', '']. */
function bcrf_crm_split_name(string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return ['Guest', ''];
    $parts = explode(' ', $name);
    $first = array_shift($parts);
    return [mb_substr($first, 0, 50), mb_substr(implode(' ', $parts), 0, 50)];
}

/** The record a customer id finally lives at (follows merges). */
function bcrf_crm_resolve(PDO $pdo, int $id): int {
    for ($i = 0; $i < 10 && $id > 0; $i++) {
        $st = $pdo->prepare("SELECT merged_into_id FROM customers WHERE id = ?");
        $st->execute([$id]);
        $next = $st->fetchColumn();
        if (!$next) return $id;
        $id = (int)$next;
    }
    return $id;
}

/** The customer for an email, made as a guest when there isn't one. Returns 0 for a missing or invalid email. */
function bcrf_crm_find_or_create(PDO $pdo, string $email, string $name = '', string $phone = ''): int {
    $email = bcrf_crm_email($email);
    if ($email === '') return 0;
    $st = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
    $st->execute([$email]);
    $id = (int)$st->fetchColumn();
    if ($id) return bcrf_crm_resolve($pdo, $id);
    [$first, $last] = bcrf_crm_split_name($name);
    $pdo->prepare("INSERT IGNORE INTO customers (first_name, last_name, email, phone_number, password, account_status, source, created_at)
                   VALUES (?, ?, ?, ?, NULL, 'active', 'guest', NOW())")
        ->execute([$first, $last, $email, mb_substr(trim($phone), 0, 20) ?: null]);
    $st->execute([$email]);
    return bcrf_crm_resolve($pdo, (int)$st->fetchColumn());
}

/**
 * Links records that have an email but no customer yet. Returns ['linked' => n, 'created' => n].
 * $limit caps how many distinct emails one run handles, so a page load stays quick; it carries on next time.
 */
function bcrf_crm_sync(PDO $pdo, int $limit = 300): array {
    $out = ['linked' => 0, 'created' => 0];
    if (!bcrf_crm_ready($pdo)) return $out;
    $before = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    foreach (BCRF_CRM_SOURCES as $t => [$col, $em, $nm, $ph]) {
        if (!bcrf_crm_has($pdo, $t, $col)) continue;
        $rows = $pdo->query("SELECT LOWER(TRIM($em)) AS e, MAX($nm) AS n, MAX($ph) AS p FROM $t
                             WHERE $col IS NULL AND $em IS NOT NULL AND $em <> '' GROUP BY LOWER(TRIM($em)) LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
        $up = $pdo->prepare("UPDATE $t SET $col = ? WHERE $col IS NULL AND LOWER(TRIM($em)) = ?");
        foreach ($rows as $r) {
            $cid = bcrf_crm_find_or_create($pdo, (string)$r['e'], (string)$r['n'], (string)$r['p']);
            if (!$cid) continue;
            $up->execute([$cid, $r['e']]);
            $out['linked'] += $up->rowCount();
        }
    }
    $out['created'] = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn() - $before;
    return $out;
}

// ── Reading ─────────────────────────────────────────────────────────────────────────────────────

function bcrf_crm_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bcrf_crm_name(array $c): string { return trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: (string)($c['email'] ?? 'Customer'); }

/** Customers matching a search (name, email or phone), not merged away. Newest first when $q is blank. */
function bcrf_crm_search(PDO $pdo, string $q, int $limit = 50): array {
    $q = trim($q);
    $sql = "SELECT c.*, h.name AS household_name FROM customers c LEFT JOIN households h ON h.id = c.household_id WHERE c.merged_into_id IS NULL";
    $args = [];
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $sql .= " AND (CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR c.email LIKE ?";
        $args = [$like, $like];
        $digits = preg_replace('/\D+/', '', $q);
        if (strlen($digits) >= 4) { $sql .= " OR REGEXP_REPLACE(COALESCE(c.phone_number, ''), '[^0-9]', '') LIKE ?"; $args[] = '%' . $digits . '%'; }
        $sql .= ")";
    }
    $sql .= " ORDER BY " . ($q !== '' ? "c.last_name, c.first_name" : "c.id DESC") . " LIMIT " . max(1, min(200, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Everything the farm has for one customer (Customer 360). */
function bcrf_crm_profile(PDO $pdo, int $id): array {
    $p = ['bookings' => [], 'parties' => [], 'passes' => [], 'tickets' => [], 'sales' => ['count' => 0, 'total' => 0, 'last' => null],
          'rewards' => null, 'orgs' => [], 'household' => null, 'members' => [], 'children' => [], 'merges' => []];
    $q = function (string $sql, array $a) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };
    $p['bookings'] = $q("SELECT b.id, b.booking_date, b.start_time, b.party_size, b.status, b.organization_id, t.label FROM bookings b JOIN booking_types t ON t.id = b.type_id
                         WHERE b.customer_id = ? ORDER BY b.booking_date DESC, b.id DESC LIMIT 50", [$id]);
    $p['parties'] = $q("SELECT id, event_type, preferred_date, start_time, adult_count, child_count, status, organization_id FROM event_inquiries
                        WHERE customer_id = ? ORDER BY preferred_date DESC, id DESC LIMIT 50", [$id]);
    $p['passes'] = $q("SELECT p.id, p.holder_name, p.valid_until, p.status, pt.label FROM passes p LEFT JOIN pass_types pt ON pt.id = p.pass_type_id
                       WHERE p.customer_id = ? ORDER BY p.valid_until DESC, p.id DESC LIMIT 20", [$id]);
    $p['tickets'] = $q("SELECT id, public_code, quantity, quantity_redeemed, status, payment_status, created_at FROM tickets WHERE customer_id = ? ORDER BY id DESC LIMIT 20", [$id]);
    $s = $q("SELECT COUNT(*) AS n, COALESCE(SUM(total_cents - refunded_cents), 0) AS t, MAX(business_date) AS d FROM sales
             WHERE customer_id = ? AND status IN ('completed', 'partially_refunded', 'refunded')", [$id]);
    if ($s) $p['sales'] = ['count' => (int)$s[0]['n'], 'total' => (int)$s[0]['t'], 'last' => $s[0]['d']];
    $r = $q("SELECT balance_points FROM reward_members WHERE customer_id = ?", [$id]);
    if ($r) $p['rewards'] = (int)$r[0]['balance_points'];
    $p['orgs'] = $q("SELECT o.id, o.name, o.kind, oc.role FROM organization_contacts oc JOIN organizations o ON o.id = oc.organization_id WHERE oc.customer_id = ? ORDER BY o.name", [$id]);
    $c = bcrf_crm_get($pdo, $id);
    if ($c && $c['household_id']) {
        $h = $q("SELECT * FROM households WHERE id = ?", [(int)$c['household_id']]);
        $p['household'] = $h[0] ?? null;
        $p['members'] = $q("SELECT id, first_name, last_name, email FROM customers WHERE household_id = ? AND merged_into_id IS NULL ORDER BY first_name", [(int)$c['household_id']]);
        $p['children'] = $q("SELECT * FROM household_children WHERE household_id = ? ORDER BY first_name", [(int)$c['household_id']]);
    }
    $p['merges'] = $q("SELECT m.*, c.first_name, c.last_name, c.email FROM customer_merges m JOIN customers c ON c.id = m.merged_id
                       WHERE m.kept_id = ? ORDER BY m.id DESC LIMIT 20", [$id]);
    return $p;
}

/** Name, phone and staff notes. Email is the sign-in, so it isn't changed here. */
function bcrf_crm_update(PDO $pdo, int $id, array $in): void {
    $first = trim(mb_substr((string)($in['first_name'] ?? ''), 0, 50));
    if ($first === '') throw new BcrfCrmError('First name is needed.');
    $phone = trim(mb_substr((string)($in['phone'] ?? ''), 0, 20));
    $pdo->prepare("UPDATE customers SET first_name = ?, last_name = ?, phone_number = ?, staff_notes = ? WHERE id = ?")
        ->execute([$first, trim(mb_substr((string)($in['last_name'] ?? ''), 0, 50)), $phone === '' ? null : $phone,
                   trim(mb_substr((string)($in['staff_notes'] ?? ''), 0, 5000)) ?: null, $id]);
}

// ── Households ──────────────────────────────────────────────────────────────────────────────────

function bcrf_crm_household_create(PDO $pdo, int $customerId, string $name, ?int $uid): int {
    $c = bcrf_crm_get($pdo, $customerId);
    if (!$c) throw new BcrfCrmError('That customer is gone.');
    $name = trim(mb_substr($name, 0, 120)) ?: ($c['last_name'] !== '' ? $c['last_name'] . ' family' : bcrf_crm_name($c) . '\'s family');
    $pdo->prepare("INSERT INTO households (name, created_at, created_by) VALUES (?, UTC_TIMESTAMP(), ?)")->execute([$name, $uid]);
    $hid = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE customers SET household_id = ? WHERE id = ?")->execute([$hid, $customerId]);
    return $hid;
}

/** Puts a customer in the same household as another (making one when needed), or takes them out ($otherId = 0). */
function bcrf_crm_household_join(PDO $pdo, int $customerId, int $otherId, ?int $uid): void {
    if ($otherId === 0) { $pdo->prepare("UPDATE customers SET household_id = NULL WHERE id = ?")->execute([$customerId]); return; }
    if ($otherId === $customerId) throw new BcrfCrmError('Pick someone else.');
    $o = bcrf_crm_get($pdo, $otherId);
    if (!$o || $o['merged_into_id']) throw new BcrfCrmError('Pick a customer from the list.');
    $hid = (int)$o['household_id'] ?: bcrf_crm_household_create($pdo, $otherId, '', $uid);
    $pdo->prepare("UPDATE customers SET household_id = ? WHERE id = ?")->execute([$hid, $customerId]);
}

function bcrf_crm_child_add(PDO $pdo, int $householdId, string $first, string $age): void {
    $first = trim(mb_substr($first, 0, 50));
    if ($first === '') throw new BcrfCrmError('Type the child\'s first name.');
    if (!isset(BCRF_CRM_AGES[$age])) throw new BcrfCrmError('Pick an age range.');
    $pdo->prepare("INSERT INTO household_children (household_id, first_name, age_range, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")->execute([$householdId, $first, $age]);
}

function bcrf_crm_child_remove(PDO $pdo, int $householdId, int $childId): void {
    $pdo->prepare("DELETE FROM household_children WHERE id = ? AND household_id = ?")->execute([$childId, $householdId]);
}

// ── Organizations ───────────────────────────────────────────────────────────────────────────────

function bcrf_crm_org_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bcrf_crm_orgs(PDO $pdo, string $q = ''): array {
    $sql = "SELECT o.*, (SELECT COUNT(*) FROM bookings b WHERE b.organization_id = o.id) AS n_bookings,
                   (SELECT COUNT(*) FROM event_inquiries e WHERE e.organization_id = o.id) AS n_parties,
                   (SELECT COUNT(*) FROM organization_contacts oc WHERE oc.organization_id = o.id) AS n_contacts
            FROM organizations o";
    $args = [];
    if (trim($q) !== '') { $sql .= " WHERE o.name LIKE ?"; $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)) . '%'; }
    $st = $pdo->prepare($sql . " ORDER BY o.name LIMIT 200");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_crm_org_save(PDO $pdo, ?int $id, array $in, ?int $uid): int {
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 160));
    if ($name === '') throw new BcrfCrmError('Give the organization a name.');
    $kind = (string)($in['kind'] ?? 'other');
    if (!isset(BCRF_CRM_ORG_KINDS[$kind])) $kind = 'other';
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !bcrf_crm_email($email)) throw new BcrfCrmError('That email doesn\'t look right.');
    $row = [$name, $kind, $email === '' ? null : strtolower($email), trim(mb_substr((string)($in['phone'] ?? ''), 0, 30)) ?: null,
            trim(mb_substr((string)($in['address'] ?? ''), 0, 255)) ?: null, trim(mb_substr((string)($in['notes'] ?? ''), 0, 5000)) ?: null];
    if ($id) {
        if (!bcrf_crm_org_get($pdo, $id)) throw new BcrfCrmError('That organization is gone.');
        $pdo->prepare("UPDATE organizations SET name = ?, kind = ?, email = ?, phone = ?, address = ?, notes = ? WHERE id = ?")->execute([...$row, $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO organizations (name, kind, email, phone, address, notes, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")->execute([...$row, $uid]);
    return (int)$pdo->lastInsertId();
}

function bcrf_crm_org_contacts(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare("SELECT oc.role, c.id, c.first_name, c.last_name, c.email, c.phone_number FROM organization_contacts oc JOIN customers c ON c.id = oc.customer_id
                         WHERE oc.organization_id = ? ORDER BY c.first_name, c.last_name");
    $st->execute([$orgId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Adds a contact by email (making a guest customer for a new email). */
function bcrf_crm_org_contact_add(PDO $pdo, int $orgId, string $email, string $name, string $role): int {
    if (!bcrf_crm_org_get($pdo, $orgId)) throw new BcrfCrmError('That organization is gone.');
    if (!isset(BCRF_CRM_CONTACT_ROLES[$role])) $role = 'booking';
    $cid = bcrf_crm_find_or_create($pdo, $email, $name);
    if (!$cid) throw new BcrfCrmError('Type the contact\'s email.');
    $pdo->prepare("INSERT INTO organization_contacts (organization_id, customer_id, role, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE role = VALUES(role)")->execute([$orgId, $cid, $role]);
    return $cid;
}

function bcrf_crm_org_contact_remove(PDO $pdo, int $orgId, int $customerId): void {
    $pdo->prepare("DELETE FROM organization_contacts WHERE organization_id = ? AND customer_id = ?")->execute([$orgId, $customerId]);
}

/** Says which organization a booking or party was for (0 = none). */
function bcrf_crm_org_link(PDO $pdo, string $type, int $id, int $orgId): void {
    $t = ['booking' => 'bookings', 'event_inquiry' => 'event_inquiries'][$type] ?? null;
    if (!$t) throw new BcrfCrmError('Unknown record.');
    if ($orgId && !bcrf_crm_org_get($pdo, $orgId)) throw new BcrfCrmError('Pick an organization from the list.');
    $pdo->prepare("UPDATE $t SET organization_id = ? WHERE id = ?")->execute([$orgId ?: null, $id]);
}

/** The organization's bookings and parties. */
function bcrf_crm_org_records(PDO $pdo, int $orgId): array {
    $b = $pdo->prepare("SELECT b.id, b.customer_name, b.booking_date, b.party_size, b.status, t.label FROM bookings b JOIN booking_types t ON t.id = b.type_id
                        WHERE b.organization_id = ? ORDER BY b.booking_date DESC, b.id DESC LIMIT 100");
    $b->execute([$orgId]);
    $e = $pdo->prepare("SELECT id, customer_name, preferred_date, adult_count, child_count, status, event_type FROM event_inquiries
                        WHERE organization_id = ? ORDER BY preferred_date DESC, id DESC LIMIT 100");
    $e->execute([$orgId]);
    return ['bookings' => $b->fetchAll(PDO::FETCH_ASSOC), 'parties' => $e->fetchAll(PDO::FETCH_ASSOC)];
}

// ── Duplicates and merging ──────────────────────────────────────────────────────────────────────

/** Pairs that may be the same person: same phone, or same first and last name. [['a' => row, 'b' => row, 'why' => '…'], …] */
function bcrf_crm_duplicates(PDO $pdo, int $limit = 100): array {
    $rows = $pdo->query("SELECT id, first_name, last_name, email, phone_number, password IS NOT NULL AND password <> '' AS has_account, staff_user_id, source, created_at
                         FROM customers WHERE merged_into_id IS NULL AND account_status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    $byPhone = []; $byName = [];
    foreach ($rows as $r) {
        if (($k = bcrf_crm_phone_key($r['phone_number'])) !== '') $byPhone[$k][] = $r;
        $n = strtolower(trim($r['first_name']) . '|' . trim($r['last_name']));
        if (trim((string)$r['last_name']) !== '' && strtolower(trim($r['first_name'])) !== 'guest') $byName[$n][] = $r;
    }
    $pairs = [];
    $add = function (array $group, string $why) use (&$pairs) {
        usort($group, fn($x, $y) => $x['id'] <=> $y['id']);
        for ($i = 1; $i < count($group); $i++) {
            $k = $group[0]['id'] . '-' . $group[$i]['id'];
            if (isset($pairs[$k])) { $pairs[$k]['why'] .= ' and ' . $why; continue; }
            $pairs[$k] = ['a' => $group[0], 'b' => $group[$i], 'why' => $why];
        }
    };
    foreach ($byPhone as $g) if (count($g) > 1) $add($g, 'same phone');
    foreach ($byName as $g) if (count($g) > 1) $add($g, 'same name');
    return array_slice(array_values($pairs), 0, $limit);
}

/** Why two records can't be merged ('' = they can). $keep stays, $dup goes. */
function bcrf_crm_merge_blocker(PDO $pdo, ?array $keep, ?array $dup): string {
    if (!$keep || !$dup) return 'Pick two customers.';
    if ((int)$keep['id'] === (int)$dup['id']) return 'That\'s the same record.';
    if ($keep['merged_into_id'] || $dup['merged_into_id']) return 'One of them was already merged.';
    if ($dup['staff_user_id']) return 'The one to remove is a staff member\'s account. Keep that one instead.';
    $acct = fn($c) => $c['password'] !== null && $c['password'] !== '';
    if ($acct($dup) && $acct($keep)) return 'Both have their own website sign-in, so they can\'t be merged here yet.';
    if ($acct($dup)) return 'The one to remove has a website sign-in. Keep that one instead.';
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM reward_members WHERE customer_id = ?");
        $st->execute([(int)$dup['id']]);
        if ((int)$st->fetchColumn()) return 'The one to remove is in Roost Rewards. Keep that one instead, or ask before merging two members.';
    } catch (Throwable $e) {}
    return '';
}

/** Merges $dupId into $keepId. Returns the customer_merges id. */
function bcrf_crm_merge(PDO $pdo, int $keepId, int $dupId, ?int $uid): int {
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT * FROM customers WHERE id IN (?, ?) ORDER BY id FOR UPDATE");
        $lock->execute([$keepId, $dupId]);
        $rows = [];
        foreach ($lock->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
        $keep = $rows[$keepId] ?? null; $dup = $rows[$dupId] ?? null;
        if (($why = bcrf_crm_merge_blocker($pdo, $keep, $dup)) !== '') throw new BcrfCrmError($why);
        $moved = [];
        foreach (BCRF_CRM_REFS as [$t, $col]) {
            if (!bcrf_crm_has($pdo, $t, $col)) continue;
            $st = $pdo->prepare("SELECT id FROM $t WHERE $col = ? FOR UPDATE");
            $st->execute([$dupId]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) continue;
            $pdo->prepare("UPDATE $t SET $col = ? WHERE $col = ?")->execute([$keepId, $dupId]);
            $moved[$t][$col] = $ids;
        }
        // Organization contacts: move, or drop where the kept record is already a contact there.
        $st = $pdo->prepare("SELECT organization_id, role FROM organization_contacts WHERE customer_id = ?");
        $st->execute([$dupId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $oc) {
            $ex = $pdo->prepare("SELECT COUNT(*) FROM organization_contacts WHERE organization_id = ? AND customer_id = ?");
            $ex->execute([$oc['organization_id'], $keepId]);
            if ((int)$ex->fetchColumn()) {
                $pdo->prepare("DELETE FROM organization_contacts WHERE organization_id = ? AND customer_id = ?")->execute([$oc['organization_id'], $dupId]);
                $moved['organization_contacts_dropped'][] = $oc;
            } else {
                $pdo->prepare("UPDATE organization_contacts SET customer_id = ? WHERE organization_id = ? AND customer_id = ?")->execute([$keepId, $oc['organization_id'], $dupId]);
                $moved['organization_contacts'][] = (int)$oc['organization_id'];
            }
        }
        if (!$keep['household_id'] && $dup['household_id']) {
            $pdo->prepare("UPDATE customers SET household_id = ? WHERE id = ?")->execute([$dup['household_id'], $keepId]);
            $moved['kept_household'] = (int)$dup['household_id'];
        }
        if (!$keep['phone_number'] && $dup['phone_number']) {
            $pdo->prepare("UPDATE customers SET phone_number = ? WHERE id = ?")->execute([$dup['phone_number'], $keepId]);
            $moved['kept_phone'] = true;
        }
        $pdo->prepare("UPDATE customers SET merged_into_id = ?, account_status = 'disabled', household_id = NULL WHERE id = ?")->execute([$keepId, $dupId]);
        $pdo->prepare("INSERT INTO customer_merges (kept_id, merged_id, moved_json, before_json, merged_at, merged_by) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
            ->execute([$keepId, $dupId, json_encode($moved), json_encode(['account_status' => $dup['account_status'], 'household_id' => $dup['household_id']]), $uid]);
        $mid = (int)$pdo->lastInsertId();
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'customers', $keepId, 'Merged ' . bcrf_crm_name($dup) . ' <' . $dup['email'] . '> (#' . $dupId . ') into this customer');
        $pdo->commit();
        return $mid;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Puts a merge back: the moved rows return to the removed record, which is switched back on. */
function bcrf_crm_merge_undo(PDO $pdo, int $mergeId, ?int $uid): void {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM customer_merges WHERE id = ? FOR UPDATE");
        $st->execute([$mergeId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new BcrfCrmError('That merge isn\'t on record.');
        if ($m['undone_at']) throw new BcrfCrmError('That merge was already undone.');
        $keepId = (int)$m['kept_id']; $dupId = (int)$m['merged_id'];
        $keep = bcrf_crm_get($pdo, $keepId);
        if (!$keep || $keep['merged_into_id']) throw new BcrfCrmError('The kept record was merged again since. Undo that merge first.');
        $moved = json_decode((string)$m['moved_json'], true) ?: [];
        foreach (BCRF_CRM_REFS as [$t, $col]) {
            $ids = $moved[$t][$col] ?? [];
            if (!$ids || !bcrf_crm_has($pdo, $t, $col)) continue;
            $in = implode(',', array_map('intval', $ids));
            $pdo->prepare("UPDATE $t SET $col = ? WHERE $col = ? AND id IN ($in)")->execute([$dupId, $keepId]);
        }
        foreach ($moved['organization_contacts'] ?? [] as $orgId) {
            $pdo->prepare("UPDATE organization_contacts SET customer_id = ? WHERE organization_id = ? AND customer_id = ?")->execute([$dupId, (int)$orgId, $keepId]);
        }
        foreach ($moved['organization_contacts_dropped'] ?? [] as $oc) {
            $pdo->prepare("INSERT IGNORE INTO organization_contacts (organization_id, customer_id, role, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")
                ->execute([(int)$oc['organization_id'], $dupId, (string)$oc['role']]);
        }
        if (!empty($moved['kept_phone'])) {
            $d = bcrf_crm_get($pdo, $dupId);
            $pdo->prepare("UPDATE customers SET phone_number = NULL WHERE id = ? AND phone_number = ?")->execute([$keepId, (string)($d['phone_number'] ?? '')]);
        }
        if (!empty($moved['kept_household'])) $pdo->prepare("UPDATE customers SET household_id = NULL WHERE id = ? AND household_id = ?")->execute([$keepId, (int)$moved['kept_household']]);
        $before = json_decode((string)$m['before_json'], true) ?: [];
        $pdo->prepare("UPDATE customers SET merged_into_id = NULL, account_status = ?, household_id = ? WHERE id = ?")
            ->execute([($before['account_status'] ?? 'active') === 'disabled' ? 'disabled' : 'active', $before['household_id'] ?? null, $dupId]);
        $pdo->prepare("UPDATE customer_merges SET undone_at = UTC_TIMESTAMP(), undone_by = ? WHERE id = ?")->execute([$uid, $mergeId]);
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'customers', $keepId, 'Undid merge #' . $mergeId . ' (customer #' . $dupId . ' is separate again)');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

}
