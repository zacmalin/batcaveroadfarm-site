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

$action = trim($_POST['action'] ?? 'search'); // 'search' or 'save'

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

// ── ACTION: SEARCH USDA ───────────────────────────────────────────────────────
$query = trim($_POST['query'] ?? '');
if (!$query) ujson(false, 'Search query is required.');
if (strlen($query) > 100) ujson(false, 'Query too long.');

// USDA FoodData Central search, through the USDA adapter (Farm OS Phase 0): includes/adapter_usda.php
require_once __DIR__ . '/../../includes/adapter_usda.php';
$usda = bcrf_usda_search($query);
if (!$usda['ok']) {
    error_log('[Nutrition] USDA request failed. Status: ' . $usda['status'] . ' ' . $usda['error']);
    ujson(false, "USDA API error (HTTP {$usda['status']}). Check USDA_API_KEY in bcrf-secrets.php.");
}
$raw = $usda['raw'];

$data = json_decode($raw, true);
if (!$data || empty($data['foods'])) {
    ujson(false, "No USDA results found for \"{$query}\". Try a simpler name (e.g. \"flour\" instead of \"all-purpose flour\").");
}

// ── NUTRIENT IDs WE CARE ABOUT ────────────────────────────────────────────────
$nutrient_map = [
    1008 => 'calories_per_100g',  // Energy (kcal)
    1003 => 'protein_per_100g',   // Protein (g)
    1004 => 'fat_per_100g',       // Total Fat (g)
    1005 => 'carbs_per_100g',     // Carbohydrate by diff (g)
    1079 => 'fiber_per_100g',     // Dietary Fiber (g)
    2000 => 'sugar_per_100g',     // Sugars total (g)
    1093 => 'sodium_per_100g',    // Sodium (mg)
];

// ── FORMAT RESULTS ────────────────────────────────────────────────────────────
$results = [];
foreach (array_slice($data['foods'], 0, 5) as $food) {
    $nutrients = array_fill_keys(array_values($nutrient_map), null);

    foreach ($food['foodNutrients'] ?? [] as $n) {
        $nid = (int)($n['nutrientId'] ?? 0);
        if (isset($nutrient_map[$nid])) {
            $nutrients[$nutrient_map[$nid]] = round((float)$n['value'], 2);
        }
    }

    $results[] = [
        'fdc_id'      => $food['fdcId'],
        'description' => $food['description'],
        'data_type'   => $food['dataType'] ?? '',
        'brand'       => $food['brandOwner'] ?? null,
        'nutrients'   => $nutrients,
    ];
}

ujson(true, count($results) . ' results found.', ['results' => $results]);
