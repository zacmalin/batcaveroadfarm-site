<?php
// admin/handlers/get_label_data.php
// AJAX endpoint — returns item details + ingredients for label print modal.

ob_start();
header('Content-Type: application/json');

function json_exit(bool $ok, string $msg, array $extra = [], int $status = 200): void {
    ob_end_clean();
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}

require_once '../../config.php';
session_start();
// Staff only — public customer accounts also have user_id, so check the role.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['administrator', 'moderator', 'Shopkeep', 'Media'], true)) json_exit(false, 'Not authenticated.', [], 401);

$item_id = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
if (!$item_id) json_exit(false, 'Invalid item ID.', [], 400);

try {
    // Item details
    $stmt = $pdo->prepare("
        SELECT i.id, i.name, i.category,
               i.size_value, i.size_unit_id,
               COALESCE(u.name, '') AS unit_name
        FROM   inventory i
        LEFT JOIN measurement_units u ON i.size_unit_id = u.id
        WHERE  i.id = ? AND i.is_active = 1
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) json_exit(false, 'Item not found.', [], 404);

    // Ingredients linked to this inventory item
    $ing_stmt = $pdo->prepare("
        SELECT ing.singular_name
        FROM   product_ingredients pi
        JOIN   ingredients ing ON pi.ingredient_id = ing.id
        WHERE  pi.product_type = 'inventory' AND pi.product_id = ?
        ORDER  BY ing.singular_name ASC
    ");
    $ing_stmt->execute([$item_id]);
    $ingredients = $ing_stmt->fetchAll(PDO::FETCH_COLUMN);

    $size_label = '';
    if (!empty($item['size_value'])) {
        $size_label = (float)$item['size_value'];
        if (!empty($item['unit_name'])) $size_label .= ' ' . $item['unit_name'];
    }

    json_exit(true, 'OK', [
        'id'          => (int)$item['id'],
        'name'        => $item['name'],
        'category'    => $item['category'],
        'size_label'  => $size_label,
        'ingredients' => $ingredients,
    ]);

} catch (Throwable $e) {
    error_log('[ERP] get_label_data failed: ' . $e->getMessage());
    json_exit(false, 'Database error.', [], 500);
}
