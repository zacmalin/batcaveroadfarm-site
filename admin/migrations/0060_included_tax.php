<?php
// admin/migrations/0060_included_tax.php — sales tax inside online ticket and pass prices (Q-09, Nov 13, 2026)
//
// Admission is taxable at the general rate (G.S. 105-164.4G; Notion Q-09 card, Nov 13). Zac chose to keep the
// posted prices and treat the tax as included, so a paid order now records the tax part of what was paid:
//   tickets  + tax_rate_bp, tax_cents   (tax inside amount_cents)
//   passes   + tax_rate_bp, tax_cents   (tax inside amount_paid), paid_at (UTC; when the tax was taken)
// NULL = paid before this was recorded. Logic: includes/included_tax.php. Only adds what is missing; safe to repeat.
return function (PDO $pdo): void {
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $adds = [
        ['tickets', 'tax_rate_bp', "INT UNSIGNED NULL DEFAULT NULL COMMENT 'sales tax rate included in the price, basis points'"],
        ['tickets', 'tax_cents',   "BIGINT NULL DEFAULT NULL COMMENT 'sales tax included in amount_cents'"],
        ['passes',  'tax_rate_bp', "INT UNSIGNED NULL DEFAULT NULL COMMENT 'sales tax rate included in the price, basis points'"],
        ['passes',  'tax_cents',   "BIGINT NULL DEFAULT NULL COMMENT 'sales tax included in amount_paid'"],
        ['passes',  'paid_at',     "DATETIME NULL DEFAULT NULL COMMENT 'UTC'"],
    ];
    foreach ($adds as [$t, $c, $def]) if (!$has($t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
};
