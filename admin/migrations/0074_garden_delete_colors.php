<?php
// admin/migrations/0074_garden_delete_colors.php — Garden: bed colors and removing starter crops (Nov 19, 2026; includes/garden_delete.php,
//   admin/garden/areas.php, admin/garden/crops.php).
//   garden_growing_locations.color                    a color picked for the bed (#rrggbb), shown on the map; NULL = none
//   garden_organization_variety_preferences.hidden_at  a starter-list variety this farm took off its list (UTC); NULL = shown
// Safe to repeat.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$col('garden_growing_locations', 'color')) $pdo->exec("ALTER TABLE garden_growing_locations ADD COLUMN color CHAR(7) NULL COMMENT 'map color, #rrggbb' AFTER notes");
    if (!$col('garden_organization_variety_preferences', 'hidden_at')) $pdo->exec("ALTER TABLE garden_organization_variety_preferences ADD COLUMN hidden_at DATETIME NULL COMMENT 'UTC; taken off this farm''s list' AFTER notes");
};
