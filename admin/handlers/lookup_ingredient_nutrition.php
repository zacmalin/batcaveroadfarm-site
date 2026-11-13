<?php
// admin/handlers/lookup_ingredient_nutrition.php
// Searches USDA FoodData Central for an ingredient and returns nutrition data.
// Also handles saving confirmed nutrition data back to the ingredients table.

ob_start();
require_once '../../config.php';
require_once __DIR__ . '/../includes/audit.php';   // fix pack O: audit log
require_once __DIR__ . '/../includes/nutrition_config.php';
ob_end_clean();

check_admin();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/permission_checks.php';   // audit #8: same Permissions Matrix rules as the console
require_permission([['table', 'ingredients', 'edit']], true);

function ujson(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ujson(false, 'POST required.');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? ''))
    ujson(false, 'Security token expired.');

$action = trim($_POST['action'] ?? 'search'); // 'search', 'detail', 'pick' or 'save'

// ── ACTION: SAVE confirmed nutrition data to ingredients table ────────────────
if ($action === 'save') {
    $ing_id = filter_input(INPUT_POST, 'ingredient_id', FILTER_VALIDATE_INT);
    if (!$ing_id) ujson(false, 'Invalid ingredient ID.');

    $fields = [
        'calories_per_100g' => filter_input(INPUT_POST, 'calories_per_100g', FILTER_VALIDATE_FLOAT),
        'protein_per_100g'  => filter_input(INPUT_POST, 'protein_per_100g',  FILTER_VALIDATE_FLOAT),
        'fat_per_100g'      => filter_input(INPUT_POST, 'fat_per_100g',      FILTER_VALIDATE_FLOAT),
        'carbs_per_100g'    => filter_input(INPUT_POST, 'carbs_per_100g',    FILTER_VALIDATE_FLOAT),
        'fiber_per_100g'    => filter_input(INPUT_POST, 'fiber_per_100g',    FILTER_VALIDATE_FLOAT),
        'sugar_per_100g'    => filter_input(INPUT_POST, 'sugar_per_100g',    FILTER_VALIDATE_FLOAT),
        'sodium_per_100g'   => filter_input(INPUT_POST, 'sodium_per_100g',   FILTER_VALIDATE_FLOAT),
        'density_g_per_ml'  => filter_input(INPUT_POST, 'density_g_per_ml',  FILTER_VALIDATE_FLOAT) ?: null,
        'weight_per_unit_g' => filter_input(INPUT_POST, 'weight_per_unit_g', FILTER_VALIDATE_FLOAT) ?: null,
        'usda_fdc_id'       => filter_input(INPUT_POST, 'usda_fdc_id',       FILTER_VALIDATE_INT)   ?: null,
    ];

    try {
        $pdo->prepare("
            UPDATE ingredients SET
                calories_per_100g  = ?,
                protein_per_100g   = ?,
                fat_per_100g       = ?,
                carbs_per_100g     = ?,
                fiber_per_100g     = ?,
                sugar_per_100g     = ?,
                sodium_per_100g    = ?,
                density_g_per_ml   = ?,
                weight_per_unit_g  = ?,
                usda_fdc_id        = ?
            WHERE id = ?
        ")->execute([
            $fields['calories_per_100g'], $fields['protein_per_100g'],
            $fields['fat_per_100g'],      $fields['carbs_per_100g'],
            $fields['fiber_per_100g'],    $fields['sugar_per_100g'],
            $fields['sodium_per_100g'],   $fields['density_g_per_ml'],
            $fields['weight_per_unit_g'], $fields['usda_fdc_id'],
            $ing_id,
        ]);
        $nm = $pdo->prepare("SELECT * FROM ingredients WHERE id = ?"); $nm->execute([$ing_id]);
        erp_audit($pdo, 'update', 'ingredients', (int)$ing_id,
            'Nutrition data: ' . (erp_audit_label($nm->fetch(PDO::FETCH_ASSOC) ?: []) ?: "#{$ing_id}")
            . ' (' . (float)$fields['calories_per_100g'] . ' cal/100g)');
        ujson(true, 'Nutrition data saved.');
    } catch (Throwable $e) {
        error_log('[Nutrition] Save failed: ' . $e->getMessage());
        ujson(false, 'Database error: ' . $e->getMessage());
    }
}

// ── USDA adapter (Farm OS Phase 0): includes/adapter_usda.php ────────────────
require_once __DIR__ . '/../../includes/adapter_usda.php';

// ── ACTION: DETAIL — one food's nutrients + portion weights (density, weight of one) ──
// Nov 13, 2026: picking a match fills everything in one go; nothing is saved here.
if ($action === 'detail' || $action === 'pick') {
    $fdc = filter_input(INPUT_POST, 'fdc_id', FILTER_VALIDATE_INT);
    if (!$fdc) ujson(false, 'Pick a USDA match first.');
    $r = bcrf_usda_food((int)$fdc);
    if (!$r['ok']) {
        error_log('[Nutrition] USDA food ' . $fdc . ' failed: ' . $r['status'] . ' ' . $r['error']);
        ujson(false, "USDA didn't answer (HTTP {$r['status']}). Try again in a minute.");
    }
    $food = $r['food'];
    $nut  = bcrf_usda_nutrients($food);
    $por  = bcrf_usda_portions($food);
    $out  = [
        'fdc_id'       => (int)$fdc,
        'description'  => (string)($food['description'] ?? ''),
        'data_type'    => (string)($food['dataType'] ?? ''),
        'nutrients'    => $nut,
        'density'      => $por['density'],
        'density_from' => $por['density_from'],
        'weight'       => $por['weight'],
        'weight_from'  => $por['weight_from'],
        'portions'     => $por['portions'],
    ];
    if ($action === 'detail') ujson(true, 'Loaded.', ['food' => $out]);

    // ACTION: PICK — save that match straight onto an ingredient (recipe Nutrition Facts flow).
    // Nutrition is replaced; density and weight of one are only filled when the ingredient has none,
    // so a number you typed in yourself is never overwritten.
    $ing_id = filter_input(INPUT_POST, 'ingredient_id', FILTER_VALIDATE_INT);
    if (!$ing_id) ujson(false, 'Invalid ingredient ID.');
    try {
        $cur = $pdo->prepare("SELECT * FROM ingredients WHERE id = ?");
        $cur->execute([$ing_id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) ujson(false, 'Ingredient not found.');
        $density = ($row['density_g_per_ml'] !== null && (float)$row['density_g_per_ml'] > 0) ? (float)$row['density_g_per_ml'] : $por['density'];
        $weight  = ($row['weight_per_unit_g'] !== null && (float)$row['weight_per_unit_g'] > 0) ? (float)$row['weight_per_unit_g'] : $por['weight'];
        $pdo->prepare("
            UPDATE ingredients SET
                calories_per_100g = ?, protein_per_100g = ?, fat_per_100g = ?, carbs_per_100g = ?,
                fiber_per_100g = ?, sugar_per_100g = ?, sodium_per_100g = ?,
                density_g_per_ml = ?, weight_per_unit_g = ?, usda_fdc_id = ?
            WHERE id = ?
        ")->execute([
            $nut['calories_per_100g'], $nut['protein_per_100g'], $nut['fat_per_100g'], $nut['carbs_per_100g'],
            $nut['fiber_per_100g'], $nut['sugar_per_100g'], $nut['sodium_per_100g'],
            $density, $weight, (int)$fdc, $ing_id,
        ]);
        erp_audit($pdo, 'update', 'ingredients', (int)$ing_id,
            'USDA match for ' . (erp_audit_label($row) ?: "#{$ing_id}") . ': ' . $out['description'] . ' (FDC ' . (int)$fdc . ')');
        ujson(true, 'Saved ' . ($row['singular_name'] ?? 'ingredient') . ' from USDA.', ['food' => $out, 'density' => $density, 'weight' => $weight]);
    } catch (Throwable $e) {
        error_log('[Nutrition] Pick save failed: ' . $e->getMessage());
        ujson(false, 'Could not save the ingredient. Try again.');
    }
}

// ── ACTION: SEARCH USDA ───────────────────────────────────────────────────────
$query = trim($_POST['query'] ?? '');
if (!$query) ujson(false, 'Search query is required.');
if (strlen($query) > 100) ujson(false, 'Query too long.');

$usda = bcrf_usda_search($query, 8);
if (!$usda['ok']) {
    error_log('[Nutrition] USDA request failed. Status: ' . $usda['status'] . ' ' . $usda['error']);
    ujson(false, "USDA API error (HTTP {$usda['status']}). Check USDA_API_KEY in bcrf-secrets.php.");
}
if (empty($usda['foods'])) {
    ujson(false, "No USDA matches for \"{$query}\". Try a simpler name (e.g. \"flour\" instead of \"all-purpose flour\").", ['results' => []]);
}

$results = [];
foreach ($usda['foods'] as $food) {
    $results[] = [
        'fdc_id'      => $food['fdcId'],
        'description' => $food['description'],
        'data_type'   => $food['dataType'] ?? '',
        'brand'       => $food['brandOwner'] ?? null,
        'nutrients'   => bcrf_usda_nutrients($food),
    ];
}

ujson(true, count($results) . ' results found.', ['results' => $results]);
