<?php
// admin/handlers/update_inventory.php
// Standalone AJAX endpoint — called directly by fetch() in inventory_update.php.
// Must bootstrap its own session and auth since it isn't routed through admin.php.

require_once '../../config.php';
check_admin();
require_once __DIR__ . '/../includes/audit.php';
date_default_timezone_set('America/New_York');

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
