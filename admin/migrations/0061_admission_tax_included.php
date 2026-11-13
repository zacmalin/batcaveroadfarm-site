<?php
// admin/migrations/0061_admission_tax_included.php — gate admission priced tax-included, like online (Q-09, Nov 13, 2026)
//
// Zac chose tax-included prices for online tickets and passes (0060), then asked for the gate to match: an $8 adult
// admission rung at the register now totals $8.00 ($7.49 + $0.51 tax) instead of $8.54.
//   tax_categories  + price_includes_tax (1 = the shelf price already holds the tax; the register backs it out)
//                   + admission 'Admission (tax included)' at 675 bp, price_includes_tax = 1
//   inventory       products in the Admissions category still on the general rate move onto it
// A category set by hand to something else is left alone. Safe to repeat.
return function (PDO $pdo): void {
    $one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
    if (!(int)$one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tax_categories'")) return;
    if (!(int)$one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tax_categories' AND column_name = 'price_includes_tax'")) {
        $pdo->exec("ALTER TABLE tax_categories ADD COLUMN price_includes_tax TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = shelf price includes the tax' AFTER rate_bp");
    }
    $pdo->exec("INSERT IGNORE INTO tax_categories (code, name, rate_bp, price_includes_tax, notes, sort_order, created_at) VALUES
        ('admission', 'Admission (tax included)', 675, 1,
         'Admission, tax inside the posted price: 4.75% state + 2% McDowell, G.S. 105-164.4G (Q-09, 2026-11-13)', 4, UTC_TIMESTAMP())");

    $adm = (int)$one("SELECT id FROM tax_categories WHERE code = 'admission'");
    $gen = (int)$one("SELECT id FROM tax_categories WHERE code = 'general'");
    if (!$adm || !$gen) return;
    if ((int)$one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inventory_categories'")) {
        $pdo->prepare("UPDATE inventory i JOIN inventory_categories ic ON ic.id = i.category_id
                          SET i.tax_category_id = ? WHERE ic.slug = 'admissions' AND (i.tax_category_id = ? OR i.tax_category_id IS NULL)")
            ->execute([$adm, $gen]);
    }
};
