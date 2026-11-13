<?php
// admin/migrations/0057_runtime_schema.php — Farm OS Phase 0 cleanup (Nov 13, 2026): the last columns that code used
// to add on first use now come from a migration, and that code no longer changes the database on page loads:
//   bookings / passes: ga_client_id, ga_session_id, ga_purchase_sent (includes/ga.php; tickets got them in 0013)
//   inventory.sku (admin/print_label.php), product_ingredients.sort_order + ingredients.allergens / sub_ingredients
//   (admin/includes/label_ingredients.php), recipes.nutrition_servings (admin/handlers/calculate_nutrition.php),
//   breeds.wiki_title (admin/views/schema.php).
// Tables events, wiki_cache, google_report_cache and mcp_oauth_* already come from 0001. Only adds what is missing;
// nothing is changed or dropped. Safe to repeat.
return function (PDO $pdo): void {
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $table = function (string $t) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$t]);
        return (int)$st->fetchColumn() > 0;
    };
    $adds = [
        ['bookings', 'ga_client_id', "VARCHAR(40) NULL DEFAULT NULL"],
        ['bookings', 'ga_session_id', "VARCHAR(20) NULL DEFAULT NULL"],
        ['bookings', 'ga_purchase_sent', "DATETIME NULL DEFAULT NULL"],
        ['passes', 'ga_client_id', "VARCHAR(40) NULL DEFAULT NULL"],
        ['passes', 'ga_session_id', "VARCHAR(20) NULL DEFAULT NULL"],
        ['passes', 'ga_purchase_sent', "DATETIME NULL DEFAULT NULL"],
        ['inventory', 'sku', "VARCHAR(50) NULL DEFAULT NULL"],
        ['product_ingredients', 'sort_order', "INT NOT NULL DEFAULT 0"],
        ['ingredients', 'allergens', "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Major allergens for labels; NULL = not checked, empty = none'"],
        ['ingredients', 'sub_ingredients', "TEXT NULL COMMENT 'Ingredient list printed on the bought product, for labels'"],
        ['recipes', 'nutrition_servings', "INT NULL DEFAULT NULL COMMENT 'Servings the stored nutrition was divided by'"],
        ['breeds', 'wiki_title', "VARCHAR(200) NULL"],
    ];
    foreach ($adds as [$t, $c, $def]) {
        if ($table($t) && !$has($t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
};
