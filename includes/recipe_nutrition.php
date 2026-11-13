<?php
// includes/recipe_nutrition.php — recipe Nutrition Facts from the ingredients' stored USDA data (no API call).
// Moved out of admin/handlers/calculate_nutrition.php on Nov 13, 2026 so a recipe Save recalculates by itself
// (admin/handlers/schema_record.php) and the chart can list exactly which ingredients still need a USDA match.

if (!function_exists('bcrf_recipe_nutrition')) {

function bcrf_recipe_qty_grams(PDO $pdo, float $qty, ?string $unit_type, ?int $unit_id, ?float $density, ?float $weight_per_unit): ?float {
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

/**
 * Work out per-serving nutrition for a recipe. With $save the recipes row is updated (only when at least one
 * ingredient counted). Never throws.
 * Returns ['ok' => bool, 'message' => string, 'calories','protein','fat','carbs','fiber','sugar','sodium',
 *          'servings' => int, 'counted' => int, 'lines' => int,
 *          'missing' => [['ingredient_id','name','need' => nutrition|density|weight|unit,'unit']]].
 */
function bcrf_recipe_nutrition(PDO $pdo, int $recipe_id, bool $save = true): array {
    $fail = fn(string $m) => ['ok' => false, 'message' => $m, 'missing' => [], 'counted' => 0, 'lines' => 0];
    try {
        $st = $pdo->prepare("SELECT * FROM recipes WHERE id = ? LIMIT 1");
        $st->execute([$recipe_id]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rec) return $fail('Recipe not found.');

        // Servings is often left empty and only Yield ("4") filled in; use it so values are really per serving.
        $servings = (int)($rec['servings'] ?? 0);
        if ($servings < 1) $servings = (int)($rec['recipe_yield'] ?? 0);
        $servings = max(1, $servings);

        $stmt = $pdo->prepare("
            SELECT ri.quantity, ri.unit_id, mu.name AS unit_name, mu.unit_type,
                   i.id AS ingredient_id, i.singular_name AS ingredient_name,
                   i.calories_per_100g, i.protein_per_100g, i.fat_per_100g, i.carbs_per_100g,
                   i.fiber_per_100g, i.sugar_per_100g, i.sodium_per_100g,
                   i.density_g_per_ml, i.weight_per_unit_g
            FROM   recipe_ingredients ri
            JOIN   ingredients i         ON ri.ingredient_id = i.id
            LEFT JOIN measurement_units mu ON ri.unit_id = mu.id
            WHERE  ri.recipe_id = ?
            ORDER BY ri.id
        ");
        $stmt->execute([$recipe_id]);
        $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[Nutrition] recipe ' . $recipe_id . ' read failed: ' . $e->getMessage());
        return $fail('Could not read the recipe.');
    }
    if (!$ingredients) return $fail('No ingredients on this recipe yet.');

    $t = ['calories' => 0, 'protein' => 0, 'fat' => 0, 'carbs' => 0, 'fiber' => 0, 'sugar' => 0, 'sodium' => 0];
    $missing = []; $seen = []; $counted = 0;
    $miss = function (array $ing, string $need) use (&$missing, &$seen) {
        $k = $ing['ingredient_id'] . ':' . $need;
        if (isset($seen[$k])) return;
        $seen[$k] = true;
        $missing[] = ['ingredient_id' => (int)$ing['ingredient_id'], 'name' => (string)$ing['ingredient_name'],
                      'need' => $need, 'unit' => (string)($ing['unit_name'] ?? '')];
    };
    foreach ($ingredients as $ing) {
        if ($ing['calories_per_100g'] === null) { $miss($ing, 'nutrition'); continue; }
        $grams = bcrf_recipe_qty_grams($pdo, (float)$ing['quantity'], $ing['unit_type'] ?: null,
            $ing['unit_id'] ? (int)$ing['unit_id'] : null,
            $ing['density_g_per_ml'] ? (float)$ing['density_g_per_ml'] : null,
            $ing['weight_per_unit_g'] ? (float)$ing['weight_per_unit_g'] : null);
        if ($grams === null) {
            $need = 'unit';
            if ($ing['unit_type'] === 'Volume' && !$ing['density_g_per_ml']) $need = 'density';
            elseif ((!$ing['unit_id'] || $ing['unit_type'] === 'Count') && !$ing['weight_per_unit_g']) $need = 'weight';
            $miss($ing, $need);
            continue;
        }
        $f = $grams / 100.0;
        $t['calories'] += $f * (float)$ing['calories_per_100g'];
        $t['protein']  += $f * (float)$ing['protein_per_100g'];
        $t['fat']      += $f * (float)$ing['fat_per_100g'];
        $t['carbs']    += $f * (float)$ing['carbs_per_100g'];
        $t['fiber']    += $f * (float)($ing['fiber_per_100g'] ?? 0);
        $t['sugar']    += $f * (float)($ing['sugar_per_100g'] ?? 0);
        $t['sodium']   += $f * (float)($ing['sodium_per_100g'] ?? 0);
        $counted++;
    }

    $out = [
        'ok' => $counted > 0, 'missing' => $missing, 'counted' => $counted, 'lines' => count($ingredients), 'servings' => $servings,
        'calories' => (int)round($t['calories'] / $servings),
        'protein'  => round($t['protein'] / $servings, 1),
        'fat'      => round($t['fat']     / $servings, 1),
        'carbs'    => round($t['carbs']   / $servings, 1),
        'fiber'    => round($t['fiber']   / $servings, 1),
        'sugar'    => round($t['sugar']   / $servings, 1),
        'sodium'   => round($t['sodium']  / $servings, 1),
    ];
    $n = count($ingredients);
    $out['message'] = $counted
        ? "Counted {$counted} of {$n} ingredient" . ($n !== 1 ? 's' : '') . " for {$servings} serving" . ($servings !== 1 ? 's' : '') . '.'
        : 'None of the ingredients have USDA data yet. Pick a match for each one below.';
    if (!$counted || !$save) return $out;

    try {
        $pdo->prepare("
            UPDATE recipes SET calories = ?, protein_g = ?, fat_g = ?, carbs_g = ?, fiber_g = ?, sugar_g = ?, sodium_mg = ?,
                   nutrition_source = 'usda_local', nutrition_servings = ?, nutrition_updated_at = NOW()
            WHERE id = ?
        ")->execute([$out['calories'], $out['protein'], $out['fat'], $out['carbs'], $out['fiber'], $out['sugar'], $out['sodium'], $servings, $recipe_id]);
        $out['was'] = isset($rec['calories']) && $rec['calories'] !== null ? (int)$rec['calories'] : null;
        $out['label'] = $rec['title'] ?? ('#' . $recipe_id);
    } catch (Throwable $e) {
        error_log('[Nutrition] recipe ' . $recipe_id . ' write failed: ' . $e->getMessage());
        $out['ok'] = false;
        $out['message'] = 'Worked it out but could not save it. Try again.';
    }
    return $out;
}

}
