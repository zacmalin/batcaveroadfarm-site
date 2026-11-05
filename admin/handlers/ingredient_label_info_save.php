<?php
// admin/handlers/ingredient_label_info_save.php
// AJAX: save one ingredient's label details from the Print Label window
// (admin/views/inventory_update.php): its major allergens and its sub-ingredients.
// These live on the ingredient, so every product that uses it prints them.
// POST: csrf_token, ingredient_id, allergens[] (keys from bcrf_allergen_list()),
//       allergen_detail[tree_nuts|fish|shellfish] (the specific food), sub_ingredients.
// Saving with no allergens ticked records "checked, none" (allergens = '').

require_once '../../config.php';
check_admin();
require_once __DIR__ . '/../includes/permission_checks.php';
// Kitchen editors, or whoever may edit items from the quick inventory screen.
require_permission([['table', 'ingredients', 'edit'], ['view', 'inventory_update']], true);
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/label_ingredients.php';

header('Content-Type: application/json');

function ili_exit(bool $ok, string $message, int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ili_exit(false, 'POST only.', 405);

$posted = (string)($_POST['csrf_token'] ?? '');
if ($posted === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted)) {
    ili_exit(false, 'Security token expired. Refresh the page and try again.', 403);
}

$ing_id = filter_input(INPUT_POST, 'ingredient_id', FILTER_VALIDATE_INT);
if (!$ing_id) ili_exit(false, 'Invalid ingredient.', 400);

$list    = bcrf_allergen_list();
$details = (array)($_POST['allergen_detail'] ?? []);
$picked  = [];
foreach ((array)($_POST['allergens'] ?? []) as $k) {
    $k = (string)$k;
    if (!isset($list[$k])) continue;
    $picked[$k] = $list[$k][1] ? trim((string)($details[$k] ?? '')) : '';
}
foreach ($picked as $k => $d) {
    if ($list[$k][1] && $d === '') ili_exit(false, 'Name the specific ' . strtolower($list[$k][0]) . ' (for example ' . ['fish' => 'cod', 'shellfish' => 'shrimp', 'tree_nuts' => 'pecans'][$k] . ').', 422);
}
$allergens = bcrf_allergens_encode($picked);

$sub = trim(preg_replace('/\s+/', ' ', (string)($_POST['sub_ingredients'] ?? '')));
if (mb_strlen($sub) > 1000) ili_exit(false, 'Sub-ingredients are too long (1,000 characters at most).', 422);

try {
    bcrf_label_ing_schema($pdo);
    $o = $pdo->prepare("SELECT singular_name, allergens, COALESCE(sub_ingredients, '') AS sub_ingredients FROM ingredients WHERE id = ?");
    $o->execute([$ing_id]);
    $old = $o->fetch(PDO::FETCH_ASSOC);
    if (!$old) ili_exit(false, 'Ingredient not found.', 404);

    $pdo->prepare("UPDATE ingredients SET allergens = ?, sub_ingredients = ? WHERE id = ?")
        ->execute([$allergens, $sub !== '' ? $sub : null, $ing_id]);

    $show = fn($a) => $a === null ? 'not checked' : ($a === '' ? 'none' : $a);
    erp_audit($pdo, 'update', 'ingredients', $ing_id, mb_substr(
        $old['singular_name'] . ' - label allergens: ' . $show($old['allergens']) . ' -> ' . $show($allergens)
        . ($old['sub_ingredients'] !== $sub ? '; sub-ingredients changed' : ''), 0, 255));

    ili_exit(true, 'Saved.', 200, ['allergens' => $allergens, 'sub_ingredients' => $sub]);

} catch (Throwable $e) {
    error_log('[ERP] ingredient_label_info_save ' . $ing_id . ': ' . $e->getMessage());
    ili_exit(false, 'Database error — check System Health.', 500);
}
