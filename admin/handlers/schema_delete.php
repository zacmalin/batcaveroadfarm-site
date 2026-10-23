<?php
// admin/handlers/schema_delete.php
// Handles record deletion from schema views with permission check.

if ($target_view !== 'schema' || empty($target_table)) return;

require_once __DIR__ . '/../includes/audit.php';

$del_id = filter_input(INPUT_GET, 'delete_id', FILTER_VALIDATE_INT);
if (!$del_id) return;

// ── Same-site check ──────────────────────────────────────────────────────────
// Delete is a plain link (GET), so it has no CSRF token. With SameSite=Lax the
// login cookie rides along on links from other sites, so only accept the click
// if the browser says it came from this site.
if (!function_exists('erp_request_is_same_site')) {
    function erp_request_is_same_site(): bool {
        $sfs = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($sfs !== '') return $sfs === 'same-origin' || $sfs === 'none';   // 'none' = typed/bookmarked
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref === '') return false;                                        // no proof -> refuse
        $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
        return $host === 'batcaveroadfarm.com' || $host === 'www.batcaveroadfarm.com';
    }
}
if (!erp_request_is_same_site()) {
    error_log('[ERP] Blocked cross-site delete attempt on ' . $target_table . ' #' . $del_id);
    $_SESSION['toast_msg']  = 'Delete blocked: please use the Drop button inside the admin.';
    $_SESSION['toast_type'] = 'error';
    header('Location: /admin/admin.php?view=schema&table=' . urlencode($target_table)); exit;
}

if (!hasTableAccess($current_role, $target_table, 'edit')) {
    $_SESSION['toast_msg']  = "Access denied: insufficient permissions to delete records.";
    $_SESSION['toast_type'] = 'error';
    header("Location: /admin/admin.php?view=schema&table=" . urlencode($target_table));
    exit;
}


// ── PRE-DELETE REFERENCE CHECK ────────────────────────────────────────────────
// Map of tables and the columns in OTHER tables that reference them
$fk_map = [
    'animals'           => [['time_cards',                'user_id'],  ['product_ingredients', 'product_id']],
    'breeds'            => [['animals',                   'breed_id']],
    'classifications'   => [['breeds',                    'classification_id']],
    'ingredients'       => [['product_ingredients',       'ingredient_id']],
    'measurement_units' => [['inventory',                 'size_unit_id'],
                            ['unit_conversions',          'from_unit_id'],
                            ['unit_conversions',          'to_unit_id']],
    'categories'        => [['recipe_categories_junction','category_id']],
    'recipes'           => [['recipe_ingredients',        'recipe_id'],
                            ['recipe_ingredients',        'linked_recipe_id'],
                            ['recipe_ratings',            'recipe_id'],
                            ['recipe_comments',           'recipe_id'],
                            ['blog_posts',                'linked_recipe_id']],
    'users'             => [['time_cards',                'user_id'],
                            ['recipe_ratings',            'user_id'],
                            ['recipe_comments',           'user_id']],
    'inventory'         => [['product_ingredients',       'product_id']],
    'mailing_list'      => [],
];

if (isset($fk_map[$target_table])) {
    foreach ($fk_map[$target_table] as [$ref_table, $ref_col]) {
        try {
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$ref_table}` WHERE `{$ref_col}` = ?");
            $count_stmt->execute([$del_id]);
            $ref_count = (int)$count_stmt->fetchColumn();
            if ($ref_count > 0) {
                $_SESSION['toast_msg']  = "Cannot delete: {$ref_count} record(s) in '{$ref_table}' still reference this item.";
                $_SESSION['toast_type'] = 'error';
                header("Location: /admin/admin.php?view=schema&table=" . urlencode($target_table));
                exit;
            }
        } catch (Throwable $fk_e) {
            error_log('[ERP] FK check failed: ' . $fk_e->getMessage());
        }
    }
}


if ($target_table === 'recipes') {
    try {
        $pdo->prepare("DELETE FROM recipe_pairings WHERE recipe_id = ? OR paired_recipe_id = ?")
            ->execute([$del_id, $del_id]);
    } catch (Throwable $e) { error_log('[ERP] pairing cleanup on delete failed: ' . $e->getMessage()); }
}

// Grab a readable label BEFORE the row is gone (for the audit trail)
$del_label = '';
try {
    $lab = $pdo->prepare("SELECT * FROM {$target_table} WHERE id = ? LIMIT 1");
    $lab->execute([$del_id]);
    $del_label = erp_audit_label($lab->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("DELETE FROM {$target_table} WHERE id = ?");
    $stmt->execute([$del_id]);
    if ($stmt->rowCount() > 0) {
        erp_audit($pdo, 'delete', (string)$target_table, (int)$del_id, $del_label);
    }
    $_SESSION['toast_msg']  = "Record successfully removed from the system logs.";
    $_SESSION['toast_type'] = 'success';
} catch (Throwable $e) {
    error_log('[ERP] Schema delete failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = "Unable to delete row due to relational safety constraints.";
    $_SESSION['toast_type'] = 'error';
}

header("Location: /admin/admin.php?view=schema&table=" . urlencode($target_table));
exit;
