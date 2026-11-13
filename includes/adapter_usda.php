<?php
// includes/adapter_usda.php — USDA FoodData Central (Farm OS Phase 0, Nov 5, 2026).
// Key in the tenant config (bcrf-secrets.php: USDA_API_KEY), read by admin/includes/nutrition_config.php;
// without it USDA's public DEMO_KEY is used (low rate limit).
require_once __DIR__ . '/adapter_http.php';

if (!function_exists('bcrf_usda_search')) {

function bcrf_usda_key(): string {
    if (!defined('USDA_API_KEY')) @include_once dirname(__DIR__) . '/admin/includes/nutrition_config.php';
    return defined('USDA_API_KEY') ? (string)USDA_API_KEY : 'DEMO_KEY';
}

/**
 * Search foods. Foundation and SR Legacy are the most reliable data types for cooking ingredients.
 * Returns ['ok' => bool, 'status' => int, 'foods' => array, 'error' => string].
 */
function bcrf_usda_search(string $query, int $pageSize = 5, string $dataTypes = 'Foundation,SR Legacy'): array {
    $r = bcrf_http('GET', 'https://api.nal.usda.gov/fdc/v1/foods/search', [
        'query'   => ['query' => $query, 'api_key' => bcrf_usda_key(), 'dataType' => $dataTypes, 'pageSize' => $pageSize],
        'timeout' => 15,
    ]);
    if ($r['status'] !== 200 || !is_array($r['json'])) {
        return ['ok' => false, 'status' => $r['status'], 'foods' => [], 'error' => $r['error'] ?: ('HTTP ' . $r['status']), 'raw' => $r['body']];
    }
    return ['ok' => true, 'status' => 200, 'foods' => (array)($r['json']['foods'] ?? []), 'error' => '', 'raw' => $r['body']];
}

/**
 * One food in full (nutrients + portion gram weights), by FDC id. Nov 13, 2026: one-step ingredient lookup.
 * Returns ['ok' => bool, 'status' => int, 'food' => array, 'error' => string].
 */
function bcrf_usda_food(int $fdcId): array {
    $r = bcrf_http('GET', 'https://api.nal.usda.gov/fdc/v1/food/' . $fdcId, [
        'query'   => ['api_key' => bcrf_usda_key()],
        'timeout' => 15,
    ]);
    if ($r['status'] !== 200 || !is_array($r['json'])) {
        return ['ok' => false, 'status' => $r['status'], 'food' => [], 'error' => $r['error'] ?: ('HTTP ' . $r['status'])];
    }
    return ['ok' => true, 'status' => 200, 'food' => $r['json'], 'error' => ''];
}

/**
 * Per-100 g nutrients from a search hit or a full food record, keyed by our ingredients columns.
 * Foundation foods often carry energy as 2047/2048 (Atwater) instead of 1008, and sugars as 1063.
 */
function bcrf_usda_nutrients(array $food): array {
    $byId = [];
    foreach ((array)($food['foodNutrients'] ?? []) as $n) {
        $id  = (int)($n['nutrientId'] ?? ($n['nutrient']['id'] ?? 0));
        $val = $n['value'] ?? ($n['amount'] ?? null);
        if ($id && $val !== null && !isset($byId[$id])) $byId[$id] = (float)$val;
    }
    $pick = function (array $ids) use ($byId) { foreach ($ids as $i) if (isset($byId[$i])) return round($byId[$i], 2); return null; };
    return [
        'calories_per_100g' => $pick([1008, 2047, 2048]),
        'protein_per_100g'  => $pick([1003]),
        'fat_per_100g'      => $pick([1004, 1085]),
        'carbs_per_100g'    => $pick([1005, 1050]),
        'fiber_per_100g'    => $pick([1079]),
        'sugar_per_100g'    => $pick([2000, 1063]),
        'sodium_per_100g'   => $pick([1093]),
    ];
}

/** mL in one of a volume unit named in a portion's text, or null when it isn't a volume. */
function bcrf_usda_volume_ml(string $text): ?float {
    $t = strtolower($text);
    $units = [
        '/\bfl\.?\s*oz\b|\bfluid ounces?\b/' => 29.5735,
        '/\bcups?\b/'                          => 236.588,
        '/\btbsps?\b|\btablespoons?\b|\btbs\b/' => 14.7868,
        '/\btsps?\b|\bteaspoons?\b/'          => 4.92892,
        '/\bml\b|\bmillilit(er|re)s?\b/'      => 1.0,
        '/\bpints?\b/'                         => 473.176,
        '/\bquarts?\b|\bqts?\b/'              => 946.353,
        '/\bgallons?\b/'                       => 3785.41,
        '/\blit(er|re)s?\b/'                   => 1000.0,
    ];
    foreach ($units as $re => $ml) if (preg_match($re, $t)) return $ml;
    return null;
}

/**
 * Turn a food's portions into rows the editor can use, and pick a density (g/mL) and a weight of one.
 * Handles both SR Legacy (unit in "modifier") and Foundation (unit in measureUnit) shapes.
 * Returns ['portions' => [[label, grams, kind volume|count|weight, density?, each?]], 'density' => ?float,
 *          'density_from' => string, 'weight' => ?float, 'weight_from' => string].
 */
function bcrf_usda_portions(array $food): array {
    $rows = [];
    foreach ((array)($food['foodPortions'] ?? []) as $p) {
        $g = (float)($p['gramWeight'] ?? 0);
        if ($g <= 0) continue;
        $amt  = (float)($p['amount'] ?? 0) ?: (float)($p['value'] ?? 0) ?: 1.0;
        $unit = trim((string)($p['measureUnit']['name'] ?? ''));
        if (in_array(strtolower($unit), ['undetermined', 'unknown'], true)) $unit = '';
        $mod  = trim((string)($p['modifier'] ?? ''));
        $desc = trim((string)($p['portionDescription'] ?? ''));
        if (strtolower($desc) === 'quantity not specified') continue;
        $text = trim($unit . ' ' . $mod);
        if ($text === '' || is_numeric($mod)) $text = trim($unit . ' ' . $desc);
        if ($text === '') continue;
        $amtTxt = rtrim(rtrim(number_format($amt, 2, '.', ''), '0'), '.');
        $label = preg_match('/^\d/', $text) ? $text : $amtTxt . ' ' . $text;
        $row = ['label' => $label, 'grams' => round($g, 1), 'kind' => 'count'];
        $ml = bcrf_usda_volume_ml($text);
        if ($ml !== null) {
            $row['kind'] = 'volume';
            $row['density'] = round($g / ($amt * $ml), 4);
        } elseif (preg_match('/\b(oz|ounces?|lbs?|pounds?|g|grams?|kg)\b/i', $text)) {
            $row['kind'] = 'weight';
        } else {
            $row['each'] = round($g / $amt, 1);
        }
        $rows[] = $row;
    }

    // Density: a cup is the most reliable measure, then tablespoon, teaspoon, anything else by volume.
    $density = null; $dFrom = '';
    foreach (['/\bcups?\b/i', '/tbsp|tablespoon/i', '/tsp|teaspoon/i', '/./'] as $re) {
        foreach ($rows as $r) {
            if ($r['kind'] === 'volume' && preg_match($re, $r['label'])) { $density = $r['density']; $dFrom = $r['label'] . ' = ' . $r['grams'] . ' g'; break 2; }
        }
    }
    // Weight of one: "medium" (eggs: "large", which is what recipes mean), then whole/each/large/clove…, else the first count portion.
    $isEgg = (bool)preg_match('/\beggs?\b/i', (string)($food['description'] ?? ''));
    $prefs = $isEgg ? ['large', 'medium'] : ['medium', 'whole', 'each', 'large', 'clove', 'small'];
    $prefs[] = '';
    $weight = null; $wFrom = '';
    foreach ($prefs as $w) {
        foreach ($rows as $r) {
            if ($r['kind'] === 'count' && ($w === '' || stripos($r['label'], $w) !== false) && stripos($r['label'], 'serving') === false) {
                $weight = $r['each']; $wFrom = $r['label'] . ' = ' . $r['grams'] . ' g'; break 2;
            }
        }
    }
    return ['portions' => $rows, 'density' => $density, 'density_from' => $dFrom, 'weight' => $weight, 'weight_from' => $wFrom];
}

}
