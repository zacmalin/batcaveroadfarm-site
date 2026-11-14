<?php
// admin/migrations/0067_record_notes_papers.php — dated notes on records + papers that expire (Nov 14, 2026; includes/record_tools.php,
// includes/documents.php).
//   record_notes            one dated, signed note on a customer, organization, vendor, animal or party. Never edited; the person who
//                           wrote it (same day) or an Owner can hide it, and it can be brought back.
//   purchase_docs.expires_on  the date an insurance certificate, W-9, contract or vet paper runs out (the bell warns 30 days before).
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS record_notes (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        record_type  VARCHAR(20) NOT NULL COMMENT 'customer, organization, vendor, animal, event_inquiry',
        record_id    INT UNSIGNED NOT NULL,
        body         TEXT NOT NULL,
        created_at   DATETIME NOT NULL COMMENT 'UTC',
        created_by   INT NULL,
        deleted_at   DATETIME NULL COMMENT 'UTC; hidden, can be brought back',
        deleted_by   INT NULL,
        KEY idx_record (record_type, record_id, created_at),
        KEY idx_created (created_at)
    ) $cs");
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $tbl = function (string $t) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$t]);
        return (int)$st->fetchColumn() > 0;
    };
    if ($tbl('purchase_docs') && !$col('purchase_docs', 'expires_on')) {
        $pdo->exec("ALTER TABLE purchase_docs ADD COLUMN expires_on DATE NULL COMMENT 'insurance / W-9 / contract / vet paper runs out' AFTER doc_date, ADD KEY idx_expires (expires_on)");
    }
};
