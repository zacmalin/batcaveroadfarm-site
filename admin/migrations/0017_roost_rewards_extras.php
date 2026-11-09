<?php
// admin/migrations/0017_roost_rewards_extras.php — Roost Rewards extras (Nov 9, 2026)
//   inventory.rewards_eligible            per-product override: NULL = by product type, 1 = always earns, 0 = never earns
//   reward_members.reminder_for / _stage  which quiet spell the 30-day / 7-day expiry reminders were sent for
// Additive and safe to repeat.
return function (PDO $pdo): void {
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$has('inventory', 'rewards_eligible')) {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN rewards_eligible TINYINT(1) NULL DEFAULT NULL COMMENT 'Roost Rewards: NULL = by product type, 1 = always earns, 0 = never earns'");
    }
    if (!$has('reward_members', 'reminder_for')) {
        $pdo->exec("ALTER TABLE reward_members ADD COLUMN reminder_for DATETIME NULL COMMENT 'activity time the expiry reminders below belong to',
                                               ADD COLUMN reminder_stage TINYINT NOT NULL DEFAULT 0 COMMENT '0 none, 1 = 30-day sent, 2 = 7-day sent'");
    }
};
