<?php
// admin/includes/label_ingredients.php
//
// Ingredient lists for printed product labels (Nov 2026, website task
// "Fix kitchen label ingredient order").
//
// Food labels must list ingredients in descending order by weight (heaviest
// first). product_ingredients links inventory items to ingredients; the
// sort_order column holds the label order staff set in the Print Label window
// (admin/views/inventory_update.php). Rows saved before this change have
// sort_order 0, so the order is "not set yet" and the window says so.
//
// Allergens and sub-ingredients (Nov 2026, task "Add allergen statement and
// sub-ingredients to kitchen labels") live on the ingredient, so every product
// using it gets them:
//   ingredients.allergens        NULL = not checked yet; '' = checked, none;
//                                otherwise keys from bcrf_allergen_list(), comma
//                                separated, with the specific food after a colon
//                                for tree nuts, fish and shellfish:
//                                "milk,wheat,tree_nuts:pecans"
//   ingredients.sub_ingredients  the bought product's own ingredient list, as
//                                printed on its label; shown in parentheses.
// The "Contains:" line (federal FALCPA rule) is built from those allergens.
//
// Also builds the other NCDA&CS Home Processor label lines: net quantity in
// US units with the metric equivalent, and the maker's name and physical address
// (from Site Info; a website doesn't count).
//
// Used by admin/print_label.php, admin/handlers/get_label_data.php and
// admin/handlers/label_ingredients_save.php.
// Farm OS Phase 0 moves the column add below into a numbered migration.

if (!function_exists('bcrf_label_ing_schema')) {

/** Make sure product_ingredients has sort_order. Cheap: checked once per request. */
function bcrf_label_ing_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM product_ingredients LIKE 'sort_order'")->fetch();
        if (!$has) $pdo->exec("ALTER TABLE product_ingredients ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        error_log('[label_ingredients] schema: ' . $e->getMessage());
    }
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM ingredients")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('allergens', $cols, true))
            $pdo->exec("ALTER TABLE ingredients ADD COLUMN allergens VARCHAR(255) NULL DEFAULT NULL COMMENT 'Major allergens for labels; NULL = not checked, empty = none'");
        if (!in_array('sub_ingredients', $cols, true))
            $pdo->exec("ALTER TABLE ingredients ADD COLUMN sub_ingredients TEXT NULL COMMENT 'Ingredient list printed on the bought product, for labels'");
    } catch (Throwable $e) {
        error_log('[label_ingredients] schema ingredients: ' . $e->getMessage());
    }
}

/**
 * Label ingredients for one inventory item, in label order.
 * Returns ['rows' => [['id', 'name', 'allergens' (null = not checked), 'sub_ingredients'], ...],
 *          'order_set' => bool].
 * order_set is false when the list has never been put in order (all sort_order 0);
 * those lists fall back to alphabetical, the old behaviour.
 */
function bcrf_label_ingredients(PDO $pdo, int $item_id): array {
    bcrf_label_ing_schema($pdo);
    $rows = [];
    $order_set = true;
    try {
        $st = $pdo->prepare("
            SELECT ing.id, ing.singular_name AS name, COALESCE(pi.sort_order, 0) AS sort_order,
                   ing.allergens, COALESCE(ing.sub_ingredients, '') AS sub_ingredients
            FROM   product_ingredients pi
            JOIN   ingredients ing ON pi.ingredient_id = ing.id
            WHERE  pi.product_type = 'inventory' AND pi.product_id = ?
            ORDER  BY (COALESCE(pi.sort_order, 0) = 0), pi.sort_order ASC, ing.singular_name ASC
        ");
        $st->execute([$item_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['sort_order'] === 0) $order_set = false;
            $rows[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'],
                       'allergens' => $r['allergens'] === null ? null : (string)$r['allergens'],
                       'sub_ingredients' => trim((string)$r['sub_ingredients'])];
        }
    } catch (Throwable $e) {
        error_log('[label_ingredients] fetch ' . $item_id . ': ' . $e->getMessage());
    }
    if (!$rows) $order_set = true;   // nothing linked: the label prints the product name
    return ['rows' => $rows, 'order_set' => $order_set];
}

/** Every ingredient, for the picker (with its label details). */
function bcrf_all_ingredients(PDO $pdo): array {
    bcrf_label_ing_schema($pdo);
    try {
        return array_map(
            fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['singular_name'],
                       'allergens' => $r['allergens'] === null ? null : (string)$r['allergens'],
                       'sub_ingredients' => trim((string)$r['sub_ingredients'])],
            $pdo->query("SELECT id, singular_name, allergens, COALESCE(sub_ingredients, '') AS sub_ingredients FROM ingredients ORDER BY singular_name ASC")->fetchAll(PDO::FETCH_ASSOC)
        );
    } catch (Throwable $e) {
        error_log('[label_ingredients] all: ' . $e->getMessage());
        return [];
    }
}

/** The nine major food allergens (FALCPA + FASTER Act). key => [label, needs the specific food named]. */
function bcrf_allergen_list(): array {
    return [
        'milk'      => ['Milk', false],
        'eggs'      => ['Eggs', false],
        'fish'      => ['Fish', true],        // name the species: "Cod"
        'shellfish' => ['Crustacean shellfish', true],   // name the species: "Shrimp"
        'tree_nuts' => ['Tree nuts', true],   // name the nut: "Pecans"
        'peanuts'   => ['Peanuts', false],
        'wheat'     => ['Wheat', false],
        'soy'       => ['Soy', false],
        'sesame'    => ['Sesame', false],
    ];
}

/** "milk,tree_nuts:pecans" -> ['milk' => '', 'tree_nuts' => 'pecans']. Unknown keys dropped. */
function bcrf_allergens_parse(?string $raw): array {
    $out = [];
    $known = bcrf_allergen_list();
    foreach (explode(',', (string)$raw) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        [$k, $d] = array_pad(explode(':', $part, 2), 2, '');
        $k = strtolower(trim(str_replace([' ', '-'], '_', $k)));
        if (isset($known[$k])) $out[$k] = trim($d);
    }
    return $out;
}

/** ['milk' => '', 'tree_nuts' => 'pecans'] -> "milk,tree_nuts:pecans" (commas/colons removed from details). */
function bcrf_allergens_encode(array $a): string {
    $parts = [];
    foreach (bcrf_allergen_list() as $k => $_) {
        if (!array_key_exists($k, $a)) continue;
        $d = trim(str_replace([',', ':'], ' ', (string)$a[$k]));
        $parts[] = $d !== '' ? $k . ':' . mb_substr($d, 0, 60) : $k;
    }
    return implode(',', $parts);
}

/**
 * Everything the label prints from the ingredient rows (in label order):
 *   'ingredients' => "Flour (wheat flour, niacin), Sugar, Butter (cream, salt)"
 *   'contains'    => "Milk, Wheat, Pecans"   ('' when none)
 *   'unchecked'   => names of ingredients whose allergens were never checked
 *   'needs_name'  => names of ingredients marked tree nuts/fish/shellfish without the specific food
 */
function bcrf_label_statement(array $rows): array {
    $list = bcrf_allergen_list();
    $parts = []; $found = []; $unchecked = []; $needs = [];
    foreach ($rows as $r) {
        $sub = trim((string)($r['sub_ingredients'] ?? ''));
        $sub = preg_replace('/^\s*ingredients\s*:\s*/i', '', $sub);   // pasted with its "Ingredients:" heading
        $sub = rtrim(preg_replace('/\s+/', ' ', $sub), " .");
        $parts[] = $r['name'] . ($sub !== '' ? ' (' . $sub . ')' : '');
        if (($r['allergens'] ?? null) === null) { $unchecked[] = $r['name']; continue; }
        foreach (bcrf_allergens_parse($r['allergens']) as $k => $d) {
            if ($list[$k][1]) {
                if ($d === '') { $needs[] = $r['name']; $found[$k . ':'] = $list[$k][0]; }
                else foreach (preg_split('/\s*(?:&|\/|\band\b)\s*/i', $d) as $one) {
                    $one = trim($one);
                    if ($one !== '') $found[$k . ':' . mb_strtolower($one)] = mb_convert_case($one, MB_CASE_TITLE);
                }
            } else {
                $found[$k] = $list[$k][0];
            }
        }
    }
    // Keep the standard order (milk, eggs, fish, ...) so labels read the same way every time.
    $order = array_flip(array_keys($list));
    uksort($found, fn($a, $b) => ($order[strtok($a, ':')] <=> $order[strtok($b, ':')]) ?: strcmp($a, $b));
    return [
        'ingredients' => implode(', ', $parts),
        'contains'    => implode(', ', array_values($found)),
        'unchecked'   => $unchecked,
        'needs_name'  => array_values(array_unique($needs)),
    ];
}

/**
 * Net quantity in US units plus the metric equivalent, as NC home processor labels
 * require: "Net Wt. 8 oz (227 g)", "Net 16 fl oz (473 mL)". Metric sizes get the US
 * equivalent. Units with no conversion (count, jar) print as entered.
 */
function bcrf_label_net_qty($value, string $unit): string {
    $v = (float)$value;
    $u = strtolower(trim(str_replace('.', '', $unit)));
    if ($v <= 0) return '';
    $num = fn(float $n, int $dp) => rtrim(rtrim(number_format($n, $dp, '.', ''), '0'), '.');
    $mass = ['oz' => 28.349523, 'ounce' => 28.349523, 'ounces' => 28.349523,
             'lb' => 453.59237, 'lbs' => 453.59237, 'pound' => 453.59237, 'pounds' => 453.59237];
    $vol  = ['fl oz' => 29.573530, 'floz' => 29.573530, 'fluid ounce' => 29.573530, 'fluid ounces' => 29.573530,
             'pint' => 473.176473, 'pints' => 473.176473, 'pt' => 473.176473,
             'quart' => 946.352946, 'quarts' => 946.352946, 'qt' => 946.352946,
             'gallon' => 3785.411784, 'gallons' => 3785.411784, 'gal' => 3785.411784,
             'cup' => 236.588237, 'cups' => 236.588237];
    if (isset($mass[$u])) return 'Net Wt. ' . $num($v, 2) . ' ' . $unit . ' (' . $num(round($v * $mass[$u]), 0) . ' g)';
    if (isset($vol[$u]))  return 'Net ' . $num($v, 2) . ' ' . $unit . ' (' . $num(round($v * $vol[$u]), 0) . ' mL)';
    $metric_mass = ['g' => 1, 'gram' => 1, 'grams' => 1, 'kg' => 1000, 'kilogram' => 1000, 'kilograms' => 1000];
    $metric_vol  = ['ml' => 1, 'milliliter' => 1, 'milliliters' => 1, 'l' => 1000, 'liter' => 1000, 'liters' => 1000];
    if (isset($metric_mass[$u])) return 'Net Wt. ' . $num($v * $metric_mass[$u] / 28.349523, 2) . ' oz (' . $num($v, 2) . ' ' . $unit . ')';
    if (isset($metric_vol[$u]))  return 'Net ' . $num($v * $metric_vol[$u] / 29.573530, 2) . ' fl oz (' . $num($v, 2) . ' ' . $unit . ')';
    return trim($num($v, 2) . ' ' . $unit);
}

/** "Made by: Bat Cave Road Farm, 4132 Bat Cave Road, Old Fort, NC 28762" from Site Info. */
function bcrf_label_made_by(): string {
    $f = dirname(__DIR__, 2) . '/includes/site_info.php';
    if (is_file($f)) require_once $f;
    $name = function_exists('site') ? site('business_name') : '';
    $addr = function_exists('site_address') ? site_address() : '';
    $line = trim($name . ($name && $addr ? ', ' : '') . $addr);
    return $line !== '' ? 'Made by: ' . $line : '';
}

}
