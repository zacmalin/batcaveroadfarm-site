<?php
// admin/migrations/0023_tax_rate_correction.php — the general sales tax rate (Nov 10, 2026)
//
// 0009 seeded the general rate at 475 bp (4.75%), which is the state rate on its own.
// From July 1, 2026 the combined rate in McDowell County is 6.75%: 4.75% state plus the
// 2% local option. Old Fort adds no city tax. Qualifying food is unchanged at 200 bp,
// because food carries the 2% local rate only and is reported on the "2% Food Rate" line
// of Form E-500.
//
// This corrects the seed so a fresh install starts right, and fixes an install still
// sitting on the untouched 475. It deliberately will not overwrite a rate that has been
// set by hand in Settings > Payment settings > Tax rates.
//
// Safe to repeat: the UPDATE matches only rows still at the seeded 475.
return function (PDO $pdo): void {
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                                 WHERE table_schema = DATABASE() AND table_name = 'tax_categories'")->fetchColumn();
    if ($exists !== 1) return;   // 0009 has not run yet; nothing to correct

    $st = $pdo->prepare("UPDATE tax_categories
                            SET rate_bp = 675,
                                notes = CONCAT_WS(' | ', NULLIF(notes, ''),
                                          '6.75% from 7/1/2026 = 4.75% state + 2% McDowell local (corrected 2026-11-10)'),
                                updated_at = UTC_TIMESTAMP()
                          WHERE code = 'general' AND rate_bp = 475 AND archived_at IS NULL");
    $st->execute();

    // If someone has already set this by hand, leave it alone but say so in the log.
    $st = $pdo->query("SELECT rate_bp FROM tax_categories WHERE code = 'general' LIMIT 1");
    $bp = $st ? $st->fetchColumn() : false;
    if ($bp !== false && $bp !== null && (int)$bp !== 675) {
        error_log('0023_tax_rate_correction: general rate is ' . (int)$bp . ' bp, not 675; left unchanged.');
    }
};
