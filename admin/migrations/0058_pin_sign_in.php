<?php
// admin/migrations/0058_pin_sign_in.php — Farm OS PIN sign-in on shared devices and manager approval by PIN (spec 03; Nov 13, 2026).
//   users.pin_hash / pin_set_at / pin_failed / pin_locked_until   a staff member's 4–8 digit PIN (hashed) and its lockout
//   staff_devices   tablets an Owner set up for PIN sign-in (the device keeps a long random cookie; only its hash is stored)
// Logic: includes/pin_auth.php. Only adds what is missing; safe to repeat.
return function (PDO $pdo): void {
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $adds = [
        ['pin_hash', "VARCHAR(255) NULL DEFAULT NULL COMMENT 'PIN for shared devices and manager approval (password_hash)'"],
        ['pin_set_at', "DATETIME NULL DEFAULT NULL"],
        ['pin_failed', "TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Wrong PINs in a row'"],
        ['pin_locked_until', "DATETIME NULL DEFAULT NULL COMMENT 'UTC; PIN refused until then'"],
    ];
    foreach ($adds as [$c, $def]) if (!$has('users', $c)) $pdo->exec("ALTER TABLE `users` ADD COLUMN `$c` $def");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_devices (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NULL,
        revoked_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_staff_devices_token (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
