<?php
// includes/record_tools.php — tools every record page shares, so customers, vendors, products, animals, parties and the rest
// work the same way (Nov 14, 2026; migration 0067):
//   links      bcrf_record_url() / bcrf_record_name(): where a record lives in the admin and what it's called (task links,
//              notes in search, the bell).
//   contact    bcrf_contact_buttons_html(): Call / Text / Email / Map buttons from a phone, email and address.
//   tasks      bcrf_record_tasks_box_html(): open tasks linked to a record + "Add a task" (pre-filled link on tasks.php).
//   changes    bcrf_record_changes_html(): who changed this record and what, from audit_events (already written by every save).
//   notes      bcrf_record_notes_box_html(): dated, signed notes that are never written over (record_notes, admin/record_note.php).
//   CSV        bcrf_csv_send(): one CSV download with the spreadsheet-formula guard, logged in the audit trail.
// Nothing here is farm-specific: kinds, labels and links come from the registry below.
if (!function_exists('bcrf_is_owner')) require_once __DIR__ . '/roles.php';   // bcrf_is_owner() (Nov 18, 2026)
if (!function_exists('bcrf_record_kinds')) {

if (!function_exists('bcrf_search_kinds')) require_once __DIR__ . '/search.php';   // bcrf_search_allowed / bcrf_search_can_for

if (!defined('BCRF_NOTE_MAX')) define('BCRF_NOTE_MAX', 5000);
if (!defined('BCRF_NOTE_UNDO_HOURS')) define('BCRF_NOTE_UNDO_HOURS', 24);   // the writer can hide their own note this long

class BcrfRecordError extends RuntimeException {}

/**
 * Every kind of record. label, who can open it (same shape as require_permission), the audit_events object_type(s)
 * its saves are logged under, and whether it takes dated notes.
 */
function bcrf_record_kinds(): array {
    return [
        'customer'       => ['label' => 'Customer',       'need' => [['view', 'customers']],          'audit' => ['customers'],        'notes' => true],
        'organization'   => ['label' => 'Organization',   'need' => [['view', 'customers']],          'audit' => ['organizations'],    'notes' => true],
        'vendor'         => ['label' => 'Vendor',         'need' => [['table', 'inventory', 'edit']], 'audit' => ['vendors'],          'notes' => true],
        'inventory'      => ['label' => 'Product',        'need' => [['table', 'inventory', 'edit']], 'audit' => ['inventory'],        'notes' => false],
        'animal'         => ['label' => 'Animal',         'need' => [['table', 'animals']],           'audit' => ['animals'],          'notes' => true],
        'event_inquiry'  => ['label' => 'Party',          'need' => [['view', 'event_inquiries']],    'audit' => ['event_inquiries'],  'notes' => true],
        'booking'        => ['label' => 'Booking',        'need' => [['view', 'bookings']],           'audit' => ['bookings'],         'notes' => false],
        'incident'       => ['label' => 'Incident',       'need' => [['view', 'incidents']],          'audit' => ['incidents'],        'notes' => false],
        'purchase_order' => ['label' => 'Purchase order', 'need' => [['table', 'inventory', 'edit']], 'audit' => ['purchase_orders'],  'notes' => false],
        'receive'        => ['label' => 'Receive',        'need' => [['table', 'inventory', 'edit']], 'audit' => ['receives'],         'notes' => false],
        'event'          => ['label' => 'Event',          'need' => [['view', 'events']],             'audit' => ['events'],           'notes' => false],
        'renewal'        => ['label' => 'Renewal',        'need' => [['view', 'renewals']],           'audit' => ['renewals'],         'notes' => true],   // Nov 15, 2026
    ];
}

/** The kinds a task can be linked to, in the order the task form lists them. */
function bcrf_record_task_kinds(): array {
    $k = bcrf_record_kinds();
    $out = [];
    foreach (['animal', 'booking', 'customer', 'event', 'incident', 'organization', 'event_inquiry', 'inventory', 'purchase_order', 'vendor'] as $t) $out[$t] = $k[$t]['label'];
    return $out;
}

function bcrf_record_role(): string { return (string)($_SESSION['user_role'] ?? ''); }
function bcrf_record_uid(): ?int { $u = (int)($_SESSION['user_id'] ?? 0); return $u ?: null; }
function bcrf_record_is_owner(): bool { return bcrf_is_owner(bcrf_record_role()); }

/** Can the signed-in person open this kind of record? */
function bcrf_record_can(string $type, ?string $role = null): bool {
    $k = bcrf_record_kinds()[$type] ?? null;
    if (!$k) return false;
    $role = $role ?? bcrf_record_role();
    if (bcrf_is_owner($role)) return true;
    return bcrf_search_allowed($k['need'], bcrf_search_can_for($role));
}

/** A whole-number id from a value (tasks keep related_id as text), or 0. */
function bcrf_record_id($id): int { return is_int($id) ? max(0, $id) : (preg_match('/^\d{1,10}$/', trim((string)$id)) ? (int)$id : 0); }

/** One row for a record, or null when it's gone (or the module isn't set up). Never throws. */
function bcrf_record_row(PDO $pdo, string $type, $id): ?array {
    $id = bcrf_record_id($id);
    if (!$id) return null;
    $sql = [
        'customer'       => "SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, email, phone_number AS phone FROM customers WHERE id = ?",
        'organization'   => "SELECT id, name, email, phone FROM organizations WHERE id = ?",
        'vendor'         => "SELECT id, name, email, phone FROM vendors WHERE id = ?",
        'inventory'      => "SELECT id, name FROM inventory WHERE id = ?",
        'animal'         => "SELECT id, name FROM animals WHERE id = ?",
        'event_inquiry'  => "SELECT id, customer_name AS name, preferred_date AS on_date, email, phone FROM event_inquiries WHERE id = ?",
        'booking'        => "SELECT id, customer_name AS name, booking_date AS on_date, email, phone FROM bookings WHERE id = ?",
        'incident'       => "SELECT id, CONCAT('#', id, ' · ', type) AS name, DATE(happened_at) AS on_date FROM incidents WHERE id = ?",
        'purchase_order' => "SELECT p.id, CONCAT('PO #', p.id, IF(v.name IS NULL, '', CONCAT(' · ', v.name))) AS name FROM purchase_orders p LEFT JOIN vendors v ON v.id = p.vendor_id WHERE p.id = ?",
        'receive'        => "SELECT r.id, CONCAT('Receive #', r.id, IF(v.name IS NULL, '', CONCAT(' · ', v.name))) AS name, r.business_date AS on_date FROM receives r LEFT JOIN vendors v ON v.id = r.vendor_id WHERE r.id = ?",
        'event'          => "SELECT id, title AS name FROM events WHERE id = ?",
        'renewal'        => "SELECT id, title AS name, renews_on AS on_date FROM renewals WHERE id = ?",
    ][$type] ?? null;
    if (!$sql) return null;
    try { $st = $pdo->prepare($sql); $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    catch (Throwable $e) { return null; }
}

/** What the record is called ("Feed Barn", "Goldie", "Emily Jenkins"), or '' when it's gone. */
function bcrf_record_name(PDO $pdo, string $type, $id): string {
    $r = bcrf_record_row($pdo, $type, $id);
    return $r ? trim((string)($r['name'] ?? '')) : '';
}

/** Where the record lives in the admin ('' for an unknown kind or a missing record). */
function bcrf_record_url(PDO $pdo, string $type, $id): string {
    $id = bcrf_record_id($id);
    if (!$id) return '';
    $simple = [
        'customer' => '/admin/customers.php?id=', 'organization' => '/admin/organizations.php?id=', 'vendor' => '/admin/vendors.php?id=',
        'inventory' => '/admin/products.php?id=', 'animal' => '/admin/animals.php?id=', 'event_inquiry' => '/admin/party.php?id=',
        'incident' => '/admin/incidents.php?id=', 'purchase_order' => '/admin/purchase_orders.php?id=', 'receive' => '/admin/receive.php?id=',
        'renewal' => '/admin/renewals.php?id=',
    ];
    if (isset($simple[$type])) return $simple[$type] . $id;
    if ($type === 'booking') {   // bookings are shown by day on the Bookings screen
        $r = bcrf_record_row($pdo, 'booking', $id);
        return '/admin/admin.php?view=bookings' . (!empty($r['on_date']) ? '&day=' . rawurlencode((string)$r['on_date']) : '');
    }
    if ($type === 'event') return '/admin/admin.php?view=events';
    return '';
}

/** "Vendor: Feed Barn" style label for a link; falls back to "Vendor #12" when the record is gone. */
function bcrf_record_label(PDO $pdo, string $type, $id): string {
    $kind = bcrf_record_kinds()[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
    $name = bcrf_record_name($pdo, $type, $id);
    if ($name !== '') return $kind . ': ' . $name;
    return $kind . ' ' . (bcrf_record_id($id) ? '#' . bcrf_record_id($id) : (string)$id);
}

/** Which session flash key the page at $back reads ('' when the page shows the shared doc flash itself). */
function bcrf_record_flash_key(string $back): string {
    $map = ['customers' => 'crm_flash', 'organizations' => 'org_flash', 'vendors' => 'vend_flash', 'animals' => 'an_flash', 'party' => 'party_flash',
            'products' => 'prod_flash', 'purchase_orders' => 'po_flash', 'receive' => 'recv_flash', 'incidents' => 'inc_flash', 'animal_expenses' => 'ax_flash', 'renewals' => 'ren_flash'];
    return preg_match('#^/admin/([a-z_]+)\.php#', $back, $m) ? ($map[$m[1]] ?? '') : '';
}

/** Only ever go back to one of our own admin pages. */
function bcrf_record_safe_back($b): string {
    $b = (string)$b;
    return preg_match('#^/admin/[a-z_]+\.php(\?[A-Za-z0-9_=&%.:\-]*)?(\#[a-z0-9_-]+)?$#', $b) ? $b : '';
}

// ── Contact buttons ──────────────────────────────────────────────────────────────────────────────

/** tel: target (digits and a leading +), '' when there are fewer than 7 digits. */
function bcrf_contact_tel(?string $phone): string {
    $d = preg_replace('/[^0-9+]/', '', (string)$phone);
    $d = ($d !== '' && $d[0] === '+' ? '+' : '') . str_replace('+', '', $d);
    return strlen(ltrim($d, '+')) >= 7 ? $d : '';
}

/**
 * Call / Text / Email / Map buttons. Any of them is left out when its value is missing or doesn't look right.
 * $name goes into the buttons' spoken labels ("Call Feed Barn").
 */
function bcrf_contact_buttons_html(?string $phone, ?string $email, string $name = '', ?string $address = null, string $wrap = 'flex flex-wrap gap-2 mb-4'): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $cls = 'inline-flex items-center justify-center min-h-10 px-3 rounded text-sm font-semibold border border-stone-300 text-stone-700 bg-white hover:bg-stone-100';
    $who = trim($name);
    $b = [];
    if (($tel = bcrf_contact_tel($phone)) !== '') {
        $b[] = '<a class="' . $cls . '" href="tel:' . $e($tel) . '" aria-label="' . $e(trim('Call ' . $who)) . '">📞 Call</a>';
        $b[] = '<a class="' . $cls . '" href="sms:' . $e($tel) . '" aria-label="' . $e(trim('Text ' . $who)) . '">💬 Text</a>';
    }
    $mail = trim((string)$email);
    if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) $b[] = '<a class="' . $cls . '" href="mailto:' . $e($mail) . '" aria-label="' . $e(trim('Email ' . $who)) . '">✉️ Email</a>';
    $addr = trim(preg_replace('/\s+/', ' ', (string)$address));
    if ($addr !== '') $b[] = '<a class="' . $cls . '" href="https://www.google.com/maps/search/?api=1&amp;query=' . $e(rawurlencode($addr)) . '" target="_blank" rel="noopener noreferrer" aria-label="' . $e(trim('Map ' . $who)) . '">📍 Map</a>';
    return $b ? '<div class="' . $wrap . '">' . implode('', $b) . '</div>' : '';
}

// ── Tasks on a record ────────────────────────────────────────────────────────────────────────────

/** Tasks linked to a record. $open: only open / started ones. Never throws. */
function bcrf_record_tasks(PDO $pdo, string $type, int $id, bool $open = true, int $limit = 20): array {
    try {
        $st = $pdo->prepare("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.due_time, t.assignee_user_id, t.assignee_role, t.created_by,
                                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS assignee_name
                             FROM tasks t LEFT JOIN users u ON u.id = t.assignee_user_id
                             WHERE t.related_type = ? AND t.related_id = ?" . ($open ? " AND t.status IN ('open', 'in_progress')" : '') . "
                             ORDER BY t.due_date IS NULL, t.due_date, t.id LIMIT " . max(1, min(100, $limit)));
        $st->execute([$type, (string)$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** How many tasks were finished for a record (for "3 done"). */
function bcrf_record_tasks_done(PDO $pdo, string $type, int $id): int {
    try { $st = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE related_type = ? AND related_id = ? AND status = 'done'"); $st->execute([$type, (string)$id]); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** "Tasks" card: open tasks for the record, Add a task, and (managers) every task for it. '' when tasks aren't set up or the person can't see tasks. */
function bcrf_record_tasks_box_html(PDO $pdo, string $type, int $id): string {
    if (!isset(bcrf_record_kinds()[$type]) || $id <= 0) return '';
    $role = bcrf_record_role();
    if (!bcrf_is_owner($role) && !(function_exists('hasViewAccess') && hasViewAccess($role, 'tasks'))) return '';
    try { $pdo->query("SELECT 1 FROM tasks LIMIT 0"); } catch (Throwable $e) { return ''; }
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $mgr = bcrf_is_owner($role) || (function_exists('hasViewAccess') && hasViewAccess($role, 'tasks_manage'));
    $uid = (int)bcrf_record_uid();
    $rows = array_values(array_filter(bcrf_record_tasks($pdo, $type, $id), function ($t) use ($mgr, $uid, $role) {
        if ($mgr) return true;   // same rule as bcrf_tasks_can_see()
        if (!empty($t['assignee_user_id'])) return (int)$t['assignee_user_id'] === $uid || (int)$t['created_by'] === $uid;
        if (!empty($t['assignee_role'])) return $t['assignee_role'] === $role || (int)$t['created_by'] === $uid;
        return true;
    }));
    $done = bcrf_record_tasks_done($pdo, $type, $id);
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $rel = rawurlencode($type . ':' . $id);
    $btn = 'inline-flex items-center justify-center min-h-10 px-3 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
    $o = '<section class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4" id="tasks">'
       . '<div class="flex flex-wrap items-center justify-between gap-2 mb-2"><h2 class="text-base font-semibold text-stone-800">✅ Tasks</h2>'
       . '<div class="flex flex-wrap gap-2"><a class="' . $btn . '" href="/admin/tasks.php?new=1&amp;related=' . $e($rel) . '">Add a task</a>'
       . ($mgr ? '<a class="' . $btn . '" href="/admin/tasks.php?tab=all&amp;status=all&amp;related=' . $e($rel) . '">All tasks for this</a>' : '') . '</div></div>';
    if (!$rows) {
        $o .= '<p class="text-sm text-stone-500">No open tasks' . ($done ? ' · ' . $done . ' done' : '') . '.</p>';
    } else {
        $o .= '<ul class="divide-y divide-stone-100">';
        foreach ($rows as $t) {
            $late = $t['due_date'] && $t['due_date'] < $today;
            $who = !empty($t['assignee_user_id']) ? ($t['assignee_name'] ?: 'someone') : (!empty($t['assignee_role']) ? (defined('BCRF_TASK_ROLES') ? (BCRF_TASK_ROLES[$t['assignee_role']] ?? $t['assignee_role']) : $t['assignee_role']) : 'Anyone');
            $o .= '<li class="py-2 text-sm flex flex-wrap items-baseline justify-between gap-2"><a class="underline text-amber-800" href="/admin/tasks.php?id=' . (int)$t['id'] . '">' . $e($t['title']) . '</a>'
                . '<span class="text-xs ' . ($late ? 'text-red-700 font-semibold' : 'text-stone-500') . '">'
                . ($t['due_date'] ? ($late ? 'late, was due ' : 'due ') . $e(date('M j', strtotime((string)$t['due_date']))) : 'no date') . ' · ' . $e($who)
                . ($t['status'] === 'in_progress' ? ' · started' : '') . '</span></li>';
        }
        $o .= '</ul>' . ($done ? '<p class="text-xs text-stone-500 mt-1">' . $done . ' done.</p>' : '');
    }
    return $o . '</section>';
}

// ── Changes (audit trail) ────────────────────────────────────────────────────────────────────────

/** Fields never worth showing (or not safe to show) in a change list. */
function bcrf_record_change_skip(string $k): bool {
    return in_array($k, ['updated_at', 'updated_by', 'created_at', 'created_by', 'password', 'token', 'csrf_token', 'secret', 'pin_hash'], true)
        || str_contains($k, 'password') || str_contains($k, 'token') || str_contains($k, 'secret');
}

/** Short text for a before/after value. */
function bcrf_record_change_val($v): string {
    if ($v === null || $v === '') return '(blank)';
    if (is_bool($v)) return $v ? 'yes' : 'no';
    if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
    $v = trim(preg_replace('/\s+/', ' ', (string)$v));
    return mb_strlen($v) > 60 ? mb_substr($v, 0, 57) . '…' : $v;
}

/** "price: 4.00 → 5.00" lines for the fields that differ. */
function bcrf_record_change_diff(?string $before, ?string $after, int $max = 6): array {
    $b = $before ? (json_decode($before, true) ?: []) : [];
    $a = $after ? (json_decode($after, true) ?: []) : [];
    if (!is_array($b) || !is_array($a)) return [];
    $out = [];
    foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $k) {
        if (!is_string($k) || bcrf_record_change_skip($k)) continue;
        $bv = $b[$k] ?? null; $av = $a[$k] ?? null;
        if ((string)json_encode($bv) === (string)json_encode($av)) continue;
        if (is_numeric($bv) && is_numeric($av) && (float)$bv === (float)$av) continue;
        $out[] = str_replace('_', ' ', $k) . ': ' . bcrf_record_change_val($bv) . ' → ' . bcrf_record_change_val($av);
        if (count($out) >= $max) break;
    }
    return $out;
}

/** Newest-first audit events for one record. Never throws. */
function bcrf_record_changes(PDO $pdo, string $type, int $id, int $limit = 30): array {
    $k = bcrf_record_kinds()[$type] ?? null;
    if (!$k || $id <= 0) return [];
    try {
        $in = implode(',', array_fill(0, count($k['audit']), '?'));
        $st = $pdo->prepare("SELECT occurred_at, actor_label, action, object_label, before_json, after_json FROM audit_events
                             WHERE object_type IN ($in) AND object_id = ? ORDER BY occurred_at DESC, id DESC LIMIT " . max(1, min(200, $limit)));
        $st->execute(array_merge($k['audit'], [(string)$id]));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Folded "Changes" card: when, who, what. '' when nothing was ever logged. */
function bcrf_record_changes_html(PDO $pdo, string $type, int $id, int $limit = 30): string {
    $rows = bcrf_record_changes($pdo, $type, $id, $limit);
    if (!$rows) return '';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $verb = ['create' => 'Added', 'update' => 'Changed', 'delete' => 'Removed', 'export' => 'Exported'];
    $o = '<details class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4" id="changes"><summary class="cursor-pointer text-base font-semibold text-stone-800">🕘 Changes <span class="text-sm font-normal text-stone-500">('
       . count($rows) . (count($rows) >= $limit ? '+' : '') . ')</span></summary><ul class="divide-y divide-stone-100 mt-2">';
    foreach ($rows as $r) {
        $when = (new DateTimeImmutable((string)$r['occurred_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('M j, Y g:i A');
        $diff = bcrf_record_change_diff($r['before_json'], $r['after_json']);
        $o .= '<li class="py-2 text-sm"><span class="text-stone-800">' . $e($verb[$r['action']] ?? ucfirst((string)$r['action'])) . ($r['object_label'] !== '' ? ': ' . $e($r['object_label']) : '') . '</span>'
            . '<span class="block text-xs text-stone-500">' . $e($when) . ' · ' . $e($r['actor_label'] ?: 'Unknown') . '</span>'
            . ($diff ? '<span class="block text-xs text-stone-600">' . implode('<br>', array_map($e, $diff)) . '</span>' : '') . '</li>';
    }
    return $o . '</ul><p class="text-xs text-stone-500 mt-2">From the audit log. Owners see every change in Settings › Audit log.</p></details>';
}

// ── Dated notes ──────────────────────────────────────────────────────────────────────────────────

function bcrf_record_notes_ready(PDO $pdo): bool {
    static $ready = [];
    $k = spl_object_id($pdo);
    if (isset($ready[$k])) return $ready[$k];
    try { $pdo->query("SELECT 1 FROM record_notes LIMIT 0"); return $ready[$k] = true; }
    catch (Throwable $e) { return $ready[$k] = false; }
}

function bcrf_record_takes_notes(string $type): bool { return !empty(bcrf_record_kinds()[$type]['notes']); }

/** Notes on a record, newest first, with the writer's name. $withHidden adds hidden ones (shown greyed, with Bring back). */
function bcrf_record_notes(PDO $pdo, string $type, int $id, bool $withHidden = false): array {
    if (!bcrf_record_notes_ready($pdo)) return [];
    $st = $pdo->prepare("SELECT n.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS by_name FROM record_notes n LEFT JOIN users u ON u.id = n.created_by
                         WHERE n.record_type = ? AND n.record_id = ?" . ($withHidden ? '' : ' AND n.deleted_at IS NULL') . " ORDER BY n.created_at DESC, n.id DESC LIMIT 200");
    $st->execute([$type, $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_record_note_get(PDO $pdo, int $noteId): array {
    $st = $pdo->prepare("SELECT * FROM record_notes WHERE id = ?");
    $st->execute([$noteId]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) throw new BcrfRecordError('That note was not found.');
    return $n;
}

/** Add a note. Returns its id. Throws BcrfRecordError with a message for the person. */
function bcrf_record_note_add(PDO $pdo, string $type, int $id, string $body, ?int $uid): int {
    if (!bcrf_record_notes_ready($pdo)) throw new BcrfRecordError('Run migration 0067 in Settings › Database first.');
    if (!bcrf_record_takes_notes($type)) throw new BcrfRecordError('Notes can\'t be added there.');
    if (!bcrf_record_can($type)) throw new BcrfRecordError('You can\'t add notes there.');
    if (!bcrf_record_row($pdo, $type, $id)) throw new BcrfRecordError('That record was not found.');
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '') throw new BcrfRecordError('Type the note first.');
    if (mb_strlen($body) > BCRF_NOTE_MAX) throw new BcrfRecordError('Notes can be up to ' . BCRF_NOTE_MAX . ' characters.');
    $pdo->prepare("INSERT INTO record_notes (record_type, record_id, body, created_at, created_by) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([$type, $id, $body, $uid]);
    return (int)$pdo->lastInsertId();
}

/** The writer (within BCRF_NOTE_UNDO_HOURS) or an Owner can hide a note; the same people can bring it back. */
function bcrf_record_note_can_hide(array $n, ?int $uid = null, ?bool $owner = null): bool {
    $owner = $owner ?? bcrf_record_is_owner();
    if ($owner) return true;
    $uid = $uid ?? bcrf_record_uid();
    if (!$uid || (int)$n['created_by'] !== (int)$uid) return false;
    $at = strtotime((string)$n['created_at'] . ' UTC');
    return $at !== false && time() - $at <= BCRF_NOTE_UNDO_HOURS * 3600;
}

/** Hide ($hide = true) or bring back a note. Returns the note. */
function bcrf_record_note_hide(PDO $pdo, int $noteId, bool $hide, ?int $uid): array {
    $n = bcrf_record_note_get($pdo, $noteId);
    if (!bcrf_record_can((string)$n['record_type'])) throw new BcrfRecordError('You can\'t change notes there.');
    if (!bcrf_record_note_can_hide($n, $uid)) throw new BcrfRecordError('Only the person who wrote it (the same day) or an owner can ' . ($hide ? 'hide' : 'bring back') . ' a note.');
    $pdo->prepare("UPDATE record_notes SET deleted_at = " . ($hide ? 'UTC_TIMESTAMP()' : 'NULL') . ", deleted_by = ? WHERE id = ?")->execute([$hide ? $uid : null, $noteId]);
    return $n;
}

/**
 * "Notes" card: add box on top, then the notes newest first, each with when and who.
 * $pinned: the record's older single notes field, shown at the bottom as "Earlier note" so nothing is lost.
 */
function bcrf_record_notes_box_html(PDO $pdo, string $type, int $id, string $back, string $csrf, string $pinned = ''): string {
    if (!bcrf_record_takes_notes($type) || $id <= 0) return '';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $o = '<section class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4" id="notes"><h2 class="text-base font-semibold text-stone-800 mb-2">🗒️ Notes</h2>';
    if (!bcrf_record_notes_ready($pdo)) return $o . '<p class="text-sm text-stone-500">Dated notes appear after migration 0067 runs in <a class="underline text-amber-800" href="/admin/migrations.php">Settings › Database</a>.</p></section>';
    $owner = bcrf_record_is_owner();
    $uid = bcrf_record_uid();
    $notes = bcrf_record_notes($pdo, $type, $id, true);
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $o .= '<form method="POST" action="/admin/record_note.php" class="mb-3">'
        . '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '"><input type="hidden" name="act" value="add">'
        . '<input type="hidden" name="type" value="' . $e($type) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . $e($back) . '">'
        . '<label class="block text-xs font-semibold text-stone-600 mb-1" for="note_body_' . $e($type) . '">Add a note <span class="font-normal text-stone-400">staff only, dated and signed</span></label>'
        . '<textarea id="note_body_' . $e($type) . '" name="body" rows="2" maxlength="' . BCRF_NOTE_MAX . '" required class="p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full mb-2" placeholder="e.g. Called about the Dec 5 date, will confirm Friday"></textarea>'
        . '<button class="px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold">Save note</button></form>';
    $shown = 0;
    if ($notes) {
        $o .= '<ul class="divide-y divide-stone-100">';
        foreach ($notes as $n) {
            $hidden = $n['deleted_at'] !== null;
            $canHide = bcrf_record_note_can_hide($n, $uid, $owner);
            if ($hidden && !$canHide) continue;
            $shown++;
            $when = (new DateTimeImmutable((string)$n['created_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('M j, Y g:i A');
            $o .= '<li class="py-2 text-sm' . ($hidden ? ' opacity-50' : '') . '"><p class="text-stone-800 whitespace-pre-line">' . $e($n['body']) . '</p>'
                . '<p class="text-xs text-stone-500 flex flex-wrap gap-2 items-center"><span>' . $e($when) . ' · ' . $e($n['by_name'] ?: 'Unknown') . ($hidden ? ' · hidden' : '') . '</span>';
            if ($canHide) {
                $o .= '<form method="POST" action="/admin/record_note.php" class="inline"><input type="hidden" name="csrf_token" value="' . $e($csrf) . '">'
                    . '<input type="hidden" name="act" value="' . ($hidden ? 'unhide' : 'hide') . '"><input type="hidden" name="note_id" value="' . (int)$n['id'] . '"><input type="hidden" name="back" value="' . $e($back) . '">'
                    . '<button class="underline text-amber-800"' . ($hidden ? '' : ' data-confirm="Hide this note? You or an owner can bring it back."') . '>' . ($hidden ? 'Bring back' : 'Hide') . '</button></form>';
            }
            $o .= '</p></li>';
        }
        $o .= '</ul>';
    }
    $pinned = trim($pinned);
    if ($pinned !== '') $o .= '<div class="mt-3 pt-3 border-t border-stone-100"><p class="text-xs font-semibold text-stone-600">Earlier note</p><p class="text-sm text-stone-700 whitespace-pre-line">' . $e($pinned) . '</p></div>';
    elseif (!$shown) $o .= '<p class="text-sm text-stone-500">No notes yet.</p>';
    return $o . '</section>';
}

// ── CSV ──────────────────────────────────────────────────────────────────────────────────────────

/** Cell text safe for a spreadsheet: free text starting with = + - @ is shown as text, not run as a formula. */
function bcrf_csv_cell($v) {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'yes' : 'no';
    if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) return "'" . $v;
    return $v;
}

/** Cents as a plain number for spreadsheets ("12.50"), '' when unknown. */
function bcrf_csv_money($cents): string { return $cents === null || $cents === '' ? '' : number_format(((int)$cents) / 100, 2, '.', ''); }

/** Send a CSV download, log it as an export, and stop. */
function bcrf_csv_send(PDO $pdo, string $filename, array $header, iterable $rows, string $what): void {
    $all = [];
    foreach ($rows as $r) $all[] = $r;
    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$t, $u, $n, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $u, 'actor_label' => $n, 'ip' => $ip, 'action' => 'export', 'object_type' => 'report',
                                'object_id' => null, 'object_label' => $what . ' CSV (' . count($all) . ' rows)']);
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");   // so Excel reads accents and emoji right
    fputcsv($f, $header, ',', '"', '');
    foreach ($all as $r) fputcsv($f, array_map('bcrf_csv_cell', array_values($r)), ',', '"', '');
    fclose($f);
    exit;
}

/** "Download CSV" link that keeps the page's current filters. $params: the filters to pass on. */
function bcrf_csv_link_html(string $what, array $params = [], string $text = 'Download CSV'): string {
    $q = http_build_query(['what' => $what] + array_filter($params, fn($v) => $v !== null && $v !== ''));
    return '<a class="inline-flex items-center justify-center min-h-10 px-3 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white" href="/admin/export.php?'
         . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">⬇️ ' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
}

}
