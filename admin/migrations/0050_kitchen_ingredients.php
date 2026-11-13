<?php
// admin/migrations/0050_kitchen_ingredients.php — Farm OS Phase 4 slice 1: ingredient label details (Nov 12, 2026; 09 › Ingredients;
// includes/kitchen.php, admin/kitchen_ingredients.php, admin/kitchen_recipes.php).
//   ingredients.label_name            the name printed on a label when it differs from the recipe name
//                                     ("enriched wheat flour" for "flour"). Empty = use the singular name.
//   ingredients.allergens_checked_at  when someone last saved the allergen ticks (UTC); allergens_checked_by = who.
// allergens and sub_ingredients already exist (0001). Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cols = $pdo->query("SHOW COLUMNS FROM ingredients")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'allergens'            => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Major allergens for labels; NULL = not checked, empty = none'",
        'sub_ingredients'      => "TEXT NULL COMMENT 'Ingredient list printed on the bought product, for labels'",
        'label_name'           => "VARCHAR(150) NULL COMMENT 'Name on food labels; empty = singular_name'",
        'allergens_checked_at' => "DATETIME NULL COMMENT 'UTC; last time the allergen ticks were saved'",
        'allergens_checked_by' => "INT NULL",
    ];
    foreach ($add as $c => $def) {
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE ingredients ADD COLUMN `$c` $def");
    }
};
