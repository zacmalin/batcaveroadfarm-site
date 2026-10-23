<?php
// admin/includes/nutrition_config.php
// USDA FoodData Central API key (free: https://fdc.nal.usda.gov/api-guide.html).
// The key lives in bcrf-secrets.php as 'USDA_API_KEY' (loaded by config.php into $bcrf),
// never in this file. Falls back to USDA's public DEMO_KEY (low rate limit) if not set.

if (!defined('USDA_API_KEY')) {
    define('USDA_API_KEY', getenv('USDA_API_KEY') ?: (($bcrf['USDA_API_KEY'] ?? ($GLOBALS['bcrf']['USDA_API_KEY'] ?? '')) ?: 'DEMO_KEY'));
}
