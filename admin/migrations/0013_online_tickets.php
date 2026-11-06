<?php
// admin/migrations/0013_online_tickets.php — Farm OS Phase 1, slice 4b: online tickets out of bookings (Nov 6, 2026)
// tickets gets what an online order needs, so the ticket page writes tickets directly (C-04 done):
//   payment_status (pending, paid, waived, refunded), order_json (what was bought, at what price),
//   stripe_session_id, paid_at, confirmation_sent_at, and the GA purchase columns includes/ga.php uses.
// Existing rows: unpaid copies → pending, free → waived, everything else → paid.
// Safe to repeat.
return function (PDO $pdo): void {
    $col = function (string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tickets' AND column_name = ?");
        $st->execute([$c]);
        return (int)$st->fetchColumn() > 0;
    };
    $adds = [
        'payment_status'       => "VARCHAR(12) NOT NULL DEFAULT 'paid' COMMENT 'pending, paid, waived, refunded'",
        'order_json'           => "JSON NULL COMMENT '[{product_id, name, qty, unit_cents}] as bought online'",
        'stripe_session_id'    => "VARCHAR(255) NULL",
        'paid_at'              => "DATETIME NULL COMMENT 'UTC'",
        'confirmation_sent_at' => "DATETIME NULL COMMENT 'UTC'",
        'ga_client_id'         => "VARCHAR(40) NULL",
        'ga_session_id'        => "VARCHAR(20) NULL",
        'ga_purchase_sent'     => "DATETIME NULL",
    ];
    $fresh = !$col('payment_status');
    foreach ($adds as $c => $def) if (!$col($c)) $pdo->exec("ALTER TABLE tickets ADD COLUMN `$c` $def");
    if ($fresh) {
        $pdo->exec("UPDATE tickets SET payment_status = 'pending' WHERE status = 'pending_payment'");
        $pdo->exec("UPDATE tickets SET payment_status = 'waived' WHERE amount_cents = 0 AND status <> 'pending_payment'");
    }
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'tickets' AND index_name = 'idx_stripe'");
    if (!(int)$st->fetchColumn()) $pdo->exec("ALTER TABLE tickets ADD KEY idx_stripe (stripe_session_id)");
};
