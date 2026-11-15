<?php
// admin/migrations/0071_feed_units.php — Feed in pounds and scoops (Nov 15, 2026; includes/animal_care.php, admin/animal_care.php).
//   animal_feed_measures   named scoops and cans with what a full one weighs ("2 pint scoop" = 4 lb). product_id NULL = for
//                          every feed. Removing one only sets active = 0. Starts with Zac's two scoops: 6 fl oz = 4 oz, 2 pint = 4 lb.
//   animal_care_logs       feed_entered_qty + feed_entered_unit (what was typed: 2 and "2 pint scoop") and feed_lb (its weight).
//                          feed_qty stays the share of the stock unit (a bag) that left stock, so stock and cost don't change.
//   inventory.stock_quantity  DECIMAL(10,2) -> DECIMAL(12,3), the same as inventory_levels and the stock ledger, so small
//                          amounts (1 lb of a 40 lb bag = 0.025 bag) don't drift.
//   inventory size         feed products with no Size get one from their name ("... 50 lb. Bag" -> 50 lb), the bag weight.
// Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_feed_measures (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id   INT NULL COMMENT 'inventory.id; NULL = offered for every feed',
        name         VARCHAR(40) NOT NULL COMMENT 'e.g. 2 pint scoop',
        weight       DECIMAL(10,3) NOT NULL COMMENT 'what a full one weighs',
        weight_unit  VARCHAR(4) NOT NULL DEFAULT 'lb' COMMENT 'lb or oz',
        active       TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = removed (past feedings keep what was typed)',
        sort_order   SMALLINT NOT NULL DEFAULT 0,
        seed_key     VARCHAR(30) NULL,
        created_at   DATETIME NOT NULL COMMENT 'UTC',
        UNIQUE KEY uq_seed (seed_key),
        KEY idx_product (product_id, active)
    ) $cs");
    $seed = $pdo->prepare("INSERT IGNORE INTO animal_feed_measures (product_id, name, weight, weight_unit, sort_order, seed_key, created_at) VALUES (NULL, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $seed->execute(['6 fl oz scoop', 4, 'oz', 10, 'scoop_6floz']);
    $seed->execute(['2 pint scoop', 4, 'lb', 20, 'scoop_2pint']);

    $adds = [
        'feed_entered_qty'  => "DECIMAL(12,3) NULL COMMENT 'amount as typed, in feed_entered_unit' AFTER feed_qty",
        'feed_entered_unit' => "VARCHAR(40) NULL COMMENT 'lb, oz, bag or a measure name' AFTER feed_entered_qty",
        'feed_lb'           => "DECIMAL(12,3) NULL COMMENT 'weight fed, in lb' AFTER feed_entered_unit",
    ];
    if ($col('animal_care_logs', 'feed_qty')) {
        foreach ($adds as $c => $def) if (!$col('animal_care_logs', $c)) $pdo->exec("ALTER TABLE animal_care_logs ADD COLUMN `$c` $def");
    }

    $st = $pdo->query("SELECT NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'stock_quantity'");
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if ($c && ((int)$c['NUMERIC_SCALE'] < 3 || (int)$c['NUMERIC_PRECISION'] < 12)) {
        $pdo->exec("ALTER TABLE inventory MODIFY stock_quantity DECIMAL(12,3) NOT NULL DEFAULT 0");
    }

    // Bag weight from the name for feed products with no Size yet.
    $ids = array_filter(array_map('intval', explode(',', (string)$pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'animal_feed_products'")->fetchColumn())));
    if ($ids && $col('inventory', 'size_value') && $col('inventory', 'size_unit_id')) {
        $in = implode(',', $ids);
        $rows = $pdo->query("SELECT id, name, unit FROM inventory WHERE id IN ($in) AND (size_value IS NULL OR size_value <= 0)")->fetchAll(PDO::FETCH_ASSOC);
        $lbId = null;
        foreach ($rows as $r) {
            if (in_array(strtolower((string)$r['unit']), ['lb', 'oz'], true)) continue;
            if (!preg_match('/(\d+(?:\.\d+)?)\s*(?:lb|lbs|pound|pounds)\b/i', (string)$r['name'], $m)) continue;
            if ($lbId === null) {
                $lbId = $pdo->query("SELECT id FROM measurement_units WHERE LOWER(abbreviation) IN ('lb','lbs') OR LOWER(name) IN ('pound','pounds','lb','lbs') ORDER BY id LIMIT 1")->fetchColumn();
                if (!$lbId) { $pdo->exec("INSERT INTO measurement_units (name, system_type, abbreviation, unit_type) VALUES ('Pound', 'US', 'lb', 'Weight')"); $lbId = $pdo->lastInsertId(); }
                $lbId = (int)$lbId;
            }
            $pdo->prepare("UPDATE inventory SET size_value = ?, size_unit_id = ? WHERE id = ?")->execute([(float)$m[1], $lbId, (int)$r['id']]);
        }
    }
};
