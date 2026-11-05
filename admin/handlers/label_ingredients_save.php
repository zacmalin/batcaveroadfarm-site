<?php
// admin/handlers/label_ingredients_save.php
// AJAX: save an inventory item's label ingredients in label order (heaviest first).
// Called from the Print Label window in admin/views/inventory_update.php.
// POST: csrf_token, item_id, ingredient_ids[] (in label order; empty = no ingredients).

require_once '../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';
// Same rule as the quick inventory editor: the Permissions Matrix decides who may change items.
require_permission([['view', 'inventory_update'], ['table', 'inventory', 'edit']], true);
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/label_ingredients.php';

header('Content-Type: application/json');

function lis_exit(bool $ok, string $message, int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') lis_exit(false, 'POST only.', 405);

$posted = (string)($_POST['csrf_token'] ?? '');
if ($posted === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted)) {
    lis_exit(false, 'Security token expired. Refresh the page and try again.', 403);
}

$item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
if (!$item_id) lis_exit(false, 'Invalid item ID.', 400);

$ids = [];
foreach ((array)($_POST['ingredient_ids'] ?? []) as $v) {
    $v = (int)$v;
    if ($v > 0 && !in_array($v, $ids, true)) $ids[] = $v;
}
if (count($ids) > 60) lis_exit(false, 'Too many ingredients.', 400);

try {
    $it = $pdo->prepare("SELECT name FROM inventory WHERE id = ?");
    $it->execute([$item_id]);
    $item_name = $it->fetchColumn();
    if ($item_name === false) lis_exit(false, 'Item not found.', 404);

    $names = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, singular_name FROM ingredients WHERE id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['id']] = (string)$r['singular_name'];
        if (count($names) !== count($ids)) lis_exit(false, 'One of those ingredients no longer exists. Refresh and try again.', 409);
    }

    bcrf_label_ing_schema($pdo);
    $before = array_column(bcrf_label_ingredients($pdo, $item_id)['rows'], 'name');

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM product_ingredients WHERE product_type = 'inventory' AND product_id = ?")->execute([$item_id]);
    $ins = $pdo->prepare("INSERT INTO product_ingredients (product_type, product_id, ingredient_id, sort_order) VALUES ('inventory', ?, ?, ?)");
    foreach ($ids as $i => $ing_id) $ins->execute([$item_id, $ing_id, $i + 1]);
    $pdo->commit();

    $after = array_map(fn($id) => $names[$id], $ids);
    erp_audit($pdo, 'update', 'inventory', $item_id,
        $item_name . ' - label ingredients: ' . ($before ? implode(', ', $before) : 'none') . ' -> ' . ($after ? implode(', ', $after) : 'none'));

    lis_exit(true, 'Ingredient order saved.', 200, ['ingredients' => $after]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ERP] label_ingredients_save ' . $item_id . ': ' . $e->getMessage());
    lis_exit(false, 'Database error — check System Health.', 500);
}
