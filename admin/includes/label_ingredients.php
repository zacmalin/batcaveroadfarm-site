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
// Sulfites (Nov 13, 2026) are stored in the same column under the key "sulfites" but are NOT one of the nine:
// 21 CFR 101.100 makes a label declare them at 10 ppm or more. They print as their own sentence,
// "Contains sulfites.", after the Contains line, never inside it. bcrf_declarable_list() holds them.
//
// Also builds the other NCDA&CS Home Processor label lines: net quantity in
// US units with the metric equivalent, and the maker's name and physical address
// (from Site Info; a website doesn't count).
//
// Used by admin/print_label.php, admin/handlers/get_label_data.php and
// admin/handlers/label_ingredients_save.php.
// The columns this uses come from migrations (0057, Nov 13, 2026).

if (!function_exists('bcrf_label_ing_schema')) {

/** product_ingredients.sort_order and ingredients.allergens / sub_ingredients come from migrations 0001 / 0057;
 *  kept as a no-op for callers (Nov 13, 2026). */
function bcrf_label_ing_schema(PDO $pdo): void {
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

/** Things a label must declare that aren't major allergens (21 CFR 101.100: sulfites at 10 ppm or more).
 *  key => label. Stored in ingredients.allergens next to the nine, printed on their own. */
function bcrf_declarable_list(): array {
    return ['sulfites' => 'Sulfites'];
}

/** "milk,tree_nuts:pecans,sulfites" -> ['milk' => '', 'tree_nuts' => 'pecans', 'sulfites' => '']. Unknown keys dropped.
 *  Sulfites come back too; use bcrf_allergens_only() for just the nine. */
function bcrf_allergens_parse(?string $raw): array {
    $out = [];
    $known = bcrf_allergen_list() + array_fill_keys(array_keys(bcrf_declarable_list()), true);
    foreach (explode(',', (string)$raw) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        [$k, $d] = array_pad(explode(':', $part, 2), 2, '');
        $k = strtolower(trim(str_replace([' ', '-'], '_', $k)));
        if (isset($known[$k])) $out[$k] = isset(bcrf_declarable_list()[$k]) ? '' : trim($d);
    }
    return $out;
}

/** A parsed map without sulfites (just the nine major allergens). */
function bcrf_allergens_only(array $parsed): array {
    return array_diff_key($parsed, bcrf_declarable_list());
}

/** True when the raw allergens string marks sulfites. */
function bcrf_allergens_has_sulfites(?string $raw): bool {
    return array_key_exists('sulfites', bcrf_allergens_parse($raw));
}

/** ['milk' => '', 'tree_nuts' => 'pecans', 'sulfites' => ''] -> "milk,tree_nuts:pecans,sulfites" (commas/colons removed from details). */
function bcrf_allergens_encode(array $a): string {
    $parts = [];
    foreach (bcrf_allergen_list() as $k => $_) {
        if (!array_key_exists($k, $a)) continue;
        $d = trim(str_replace([',', ':'], ' ', (string)$a[$k]));
        $parts[] = $d !== '' ? $k . ':' . mb_substr($d, 0, 60) : $k;
    }
    foreach (bcrf_declarable_list() as $k => $_) if (array_key_exists($k, $a)) $parts[] = $k;
    return implode(',', $parts);
}

/**
 * Common sulfite sources, for a hint on the ingredient form (never ticks the box by itself).
 * Returns what matched ("vinegar", "dried fruit", "sodium metabisulfite" ...) or '' when nothing did.
 * A sulfiting agent named in the bought product's own ingredient list is the strongest sign, so it's checked first.
 */
function bcrf_sulfite_hint(string $name, string $labelName = '', string $sub = ''): string {
    $sub = mb_strtolower($sub);
    if (preg_match('/\b((?:sodium |potassium )?(?:meta)?bisulfite|(?:sodium |potassium )?(?:meta)?bisulphite|sodium sulfite|sodium sulphite|sulfur dioxide|sulphur dioxide|sulfites?|sulphites?)\b/u', $sub, $m)) return $m[1];
    $txt = mb_strtolower($name . ' ' . $labelName . ' ' . $sub);
    $sources = [
        'wine'               => '/\bwines?\b|\bsherry\b|\bmarsala\b|\bvermouth\b|\bport\b/u',
        'vinegar'            => '/vinegar/u',
        'bottled lemon or lime juice' => '/\b(lemon|lime) juice\b|\breal(lemon|lime)\b/u',
        'molasses'           => '/molasses/u',
        'dried fruit'        => '/\b(raisins?|sultanas?|currants?|craisins?)\b|\bdried (apricots?|cranberr(y|ies)|cherr(y|ies)|figs?|dates?|mango(es)?|peach(es)?|pears?|apples?|pineapple|papaya|fruit|blueberr(y|ies))\b|\b(apricots?|cranberr(y|ies)|cherr(y|ies)|mango(es)?|figs?),? dried\b/u',
        'maraschino cherries'=> '/maraschino/u',
        'dried potato'       => '/potato flakes|instant potato|dehydrated potato/u',
        'white grape juice'  => '/white grape juice/u',
        'pickled peppers or relish' => '/pepperoncini|banana peppers|\brelish\b/u',
    ];
    foreach ($sources as $what => $re) if (preg_match($re, $txt)) return $what;
    return '';
}

/**
 * Everything the label prints from the ingredient rows (in label order):
 *   'ingredients' => "Flour (wheat flour, niacin), Sugar, Butter (cream, salt)"
 *   'contains'    => "Milk, Wheat, Pecans"   ('' when none)
 *   'unchecked'   => names of ingredients whose allergens were never checked
 *   'needs_name'  => names of ingredients marked tree nuts/fish/shellfish without the specific food
 *   'sulfites'    => true when any ingredient is marked sulfites (prints "Contains sulfites." after the Contains line)
 */
function bcrf_label_statement(array $rows): array {
    $list = bcrf_allergen_list();
    $parts = []; $found = []; $unchecked = []; $needs = []; $sulfites = false;
    foreach ($rows as $r) {
        $sub = trim((string)($r['sub_ingredients'] ?? ''));
        $sub = preg_replace('/^\s*ingredients\s*:\s*/i', '', $sub);   // pasted with its "Ingredients:" heading
        $sub = rtrim(preg_replace('/\s+/', ' ', $sub), " .");
        $parts[] = $r['name'] . ($sub !== '' ? ' (' . $sub . ')' : '');
        if (($r['allergens'] ?? null) === null) { $unchecked[] = $r['name']; continue; }
        $parsed = bcrf_allergens_parse($r['allergens']);
        if (array_key_exists('sulfites', $parsed)) $sulfites = true;
        foreach (bcrf_allergens_only($parsed) as $k => $d) {
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
        'sulfites'    => $sulfites,
    ];
}

/** The sentence printed after the Contains line when sulfites are declared. */
function bcrf_label_sulfites_text(): string {
    return 'Contains sulfites.';
}

/** The whole bold allergen line: "Contains: Milk, Wheat. Contains sulfites." Sulfites sit next to the
 *  Contains statement, never inside it. '' when there's nothing to declare. */
function bcrf_label_contains_text(string $contains, bool $sulfites): string {
    $c = rtrim(trim($contains), '.');
    $out = $c !== '' ? 'Contains: ' . $c . '.' : '';
    if ($sulfites) $out .= ($out !== '' ? ' ' : '') . bcrf_label_sulfites_text();
    return $out;
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
