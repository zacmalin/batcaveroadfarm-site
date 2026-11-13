<?php
// admin/kitchen_usda_check.php — Kitchen › Ingredients › USDA check (Nov 13, 2026).
// Runs every ingredient against USDA FoodData Central in one go: ingredients already matched use their saved
// USDA food, the rest use the best match for their name. Shows what differs (nutrition, grams per mL, weight
// of one) with a tick box per change; "Update ticked" saves them and recalculates the recipes that use them.
// The browser does the rows one at a time (admin/assets/kitchen-usda-check.js) through
// admin/handlers/lookup_ingredient_nutrition.php (search, detail, apply), so no request runs long.
// Permission: table 'ingredients' edit (the USDA handler needs it).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'ingredients', 'edit']]);
require_once __DIR__ . '/../includes/admin_shell.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$rows = [];
try {
    $rows = $pdo->query("
        SELECT i.id, i.singular_name AS name, i.usda_fdc_id AS fdc,
               i.calories_per_100g, i.protein_per_100g, i.fat_per_100g, i.carbs_per_100g,
               i.fiber_per_100g, i.sugar_per_100g, i.sodium_per_100g,
               i.density_g_per_ml AS density, i.weight_per_unit_g AS weight,
               (SELECT COUNT(DISTINCT ri.recipe_id) FROM recipe_ingredients ri WHERE ri.ingredient_id = i.id) AS recipes
        FROM ingredients i
        ORDER BY recipes = 0, i.singular_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[kitchen_usda_check] list: ' . $e->getMessage());
}

bcrf_shell_open('USDA check', ['js' => ['usda-picker.js', 'kitchen-usda-check.js']]);
?>
<h1 class="text-2xl font-bold text-stone-800 mb-1">USDA check</h1>
<p class="text-sm text-stone-600 mb-4">Checks every ingredient against USDA. Ingredients you already matched use that match; the rest use the best match for their name, so look at the match before updating. Nothing changes until you tap Update.</p>

<?php if (!$rows): ?>
    <?= bcrf_shell_notice('info', 'No ingredients yet.') ?>
<?php else: ?>
<script type="application/json" id="ucData"><?= json_encode(['csrf' => $_SESSION['csrf_token'], 'rows' => $rows], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4">
    <div class="flex flex-wrap items-center gap-3">
        <button type="button" id="ucStart" class="px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold">Check <?= count($rows) ?> ingredients</button>
        <label class="text-sm text-stone-700 flex items-center gap-2"><input type="checkbox" id="ucHideOk" checked> Hide ones that match</label>
    </div>
    <p id="ucStatus" class="text-sm text-stone-600 mt-2"></p>
</div>

<div id="ucList" class="space-y-2 mb-24"></div>

<div id="ucBar" class="hidden fixed bottom-0 left-0 right-0 bg-white border-t border-stone-200 p-3 shadow-sm z-50">
    <div class="max-w-5xl mx-auto flex items-center gap-3">
        <button type="button" id="ucApply" class="px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold">Update ticked</button>
        <span id="ucApplyNote" class="text-sm text-stone-600"></span>
    </div>
</div>
<?php endif; ?>
<?php bcrf_shell_close();
