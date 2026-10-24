<?php
// admin/handlers/calculate_nutrition.php
// Calculates recipe nutrition from local ingredient data (no external API call).
// Requires ingredients to have USDA nutrition data stored via lookup_ingredient_nutrition.php

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

ob_end_clean();

check_admin();
header('Content-Type: application/json');

function njson(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST')            njson(false, 'POST required.');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? ''))
    njson(false, 'Security token expired.');

$recipe_id = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT);
if (!$recipe_id) njson(false, 'Invalid recipe ID.');

// ── FETCH RECIPE ─────────────────────────────────────────────────────────────
try {
    $recipe = $pdo->prepare("SELECT * FROM recipes WHERE id = ? LIMIT 1");
    $recipe->execute([$recipe_id]);
    $rec = $recipe->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    njson(false, 'Could not fetch recipe: ' . $e->getMessage());
}
if (!$rec) njson(false, 'Recipe not found.');

$servings = max(1, (int)($rec['servings'] ?? 1));

// ── FETCH INGREDIENTS WITH NUTRITION DATA ────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            ri.quantity,
            ri.unit_id,
            mu.name         AS unit_name,
            mu.unit_type,
            i.singular_name AS ingredient_name,
            i.calories_per_100g,
            i.protein_per_100g,
            i.fat_per_100g,
            i.carbs_per_100g,
            i.fiber_per_100g,
            i.sugar_per_100g,
            i.sodium_per_100g,
            i.density_g_per_ml,
            i.weight_per_unit_g
        FROM   recipe_ingredients ri
        JOIN   ingredients i         ON ri.ingredient_id = i.id
        LEFT JOIN measurement_units mu ON ri.unit_id = mu.id
        WHERE  ri.recipe_id = ?
    ");
    $stmt->execute([$recipe_id]);
    $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    njson(false, 'Ingredient query failed: ' . $e->getMessage());
}

if (empty($ingredients)) njson(false, 'No ingredients on this recipe yet.');

// ── UNIT CONVERSION HELPER ────────────────────────────────────────────────────
// Converts a quantity in given unit to grams.
// Returns null if conversion isn't possible (no density, no weight_per_unit, etc.)
function quantityToGrams(PDO $pdo, float $qty, ?string $unit_type, ?int $unit_id, ?float $density, ?float $weight_per_unit): ?float {
    if (!$unit_id) {
        // No unit — treat as count, use weight_per_unit_g
        return $weight_per_unit ? $qty * $weight_per_unit : null;
    }

    if ($unit_type === 'Weight') {
        // Convert weight unit → grams via unit_conversions table
        $s = $pdo->prepare("
            SELECT uc.conversion_factor
            FROM   unit_conversions uc
            JOIN   measurement_units tu ON uc.to_unit_id = tu.id AND tu.name = 'Gram'
            WHERE  uc.from_unit_id = ?
            LIMIT 1
        ");
        $s->execute([$unit_id]);
        $factor = $s->fetchColumn();
        if ($factor !== false) return $qty * (float)$factor;

        // Already grams?
        $s2 = $pdo->prepare("SELECT name FROM measurement_units WHERE id = ?");
        $s2->execute([$unit_id]);
        if ($s2->fetchColumn() === 'Gram') return $qty;
    }

    if ($unit_type === 'Volume' && $density) {
        // Convert volume unit → mL → grams via density
        $s = $pdo->prepare("
            SELECT uc.conversion_factor
            FROM   unit_conversions uc
            JOIN   measurement_units tu ON uc.to_unit_id = tu.id AND tu.name = 'Milliliter'
            WHERE  uc.from_unit_id = ?
            LIMIT 1
        ");
        $s->execute([$unit_id]);
        $ml_factor = $s->fetchColumn();

        // Check if unit is already mL
        if ($ml_factor === false) {
            $s2 = $pdo->prepare("SELECT name FROM measurement_units WHERE id = ?");
            $s2->execute([$unit_id]);
            if ($s2->fetchColumn() === 'Milliliter') $ml_factor = 1.0;
        }

        if ($ml_factor !== false) return $qty * (float)$ml_factor * $density;
    }

    if ($unit_type === 'Count' && $weight_per_unit) {
        return $qty * $weight_per_unit;
    }

    return null; // Cannot convert
}

// ── CALCULATE TOTALS ─────────────────────────────────────────────────────────
$totals = ['calories' => 0, 'protein' => 0, 'fat' => 0,
           'carbs' => 0, 'fiber' => 0, 'sugar' => 0, 'sodium' => 0];

$skipped   = [];
$processed = 0;

foreach ($ingredients as $ing) {
    // Skip if no nutrition data at all
    if ($ing['calories_per_100g'] === null) {
        $skipped[] = $ing['ingredient_name'] . ' (no nutrition data — run USDA lookup)';
        continue;
    }

    $grams = quantityToGrams(
        $pdo,
        (float)$ing['quantity'],
        $ing['unit_type'] ?: null,
        $ing['unit_id'] ? (int)$ing['unit_id'] : null,
        $ing['density_g_per_ml'] ? (float)$ing['density_g_per_ml'] : null,
        $ing['weight_per_unit_g'] ? (float)$ing['weight_per_unit_g'] : null
    );

    if ($grams === null) {
        $unit_hint = '';
        if ($ing['unit_type'] === 'Volume' && !$ing['density_g_per_ml'])
            $unit_hint = ' — set density_g_per_ml on ingredient';
        elseif ($ing['unit_type'] === 'Count' && !$ing['weight_per_unit_g'])
            $unit_hint = ' — set weight_per_unit_g on ingredient';
        $skipped[] = $ing['ingredient_name'] . " ({$ing['unit_name']} can't convert to grams{$unit_hint})";
        continue;
    }

    $factor = $grams / 100.0;
    $totals['calories'] += $factor * (float)$ing['calories_per_100g'];
    $totals['protein']  += $factor * (float)$ing['protein_per_100g'];
    $totals['fat']      += $factor * (float)$ing['fat_per_100g'];
    $totals['carbs']    += $factor * (float)$ing['carbs_per_100g'];
    $totals['fiber']    += $factor * (float)($ing['fiber_per_100g'] ?? 0);
    $totals['sugar']    += $factor * (float)($ing['sugar_per_100g'] ?? 0);
    $totals['sodium']   += $factor * (float)($ing['sodium_per_100g'] ?? 0);
    $processed++;
}

if ($processed === 0) {
    njson(false, 'No ingredients have USDA nutrition data yet. Open each ingredient and click "Lookup via USDA" to populate nutrition data first.');
}

// ── PER-SERVING ───────────────────────────────────────────────────────────────
$calories = (int)round($totals['calories'] / $servings);
$protein  = round($totals['protein'] / $servings, 1);
$fat      = round($totals['fat']     / $servings, 1);
$carbs    = round($totals['carbs']   / $servings, 1);
$fiber    = round($totals['fiber']   / $servings, 1);
$sugar    = round($totals['sugar']   / $servings, 1);
$sodium   = round($totals['sodium']  / $servings, 1);

// ── SAVE TO RECIPES TABLE ─────────────────────────────────────────────────────
try {
    $pdo->prepare("
        UPDATE recipes SET
            calories              = ?,
            protein_g             = ?,
            fat_g                 = ?,
            carbs_g               = ?,
            fiber_g               = ?,
            sugar_g               = ?,
            sodium_mg             = ?,
            nutrition_source      = 'usda_local',
            nutrition_updated_at  = NOW()
        WHERE id = ?
    ")->execute([$calories, $protein, $fat, $carbs, $fiber, $sugar, $sodium, $recipe_id]);
    $was = isset($rec['calories']) && $rec['calories'] !== null ? (int)$rec['calories'] . ' cal' : 'none';
    erp_audit($pdo, 'update', 'recipes', (int)$recipe_id,
        'Nutrition recalculated: ' . erp_audit_label($rec) . " ({$was} -> {$calories} cal per serving)");
} catch (Throwable $e) {
    error_log('[Nutrition] DB write failed: ' . $e->getMessage());
    njson(false, 'Nutrition calculated but could not save: ' . $e->getMessage());
}

// Build response message
$msg = "Calculated from {$processed} of " . count($ingredients) . " ingredient" . (count($ingredients) !== 1 ? 's' : '') . " for {$servings} serving" . ($servings !== 1 ? 's' : '') . ".";
if ($skipped) $msg .= ' Skipped: ' . implode(', ', $skipped) . '.';

njson(true, $msg, compact('calories', 'protein', 'fat', 'carbs', 'fiber', 'sugar', 'sodium'));
