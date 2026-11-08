<?php
// includes/mcp/tools.php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
//
// The farm MCP server's tools (Nov 2026). Each tool = [description, inputSchema,
// annotations, handler]. Handlers get ($pdo, $args, $ctx) and return an array that is
// sent back to the AI as JSON. Throw McpToolError for a message the AI should see.
//
// Every change is written to the admin audit log as "<name> (AI: <app>)".
// Read tools cap results so a big table can't flood the chat.

require_once __DIR__ . '/files.php';

if (!class_exists('McpToolError')) {

class McpToolError extends RuntimeException {}

function mcp_tz(): DateTimeZone { return new DateTimeZone('America/New_York'); }
function mcp_today(): string { return (new DateTime('now', mcp_tz()))->format('Y-m-d'); }

function mcp_date_arg(array $a, string $k, ?string $default): ?string {
    $v = trim((string)($a[$k] ?? ''));
    if ($v === '') return $default;
    $d = DateTime::createFromFormat('Y-m-d', $v, mcp_tz());
    if (!$d || $d->format('Y-m-d') !== $v) throw new McpToolError("$k must be a date like 2026-11-04.");
    return $v;
}
function mcp_limit(array $a, int $def = 25, int $max = 100): int { return max(1, min($max, (int)($a['limit'] ?? $def))); }

/** Columns of a table (cached), so tools work even if a column is missing on the live DB. */
function mcp_cols(PDO $pdo, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        try { $cache[$table] = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field'); }
        catch (Throwable $e) { $cache[$table] = []; }
    }
    return $cache[$table];
}

/** Trim long text fields in list results. */
function mcp_trim_rows(array $rows, int $max = 300): array {
    foreach ($rows as &$r) foreach ($r as $k => $v) {
        if (is_string($v) && mb_strlen($v) > $max) $r[$k] = mb_substr(strip_tags($v), 0, $max) . '…';
    }
    return $rows;
}

function mcp_audit(PDO $pdo, string $action, string $table, ?int $id, string $label): void {
    require_once dirname(__DIR__, 2) . '/admin/includes/audit.php';
    erp_audit($pdo, $action, $table, $id, $label);   // user from $_SESSION, set per request in api/index.php
}

function mcp_slug(string $s): string {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s), '-'));
    return substr($s, 0, 80) ?: 'item-' . time();
}
function mcp_unique_slug(PDO $pdo, string $table, string $slug): string {
    $base = $slug; $i = 2;
    $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE slug = ? LIMIT 1");
    while (true) { $st->execute([$slug]); if (!$st->fetchColumn()) return $slug; $slug = $base . '-' . $i++; }
}

/** Keep only safe-ish HTML for drafts (the admin editor re-saves it anyway). */
function mcp_clean_html(string $h): string {
    $h = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $h);
    $h = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $h);
    $h = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $h);
    return trim($h);
}

// ── Tool definitions ─────────────────────────────────────────────────────────

function mcp_tools(): array {
    $RO = ['readOnlyHint' => true, 'openWorldHint' => false];
    $W  = ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];
    $WD = ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false];
    $date = ['type' => 'string', 'description' => 'YYYY-MM-DD (Eastern time)'];
    $lim  = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100];

    return [
    // ── Read ────────────────────────────────────────────────────────────────
    'farm_overview' => [
        'Today at a glance: visits booked today, pending bookings, new private-event inquiries, low-stock items, today\'s POS sales and upcoming events.',
        ['type' => 'object', 'properties' => new stdClass()], $RO, 'mcp_t_overview'],
    'list_bookings' => [
        'Bookings and day tickets (one row per booking) with customer, party size, date, status and payment. Defaults to today through the next 14 days.',
        ['type' => 'object', 'properties' => [
            'from' => $date, 'to' => $date,
            'status' => ['type' => 'string', 'enum' => ['pending', 'confirmed', 'cancelled', 'completed', 'no_show']],
            'search' => ['type' => 'string', 'description' => 'Name, email or phone contains'], 'limit' => $lim]], $RO, 'mcp_t_list_bookings'],
    'get_booking' => [
        'Everything about one booking, including ticket breakdown and notes.',
        ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']], $RO, 'mcp_t_get_booking'],
    'list_event_inquiries' => [
        'Private event / birthday party inquiries, newest first.',
        ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['new', 'contacted', 'quoted', 'confirmed', 'declined', 'archived']], 'limit' => $lim]], $RO, 'mcp_t_list_inquiries'],
    'list_passes' => [
        'Annual passes with holder, type, status and dates.',
        ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string'], 'search' => ['type' => 'string', 'description' => 'Holder name or email contains'], 'limit' => $lim]], $RO, 'mcp_t_list_passes'],
    'list_inventory' => [
        'Farm stand / shop inventory with stock levels and prices. Use low_stock_only to see what needs restocking.',
        ['type' => 'object', 'properties' => [
            'search' => ['type' => 'string'], 'low_stock_only' => ['type' => 'boolean'],
            'include_inactive' => ['type' => 'boolean'], 'limit' => $lim]], $RO, 'mcp_t_list_inventory'],
    'sales_report' => [
        'Sales between two dates: POS totals (by day and payment method), top items, plus booking and pass revenue. Defaults to the last 7 days.',
        ['type' => 'object', 'properties' => ['from' => $date, 'to' => $date]], $RO, 'mcp_t_sales'],
    'list_content' => [
        'List recipes, blog posts or events (title, slug, status, dates).',
        ['type' => 'object', 'properties' => [
            'type' => ['type' => 'string', 'enum' => ['recipes', 'blog_posts', 'events']],
            'search' => ['type' => 'string'], 'status' => ['type' => 'string'], 'limit' => $lim], 'required' => ['type']], $RO, 'mcp_t_list_content'],
    'get_content' => [
        'One recipe, blog post or event in full, by id or slug.',
        ['type' => 'object', 'properties' => [
            'type' => ['type' => 'string', 'enum' => ['recipes', 'blog_posts', 'events']],
            'id' => ['type' => 'integer'], 'slug' => ['type' => 'string']], 'required' => ['type']], $RO, 'mcp_t_get_content'],
    'get_site_info' => [
        'The farm\'s Site Info: hours, contacts, social links and settings shown on the website.',
        ['type' => 'object', 'properties' => new stdClass()], $RO, 'mcp_t_site_info'],

    // ── Write ───────────────────────────────────────────────────────────────
    'adjust_stock' => [
        'Change an inventory item\'s stock. Give set_to (new count) OR change_by (e.g. -3 or +12). Stock is kept by location (Farmstand, Thrift Shop, Kitchen, Feed Room...): give location (its name) for items kept in more than one place; otherwise the item\'s only location (or the default) is used. Written to the stock history and the audit log.',
        ['type' => 'object', 'properties' => [
            'item_id' => ['type' => 'integer'], 'set_to' => ['type' => 'number'], 'change_by' => ['type' => 'number'],
            'location' => ['type' => 'string'], 'reason' => ['type' => 'string']], 'required' => ['item_id']], $W, 'mcp_t_adjust_stock'],
    'create_blog_draft' => [
        'Create a blog post as a DRAFT (not published, not scheduled). Zac publishes it from the admin. body_html is the article HTML.',
        ['type' => 'object', 'properties' => [
            'title' => ['type' => 'string'], 'body_html' => ['type' => 'string'], 'excerpt' => ['type' => 'string']],
            'required' => ['title', 'body_html']], $W, 'mcp_t_blog_draft'],
    'create_event_draft' => [
        'Create an event as a DRAFT (hidden from the site until published in the admin).',
        ['type' => 'object', 'properties' => [
            'title' => ['type' => 'string'],
            'start' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM, or YYYY-MM-DD with all_day'],
            'end' => ['type' => 'string'], 'all_day' => ['type' => 'boolean'],
            'description' => ['type' => 'string'], 'price' => ['type' => 'number', 'description' => 'Leave out for a free event'],
            'location_note' => ['type' => 'string']], 'required' => ['title', 'start']], $W, 'mcp_t_event_draft'],
    'set_booking_status' => [
        'Change a booking\'s status. "confirmed" checks capacity and EMAILS THE CUSTOMER a confirmation (same as the admin Confirm button); override_capacity skips the capacity check. cancelled / completed / no_show send no email. Ask the user before confirming or cancelling.',
        ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['confirmed', 'cancelled', 'completed', 'no_show']],
            'override_capacity' => ['type' => 'boolean']], 'required' => ['id', 'status']], $WD, 'mcp_t_booking_status'],
    'set_inquiry_status' => [
        'Change a private-event inquiry\'s status (no email is sent).',
        ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['new', 'contacted', 'quoted', 'confirmed', 'declined', 'archived']]],
            'required' => ['id', 'status']], $W, 'mcp_t_inquiry_status'],

    // ── Files (see files.php) ───────────────────────────────────────────────
    'list_files' => [
        'List a folder of the website (path relative to the site root, e.g. "recipes" or "css"). Secrets, config and uploads are hidden.',
        ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], $RO, 'mcp_t_list_files'],
    'read_file' => [
        'Read a website text file (php, html, css, js, json, md, txt, xml, svg), .htaccess, or a PHP error log ("error_log" files, read-only). Large files come back in parts: pass offset_line to continue (for error logs, start near the end using total_lines).',
        ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'offset_line' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['path']], $RO, 'mcp_t_read_file'],
    'write_file' => [
        'Replace (or create) a website text file on the LIVE server. The old version is backed up first (restore_file_backup undoes it). PHP is syntax-checked and refused if broken. If the content has code that a firewall might block, send it as content_base64 instead of content. Note: this edits the live server only, not GitHub; tell the user so they can copy the change to GitHub, or the next upload will overwrite it. js/min.site.js is built from js/*.js and should not be hand-edited.',
        ['type' => 'object', 'properties' => [
            'path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'content_base64' => ['type' => 'string'],
            'expected_sha256' => ['type' => 'string', 'description' => 'Optional: sha256 from read_file; refuses to save if the file changed since'],
            'create' => ['type' => 'boolean', 'description' => 'Allow creating a new file']], 'required' => ['path']], $WD, 'mcp_t_write_file'],
    'edit_file' => [
        'Change part of a website file on the LIVE server without resending it: each edit replaces old_text (must match exactly once, unless replace_all) with new_text. All edits apply together or not at all. Backed up first; PHP syntax-checked. Use *_base64 variants if a firewall might block the text. Pass expected_sha256 (from read_file or a previous write) to refuse if the file changed. Live only, not GitHub.',
        ['type' => 'object', 'properties' => [
            'path' => ['type' => 'string'],
            'edits' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'old_text' => ['type' => 'string'], 'new_text' => ['type' => 'string'],
                'old_text_base64' => ['type' => 'string'], 'new_text_base64' => ['type' => 'string'],
                'replace_all' => ['type' => 'boolean']]]],
            'expected_sha256' => ['type' => 'string']], 'required' => ['path', 'edits']], $WD, 'mcp_t_edit_file'],
    'read_error_log' => [
        'The newest lines of the PHP error log (~/logs/php_errors.log, outside the website). Read-only. Use to diagnose problems; Admin → System Health shows the same errors grouped.',
        ['type' => 'object', 'properties' => ['lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'How many lines from the end (default 200)'],
            'contains' => ['type' => 'string', 'description' => 'Only lines containing this text']]], $RO, 'mcp_t_error_log'],
    'list_file_backups' => [
        'Backups made by write_file (newest first), optionally for one path.',
        ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'limit' => $lim]], $RO, 'mcp_t_list_backups'],
    'restore_file_backup' => [
        'Put a backed-up version back (the current version is backed up first, so this can be undone too).',
        ['type' => 'object', 'properties' => ['backup_id' => ['type' => 'string']], 'required' => ['backup_id']], $WD, 'mcp_t_restore_backup'],
    ];
}

// ── Read handlers ────────────────────────────────────────────────────────────

function mcp_t_overview(PDO $pdo, array $a): array {
    $today = mcp_today();
    $out = ['date' => $today];
    $q = function (string $sql, array $p = []) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return null; } };
    $one = fn($r) => $r === null ? null : ($r[0] ?? null);

    $out['bookings_today'] = $q("SELECT b.id, b.customer_name, b.party_size, b.start_time, b.status, b.payment_status, t.label AS type
                                  FROM bookings b LEFT JOIN booking_types t ON t.id = b.type_id
                                 WHERE b.booking_date = ? AND b.status NOT IN ('cancelled') ORDER BY b.start_time", [$today]);
    $out['pending_bookings'] = ($one($q("SELECT COUNT(*) AS n FROM bookings WHERE status = 'pending' AND booking_date >= ?", [$today])) ?? [])['n'] ?? null;
    $out['new_inquiries'] = ($one($q("SELECT COUNT(*) AS n FROM event_inquiries WHERE status = 'new'")) ?? [])['n'] ?? null;
    $out['low_stock'] = $q("SELECT id, name, stock_quantity, min_stock_level FROM inventory
                             WHERE is_active = 1 AND min_stock_level IS NOT NULL AND stock_quantity <= min_stock_level ORDER BY name LIMIT 30");
    $out['pos_today'] = $one($q("SELECT COUNT(*) AS receipts, COALESCE(SUM(total),0) AS total FROM pos_receipts WHERE DATE(created_at) = ?", [$today]));
    $out['upcoming_events'] = $q("SELECT id, title, start_datetime, status FROM events
                                   WHERE status = 'published' AND (start_datetime >= NOW() OR recurrence <> 'none') ORDER BY start_datetime LIMIT 5");
    return $out;
}

function mcp_t_list_bookings(PDO $pdo, array $a): array {
    $from = mcp_date_arg($a, 'from', mcp_today());
    $to   = mcp_date_arg($a, 'to', (new DateTime($from, mcp_tz()))->modify('+14 days')->format('Y-m-d'));
    $w = ['b.booking_date BETWEEN ? AND ?']; $p = [$from, $to];
    if (!empty($a['status'])) { $w[] = 'b.status = ?'; $p[] = (string)$a['status']; }
    if (trim((string)($a['search'] ?? '')) !== '') {
        $w[] = '(b.customer_name LIKE ? OR b.email LIKE ? OR b.phone LIKE ?)';
        $s = '%' . trim((string)$a['search']) . '%'; array_push($p, $s, $s, $s);
    }
    $st = $pdo->prepare("SELECT b.id, b.booking_date, b.start_time, b.end_time, t.label AS type, b.customer_name, b.email, b.phone,
                                b.party_size, b.status, b.payment_status, b.amount_total
                           FROM bookings b LEFT JOIN booking_types t ON t.id = b.type_id
                          WHERE " . implode(' AND ', $w) . " ORDER BY b.booking_date, b.start_time LIMIT " . mcp_limit($a, 50));
    $st->execute($p);
    return ['from' => $from, 'to' => $to, 'bookings' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

function mcp_booking_row(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT b.*, t.label AS type FROM bookings b LEFT JOIN booking_types t ON t.id = b.type_id WHERE b.id = ?");
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) throw new McpToolError("No booking with id $id.");
    return $b;
}
function mcp_t_get_booking(PDO $pdo, array $a): array {
    $b = mcp_booking_row($pdo, (int)($a['id'] ?? 0));
    unset($b['token'], $b['payment_reference']);   // customer self-service link secret / Stripe id
    if (!empty($b['ticket_breakdown'])) $b['ticket_breakdown'] = json_decode((string)$b['ticket_breakdown'], true) ?? $b['ticket_breakdown'];
    return $b;
}

function mcp_t_list_inquiries(PDO $pdo, array $a): array {
    $w = ''; $p = [];
    if (!empty($a['status'])) { $w = 'WHERE status = ?'; $p[] = (string)$a['status']; }
    $st = $pdo->prepare("SELECT * FROM event_inquiries $w ORDER BY created_at DESC LIMIT " . mcp_limit($a, 25));
    $st->execute($p);
    return ['inquiries' => mcp_trim_rows($st->fetchAll(PDO::FETCH_ASSOC), 600)];
}

function mcp_t_list_passes(PDO $pdo, array $a): array {
    $w = []; $p = [];
    if (!empty($a['status'])) { $w[] = 'p.status = ?'; $p[] = (string)$a['status']; }
    if (trim((string)($a['search'] ?? '')) !== '') { $w[] = '(p.holder_name LIKE ? OR p.email LIKE ?)'; $s = '%' . trim((string)$a['search']) . '%'; array_push($p, $s, $s); }
    $st = $pdo->prepare("SELECT p.id, p.holder_name, p.email, p.phone, t.name AS pass_type, p.status, p.payment_status, p.amount_paid,
                                p.valid_from, p.valid_until
                           FROM passes p LEFT JOIN pass_types t ON t.id = p.pass_type_id"
                       . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY p.id DESC LIMIT " . mcp_limit($a, 50));
    $st->execute($p);
    return ['passes' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

function mcp_t_list_inventory(PDO $pdo, array $a): array {
    $cols = mcp_cols($pdo, 'inventory');
    $want = array_values(array_intersect(['id', 'name', 'sku', 'price', 'stock_quantity', 'min_stock_level', 'unit', 'is_active', 'updated_at'], $cols));
    $select = array_map(static fn($col) => 'i.`' . $col . '`', $want);
    $select[] = 'ic.name AS category';
    $select[] = 'ic.slug AS category_slug';
    $w = []; $p = [];
    if (empty($a['include_inactive']) && in_array('is_active', $cols, true)) $w[] = 'i.is_active = 1';
    if (!empty($a['low_stock_only'])) $w[] = 'i.min_stock_level IS NOT NULL AND i.stock_quantity <= i.min_stock_level';
    if (trim((string)($a['search'] ?? '')) !== '') {
        $w[] = in_array('sku', $cols, true) ? '(i.name LIKE ? OR i.sku LIKE ?)' : 'i.name LIKE ?';
        $s = '%' . trim((string)$a['search']) . '%'; $p[] = $s; if (in_array('sku', $cols, true)) $p[] = $s;
    }
    $st = $pdo->prepare('SELECT ' . implode(', ', $select)
                       . ' FROM inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id'
                       . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                       . ' ORDER BY i.name LIMIT ' . mcp_limit($a, 50));
    $st->execute($p);
    return ['items' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

function mcp_t_sales(PDO $pdo, array $a): array {
    $to   = mcp_date_arg($a, 'to', mcp_today());
    $from = mcp_date_arg($a, 'from', (new DateTime($to, mcp_tz()))->modify('-6 days')->format('Y-m-d'));
    if ($from > $to) throw new McpToolError('from must be on or before to.');
    $f = $from . ' 00:00:00'; $t = $to . ' 23:59:59';
    $run = function (string $sql, array $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };
    $out = ['from' => $from, 'to' => $to];
    $out['pos_summary'] = $run("SELECT COUNT(*) AS receipts, COALESCE(SUM(total),0) AS revenue, COALESCE(SUM(tax),0) AS tax,
                                       COALESCE(SUM(discount),0) AS discounts, COALESCE(AVG(total),0) AS average
                                  FROM pos_receipts WHERE created_at BETWEEN ? AND ?", [$f, $t])[0] ?? null;
    $out['pos_by_day'] = $run("SELECT DATE(created_at) AS day, COUNT(*) AS receipts, SUM(total) AS revenue
                                 FROM pos_receipts WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY day", [$f, $t]);
    $out['pos_by_payment'] = $run("SELECT payment_method, COUNT(*) AS receipts, SUM(total) AS revenue
                                     FROM pos_receipts WHERE created_at BETWEEN ? AND ? GROUP BY payment_method ORDER BY revenue DESC", [$f, $t]);
    try {
        $out['top_items'] = $run("SELECT i.name, SUM(sh.quantity_sold) AS qty, ROUND(SUM(sh.quantity_sold * i.price), 2) AS revenue
                                    FROM sales_history sh JOIN inventory i ON i.id = sh.inventory_id
                                   WHERE sh.sold_at BETWEEN ? AND ? GROUP BY i.id, i.name ORDER BY revenue DESC LIMIT 15", [$f, $t]);
    } catch (Throwable $e) { $out['top_items'] = null; }
    try {
        $out['bookings_paid'] = $run("SELECT COUNT(*) AS bookings, COALESCE(SUM(amount_total),0) AS revenue
                                        FROM bookings WHERE payment_status = 'paid' AND COALESCE(paid_at, created_at) BETWEEN ? AND ?", [$f, $t])[0] ?? null;
    } catch (Throwable $e) { $out['bookings_paid'] = null; }
    try {
        $out['passes_paid'] = $run("SELECT COUNT(*) AS passes, COALESCE(SUM(amount_paid),0) AS revenue
                                      FROM passes WHERE payment_status = 'paid' AND created_at BETWEEN ? AND ?", [$f, $t])[0] ?? null;
    } catch (Throwable $e) { $out['passes_paid'] = null; }
    return $out;
}

function mcp_content_table(array $a): string {
    $t = (string)($a['type'] ?? '');
    if (!in_array($t, ['recipes', 'blog_posts', 'events'], true)) throw new McpToolError('type must be recipes, blog_posts or events.');
    return $t;
}

function mcp_t_list_content(PDO $pdo, array $a): array {
    $t = mcp_content_table($a);
    $cols = mcp_cols($pdo, $t);
    $want = array_values(array_intersect(['id', 'title', 'slug', 'status', 'author', 'publish_at', 'start_datetime', 'end_datetime', 'recurrence', 'created_at', 'updated_at'], $cols));
    $w = []; $p = [];
    if (!empty($a['status']) && in_array('status', $cols, true)) { $w[] = 'status = ?'; $p[] = (string)$a['status']; }
    if (trim((string)($a['search'] ?? '')) !== '') { $w[] = 'title LIKE ?'; $p[] = '%' . trim((string)$a['search']) . '%'; }
    $order = $t === 'events' ? 'start_datetime DESC' : 'id DESC';
    $st = $pdo->prepare("SELECT `" . implode('`,`', $want) . "` FROM `$t`" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY $order LIMIT " . mcp_limit($a, 50));
    $st->execute($p);
    return [$t => $st->fetchAll(PDO::FETCH_ASSOC)];
}

function mcp_t_get_content(PDO $pdo, array $a): array {
    $t = mcp_content_table($a);
    if (!empty($a['id'])) { $st = $pdo->prepare("SELECT * FROM `$t` WHERE id = ?"); $st->execute([(int)$a['id']]); }
    elseif (!empty($a['slug'])) { $st = $pdo->prepare("SELECT * FROM `$t` WHERE slug = ?"); $st->execute([(string)$a['slug']]); }
    else throw new McpToolError('Give id or slug.');
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new McpToolError('Not found.');
    if ($t === 'recipes') {
        try {
            $ing = $pdo->prepare("SELECT * FROM recipe_ingredients WHERE recipe_id = ?");
            $ing->execute([(int)$row['id']]);
            $row['ingredients'] = $ing->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
    return $row;
}

function mcp_t_site_info(PDO $pdo, array $a): array {
    $out = [];
    foreach (['site_settings', 'site_hours', 'site_contacts', 'site_socials'] as $t) {
        try { $out[$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $out[$t] = null; }
    }
    // Drop anything that looks like a credential.
    foreach ($out['site_settings'] ?? [] as $i => $r) {
        foreach ($r as $k => $v) {
            if (is_string($v) && preg_match('/(secret|token|password|api[_-]?key)/i', (string)($r['setting_key'] ?? $r['key'] ?? $r['name'] ?? ''))) {
                $out['site_settings'][$i] = ['hidden' => 'credential'];
                break;
            }
        }
    }
    return $out;
}

// ── Write handlers ───────────────────────────────────────────────────────────

function mcp_t_adjust_stock(PDO $pdo, array $a): array {
    $id = (int)($a['item_id'] ?? 0);
    $hasSet = isset($a['set_to']) && $a['set_to'] !== '';
    $hasBy  = isset($a['change_by']) && $a['change_by'] !== '';
    if ($hasSet === $hasBy) throw new McpToolError('Give exactly one of set_to or change_by.');
    require_once dirname(__DIR__) . '/stock.php';
    if (bcrf_stock_ready($pdo)) return mcp_t_adjust_stock_by_location($pdo, $a, $id, $hasSet);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, name, stock_quantity FROM inventory WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it) throw new McpToolError("No inventory item with id $id.");
        $old = (float)$it['stock_quantity'];
        $new = $hasSet ? (float)$a['set_to'] : $old + (float)$a['change_by'];
        if ($new < 0) throw new McpToolError("That would make stock negative ($new).");
        if ($new > 100000) throw new McpToolError('That number looks wrong (over 100,000).');
        $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?")->execute([$new, $id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $fmt = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    $reason = trim((string)($a['reason'] ?? ''));
    mcp_audit($pdo, 'update', 'inventory', $id, $it['name'] . ' - stock ' . $fmt($old) . ' -> ' . $fmt($new) . ($reason !== '' ? ' (' . mb_substr($reason, 0, 80) . ')' : ''));
    return ['item_id' => $id, 'name' => $it['name'], 'old_stock' => $old, 'new_stock' => $new];
}

/** adjust_stock once stock is kept by location (Farm OS slice 8, migration 0015). */
function mcp_t_adjust_stock_by_location(PDO $pdo, array $a, int $id, bool $hasSet): array {
    $reason = trim((string)($a['reason'] ?? ''));
    $uid = null;
    $locName = trim((string)($a['location'] ?? ''));
    $locs = bcrf_locations($pdo);
    $locId = null;
    if ($locName !== '') {
        foreach ($locs as $l) if (strcasecmp($l['name'], $locName) === 0 || strcasecmp($l['code'], $locName) === 0) $locId = (int)$l['id'];
        if (!$locId) throw new McpToolError('No location called "' . $locName . '". Locations: ' . implode(', ', array_column($locs, 'name')) . '.');
    }
    $pdo->beginTransaction();
    try {
        $L = bcrf_stock_lock($pdo, $id, $uid);
        if ((int)$L['p']['track_inventory'] !== 1) throw new McpToolError('"' . $L['p']['name'] . '" is not a stocked item, so it has no count.');
        if (!$locId) {
            $held = array_keys(array_filter($L['lv'], fn($q) => $q !== 0));
            if (count($held) > 1) {
                $names = [];
                foreach ($locs as $l) if (in_array((int)$l['id'], $held, true)) $names[] = $l['name'] . ' ' . bcrf_qtxt(bcrf_qf($L['lv'][(int)$l['id']]));
                throw new McpToolError('"' . $L['p']['name'] . '" is kept in more than one place (' . implode(', ', $names) . '). Give location.');
            }
            $locId = $held ? $held[0] : (count($L['lv']) === 1 ? (int)array_key_first($L['lv']) : bcrf_location_default($pdo));
        }
        $before = bcrf_qf($L['lv'][$locId] ?? 0);
        $new = $hasSet ? (float)$a['set_to'] : $before + (float)$a['change_by'];
        if ($new < 0) throw new McpToolError("That would make stock negative ($new).");
        if ($new > 100000) throw new McpToolError('That number looks wrong (over 100,000).');
        $r = $hasSet ? bcrf_stock_set_count($pdo, $id, $locId, $new, null, $uid, $L)
                     : bcrf_stock_move($pdo, $id, $locId, $new - $before, 'correction', 'connector', null, $reason !== '' ? mb_substr($reason, 0, 200) : 'Changed through the connector', $uid, 'strict', $L);
        if ($hasSet && $r['id'] && $reason !== '') $pdo->prepare("UPDATE inventory_adjustments SET note = ? WHERE id = ?")->execute([mb_substr($reason, 0, 200), $r['id']]);
        $pdo->commit();
    } catch (BcrfStockError $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw new McpToolError($e->getMessage()); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $loc = bcrf_location($pdo, $locId);
    mcp_audit($pdo, 'update', 'inventory', $id, $L['p']['name'] . ' - stock at ' . $loc['name'] . ' ' . bcrf_qtxt($r['before']) . ' -> ' . bcrf_qtxt($r['after']) . ($reason !== '' ? ' (' . mb_substr($reason, 0, 80) . ')' : ''));
    return ['item_id' => $id, 'name' => $L['p']['name'], 'location' => $loc['name'], 'old_stock' => $r['before'], 'new_stock' => $r['after'],
            'total_all_locations' => bcrf_qf(array_sum($L['lv']) - bcrf_qm($r['before']) + bcrf_qm($r['after']))];
}

function mcp_t_blog_draft(PDO $pdo, array $a): array {
    $title = trim(strip_tags((string)($a['title'] ?? '')));
    $body  = mcp_clean_html((string)($a['body_html'] ?? ''));
    if ($title === '' || $body === '') throw new McpToolError('title and body_html are required.');
    $cols = mcp_cols($pdo, 'blog_posts');
    $bodyCol = null;
    foreach (['content', 'article', 'body'] as $c) if (in_array($c, $cols, true)) { $bodyCol = $c; break; }
    if (!$bodyCol) throw new McpToolError('Could not find the blog body column.');
    $row = ['title' => mb_substr($title, 0, 250), $bodyCol => $body, 'status' => 'draft'];
    if (in_array('slug', $cols, true)) $row['slug'] = mcp_unique_slug($pdo, 'blog_posts', mcp_slug($title));
    $ex = trim(strip_tags((string)($a['excerpt'] ?? '')));
    if ($ex !== '') foreach (['excerpt', 'description', 'summary'] as $c) if (in_array($c, $cols, true)) { $row[$c] = mb_substr($ex, 0, 500); break; }
    if (in_array('author', $cols, true)) $row['author'] = $_SESSION['user_name'] ?? 'Bat Cave Road Farm';
    if (in_array('publish_at', $cols, true)) $row['publish_at'] = null;
    $pdo->prepare("INSERT INTO blog_posts (`" . implode('`,`', array_keys($row)) . "`) VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")")
        ->execute(array_values($row));
    $id = (int)$pdo->lastInsertId();
    mcp_audit($pdo, 'create', 'blog_posts', $id, $title . ' (draft)');
    return ['id' => $id, 'status' => 'draft', 'slug' => $row['slug'] ?? null,
            'edit_in_admin' => (bcrf_farm_url() . '/admin/admin.php?view=schema&table=blog_posts&edit_id=') . $id];
}

function mcp_t_event_draft(PDO $pdo, array $a): array {
    $title = trim(strip_tags((string)($a['title'] ?? '')));
    if ($title === '') throw new McpToolError('title is required.');
    $allDay = !empty($a['all_day']) ? 1 : 0;
    $parse = function (?string $v) use ($allDay): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        $d = DateTime::createFromFormat($allDay ? 'Y-m-d' : 'Y-m-d H:i', $v, mcp_tz())
          ?: DateTime::createFromFormat('Y-m-d\TH:i', $v, mcp_tz())
          ?: DateTime::createFromFormat('Y-m-d', $v, mcp_tz());
        if (!$d) throw new McpToolError("Can't read the date \"$v\". Use YYYY-MM-DD HH:MM.");
        return $allDay ? $d->format('Y-m-d') . ' 00:00:00' : $d->format('Y-m-d H:i:00');
    };
    $start = $parse($a['start'] ?? null);
    $end   = $parse($a['end'] ?? null);
    if ($end !== null && $end < $start) throw new McpToolError('end is before start.');
    $price = isset($a['price']) && $a['price'] !== '' ? (float)$a['price'] : null;
    $slug  = mcp_unique_slug($pdo, 'events', mcp_slug($title));
    $pdo->prepare("INSERT INTO events (title,slug,description,image_url,start_datetime,end_datetime,all_day,recurrence,recur_days,recur_until,recurrence_note,is_free,price,location_note,status)
                   VALUES (?,?,?,NULL,?,?,?,'none',NULL,NULL,NULL,?,?,?,'draft')")
        ->execute([$title, $slug, trim((string)($a['description'] ?? '')) ?: null, $start, $end, $allDay,
                   $price === null ? 1 : 0, $price, trim((string)($a['location_note'] ?? '')) ?: null]);
    $id = (int)$pdo->lastInsertId();
    mcp_audit($pdo, 'create', 'events', $id, $title . ' (draft)');
    return ['id' => $id, 'status' => 'draft', 'slug' => $slug, 'edit_in_admin' => (bcrf_farm_url() . '/admin/admin.php?view=events&edit=') . $id];
}

function mcp_t_booking_status(PDO $pdo, array $a): array {
    $id = (int)($a['id'] ?? 0);
    $to = (string)($a['status'] ?? '');
    $bk = mcp_booking_row($pdo, $id);
    $oldStatus = (string)($bk['status'] ?? '');
    $label = trim(($bk['customer_name'] ?? '') . ' (' . ($bk['booking_date'] ?? '') . ')');
    $simple = ['cancelled', 'completed', 'no_show'];
    if (in_array($to, $simple, true)) {
        $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?")->execute([$to, $id]);
        mcp_audit($pdo, 'update', 'bookings', $id, $label . ' - ' . ($bk['status'] ?? '?') . ' -> ' . $to);
        return ['id' => $id, 'old_status' => $bk['status'], 'new_status' => $to, 'email_sent' => false];
    }
    if ($to !== 'confirmed') throw new McpToolError('status must be confirmed, cancelled, completed or no_show.');
    if ($bk['status'] === 'confirmed') return ['id' => $id, 'new_status' => 'confirmed', 'note' => 'Already confirmed; no email sent.'];
    require_once dirname(__DIR__) . '/booking.php';
    $force = !empty($a['override_capacity']);
    $pdo->beginTransaction();
    try {
        if (!$force && function_exists('booking_capacity_ok')) {
            $res = booking_capacity_ok($pdo, ['date' => $bk['booking_date'], 'start' => $bk['start_time'], 'end' => $bk['end_time'],
                'party_size' => (int)$bk['party_size'], 'is_exclusive' => (int)$bk['is_exclusive'], 'exclude_id' => $id]);
            if (!$res['ok']) { $pdo->rollBack(); throw new McpToolError('Not confirmed: ' . $res['reason'] . ' Ask the user whether to override_capacity.'); }
        }
        $pdo->prepare("UPDATE bookings SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    mcp_audit($pdo, 'update', 'bookings', $id, $label . ' - ' . ($bk['status'] ?? '?') . ' -> confirmed' . ($force ? ' (capacity override)' : ''));
    $ts = $pdo->prepare("SELECT * FROM booking_types WHERE id = ?");
    $ts->execute([(int)$bk['type_id']]);
    $type = $ts->fetch(PDO::FETCH_ASSOC) ?: null;
    $bk['status'] = 'confirmed';
    $sent = function_exists('booking_send_confirmation') ? (bool)@booking_send_confirmation($bk, $type) : false;
    return ['id' => $id, 'old_status' => $oldStatus, 'new_status' => 'confirmed', 'email_sent' => $sent];
}

function mcp_t_inquiry_status(PDO $pdo, array $a): array {
    $id = (int)($a['id'] ?? 0);
    $to = (string)($a['status'] ?? '');
    if (!in_array($to, ['new', 'contacted', 'quoted', 'confirmed', 'declined', 'archived'], true)) throw new McpToolError('Unknown status.');
    $st = $pdo->prepare("SELECT status FROM event_inquiries WHERE id = ?");
    $st->execute([$id]);
    $old = $st->fetchColumn();
    if ($old === false) throw new McpToolError("No inquiry with id $id.");
    $pdo->prepare("UPDATE event_inquiries SET status = ? WHERE id = ?")->execute([$to, $id]);
    require_once dirname(__DIR__, 2) . '/admin/includes/audit.php';
    $lbl = function_exists('erp_row_label') ? erp_row_label($pdo, 'event_inquiries', $id) : 'Inquiry #' . $id;
    mcp_audit($pdo, 'update', 'event_inquiries', $id, $lbl . ' - status ' . $to);
    return ['id' => $id, 'old_status' => $old, 'new_status' => $to];
}

function mcp_t_error_log(PDO $pdo, array $a): array {
    $f = (string)ini_get('error_log');
    if ($f === '' || !is_file($f)) return ['file' => $f ?: null, 'lines' => [], 'note' => 'No error log file yet (nothing logged since it was set up).'];
    $n = max(1, min(1000, (int)($a['lines'] ?? 200)));
    $size = (int)filesize($f);
    $fh = fopen($f, 'r');
    if ($size > 3000000) fseek($fh, $size - 3000000);
    $buf = [];
    $needle = (string)($a['contains'] ?? '');
    while (($line = fgets($fh)) !== false) {
        if ($needle !== '' && stripos($line, $needle) === false) continue;
        $buf[] = rtrim($line);
        if (count($buf) > $n) array_shift($buf);
    }
    fclose($fh);
    $root = dirname(__DIR__, 2) . '/';
    return ['file' => '~/logs/' . basename($f), 'size' => $size, 'lines' => array_map(fn($l) => str_replace($root, '', $l), $buf)];
}

// ── Dispatch ─────────────────────────────────────────────────────────────────

function mcp_tools_list(): array {
    $out = [];
    foreach (mcp_tools() as $name => [$desc, $schema, $ann]) {
        $out[] = ['name' => $name, 'description' => $desc, 'inputSchema' => $schema, 'annotations' => $ann + ['title' => ucfirst(str_replace('_', ' ', $name))]];
    }
    return $out;
}

/** Run one tool. Returns the MCP CallToolResult. */
function mcp_tools_call(PDO $pdo, string $name, array $args): array {
    $tools = mcp_tools();
    if (!isset($tools[$name])) throw new InvalidArgumentException("Unknown tool: $name");
    $fn = $tools[$name][3];
    try {
        $res = $fn($pdo, $args);
        $json = json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        return ['content' => [['type' => 'text', 'text' => $json]], 'isError' => false];
    } catch (McpToolError $e) {
        return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
    }
}

}
