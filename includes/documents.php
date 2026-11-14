<?php
// includes/documents.php — Invoices & receipts for everything that comes in (Nov 14, 2026; migration 0066).
//   A document is one invoice, receipt, packing slip or statement, with one or more pages (photos or PDFs). It belongs to
//   a receive, a purchase order, an animal expense, an "other expense" (fuel, repairs, utilities...) or nothing yet: the
//   Inbox, for a receipt snapped at the truck and linked later.
//   Files: photos are checked, turned upright, shrunk to 2400 px and saved as JPEG (which also drops GPS data); PDFs are
//   kept as-is; iPhone HEIC is turned into JPEG when the server can, otherwise kept as-is for download. Up to 20 MB each.
//   Saved under a random name OUTSIDE the website folder (bcrf-uploads/documents/YYYY/MM), only sent through
//   admin/document_file.php to staff who can see that document. The weekly files backup already copies bcrf-uploads.
//   Never removed: an Owner can delete (hide) a document or one page, and restore it.
// Who: anyone with inventory edit (the people who receive stock) for receives, orders, other expenses and the Inbox;
// animal-expense documents need the 'animal_expenses' view, like the expenses themselves. Owners see everything.
// Nov 14, 2026 (later; migration 0067): papers on a vendor (insurance certificate, W-9, contract) or an animal (vet record,
// registration papers), with an optional expiry date; the bell warns 30 days before (includes/notifications.php).
// Vendor papers: the same people as vendors (inventory edit). Animal papers: anyone who can open animals.
if (!function_exists('bcrf_docs_ready')) {

if (!defined('BCRF_DOC_MAX_BYTES')) define('BCRF_DOC_MAX_BYTES', 20 * 1024 * 1024);
if (!defined('BCRF_DOC_MAX_FILES')) define('BCRF_DOC_MAX_FILES', 30);    // pages on one document
if (!defined('BCRF_DOC_MAX_EDGE'))  define('BCRF_DOC_MAX_EDGE', 2400);   // longest side of a saved photo

define('BCRF_DOC_TYPES', ['invoice' => 'Invoice', 'receipt' => 'Receipt', 'packing' => 'Packing slip', 'statement' => 'Statement',
                          'insurance' => 'Insurance certificate', 'w9' => 'W-9 / tax form', 'contract' => 'Contract / agreement',
                          'vet' => 'Vet record', 'registration' => 'Registration papers', 'other' => 'Other']);
define('BCRF_DOC_LINKS', ['receive' => 'Receive', 'po' => 'Purchase order', 'animal_expense' => 'Animal expense', 'expense' => 'Other expense',
                          'vendor' => 'Vendor', 'animal' => 'Animal']);
define('BCRF_DOC_PAPER_TYPES', ['vendor' => ['insurance', 'w9', 'contract', 'other'], 'animal' => ['vet', 'registration', 'contract', 'other']]);
if (!defined('BCRF_DOC_EXPIRY_WARN_DAYS')) define('BCRF_DOC_EXPIRY_WARN_DAYS', 30);
define('BCRF_DOC_EXPENSE_CATS', ['fuel' => 'Fuel', 'repairs' => 'Repairs & upkeep', 'utilities' => 'Utilities', 'supplies' => 'Supplies',
                                 'equipment' => 'Equipment', 'feed' => 'Feed & hay', 'services' => 'Services', 'fees' => 'Fees & permits', 'other' => 'Other']);

class BcrfDocError extends RuntimeException {}

function bcrf_docs_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM purchase_docs LIMIT 0"); $pdo->query("SELECT 1 FROM purchase_doc_files LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/** Migration 0067: expiry dates on papers. */
function bcrf_docs_expiry_ready(PDO $pdo): bool {
    static $ready = [];
    $k = spl_object_id($pdo);
    if (isset($ready[$k])) return $ready[$k];
    try { $pdo->query("SELECT expires_on FROM purchase_docs LIMIT 0"); return $ready[$k] = true; }
    catch (Throwable $e) { return $ready[$k] = false; }
}

/** Papers (vendor / animal) that run out within BCRF_DOC_EXPIRY_WARN_DAYS, or already have, soonest first (no permission filter: the bell uses it). */
function bcrf_doc_expiring(PDO $pdo, ?string $today = null, int $limit = 100): array {
    if (!bcrf_docs_ready($pdo) || !bcrf_docs_expiry_ready($pdo)) return [];
    $today = $today ?? bcrf_doc_today();
    $until = (new DateTimeImmutable($today))->modify('+' . BCRF_DOC_EXPIRY_WARN_DAYS . ' days')->format('Y-m-d');
    $st = $pdo->prepare(bcrf_doc_select() . " WHERE d.deleted_at IS NULL AND d.link_type IN ('vendor', 'animal') AND d.expires_on IS NOT NULL
                         AND d.expires_on <= ? AND d.expires_on >= ? - INTERVAL 60 DAY ORDER BY d.expires_on, d.id LIMIT " . max(1, min(500, $limit)));
    $st->execute([$until, $today]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Who can do what ──────────────────────────────────────────────────────────────────────────────

function bcrf_doc_role(): string { return (string)($_SESSION['user_role'] ?? ''); }
function bcrf_doc_is_owner(): bool { return bcrf_doc_role() === 'administrator'; }

/** Receives, orders, other expenses, the Inbox: the people who receive stock. */
function bcrf_doc_can_stock(): bool {
    $r = bcrf_doc_role();
    return $r === 'administrator' || (function_exists('hasTableAccess') && $r !== '' && hasTableAccess($r, 'inventory', 'edit'));
}

/** Animal-expense receipts: same people who see animal costs. */
function bcrf_doc_can_animal(): bool {
    $r = bcrf_doc_role();
    return $r === 'administrator' || (function_exists('hasViewAccess') && $r !== '' && hasViewAccess($r, 'animal_expenses'));
}

/** Animal papers (vet records, registration): anyone who can open animals. */
function bcrf_doc_can_animal_papers(): bool {
    $r = bcrf_doc_role();
    return $r === 'administrator' || (function_exists('hasTableAccess') && $r !== '' && hasTableAccess($r, 'animals'));
}

function bcrf_doc_can_any(): bool { return bcrf_doc_can_stock() || bcrf_doc_can_animal() || bcrf_doc_can_animal_papers(); }

/** Can the current user work with documents linked this way? (null = Inbox / other expense) */
function bcrf_doc_can_link(?string $type): bool {
    return match ($type) { 'animal_expense' => bcrf_doc_can_animal(), 'animal' => bcrf_doc_can_animal_papers(), default => bcrf_doc_can_stock() };
}

/** Can the current user see (and add pages to / edit) this document? */
function bcrf_doc_can_see(array $d): bool {
    if (!empty($d['deleted_at']) && !bcrf_doc_is_owner()) return false;
    return bcrf_doc_can_link($d['link_type'] ?? null);
}

/** SQL piece limiting a list to what the current user can see ("d" = purchase_docs). */
function bcrf_doc_visible_sql(): string {
    $stock = bcrf_doc_can_stock(); $animal = bcrf_doc_can_animal(); $papers = bcrf_doc_can_animal_papers();
    if ($stock && $animal && $papers) return '1=1';
    $or = [];
    if ($stock) $or[] = "(d.link_type IS NULL OR d.link_type NOT IN ('animal_expense', 'animal'))";
    if ($animal) $or[] = "d.link_type = 'animal_expense'";
    if ($papers) $or[] = "d.link_type = 'animal'";
    return $or ? '(' . implode(' OR ', $or) . ')' : '1=0';
}

// ── Small helpers ────────────────────────────────────────────────────────────────────────────────

function bcrf_doc_today(): string {
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

function bcrf_doc_fmt(?int $cents): string { return $cents === null ? '' : (($cents < 0 ? '-$' : '$') . number_format(abs($cents) / 100, 2)); }

/** "" → null; "85", "$1,200.50" → cents. */
function bcrf_doc_money($v): ?int {
    $v = trim(str_replace([',', '$', ' '], '', (string)$v));
    if ($v === '') return null;
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1000000) throw new BcrfDocError('Enter the total in dollars, like 85 or 85.50.');
    return (int)round((float)$v * 100);
}

function bcrf_doc_date($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v || $v < '2000-01-01') throw new BcrfDocError('Pick a real date.');
    if ($v > (new DateTimeImmutable(bcrf_doc_today()))->modify('+400 days')->format('Y-m-d')) throw new BcrfDocError('That date is too far ahead.');
    return $v;
}

/** "receive:12" / "po:3" / "animal_expense:9" / "vendor:4" / "animal:7" / "expense" / "" → [type|null, id|null]. */
function bcrf_doc_parse_link(string $v): array {
    $v = trim($v);
    if ($v === '' || $v === 'inbox') return [null, null];
    if ($v === 'expense') return ['expense', null];
    if (preg_match('/^(receive|po|animal_expense|vendor|animal):(\d+)$/', $v, $m)) return [$m[1], (int)$m[2]];
    throw new BcrfDocError('Pick what this is for.');
}

/** The linked record's row, or null if it doesn't exist. */
function bcrf_doc_link_row(PDO $pdo, string $type, int $id): ?array {
    $sql = [
        'receive'        => "SELECT r.id, r.vendor_id, r.invoice_ref AS ref, r.business_date AS on_date, v.name AS vendor_name,
                                    (SELECT COALESCE(SUM(line_cost_cents), 0) FROM receive_lines WHERE receive_id = r.id) AS total_cents
                             FROM receives r LEFT JOIN vendors v ON v.id = r.vendor_id WHERE r.id = ?",
        'po'             => "SELECT p.id, p.vendor_id, p.po_ref AS ref, NULL AS on_date, v.name AS vendor_name, NULL AS total_cents
                             FROM purchase_orders p LEFT JOIN vendors v ON v.id = p.vendor_id WHERE p.id = ?",
        'animal_expense' => "SELECT id, NULL AS vendor_id, NULL AS ref, spent_on AS on_date, vendor AS vendor_name, amount_cents AS total_cents, description
                             FROM animal_expenses WHERE id = ?",
        'vendor'         => "SELECT id, id AS vendor_id, NULL AS ref, NULL AS on_date, name AS vendor_name, NULL AS total_cents, name FROM vendors WHERE id = ?",
        'animal'         => "SELECT id, NULL AS vendor_id, NULL AS ref, NULL AS on_date, NULL AS vendor_name, NULL AS total_cents, name FROM animals WHERE id = ?",
    ][$type] ?? null;
    if (!$sql) return null;
    try { $st = $pdo->prepare($sql); $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    catch (Throwable $e) { return null; }
}

/** Where the linked record lives in the admin. */
function bcrf_doc_link_url(?string $type, ?int $id): string {
    if (!$type || !$id) return '';
    return match ($type) {
        'receive'        => '/admin/receive.php?id=' . $id,
        'po'             => '/admin/purchase_orders.php?id=' . $id,
        'animal_expense' => '/admin/animal_expenses.php',
        'vendor'         => '/admin/vendors.php?id=' . $id,
        'animal'         => '/admin/animals.php?id=' . $id . '&tab=papers',
        default          => '',
    };
}

/** "Receive #12", "Other expense · Fuel", "Inbox (not linked yet)". */
function bcrf_doc_link_label(array $d): string {
    $t = $d['link_type'] ?? null; $id = (int)($d['link_id'] ?? 0);
    if (!$t) return 'Inbox (not linked yet)';
    if ($t === 'expense') return 'Other expense' . (!empty($d['expense_category']) ? ' · ' . (BCRF_DOC_EXPENSE_CATS[$d['expense_category']] ?? $d['expense_category']) : '');
    if (($t === 'vendor' || $t === 'animal') && trim((string)($d['link_name'] ?? '')) !== '') return BCRF_DOC_LINKS[$t] . ': ' . $d['link_name'];
    return match ($t) { 'receive' => 'Receive #' . $id, 'po' => 'PO #' . $id, 'animal_expense' => 'Animal expense #' . $id, 'vendor' => 'Vendor #' . $id, 'animal' => 'Animal #' . $id, default => $t };
}

/** Is this a paper (vendor / animal) rather than an invoice or receipt? */
function bcrf_doc_is_paper(array $d): bool { return in_array($d['link_type'] ?? null, ['vendor', 'animal'], true); }

/** "expired Mar 3, 2027" / "expires Mar 3, 2027" / '' and whether it's due soon (or past). */
function bcrf_doc_expiry(array $d, ?string $today = null): array {
    $x = (string)($d['expires_on'] ?? '');
    if ($x === '') return ['', false];
    $today = $today ?? bcrf_doc_today();
    $soon = $x <= (new DateTimeImmutable($today))->modify('+' . BCRF_DOC_EXPIRY_WARN_DAYS . ' days')->format('Y-m-d');
    return [($x < $today ? 'expired ' : 'expires ') . date('M j, Y', strtotime($x)), $soon];
}

function bcrf_doc_vendor_label(array $d): string {
    return trim((string)($d['vendor'] ?? '')) !== '' ? (string)$d['vendor'] : trim((string)($d['vendor_name'] ?? ''));
}

/** One line for lists: "Invoice · Feed Barn · Nov 14, 2026 · $212.40 · #A-1009". */
function bcrf_doc_line(array $d): string {
    $p = [BCRF_DOC_TYPES[$d['doc_type']] ?? 'Document'];
    if (($v = bcrf_doc_vendor_label($d)) !== '') $p[] = $v;
    if (!empty($d['doc_date'])) $p[] = date('M j, Y', strtotime((string)$d['doc_date']));
    if ($d['total_cents'] !== null && $d['total_cents'] !== '') $p[] = bcrf_doc_fmt((int)$d['total_cents']);
    if (!empty($d['ref'])) $p[] = '#' . $d['ref'];
    if (($x = bcrf_doc_expiry($d)[0]) !== '') $p[] = $x;
    return implode(' · ', $p);
}

// ── Reading ──────────────────────────────────────────────────────────────────────────────────────

function bcrf_doc_select(): string {
    return "SELECT d.*, v.name AS vendor,
                   CASE d.link_type WHEN 'vendor' THEN (SELECT lv.name FROM vendors lv WHERE lv.id = d.link_id)
                                    WHEN 'animal' THEN (SELECT la.name FROM animals la WHERE la.id = d.link_id) END AS link_name,
                   (SELECT COUNT(*) FROM purchase_doc_files f WHERE f.doc_id = d.id AND f.deleted_at IS NULL) AS files_n,
                   (SELECT MIN(f.id) FROM purchase_doc_files f WHERE f.doc_id = d.id AND f.deleted_at IS NULL) AS first_file_id,
                   (SELECT f.mime FROM purchase_doc_files f WHERE f.doc_id = d.id AND f.deleted_at IS NULL ORDER BY f.id LIMIT 1) AS first_mime,
                   TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS by_name
            FROM purchase_docs d LEFT JOIN vendors v ON v.id = d.vendor_id LEFT JOIN users u ON u.id = d.created_by";
}

function bcrf_doc_get(PDO $pdo, int $id): array {
    $st = $pdo->prepare(bcrf_doc_select() . " WHERE d.id = ?");
    $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) throw new BcrfDocError('That document was not found.');
    return $d;
}

/** Pages of a document, oldest first. $withDeleted: include removed pages (owners). */
function bcrf_doc_files(PDO $pdo, int $docId, bool $withDeleted = false): array {
    $st = $pdo->prepare("SELECT * FROM purchase_doc_files WHERE doc_id = ?" . ($withDeleted ? '' : ' AND deleted_at IS NULL') . " ORDER BY id");
    $st->execute([$docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_doc_file_get(PDO $pdo, int $fileId): array {
    $st = $pdo->prepare("SELECT * FROM purchase_doc_files WHERE id = ?");
    $st->execute([$fileId]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) throw new BcrfDocError('That file was not found.');
    return $f;
}

/** Documents attached to one record (that the user can see), newest first. */
function bcrf_doc_for(PDO $pdo, string $type, int $id): array {
    $st = $pdo->prepare(bcrf_doc_select() . " WHERE d.link_type = ? AND d.link_id = ? AND d.deleted_at IS NULL AND " . bcrf_doc_visible_sql() . " ORDER BY d.id DESC");
    $st->execute([$type, $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** [record id => first document id] for a list of records, to show a 📎 next to each. */
function bcrf_doc_map(PDO $pdo, string $type, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    try {
        $st = $pdo->prepare("SELECT link_id, MIN(id) AS doc_id FROM purchase_docs WHERE link_type = ? AND deleted_at IS NULL
                             AND link_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY link_id");
        $st->execute(array_merge([$type], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['link_id']] = (int)$r['doc_id'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * The Documents list. $f: show = all | inbox | expense | deleted, q = words / invoice # / amount, vendor_id, from, to.
 */
function bcrf_doc_list(PDO $pdo, array $f = [], int $limit = 100): array {
    $w = [bcrf_doc_visible_sql()]; $a = [];
    $show = (string)($f['show'] ?? 'all');
    if ($show === 'deleted') { $w[] = 'd.deleted_at IS NOT NULL'; if (!bcrf_doc_is_owner()) $w[] = '1=0'; }
    else $w[] = 'd.deleted_at IS NULL';
    if ($show === 'inbox') $w[] = 'd.link_type IS NULL';
    if ($show === 'expense') $w[] = "d.link_type = 'expense'";
    if ($show === 'papers') $w[] = "d.link_type IN ('vendor', 'animal')";
    if ($show === 'expiring') { $w[] = "d.link_type IN ('vendor', 'animal') AND d.expires_on IS NOT NULL AND d.expires_on <= ?"; $a[] = (new DateTimeImmutable(bcrf_doc_today()))->modify('+' . BCRF_DOC_EXPIRY_WARN_DAYS . ' days')->format('Y-m-d'); }
    if (!empty($f['vendor_id'])) { $w[] = 'd.vendor_id = ?'; $a[] = (int)$f['vendor_id']; }
    if (!empty($f['from'])) { $w[] = 'COALESCE(d.doc_date, DATE(d.created_at)) >= ?'; $a[] = (string)$f['from']; }
    if (!empty($f['to']))   { $w[] = 'COALESCE(d.doc_date, DATE(d.created_at)) <= ?'; $a[] = (string)$f['to']; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $or = "(v.name LIKE ? OR d.vendor_name LIKE ? OR d.ref LIKE ? OR d.note LIKE ?
                OR EXISTS (SELECT 1 FROM purchase_doc_files f WHERE f.doc_id = d.id AND f.original_name LIKE ?)";
        array_push($a, $like, $like, $like, $like, $like);
        $money = str_replace([',', '$', ' '], '', $q);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $money)) { $or .= ' OR d.total_cents = ?'; $a[] = (int)round((float)$money * 100); }
        if (preg_match('/^#?(\d+)$/', $q, $m)) { $or .= ' OR d.id = ? OR d.link_id = ?'; $a[] = (int)$m[1]; $a[] = (int)$m[1]; }
        $w[] = $or . ')';
    }
    $order = $show === 'expiring' ? 'd.expires_on, d.id' : 'COALESCE(d.doc_date, DATE(d.created_at)) DESC, d.id DESC';
    $st = $pdo->prepare(bcrf_doc_select() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY ' . $order . ' LIMIT ' . max(1, min(5000, $limit)));
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Receives with no invoice or receipt yet, newest first (for people who receive stock). */
function bcrf_doc_receives_missing(PDO $pdo, int $limit = 50): array {
    if (!bcrf_doc_can_stock()) return [];
    try {
        return $pdo->query("SELECT r.id, r.business_date, r.invoice_ref, v.name AS vendor_name,
                                   (SELECT COALESCE(SUM(line_cost_cents), 0) FROM receive_lines WHERE receive_id = r.id) AS total_cents
                            FROM receives r LEFT JOIN vendors v ON v.id = r.vendor_id
                            WHERE NOT EXISTS (SELECT 1 FROM purchase_docs d WHERE d.link_type = 'receive' AND d.link_id = r.id AND d.deleted_at IS NULL)
                            ORDER BY r.id DESC LIMIT " . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Choices for "What's it for": recent receives, open/recent orders, recent animal expenses. [group label => [value => label]] */
function bcrf_doc_link_choices(PDO $pdo): array {
    $out = [];
    $m = fn($c) => $c !== null ? ' · ' . bcrf_doc_fmt((int)$c) : '';
    try {
        if (bcrf_doc_can_stock()) {
            $o = [];
            foreach ($pdo->query("SELECT r.id, r.business_date, v.name, (SELECT COALESCE(SUM(line_cost_cents), 0) FROM receive_lines WHERE receive_id = r.id) AS t
                                  FROM receives r LEFT JOIN vendors v ON v.id = r.vendor_id ORDER BY r.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $o['receive:' . (int)$r['id']] = 'Receive #' . (int)$r['id'] . ' · ' . date('M j', strtotime((string)$r['business_date'])) . ($r['name'] ? ' · ' . $r['name'] : '') . $m($r['t']);
            if ($o) $out['Receives'] = $o;
            $o = [];
            foreach ($pdo->query("SELECT p.id, p.po_ref, p.status, v.name FROM purchase_orders p LEFT JOIN vendors v ON v.id = p.vendor_id
                                  ORDER BY (p.status IN ('draft','ordered','partial')) DESC, p.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $o['po:' . (int)$r['id']] = 'PO #' . (int)$r['id'] . ($r['name'] ? ' · ' . $r['name'] : '') . ($r['po_ref'] ? ' · ' . $r['po_ref'] : '') . ' · ' . $r['status'];
            if ($o) $out['Purchase orders'] = $o;
        }
    } catch (Throwable $e) { /* purchasing tables not there yet */ }
    try {
        if (bcrf_doc_can_animal()) {
            $o = [];
            foreach ($pdo->query("SELECT id, spent_on, description, amount_cents FROM animal_expenses WHERE voided_at IS NULL ORDER BY spent_on DESC, id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $o['animal_expense:' . (int)$r['id']] = date('M j', strtotime((string)$r['spent_on'])) . ' · ' . mb_strimwidth((string)$r['description'], 0, 40, '…') . $m($r['amount_cents']);
            if ($o) $out['Animal expenses'] = $o;
        }
    } catch (Throwable $e) { /* animals not set up */ }
    try {   // papers (Nov 14, 2026)
        if (bcrf_doc_can_stock()) {
            $o = [];
            foreach ($pdo->query("SELECT id, name FROM vendors WHERE archived_at IS NULL AND is_active = 1 ORDER BY name LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) as $r) $o['vendor:' . (int)$r['id']] = $r['name'];
            if ($o) $out['Vendors (insurance, W-9, contracts)'] = $o;
        }
    } catch (Throwable $e) { /* vendors not set up */ }
    try {
        if (bcrf_doc_can_animal_papers()) {
            $o = [];
            foreach ($pdo->query("SELECT id, name FROM animals WHERE status NOT IN ('sold', 'transferred', 'adopted', 'deceased') ORDER BY name LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) as $r) $o['animal:' . (int)$r['id']] = $r['name'];
            if ($o) $out['Animals (vet records, registration)'] = $o;
        }
    } catch (Throwable $e) { /* animals not set up */ }
    return $out;
}

// ── Saving ───────────────────────────────────────────────────────────────────────────────────────

/**
 * Check and clean the details. $in: doc_type, link ("receive:12" ...) or link_type + link_id, expense_category, vendor_id,
 * vendor_name, doc_date, total, ref, note. Empty vendor / date / total / ref are filled from the linked record.
 */
function bcrf_doc_clean(PDO $pdo, array $in): array {
    $type = (string)($in['doc_type'] ?? 'invoice');
    if (!isset(BCRF_DOC_TYPES[$type])) throw new BcrfDocError('Pick what kind of paper it is.');
    if (array_key_exists('link', $in)) [$lt, $lid] = bcrf_doc_parse_link((string)$in['link']);
    else { $lt = ($in['link_type'] ?? null) ?: null; $lid = isset($in['link_id']) ? ((int)$in['link_id'] ?: null) : null; }
    if ($lt !== null && !isset(BCRF_DOC_LINKS[$lt])) throw new BcrfDocError('Pick what this is for.');
    $row = null;
    if ($lt !== null && $lt !== 'expense') {
        if (!bcrf_doc_can_link($lt)) throw new BcrfDocError('You can\'t attach papers to that.');
        $row = $lid ? bcrf_doc_link_row($pdo, $lt, $lid) : null;
        if (!$row) throw new BcrfDocError('That ' . strtolower(BCRF_DOC_LINKS[$lt]) . ' was not found.');
    } elseif (!bcrf_doc_can_stock()) {
        throw new BcrfDocError('Pick what it\'s for first (an animal expense or an animal).');
    }
    if ($lt === 'expense') $lid = null;
    $cat = null;
    if ($lt === 'expense') {
        $cat = (string)($in['expense_category'] ?? '') ?: 'other';
        if (!isset(BCRF_DOC_EXPENSE_CATS[$cat])) throw new BcrfDocError('Pick what the expense was for.');
    }
    $vid = (int)($in['vendor_id'] ?? 0) ?: null;
    if ($vid) {
        $st = $pdo->prepare("SELECT id FROM vendors WHERE id = ?");
        $st->execute([$vid]);
        if (!$st->fetchColumn()) throw new BcrfDocError('That vendor was not found.');
    }
    $vname = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($in['vendor_name'] ?? ''))), 0, 120) ?: null;
    if ($vid) $vname = null;
    $date = bcrf_doc_date($in['doc_date'] ?? '');
    $total = bcrf_doc_money($in['total'] ?? '');
    $ref = mb_substr(trim((string)($in['ref'] ?? '')), 0, 80) ?: null;
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255) ?: null;
    $expires = null;
    $xv = trim((string)($in['expires_on'] ?? ''));
    if ($xv !== '') {
        $xd = DateTimeImmutable::createFromFormat('!Y-m-d', $xv);
        if (!$xd || $xd->format('Y-m-d') !== $xv || $xv < '2000-01-01' || $xv > '2100-12-31') throw new BcrfDocError('Pick a real expiry date.');
        $expires = $xv;
    }
    if ($row) {   // fill blanks from what it's attached to
        if (!$vid && !$vname) { $vid = (int)($row['vendor_id'] ?? 0) ?: null; $vname = $vid ? null : ((string)($row['vendor_name'] ?? '') ?: null); }
        if (!$date && !empty($row['on_date'])) $date = (string)$row['on_date'];
        if ($total === null && $row['total_cents'] !== null && (int)$row['total_cents'] > 0) $total = (int)$row['total_cents'];
        if (!$ref && !empty($row['ref'])) $ref = mb_substr((string)$row['ref'], 0, 80);
    }
    return ['doc_type' => $type, 'link_type' => $lt, 'link_id' => $lid, 'expense_category' => $cat, 'vendor_id' => $vid, 'vendor_name' => $vname,
            'doc_date' => $date ?? bcrf_doc_today(), 'total_cents' => $total, 'ref' => $ref, 'note' => $note, 'expires_on' => $expires];
}

/**
 * Turn PHP's $_FILES entry (single or files[]) into a list of single files, skipping empty slots.
 * Several inputs with the same name (camera + choose files) arrive merged already.
 */
function bcrf_doc_files_list(?array $f): array {
    if (!$f || !isset($f['name'])) return [];
    $out = [];
    if (is_array($f['name'])) {
        foreach (array_keys($f['name']) as $i) {
            $one = ['name' => $f['name'][$i] ?? '', 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
            if ((int)$one['error'] === UPLOAD_ERR_NO_FILE) continue;
            $out[] = $one;
        }
    } elseif ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $out[] = $f;
    }
    return $out;
}

/** Merge several $_FILES entries (e.g. 'doc_camera' and 'doc_files') into one list. */
function bcrf_doc_files_from(array $files, array $names): array {
    $out = [];
    foreach ($names as $n) $out = array_merge($out, bcrf_doc_files_list($files[$n] ?? null));
    return $out;
}

/** Folder outside the website folder (next to task and incident photos). */
function bcrf_doc_dir(): string {
    $base = getenv('BCRF_UPLOAD_DIR') ?: '';
    if ($base === '') $base = dirname(__DIR__, 3) . '/bcrf-uploads';
    $dir = rtrim($base, '/') . '/documents';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    return $dir;
}

function bcrf_doc_file_path(array $f): string {
    $rel = (string)($f['file'] ?? '');
    if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.(jpg|pdf|heic)$#', $rel)) return '';
    return bcrf_doc_dir() . '/' . $rel;
}

/** HEIC / HEIF (iPhone photos): an ISO box "ftyp" with a HEIF brand. */
function bcrf_doc_is_heic(string $head): bool {
    return strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp'
        && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true);
}

/**
 * Check and save one uploaded file. Returns [rel path, mime, bytes, sha256, width, height, original name].
 * Throws BcrfDocError with a message for the person uploading.
 * ($GLOBALS['bcrf_docs_test_upload'] lets the tests pass a plain file.)
 */
function bcrf_doc_store_file(array $file): array {
    $name = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
    $name = mb_substr(preg_replace('/[^\pL\pN ._()#&,+-]/u', '_', $name), 0, 200) ?: 'file';
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $big = 'Files can be up to ' . (int)(BCRF_DOC_MAX_BYTES / 1048576) . ' MB.';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new BcrfDocError("“{$name}” is too big. {$big}");
    if ($err !== UPLOAD_ERR_OK) throw new BcrfDocError("“{$name}” did not upload. Try again.");
    $tmp = (string)($file['tmp_name'] ?? '');
    if (empty($GLOBALS['bcrf_docs_test_upload']) && !is_uploaded_file($tmp)) throw new BcrfDocError("“{$name}” did not upload. Try again.");
    $size = (int)@filesize($tmp);
    if ($size <= 0) throw new BcrfDocError("“{$name}” is empty.");
    if ($size > BCRF_DOC_MAX_BYTES) throw new BcrfDocError("“{$name}” is too big. {$big}");
    $sha = (string)hash_file('sha256', $tmp);
    $head = (string)@file_get_contents($tmp, false, null, 0, 16);

    $dir = bcrf_doc_dir();
    $sub = gmdate('Y/m');
    if (!is_dir($dir . '/' . $sub) && !@mkdir($dir . '/' . $sub, 0700, true) && !is_dir($dir . '/' . $sub)) throw new BcrfDocError('Could not save the file (folder). Tell an owner.');
    $base = $sub . '/' . bin2hex(random_bytes(12));
    $w = null; $h = null;

    if (str_starts_with($head, '%PDF-')) {
        $rel = $base . '.pdf'; $mime = 'application/pdf';
        if (!@copy($tmp, $dir . '/' . $rel)) throw new BcrfDocError('Could not save the file. Tell an owner.');
    } elseif (bcrf_doc_is_heic($head)) {
        // iPhones usually send JPEG already. When a HEIC arrives, turn it into JPEG if this server's Imagick can read it.
        $im = null;
        if (class_exists('Imagick')) {
            try {
                $im = new Imagick();
                $im->readImage($tmp);
                $im->autoOrient();
                if (max($im->getImageWidth(), $im->getImageHeight()) > BCRF_DOC_MAX_EDGE) $im->thumbnailImage(BCRF_DOC_MAX_EDGE, BCRF_DOC_MAX_EDGE, true);
                $im->setImageFormat('jpeg'); $im->setImageCompressionQuality(85); $im->stripImage();
            } catch (Throwable $e) { $im = null; }
        }
        if ($im) {
            $rel = $base . '.jpg'; $mime = 'image/jpeg';
            if (!@$im->writeImage($dir . '/' . $rel)) throw new BcrfDocError('Could not save the photo. Tell an owner.');
            $w = $im->getImageWidth(); $h = $im->getImageHeight();
        } else {
            $rel = $base . '.heic'; $mime = 'image/heic';
            if (!@copy($tmp, $dir . '/' . $rel)) throw new BcrfDocError('Could not save the file. Tell an owner.');
        }
    } else {
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new BcrfDocError("“{$name}” isn't a photo or PDF. Use a photo (JPEG, PNG, WebP, HEIC) or a PDF.");
        }
        if ($info[0] * $info[1] > 60000000) throw new BcrfDocError("“{$name}” is too large to open.");
        @ini_set('memory_limit', '512M');
        $im = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
            default        => false,
        };
        if (!$im) throw new BcrfDocError("“{$name}” could not be opened.");
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
            $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) $im = $r; }
        }
        $w = imagesx($im); $h = imagesy($im);
        $scale = min(1, BCRF_DOC_MAX_EDGE / max($w, $h));
        // White behind see-through PNGs so they don't turn black as JPEG
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $c = imagecreatetruecolor($nw, $nh);
        imagefill($c, 0, 0, imagecolorallocate($c, 255, 255, 255));
        imagecopyresampled($c, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $w = $nw; $h = $nh;
        $rel = $base . '.jpg'; $mime = 'image/jpeg';
        if (!@imagejpeg($c, $dir . '/' . $rel, 85)) throw new BcrfDocError('Could not save the photo. Tell an owner.');
    }
    @chmod($dir . '/' . $rel, 0600);
    clearstatcache(true, $dir . '/' . $rel);
    return ['file' => $rel, 'mime' => $mime, 'bytes' => (int)filesize($dir . '/' . $rel), 'sha256' => $sha,
            'width' => $w ? min(65535, $w) : null, 'height' => $h ? min(65535, $h) : null, 'original_name' => $name];
}

/** Other documents that already have this exact file: [doc id, ...]. */
function bcrf_doc_dupes(PDO $pdo, string $sha, int $exceptDoc = 0): array {
    $st = $pdo->prepare("SELECT DISTINCT f.doc_id FROM purchase_doc_files f JOIN purchase_docs d ON d.id = f.doc_id
                         WHERE f.sha256 = ? AND f.deleted_at IS NULL AND d.deleted_at IS NULL AND f.doc_id <> ? ORDER BY f.doc_id");
    $st->execute([$sha, $exceptDoc]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Save pages onto a document. Every file is checked first; if any is refused, nothing is saved.
 * Returns ['saved' => n, 'dupes' => [doc ids that already had one of these files]].
 */
function bcrf_doc_add_files(PDO $pdo, int $docId, array $files, ?int $uid): array {
    if (!$files) return ['saved' => 0, 'dupes' => []];
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchase_doc_files WHERE doc_id = ? AND deleted_at IS NULL");
    $st->execute([$docId]);
    if ((int)$st->fetchColumn() + count($files) > BCRF_DOC_MAX_FILES) throw new BcrfDocError('Up to ' . BCRF_DOC_MAX_FILES . ' pages on one document.');
    $stored = [];
    try {
        foreach ($files as $f) $stored[] = bcrf_doc_store_file($f);
    } catch (Throwable $e) {
        foreach ($stored as $s) @unlink(bcrf_doc_dir() . '/' . $s['file']);
        throw $e;
    }
    $dupes = [];
    $ins = $pdo->prepare("INSERT INTO purchase_doc_files (doc_id, file, original_name, mime, bytes, sha256, width, height, created_at, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)");
    foreach ($stored as $s) {
        $dupes = array_merge($dupes, bcrf_doc_dupes($pdo, $s['sha256'], $docId));
        $ins->execute([$docId, $s['file'], $s['original_name'], $s['mime'], $s['bytes'], $s['sha256'], $s['width'], $s['height'], $uid]);
    }
    return ['saved' => count($stored), 'dupes' => array_values(array_unique($dupes))];
}

/**
 * New document with its pages. $in as bcrf_doc_clean(). Needs at least one file.
 * Returns ['id' => doc id, 'saved' => n, 'dupes' => [...]].
 */
function bcrf_doc_create(PDO $pdo, array $in, array $files, ?int $uid): array {
    if (!$files) throw new BcrfDocError('Take a photo or choose a file first.');
    if (count($files) > BCRF_DOC_MAX_FILES) throw new BcrfDocError('Up to ' . BCRF_DOC_MAX_FILES . ' pages on one document.');
    $c = bcrf_doc_clean($pdo, $in);
    $pdo->prepare("INSERT INTO purchase_docs (doc_type, link_type, link_id, expense_category, vendor_id, vendor_name, doc_date, total_cents, ref, note, created_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([$c['doc_type'], $c['link_type'], $c['link_id'], $c['expense_category'], $c['vendor_id'], $c['vendor_name'], $c['doc_date'], $c['total_cents'], $c['ref'], $c['note'], $uid]);
    $id = (int)$pdo->lastInsertId();
    if ($c['expires_on'] !== null && bcrf_docs_expiry_ready($pdo)) $pdo->prepare("UPDATE purchase_docs SET expires_on = ? WHERE id = ?")->execute([$c['expires_on'], $id]);
    try {
        $r = bcrf_doc_add_files($pdo, $id, $files, $uid);
    } catch (Throwable $e) {
        $pdo->prepare("DELETE FROM purchase_docs WHERE id = ?")->execute([$id]);   // nothing was saved for it
        throw $e;
    }
    return ['id' => $id] + $r;
}

/** Change the details (kind, what it's for, vendor, date, total, #, note). */
function bcrf_doc_update(PDO $pdo, int $id, array $in, ?int $uid): void {
    $d = bcrf_doc_get($pdo, $id);
    if (!bcrf_doc_can_see($d) || $d['deleted_at']) throw new BcrfDocError('You can\'t change that document.');
    $c = bcrf_doc_clean($pdo, $in);
    $pdo->prepare("UPDATE purchase_docs SET doc_type = ?, link_type = ?, link_id = ?, expense_category = ?, vendor_id = ?, vendor_name = ?, doc_date = ?,
                   total_cents = ?, ref = ?, note = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
        ->execute([$c['doc_type'], $c['link_type'], $c['link_id'], $c['expense_category'], $c['vendor_id'], $c['vendor_name'], $c['doc_date'],
                   $c['total_cents'], $c['ref'], $c['note'], $uid, $id]);
    if (bcrf_docs_expiry_ready($pdo)) $pdo->prepare("UPDATE purchase_docs SET expires_on = ? WHERE id = ?")->execute([$c['expires_on'], $id]);
}

/** Owner only: hide a document (files stay on the server) or bring it back. */
function bcrf_doc_set_deleted(PDO $pdo, int $id, bool $deleted, ?int $uid): void {
    if (!bcrf_doc_is_owner()) throw new BcrfDocError('Only an owner can delete or restore documents.');
    bcrf_doc_get($pdo, $id);
    $pdo->prepare("UPDATE purchase_docs SET deleted_at = " . ($deleted ? 'UTC_TIMESTAMP()' : 'NULL') . ", deleted_by = ? WHERE id = ?")
        ->execute([$deleted ? $uid : null, $id]);
}

/** Owner only: take one page off a document (kept on the server) or put it back. */
function bcrf_doc_file_set_deleted(PDO $pdo, int $fileId, bool $deleted, ?int $uid): int {
    if (!bcrf_doc_is_owner()) throw new BcrfDocError('Only an owner can remove pages.');
    $f = bcrf_doc_file_get($pdo, $fileId);
    $pdo->prepare("UPDATE purchase_doc_files SET deleted_at = " . ($deleted ? 'UTC_TIMESTAMP()' : 'NULL') . ", deleted_by = ? WHERE id = ?")
        ->execute([$deleted ? $uid : null, $fileId]);
    return (int)$f['doc_id'];
}

// ── Shared HTML ──────────────────────────────────────────────────────────────────────────────────

/** The two file pickers: camera (phones open it straight away) and choose files. Used in every upload form. */
function bcrf_doc_inputs_html(string $prefix = 'doc', bool $compact = false): string {
    $lbl = 'block text-xs font-semibold text-stone-600 mb-1';
    $inp = 'block w-full text-sm text-stone-700 p-2 border border-stone-300 rounded bg-stone-50';
    return '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3' . ($compact ? '' : ' mb-3') . '">'
         . '<div><label class="' . $lbl . '" for="' . $prefix . '_cam">📷 Take a photo</label>'
         . '<input id="' . $prefix . '_cam" class="' . $inp . '" type="file" name="' . $prefix . '_camera[]" accept="image/*" capture="environment"></div>'
         . '<div><label class="' . $lbl . '" for="' . $prefix . '_pick">📎 Or choose photos / PDFs</label>'
         . '<input id="' . $prefix . '_pick" class="' . $inp . '" type="file" name="' . $prefix . '_files[]" accept="image/*,application/pdf" multiple></div>'
         . '</div>';
}

/**
 * "Invoices & receipts" card for a receive, purchase order or animal expense page, or "Papers" card for a vendor or an
 * animal (Nov 14, 2026): what's attached, plus a quick upload form that posts to documents.php and comes back to $back.
 */
function bcrf_doc_box_html(PDO $pdo, string $type, int $id, string $back, string $csrf): string {
    if (!bcrf_docs_ready($pdo)) return '';
    if (!bcrf_doc_can_link($type)) return '';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $paper = isset(BCRF_DOC_PAPER_TYPES[$type]);
    $docs = bcrf_doc_for($pdo, $type, $id);
    $title = $paper ? ($type === 'vendor' ? '📎 Papers <span class="text-sm font-normal text-stone-500">insurance certificate, W-9, contracts</span>'
                                          : '📎 Papers <span class="text-sm font-normal text-stone-500">vet records, registration</span>')
                    : '📎 Invoices &amp; receipts';
    $o = '<section class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm my-4" id="papers">'
       . '<h2 class="text-base font-semibold text-stone-800 mb-2">' . $title . '</h2>';
    if ($docs) {
        $o .= '<ul class="divide-y divide-stone-100 mb-3">';
        foreach ($docs as $d) {
            [$xText, $xSoon] = bcrf_doc_expiry($d);
            $o .= '<li class="py-2 flex gap-3 items-center">' . bcrf_doc_thumb_html($d, 'w-14 h-14')
                . '<span class="min-w-0"><a class="text-sm underline text-amber-800" href="/admin/documents.php?id=' . (int)$d['id'] . '">' . $e(bcrf_doc_line($d)) . '</a>'
                . ($xText !== '' && $xSoon ? '<span class="block text-xs font-semibold text-red-700">' . $e(ucfirst($xText)) . '</span>' : '')
                . '<span class="block text-xs text-stone-500">' . (int)$d['files_n'] . ' page' . ((int)$d['files_n'] === 1 ? '' : 's') . '</span></span></li>';
        }
        $o .= '</ul>';
    } else {
        $o .= '<p class="text-sm text-stone-500 mb-3">' . ($paper ? 'No papers saved yet.' : 'No invoice or receipt saved yet.') . '</p>';
    }
    $o .= '<form method="POST" action="/admin/documents.php" enctype="multipart/form-data">'
        . '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '"><input type="hidden" name="act" value="upload">'
        . '<input type="hidden" name="link" value="' . $e($type . ':' . $id) . '"><input type="hidden" name="back" value="' . $e($back) . '">';
    if ($paper) {
        $lbl = 'block text-xs font-semibold text-stone-600 mb-1';
        $inp = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
        $o .= '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3"><div><label class="' . $lbl . '" for="pp_type_' . $id . '">What is it</label><select id="pp_type_' . $id . '" name="doc_type" class="' . $inp . '">';
        foreach (BCRF_DOC_PAPER_TYPES[$type] as $k) $o .= '<option value="' . $e($k) . '">' . $e(BCRF_DOC_TYPES[$k]) . '</option>';
        $o .= '</select></div>';
        if (bcrf_docs_expiry_ready($pdo)) $o .= '<div><label class="' . $lbl . '" for="pp_exp_' . $id . '">Expires <span class="font-normal text-stone-400">optional; the bell warns ' . BCRF_DOC_EXPIRY_WARN_DAYS . ' days before</span></label><input id="pp_exp_' . $id . '" name="expires_on" type="date" class="' . $inp . '"></div>';
        $o .= '</div>';
    } else {
        $o .= '<input type="hidden" name="doc_type" value="' . ($type === 'po' ? 'other' : ($type === 'animal_expense' ? 'receipt' : 'invoice')) . '">';
    }
    $o .= bcrf_doc_inputs_html('doc')
        . '<button class="px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold">Save to this ' . $e(strtolower(BCRF_DOC_LINKS[$type] ?? 'record')) . '</button>'
        . '<p class="text-xs text-stone-500 mt-2">' . ($paper ? 'Several photos at once are saved as pages of one paper. Open it to change the details.'
                                                          : 'Several photos at once are saved as pages of one document. Vendor, date and total are copied from here; fix them on the document if they differ.') . '</p>'
        . '</form></section>';
    return $o;
}

/** Small preview: the photo, or a PDF / HEIC tile. */
function bcrf_doc_thumb_html(array $d, string $size = 'w-16 h-16'): string {
    $fid = (int)($d['first_file_id'] ?? 0);
    $box = $size . ' shrink-0 rounded border border-stone-200 bg-stone-50 flex items-center justify-center text-xs font-semibold text-stone-500 overflow-hidden';
    if (!$fid) return '<span class="' . $box . '">—</span>';
    if (($d['first_mime'] ?? '') === 'image/jpeg') {
        return '<a class="' . $box . '" href="/admin/documents.php?id=' . (int)$d['id'] . '"><img class="w-full h-full object-cover" src="/admin/document_file.php?id=' . $fid . '&amp;thumb=1" alt="" loading="lazy"></a>';
    }
    return '<a class="' . $box . '" href="/admin/documents.php?id=' . (int)$d['id'] . '">' . (($d['first_mime'] ?? '') === 'application/pdf' ? 'PDF' : 'HEIC') . '</a>';
}

}
