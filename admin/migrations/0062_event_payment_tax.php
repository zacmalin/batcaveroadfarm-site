<?php
// admin/migrations/0062_event_payment_tax.php — sales tax inside booking and party payments (Q-09, Nov 13, 2026)
//
// Group bookings, parties and private events are admission and taxable at the general rate (G.S. 105-164.4G).
// Like tickets, passes and gate admission (0060, 0061), Zac chose tax-included prices: the quote total stays, and
// each payment or refund in the event ledger records the tax inside it.
//   event_payments  + tax_rate_bp, tax_cents   (tax inside amount_cents; refunds negative)
// Payments rung up on the register are left NULL here: the register sale already records their tax.
// NULL = recorded before this. Logic: includes/included_tax.php. Only adds what is missing; safe to repeat.
return function (PDO $pdo): void {
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'event_payments'")->fetchColumn()) return;
    if (!$has('event_payments', 'tax_rate_bp')) $pdo->exec("ALTER TABLE event_payments ADD COLUMN tax_rate_bp INT UNSIGNED NULL DEFAULT NULL COMMENT 'sales tax rate included in amount_cents, basis points'");
    if (!$has('event_payments', 'tax_cents'))   $pdo->exec("ALTER TABLE event_payments ADD COLUMN tax_cents INT NULL DEFAULT NULL COMMENT 'sales tax included in amount_cents (refunds negative)'");
};
