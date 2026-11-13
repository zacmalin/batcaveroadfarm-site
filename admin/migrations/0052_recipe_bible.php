<?php
// admin/migrations/0052_recipe_bible.php — Farm OS Phase 4: the house Recipe Bible (Nov 13, 2026; includes/kitchen_bible.php,
// admin/kitchen_bible.php). recipes.in_house = 1 puts a recipe in the staff-only Recipe Bible. It is separate from
// recipes.status, which still decides whether the recipe is on the website: a recipe can be on the website, in the
// Bible, or both. Existing recipes start outside the Bible. Adds one column; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    if (!$pdo->query("SHOW COLUMNS FROM recipes LIKE 'in_house'")->fetch()) {
        $pdo->exec("ALTER TABLE recipes ADD COLUMN in_house TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'in the staff-only Recipe Bible (separate from website status)'");
    }
    if (!$pdo->query("SHOW INDEX FROM recipes WHERE Key_name = 'idx_in_house'")->fetch()) {
        $pdo->exec("ALTER TABLE recipes ADD KEY idx_in_house (in_house)");
    }
};
