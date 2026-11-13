<?php
// includes/kitchen.php — Farm OS Phase 4 (Kitchen & labels), slice 1 (Nov 12, 2026; 09 › Ingredients, Labels; migration 0050).
// Turns every recipe line into grams so a label can list ingredients heaviest first, and checks what's missing:
//   - weight units (g, kg, oz, lb) convert directly;
//   - volume units (tsp, tbsp, cup, mL, ...) need the ingredient's density (grams per mL);
//   - anything else (each, clove, slice, no unit) needs the ingredient's weight for one;
//   - a line that is another recipe (linked_recipe_id) counts as a compound ingredient: weight units, or a count
//     of whole batches of that recipe; its own ingredients go in parentheses and its allergens carry up.
// Allergens and sub-ingredients live on the ingredient (admin/includes/label_ingredients.php has the allergen list
// and the "Contains:" rules, shared with the print page). Nothing here prints or saves a label; slice 4 does.
// Used by admin/kitchen_ingredients.php, admin/kitchen_recipes.php and tests/kitchen_test.php.
if (!function_exists('bcrf_kitchen_ready')) {

require_once dirname(__DIR__) . '/admin/includes/label_ingredients.php';

class BcrfKitchenError extends RuntimeException {}

/** Migration 0050 has run. */
function bcrf_kitchen_ready(PDO $pdo): bool {
    try { return (bool)$pdo->query("SHOW COLUMNS FROM ingredients LIKE 'allergens_checked_at'")->fetch(); }
    catch (Throwable $e) { return false; }
}

/** Grams or millilitres in one of a unit, by its name or abbreviation (lower case, no dots). */
function bcrf_kitchen_unit_table(): array {
    return [
        'mass' => ['g' => 1, 'gram' => 1, 'gm' => 1, 'kg' => 1000, 'kilogram' => 1000, 'mg' => 0.001, 'milligram' => 0.001,
                   'oz' => 28.349523, 'ounce' => 28.349523, 'lb' => 453.59237, 'lbs' => 453.59237, 'pound' => 453.59237],
        'volume' => ['ml' => 1, 'milliliter' => 1, 'millilitre' => 1, 'l' => 1000, 'liter' => 1000, 'litre' => 1000,
                     'tsp' => 4.928922, 'teaspoon' => 4.928922, 'tbsp' => 14.786765, 'tbs' => 14.786765, 'tablespoon' => 14.786765,
                     'cup' => 236.588237, 'c' => 236.588237, 'fl oz' => 29.57353, 'floz' => 29.57353, 'fluid ounce' => 29.57353,
                     'pint' => 473.176473, 'pt' => 473.176473, 'quart' => 946.352946, 'qt' => 946.352946,
                     'gallon' => 3785.411784, 'gal' => 3785.411784, 'dash' => 0.616115, 'pinch' => 0.308057],
    ];
}

/** Plain key for a unit word: "Tablespoons" -> "tablespoon", "Fl. Oz." -> "fl oz". */
function bcrf_kitchen_unit_key(string $s): string {
    $s = strtolower(trim(preg_replace('/\s+/', ' ', str_replace('.', '', $s))));
    if (strlen($s) > 3 && str_ends_with($s, 'es') && in_array(substr($s, 0, -2), ['pinch', 'dash'], true)) return substr($s, 0, -2);
    if (strlen($s) > 2 && str_ends_with($s, 's') && !in_array($s, ['lbs', 'tbs'], true)) $s = substr($s, 0, -1);
    return $s;
}

/** Every unit: id => ['name', 'abbr', 'kind' => mass|volume|count, 'factor' => g or mL per unit, or null if unknown]. */
function bcrf_kitchen_units(PDO $pdo, bool $fresh = false): array {
    static $cache = [];
    $k = spl_object_id($pdo);
    if (!$fresh && isset($cache[$k])) return $cache[$k];
    $table = bcrf_kitchen_unit_table();
    $out = [];
    try {
        $rows = $pdo->query("SELECT id, name, abbreviation, unit_type FROM measurement_units")->fetchAll(PDO::FETCH_ASSOC);
        $conv = [];
        foreach ($pdo->query("SELECT uc.from_unit_id, uc.conversion_factor, tu.name AS to_name FROM unit_conversions uc
                              JOIN measurement_units tu ON tu.id = uc.to_unit_id WHERE tu.name IN ('Gram', 'Milliliter')")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $conv[(int)$c['from_unit_id']][$c['to_name'] === 'Gram' ? 'mass' : 'volume'] = (float)$c['conversion_factor'];
        }
    } catch (Throwable $e) {
        error_log('[kitchen] units: ' . $e->getMessage());
        $rows = []; $conv = [];
    }
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $type = strtolower((string)$r['unit_type']);
        $want = $type === 'weight' ? 'mass' : ($type === 'volume' ? 'volume' : null);
        $kind = 'count'; $factor = null;
        foreach ([(string)$r['name'], (string)$r['abbreviation']] as $word) {
            $w = bcrf_kitchen_unit_key($word);
            if ($w === '') continue;
            // "Ounce" set up as a volume unit means fluid ounces.
            if ($want === 'volume' && in_array($w, ['oz', 'ounce'], true)) $w = 'fl oz';
            foreach (($want ? [$want] : ['mass', 'volume']) as $kd) {
                if (isset($table[$kd][$w])) { $kind = $kd; $factor = (float)$table[$kd][$w]; break 2; }
            }
        }
        if ($factor === null && $want !== null) {
            $kind = $want;
            $factor = $conv[$id][$want] ?? null;   // a unit only this farm uses, with a conversion row
        }
        $out[$id] = ['name' => (string)$r['name'], 'abbr' => (string)($r['abbreviation'] ?? ''), 'kind' => $kind, 'factor' => $factor];
    }
    return $cache[$k] = $out;
}

/**
 * Grams for one recipe line. Returns ['grams' => float|null, 'problem' => '' | 'unit' | 'density' | 'each', 'kind' => ...].
 *   unit    — a weight or volume unit the system can't convert (add a unit conversion)
 *   density — a volume measure and the ingredient has no grams per mL
 *   each    — a count (or no unit) and the ingredient has no weight for one
 */
function bcrf_kitchen_line_grams(PDO $pdo, float $qty, ?int $unitId, ?float $density, ?float $eachG): array {
    $u = $unitId ? (bcrf_kitchen_units($pdo)[$unitId] ?? null) : null;
    $kind = $u['kind'] ?? 'count';
    if ($kind === 'mass') {
        return $u['factor'] ? ['grams' => $qty * $u['factor'], 'problem' => '', 'kind' => $kind] : ['grams' => null, 'problem' => 'unit', 'kind' => $kind];
    }
    if ($kind === 'volume') {
        if (!$u['factor']) return ['grams' => null, 'problem' => 'unit', 'kind' => $kind];
        if (!$density || $density <= 0) return ['grams' => null, 'problem' => 'density', 'kind' => $kind];
        return ['grams' => $qty * $u['factor'] * $density, 'problem' => '', 'kind' => $kind];
    }
    if (!$eachG || $eachG <= 0) return ['grams' => null, 'problem' => 'each', 'kind' => 'count'];
    return ['grams' => $qty * $eachG, 'problem' => '', 'kind' => 'count'];
}

/** What to tell staff about a line problem. */
function bcrf_kitchen_problem_text(string $problem, string $unitName = ''): string {
    return match ($problem) {
        'density' => 'Needs grams per mL, to turn ' . ($unitName !== '' ? strtolower($unitName) . 's' : 'a volume') . ' into a weight',
        'each'    => 'Needs the weight of one' . ($unitName !== '' ? ' ' . strtolower($unitName) : ''),
        'unit'    => 'The unit "' . $unitName . '" has no conversion to grams or mL (Kitchen › Reference › Unit Conversions)',
        'sub_unit'=> 'A recipe used as an ingredient must be in a weight (g, oz, lb) or a number of whole batches',
        'sub_blocked' => 'That recipe has lines without a weight; fix it first',
        'loop'    => 'This recipe uses itself (directly or through another recipe)',
        'zero'    => 'Quantity is 0',
        default   => '',
    };
}

/** Name on the label for an ingredient row. */
function bcrf_kitchen_label_name(array $ing): string {
    $n = trim((string)($ing['label_name'] ?? ''));
    return $n !== '' ? $n : trim((string)($ing['singular_name'] ?? $ing['name'] ?? ''));
}

/** Merge two parsed allergen maps (['tree_nuts' => 'pecans']), joining the specific foods. */
function bcrf_kitchen_allergens_merge(array $a, array $b): array {
    foreach ($b as $k => $d) {
        if (!array_key_exists($k, $a)) { $a[$k] = $d; continue; }
        $have = array_filter(array_map('trim', preg_split('/\s*&\s*/', (string)$a[$k])));
        foreach (array_filter(array_map('trim', preg_split('/\s*(?:&|\/|\band\b)\s*/i', (string)$d))) as $one) {
            if (!in_array(mb_strtolower($one), array_map('mb_strtolower', $have), true)) $have[] = $one;
        }
        $a[$k] = implode(' & ', $have);
    }
    return $a;
}

/**
 * Resolve a recipe for its label.
 * Returns [
 *   'recipe'    => ['id', 'title', 'slug'],
 *   'lines'     => each recipe line: ['id', 'group', 'qty', 'unit', 'unit_id', 'type' => ingredient|recipe, 'ingredient_id',
 *                  'linked_recipe_id', 'name', 'label_name', 'grams' (null if unknown), 'problem', 'problem_text'],
 *   'items'     => one per ingredient or sub-recipe, heaviest first: ['key', 'type', 'id', 'name', 'grams', 'pct',
 *                  'allergens' (raw, null = not checked), 'sub_ingredients'],
 *   'total_g'   => total weight of the lines that resolved,
 *   'statement' => "flour (wheat flour, niacin), sugar, butter (cream, salt)",
 *   'contains'  => "Milk, Wheat, Pecans" ('' when none),
 *   'sulfites'  => true when any ingredient (or sub-recipe ingredient) is marked sulfites,
 *   'unchecked' => ingredient names with allergens never checked (including inside sub-recipes),
 *   'needs_name'=> ingredients marked tree nuts/fish/shellfish without saying which,
 *   'problems'  => count of lines without a weight,
 *   'ready'     => true when every line has a weight, every allergen is checked and named, and there is at least one line,
 * ]
 * null when the recipe doesn't exist.
 */
function bcrf_kitchen_resolve(PDO $pdo, int $recipeId, array $seen = []): ?array {
    $st = $pdo->prepare("SELECT id, title, slug FROM recipes WHERE id = ?");
    $st->execute([$recipeId]);
    $recipe = $st->fetch(PDO::FETCH_ASSOC);
    if (!$recipe) return null;
    $seen[$recipeId] = true;
    $st = $pdo->prepare("
        SELECT ri.id, ri.quantity, ri.unit_id, ri.ingredient_group, ri.ingredient_id, ri.linked_recipe_id,
               i.singular_name, i.label_name, i.density_g_per_ml, i.weight_per_unit_g, i.allergens, i.sub_ingredients,
               lr.title AS linked_title
        FROM recipe_ingredients ri
        LEFT JOIN ingredients i ON i.id = ri.ingredient_id
        LEFT JOIN recipes lr ON lr.id = ri.linked_recipe_id
        WHERE ri.recipe_id = ?
        ORDER BY ri.id");
    $st->execute([$recipeId]);
    $units = bcrf_kitchen_units($pdo);
    $lines = []; $items = []; $unchecked = []; $needs = []; $problems = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['quantity'];
        $uid = $r['unit_id'] !== null ? (int)$r['unit_id'] : null;
        $unitName = $uid ? ($units[$uid]['name'] ?? '') : '';
        $line = ['id' => (int)$r['id'], 'group' => (string)($r['ingredient_group'] ?? ''), 'qty' => $qty, 'unit' => $unitName, 'unit_id' => $uid,
                 'ingredient_id' => $r['ingredient_id'] !== null ? (int)$r['ingredient_id'] : null,
                 'linked_recipe_id' => $r['linked_recipe_id'] !== null ? (int)$r['linked_recipe_id'] : null,
                 'grams' => null, 'problem' => '', 'problem_text' => ''];
        if ($line['linked_recipe_id'] && !$line['ingredient_id']) {
            $sid = $line['linked_recipe_id'];
            $line['type'] = 'recipe';
            $line['name'] = $line['label_name'] = trim((string)$r['linked_title']) !== '' ? (string)$r['linked_title'] : 'Recipe #' . $sid;
            $sub = isset($seen[$sid]) ? null : bcrf_kitchen_resolve($pdo, $sid, $seen);
            $kind = $uid ? ($units[$uid]['kind'] ?? 'count') : 'count';
            if (isset($seen[$sid])) {
                $line['problem'] = 'loop';
            } elseif (!$sub) {
                $line['problem'] = 'sub_blocked';
            } elseif ($kind === 'mass' && !empty($units[$uid]['factor'])) {
                $line['grams'] = $qty * $units[$uid]['factor'];
            } elseif ($kind === 'count') {
                if ($sub['problems'] > 0 || $sub['total_g'] <= 0) $line['problem'] = 'sub_blocked';
                else $line['grams'] = $qty * $sub['total_g'];
            } else {
                $line['problem'] = 'sub_unit';
            }
            if ($sub) {
                $unchecked = array_merge($unchecked, $sub['unchecked']);
                $needs = array_merge($needs, $sub['needs_name']);
                $map = [];
                foreach ($sub['items'] as $it) $map = bcrf_kitchen_allergens_merge($map, bcrf_allergens_parse($it['allergens']));
                $subAll = $sub['unchecked'] ? null : bcrf_allergens_encode($map);
                $subText = $sub['statement'];
            } else {
                $subAll = null; $subText = '';
            }
            $key = 'r' . $sid;
            if (!isset($items[$key])) $items[$key] = ['key' => $key, 'type' => 'recipe', 'id' => $sid, 'name' => $line['name'], 'grams' => 0.0, 'known' => true,
                                                       'allergens' => $subAll, 'sub_ingredients' => $subText];
        } elseif ($line['ingredient_id']) {
            $line['type'] = 'ingredient';
            $line['name'] = (string)$r['singular_name'];
            $line['label_name'] = bcrf_kitchen_label_name($r);
            $g = bcrf_kitchen_line_grams($pdo, $qty, $uid,
                $r['density_g_per_ml'] !== null ? (float)$r['density_g_per_ml'] : null,
                $r['weight_per_unit_g'] !== null ? (float)$r['weight_per_unit_g'] : null);
            $line['grams'] = $g['grams'];
            $line['problem'] = $g['problem'];
            $key = 'i' . $line['ingredient_id'];
            if (!isset($items[$key])) {
                $items[$key] = ['key' => $key, 'type' => 'ingredient', 'id' => $line['ingredient_id'], 'name' => $line['label_name'], 'grams' => 0.0, 'known' => true,
                                'allergens' => $r['allergens'] === null ? null : (string)$r['allergens'], 'sub_ingredients' => trim((string)($r['sub_ingredients'] ?? ''))];
            }
        } else {
            continue;   // an empty line (no ingredient, no recipe): nothing to label
        }
        if ($line['problem'] === '' && $qty <= 0) { $line['problem'] = 'zero'; $line['grams'] = null; }
        if ($line['problem'] !== '') {
            $problems++;
            $items[$key]['known'] = false;
            $line['problem_text'] = bcrf_kitchen_problem_text($line['problem'], $unitName);
        } else {
            $items[$key]['grams'] += (float)$line['grams'];
        }
        $lines[] = $line;
    }
    $total = 0.0;
    foreach ($items as $it) $total += $it['grams'];
    $items = array_values($items);
    usort($items, fn($a, $b) => ($b['grams'] <=> $a['grams']) ?: strcasecmp($a['name'], $b['name']));
    foreach ($items as &$it) { $it['pct'] = $total > 0 ? round($it['grams'] * 100 / $total, 1) : 0.0; }
    unset($it);
    $label = bcrf_label_statement(array_map(fn($it) => ['name' => $it['name'], 'allergens' => $it['allergens'], 'sub_ingredients' => $it['sub_ingredients']], $items));
    // Sub-recipe rows with unchecked insides were already listed by name above; don't list the recipe itself.
    $subNames = array_column(array_filter($items, fn($it) => $it['type'] === 'recipe'), 'name');
    $unchecked = array_values(array_unique(array_merge($unchecked, array_diff($label['unchecked'], $subNames))));
    $needs = array_values(array_unique(array_merge($needs, array_diff($label['needs_name'], $subNames))));
    return [
        'recipe'    => ['id' => (int)$recipe['id'], 'title' => (string)$recipe['title'], 'slug' => (string)$recipe['slug']],
        'lines'     => $lines,
        'items'     => $items,
        'total_g'   => round($total, 2),
        'statement' => $label['ingredients'],
        'contains'  => $label['contains'],
        'sulfites'  => $label['sulfites'],
        'unchecked' => $unchecked,
        'needs_name'=> $needs,
        'problems'  => $problems,
        'ready'     => $lines && $problems === 0 && !$unchecked && !$needs,
    ];
}

/** Every recipe with a label readiness summary, for the Label check list. */
function bcrf_kitchen_recipe_list(PDO $pdo, string $q = ''): array {
    $sql = "SELECT id, title FROM recipes" . ($q !== '' ? " WHERE title LIKE ?" : '') . " ORDER BY title";
    $st = $pdo->prepare($sql);
    $st->execute($q !== '' ? ['%' . $q . '%'] : []);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $res = bcrf_kitchen_resolve($pdo, (int)$r['id']);
        if (!$res) continue;
        $out[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'lines' => count($res['lines']), 'problems' => $res['problems'],
                  'unchecked' => count($res['unchecked']), 'needs_name' => count($res['needs_name']), 'ready' => $res['ready']];
    }
    return $out;
}

/** One ingredient with its label fields. */
function bcrf_kitchen_ingredient(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT i.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS checked_by_name
                         FROM ingredients i LEFT JOIN users u ON u.id = i.allergens_checked_by WHERE i.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * How each ingredient is used in recipes, and what it still needs for labels.
 * Returns ingredient id => ['recipes' => [id => title], 'needs_density' => bool, 'needs_each' => bool].
 */
function bcrf_kitchen_ingredient_use(PDO $pdo): array {
    $units = bcrf_kitchen_units($pdo);
    $out = [];
    $rows = $pdo->query("SELECT ri.ingredient_id, ri.unit_id, r.id AS recipe_id, r.title, i.density_g_per_ml, i.weight_per_unit_g
                         FROM recipe_ingredients ri JOIN recipes r ON r.id = ri.recipe_id JOIN ingredients i ON i.id = ri.ingredient_id
                         WHERE ri.ingredient_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $id = (int)$r['ingredient_id'];
        $out[$id] ??= ['recipes' => [], 'needs_density' => false, 'needs_each' => false];
        $out[$id]['recipes'][(int)$r['recipe_id']] = (string)$r['title'];
        $kind = $r['unit_id'] !== null ? ($units[(int)$r['unit_id']]['kind'] ?? 'count') : 'count';
        if ($kind === 'volume' && !((float)$r['density_g_per_ml'] > 0)) $out[$id]['needs_density'] = true;
        if ($kind === 'count' && !((float)$r['weight_per_unit_g'] > 0)) $out[$id]['needs_each'] = true;
    }
    return $out;
}

/** Ingredients for the Label details list. $filter: 'attention' (default: used in a recipe and missing something), 'all', 'unchecked'. */
function bcrf_kitchen_ingredient_list(PDO $pdo, string $filter = 'attention', string $q = ''): array {
    $use = bcrf_kitchen_ingredient_use($pdo);
    $sql = "SELECT id, singular_name, label_name, allergens, sub_ingredients, density_g_per_ml, weight_per_unit_g, allergens_checked_at FROM ingredients"
         . ($q !== '' ? " WHERE singular_name LIKE ? OR plural_name LIKE ? OR label_name LIKE ?" : '') . " ORDER BY singular_name";
    $st = $pdo->prepare($sql);
    $st->execute($q !== '' ? array_fill(0, 3, '%' . $q . '%') : []);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['id'];
        $u = $use[$id] ?? ['recipes' => [], 'needs_density' => false, 'needs_each' => false];
        $parsed = bcrf_allergens_parse($r['allergens']);
        $needsName = false;
        foreach (bcrf_allergens_only($parsed) as $k => $d) if (bcrf_allergen_list()[$k][1] && $d === '') $needsName = true;
        $r['sulfites'] = array_key_exists('sulfites', $parsed);
        $r['sulfite_hint'] = $r['sulfites'] ? '' : bcrf_sulfite_hint((string)$r['singular_name'], (string)($r['label_name'] ?? ''), (string)($r['sub_ingredients'] ?? ''));
        $r['used_in'] = $u['recipes'];
        $r['needs_density'] = $u['needs_density'];
        $r['needs_each'] = $u['needs_each'];
        $r['needs_allergens'] = $r['allergens'] === null;
        $r['needs_name'] = $needsName;
        $r['attention'] = $u['recipes'] && ($r['needs_allergens'] || $needsName || $u['needs_density'] || $u['needs_each']);
        if ($filter === 'attention' && !$r['attention']) continue;
        if ($filter === 'unchecked' && !$r['needs_allergens']) continue;
        $out[] = $r;
    }
    return $out;
}

/** A positive decimal from a form, or null when blank. */
function bcrf_kitchen_num($v, string $what, float $min, float $max): ?float {
    $v = trim(str_replace(',', '', (string)$v));
    if ($v === '') return null;
    if (!is_numeric($v)) throw new BcrfKitchenError($what . ' must be a number.');
    $n = (float)$v;
    if ($n < $min || $n > $max) throw new BcrfKitchenError($what . ' must be between ' . $min . ' and ' . $max . '.');
    return $n;
}

/**
 * Save an ingredient's label details. Fields missing from $in keep their value.
 * $in: label_name, sub_ingredients, density_g_per_ml, weight_per_unit_g,
 *      allergen[] (keys from bcrf_allergen_list()), allergen_detail[key] (which nut/fish/shellfish),
 *      no_allergens = '1' when none of the nine (needed when nothing is ticked, so a blank form never counts as checked),
 *      allergens_unknown = '1' to leave allergens not checked.
 *      sulfites = '1' when it has sulfites at 10 ppm or more (not one of the nine; left out = keep what it has).
 * Returns the fields that changed (for the audit line).
 */
function bcrf_kitchen_ingredient_save(PDO $pdo, int $id, array $in, ?int $uid): array {
    $cur = bcrf_kitchen_ingredient($pdo, $id);
    if (!$cur) throw new BcrfKitchenError('That ingredient no longer exists.');
    // Fields left out of $in keep what they have (a form always sends all four).
    foreach (['label_name' => 'label_name', 'sub_ingredients' => 'sub_ingredients', 'density_g_per_ml' => 'density_g_per_ml', 'weight_per_unit_g' => 'weight_per_unit_g'] as $f => $c) {
        if (!array_key_exists($f, $in)) $in[$f] = (string)($cur[$c] ?? '');
    }
    $label = trim(preg_replace('/\s+/', ' ', (string)($in['label_name'] ?? '')));
    if (mb_strlen($label) > 150) throw new BcrfKitchenError('Label name is too long (150 characters at most).');
    if ($label !== '' && mb_strtolower($label) === mb_strtolower((string)$cur['singular_name'])) $label = '';
    $sub = trim(preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r", "\n"], ' ', (string)($in['sub_ingredients'] ?? ''))));
    if (mb_strlen($sub) > 2000) throw new BcrfKitchenError('Sub-ingredients are too long (2000 characters at most).');
    $density = bcrf_kitchen_num($in['density_g_per_ml'] ?? '', 'Grams per mL', 0.05, 5);
    $each = bcrf_kitchen_num($in['weight_per_unit_g'] ?? '', 'Weight of one', 0.01, 100000);

    $list = bcrf_allergen_list();
    $allergens = $cur['allergens'];
    $checkedNow = false;
    if (empty($in['allergens_unknown'])) {
        $ticked = [];
        foreach ((array)($in['allergen'] ?? []) as $k) {
            $k = (string)$k;
            if (!isset($list[$k])) continue;
            $d = trim((string)($in['allergen_detail'][$k] ?? ''));
            if ($list[$k][1] && $d === '') throw new BcrfKitchenError('Say which ' . match ($k) { 'tree_nuts' => 'nut (for example "pecans")', 'fish' => 'fish (for example "cod")', default => 'shellfish (for example "shrimp")' } . '. Labels must name it.');
            $ticked[$k] = $list[$k][1] ? $d : '';
        }
        if (!$ticked && empty($in['no_allergens'])) throw new BcrfKitchenError('Tick the allergens it contains, or tick "None of the nine" so the label knows it was checked.');
        if ($ticked && !empty($in['no_allergens'])) throw new BcrfKitchenError('You ticked allergens and "None of the nine". Pick one.');
        $sulfites = array_key_exists('sulfites', $in) ? !empty($in['sulfites']) : bcrf_allergens_has_sulfites($cur['allergens']);
        if ($sulfites) $ticked['sulfites'] = '';
        $allergens = bcrf_allergens_encode($ticked);
        $checkedNow = true;
    } else {
        $allergens = null;
    }

    $changes = [];
    if ((string)($cur['label_name'] ?? '') !== $label) $changes[] = 'label name';
    if (trim((string)($cur['sub_ingredients'] ?? '')) !== $sub) $changes[] = 'sub-ingredients';
    if (($cur['density_g_per_ml'] === null ? null : (float)$cur['density_g_per_ml']) !== $density) $changes[] = 'grams per mL';
    if (($cur['weight_per_unit_g'] === null ? null : (float)$cur['weight_per_unit_g']) !== $each) $changes[] = 'weight of one';
    if ($cur['allergens'] !== $allergens) $changes[] = 'allergens';

    $pdo->prepare("UPDATE ingredients SET label_name = ?, sub_ingredients = ?, density_g_per_ml = ?, weight_per_unit_g = ?, allergens = ?"
        . ($checkedNow ? ", allergens_checked_at = UTC_TIMESTAMP(), allergens_checked_by = ?" : ($allergens === null ? ", allergens_checked_at = NULL, allergens_checked_by = NULL" : ''))
        . " WHERE id = ?")
        ->execute(array_merge([$label !== '' ? $label : null, $sub !== '' ? $sub : null, $density, $each, $allergens], $checkedNow ? [$uid] : [], [$id]));
    return $changes;
}

/** Readable allergens for a list cell: "Milk, Tree nuts (pecans)", "None", or "Not checked". */
function bcrf_kitchen_allergen_text(?string $raw): string {
    if ($raw === null) return 'Not checked';
    $all = bcrf_allergens_parse($raw);
    $p = bcrf_allergens_only($all);
    $sulf = array_key_exists('sulfites', $all) ? 'Sulfites' : '';
    if (!$p) return $sulf !== '' ? 'None of the nine · ' . $sulf : 'None';
    $list = bcrf_allergen_list();
    $o = [];
    foreach ($p as $k => $d) $o[] = $list[$k][0] . ($d !== '' ? ' (' . $d . ')' : ($list[$k][1] ? ' (which?)' : ''));
    return implode(', ', $o) . ($sulf !== '' ? ' · ' . $sulf : '');
}

/** "1 1/2" style quantity for display. */
function bcrf_kitchen_qty(float $q): string {
    $whole = floor($q);
    $frac = $q - $whole;
    foreach ([[0.125, '1/8'], [0.25, '1/4'], [0.333, '1/3'], [0.5, '1/2'], [0.667, '2/3'], [0.75, '3/4']] as [$v, $s]) {
        if (abs($frac - $v) < 0.01) return ($whole > 0 ? (int)$whole . ' ' : '') . $s;
    }
    return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
}

/** "227 g" / "1.2 kg" */
function bcrf_kitchen_grams(?float $g): string {
    if ($g === null) return '—';
    if ($g >= 1000) return rtrim(rtrim(number_format($g / 1000, 2, '.', ''), '0'), '.') . ' kg';
    return ($g < 10 ? rtrim(rtrim(number_format($g, 1, '.', ''), '0'), '.') : (string)round($g)) . ' g';
}

}
