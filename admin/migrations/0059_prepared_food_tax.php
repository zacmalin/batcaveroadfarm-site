<?php
// admin/migrations/0059_prepared_food_tax.php — prepared food tax category (Q-09, Nov 13, 2026)
//
// Notion card "Confirm the register's sales tax rates (Q-09)", decisions + revised mapping of Nov 13, 2026:
// food the farm makes by mixing two or more ingredients (our bread, muffins, pastries, jams, jelly, pickles,
// canned goods) is prepared food at the full 6.75% (4.75% state + 2% McDowell). Our bakery fails the
// 80% artisan-bakery test (G.S. 105-164.13B(a)(4)), so it is not 2% food.
//
//   tax_categories  + food_prepared 'Prepared food (made here)' at 675 bp
//   inventory       our own made goods move off 2% food onto it:
//                     Bread (BCF-004), Pickles (BCF-005), and anything linked to a Kitchen product
//
// Only moves a product still on 'food_unprepared' (2%): a category set by hand to something else is left alone.
// Raw produce stays at 2% until NCDOR answers the 13(4b) producer question. Safe to repeat.
return function (PDO $pdo): void {
    $tbl = function (string $t) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$t]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$tbl('tax_categories')) return;   // 0009 has not run yet

    $pdo->exec("INSERT IGNORE INTO tax_categories (code, name, rate_bp, notes, sort_order, created_at) VALUES
        ('food_prepared', 'Prepared food (made here)', 675,
         'Our own bread, muffins, pastries, jams, jelly, pickles, canned goods: 4.75% state + 2% McDowell (Q-09, 2026-11-13)', 2, UTC_TIMESTAMP())");
    $pdo->exec("UPDATE tax_categories SET sort_order = 3 WHERE code = 'food_unprepared' AND sort_order = 2");

    $prep = (int)$pdo->query("SELECT id FROM tax_categories WHERE code = 'food_prepared'")->fetchColumn();
    $food = (int)$pdo->query("SELECT id FROM tax_categories WHERE code = 'food_unprepared'")->fetchColumn();
    if (!$prep || !$food) return;

    $st = $pdo->prepare("UPDATE inventory SET tax_category_id = ? WHERE tax_category_id = ? AND sku IN ('BCF-004', 'BCF-005')");
    $st->execute([$prep, $food]);
    if ($tbl('kitchen_products')) {
        $st = $pdo->prepare("UPDATE inventory i JOIN kitchen_products kp ON kp.inventory_id = i.id
                                SET i.tax_category_id = ? WHERE i.tax_category_id = ?");
        $st->execute([$prep, $food]);
    }
};
