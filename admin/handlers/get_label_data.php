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
if (session_status() !== PHP_SESSION_ACTIVE) session_start();   // config.php usually started it already (fix pack O)
// Staff only — public customer accounts also have user_id, so check the role.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['administrator', 'moderator', 'Shopkeep', 'Media'], true)) json_exit(false, 'Not authenticated.', [], 401);
require_once __DIR__ . '/../includes/permission_checks.php';   // audit #8: same Permissions Matrix rules as the console
require_permission([['view', 'inventory_update'], ['view', 'pos']], true);

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

    // Ingredients linked to this inventory item, in label order (heaviest first)
    require_once __DIR__ . '/../includes/label_ingredients.php';
    $lab         = bcrf_label_ingredients($pdo, (int)$item_id);
    $ingredients = array_column($lab['rows'], 'name');

    // Net quantity with metric equivalent, as printed on the label
    $size_label = !empty($item['size_value']) ? bcrf_label_net_qty($item['size_value'], (string)$item['unit_name']) : '';

    json_exit(true, 'OK', [
        'id'          => (int)$item['id'],
        'name'        => $item['name'],
        'category'    => $item['category'],
        'size_label'  => $size_label,
        'made_by'     => bcrf_label_made_by(),
        'ingredients' => $ingredients,
        'ingredient_rows' => $lab['rows'],      // [{id, name}] in label order
        'order_set'   => $lab['order_set'],     // false = never put in order (old alphabetical list)
        'all_ingredients' => bcrf_all_ingredients($pdo),
    ]);

} catch (Throwable $e) {
    error_log('[ERP] get_label_data failed: ' . $e->getMessage());
    json_exit(false, 'Database error.', [], 500);
}
