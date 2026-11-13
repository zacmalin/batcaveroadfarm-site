<?php
// admin/handlers/calculate_nutrition.php
// Calculates recipe nutrition from local ingredient data (no external API call).
// The math lives in includes/recipe_nutrition.php (Nov 13, 2026), shared with the recipe Save, which now
// recalculates by itself. The answer lists ingredients that still need a USDA match (missing[]).

ob_start(); // Buffer everything — catches stray output from config or errors

// Global safety net — any uncaught fatal becomes a JSON error instead of empty response
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'PHP Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']]);
    }
});

require_once '../../config.php';
require_once __DIR__ . '/../includes/audit.php';   // fix pack O: audit log
require_once __DIR__ . '/../../includes/recipe_nutrition.php';

ob_end_clean();

check_admin();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/permission_checks.php';   // audit #8: same Permissions Matrix rules as the console
require_permission([['table', 'recipes', 'edit']], true);

function njson(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST')            njson(false, 'POST required.');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? ''))
    njson(false, 'Security token expired.');

$recipe_id = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT);
if (!$recipe_id) njson(false, 'Invalid recipe ID.');

$r = bcrf_recipe_nutrition($pdo, (int)$recipe_id, true);
if ($r['ok'] && isset($r['label'])) {
    erp_audit($pdo, 'update', 'recipes', (int)$recipe_id,
        'Nutrition recalculated: ' . $r['label'] . ' (' . ($r['was'] === null ? 'none' : $r['was'] . ' cal') . ' -> ' . $r['calories'] . ' cal per serving)');
}
$keys = ['calories', 'protein', 'fat', 'carbs', 'fiber', 'sugar', 'sodium', 'missing', 'counted', 'lines', 'servings'];
njson($r['ok'], $r['message'], array_intersect_key($r, array_flip($keys)));
