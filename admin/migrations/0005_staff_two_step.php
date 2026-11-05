<?php
// admin/migrations/0005_staff_two_step.php — Farm OS Phase 0 (Nov 5, 2026)
// Two-step sign-in for staff (includes/staff_auth.php, admin/two_step.php):
//   totp_secret_enc    authenticator secret, encrypted (key outside the website)
//   totp_enabled_at    when it was turned on (UTC); NULL = off
//   totp_last_step     last 30-second step used, so a code can't be used twice
//   mfa_recovery_json  password hashes of the unused backup codes
// These move with staff accounts when staff and customer accounts are split (C-02).
// Safe to repeat: each column is only added if it isn't there.
return function (PDO $pdo): void {
    $cols = [
        'totp_secret_enc'   => "VARCHAR(255) NULL",
        'totp_enabled_at'   => "DATETIME NULL COMMENT 'UTC'",
        'totp_last_step'    => "BIGINT NULL",
        'mfa_recovery_json' => "TEXT NULL",
    ];
    $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = ?");
    foreach ($cols as $c => $def) {
        $has->execute([$c]);
        if ((int)$has->fetchColumn() === 0) $pdo->exec("ALTER TABLE users ADD COLUMN `{$c}` {$def}");
    }
};
