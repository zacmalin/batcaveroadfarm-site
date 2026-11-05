<?php
// includes/adapters/usda.php — USDA FoodData Central (Farm OS Phase 0, Nov 5, 2026).
// Key in the tenant config (bcrf-secrets.php: USDA_API_KEY), read by admin/includes/nutrition_config.php;
// without it USDA's public DEMO_KEY is used (low rate limit).
require_once __DIR__ . '/http.php';

if (!function_exists('bcrf_usda_search')) {

function bcrf_usda_key(): string {
    if (!defined('USDA_API_KEY')) @include_once dirname(__DIR__, 2) . '/admin/includes/nutrition_config.php';
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

}
