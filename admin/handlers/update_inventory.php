<?php
// admin/handlers/update_inventory.php
// Standalone AJAX endpoint — called directly by fetch() in inventory_update.php.
// Must bootstrap its own session and auth since it isn't routed through admin.php.

require_once '../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';   // audit #8: same Permissions Matrix rules as the console
// Edits come from the Quick Inventory Update screen; the Permissions Matrix decides who may use it.
require_permission([['view', 'inventory_update'], ['table', 'inventory', 'edit']], true);
require_once __DIR__ . '/../includes/audit.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

header('Content-Type: application/json');

// Helper — always exit with JSON
function json_exit(bool $ok, string $message, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$posted_token = $_POST['csrf_token'] ?? '';
if (empty($posted_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted_token)) {
    json_exit(false, 'Security token expired. Refresh the page and try again.', 403);
}

// ── INPUT ─────────────────────────────────────────────────────────────────────
$id    = filter_input(INPUT_POST, 'id',    FILTER_VALIDATE_INT);
$field = trim($_POST['field']  ?? '');
$value = trim($_POST['value']  ?? '');

if (!$id) {
    json_exit(false, 'Invalid item ID.', 400);
}

// ── WHITELIST: only these fields may be updated from this view ────────────────
$allowed_fields = [
    'stock_quantity' => FILTER_VALIDATE_FLOAT,
];

if (!array_key_exists($field, $allowed_fields)) {
    json_exit(false, "Field '{$field}' is not editable from this view.", 400);
}

// ── VALIDATE VALUE ────────────────────────────────────────────────────────────
$clean_value = filter_var($value, $allowed_fields[$field]);

if ($clean_value === false) {
    json_exit(false, 'Invalid value — must be a number.', 400);
}

if ($clean_value < 0) {
    json_exit(false, 'Value cannot be negative.', 400);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────
// -- STOCK BY LOCATION (Farm OS slice 8, migration 0015) --
// The number typed is the count for the item's one location. Items kept in more than one place are counted in Stock › Counts.
require_once __DIR__ . '/../../includes/stock.php';
if (bcrf_stock_ready($pdo)) {
    try {
        $pdo->beginTransaction();
        $L = bcrf_stock_lock($pdo, (int)$id, (int)($_SESSION['user_id'] ?? 0) ?: null);
        if (!(int)$L['p']['track_inventory']) { $pdo->rollBack(); json_exit(false, 'This item is not counted (stock is not tracked for it).', 400); }
        $held = array_keys(array_filter($L['lv'], fn($q) => $q !== 0));
        if (count($held) > 1) { $pdo->rollBack(); json_exit(false, 'This item is kept in more than one place. Count it in Stock › Counts.', 409); }
        $loc = $held ? $held[0] : (count($L['lv']) === 1 ? (int)array_key_first($L['lv']) : bcrf_location_default($pdo));
        $r = bcrf_stock_set_count($pdo, (int)$id, $loc, (float)$clean_value, null, (int)($_SESSION['user_id'] ?? 0) ?: null, $L);
        $pdo->commit();
        $ln = bcrf_location($pdo, $loc);
        erp_audit($pdo, 'update', 'inventory', $id, $L['p']['name'] . ' - stock at ' . ($ln['name'] ?? '?') . ' ' . bcrf_qtxt($r['before']) . ' -> ' . bcrf_qtxt($r['after']));
        json_exit(true, 'Saved.');
    } catch (BcrfStockError $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_exit(false, $e->getMessage(), 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ERP] update_inventory (stock) failed: ' . $e->getMessage());
        json_exit(false, 'Database error — check server logs.', 500);
    }
}

// -- UPDATE (before migration 0015) --
try {
    $oldQty = null; $invName = '';
    try { $o = $pdo->prepare("SELECT name, stock_quantity FROM inventory WHERE id = ?"); $o->execute([$id]); if ($r = $o->fetch(PDO::FETCH_ASSOC)) { $oldQty = $r['stock_quantity']; $invName = (string)$r['name']; } } catch (Throwable $e) {}
    $stmt = $pdo->prepare("UPDATE inventory SET `{$field}` = ? WHERE id = ? AND is_active = 1");
    $stmt->execute([$clean_value, $id]);

    if ($stmt->rowCount() === 0) {
        json_exit(false, 'No matching active item found.', 404);
    }

    erp_audit($pdo, 'update', 'inventory', $id, $invName . ' - stock ' . ($oldQty === null ? '?' : rtrim(rtrim((string)$oldQty, '0'), '.')) . ' -> ' . rtrim(rtrim((string)$clean_value, '0'), '.'));
    json_exit(true, 'Saved.');

} catch (Throwable $e) {
    error_log('[ERP] update_inventory failed: ' . $e->getMessage());
    json_exit(false, 'Database error — check server logs.', 500);
}
