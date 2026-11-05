<?php
// admin/migrations/0002_drop_unused_pairs_with_columns.php — Farm OS Phase 0 (Nov 5, 2026)
// recipes.pairs_with_recipe_id and ingredients.pairs_with_recipe_id were replaced by the recipe_pairings
// table. The old schema_safety.php dropped and re-added both columns on every admin page load, so they
// never kept a value; both are hidden in the editor and nothing else reads them.
// Safe to repeat: each column is only dropped if it is still there.
return function (PDO $pdo): void {
    foreach (['recipes', 'ingredients'] as $table) {
        $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'pairs_with_recipe_id'");
        $has->execute([$table]);
        if ((int)$has->fetchColumn() > 0) {
            $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN pairs_with_recipe_id");
        }
    }
};
