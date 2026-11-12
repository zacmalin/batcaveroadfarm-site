<?php
// admin/migrations/0048_animal_public.php — Farm OS Phase 5 slice 9: public profiles (Nov 12, 2026; 08 › Public profiles;
// includes/animal_public.php).
//   animals.birthday_display  what the website shows: 'age' ("2 yrs old"), 'birthday' ("Birthday: March 3") or 'none'.
//   animal_photos             more photos for an animal's public page (the main photo stays in animals.photo_url).
//                             Public pictures, saved under /img/animals/ like the main photos.
// The public switch stays animals.is_active; name, tagline, bio and fun facts stay where they are.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $has = function (string $col) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'animals' AND COLUMN_NAME = ?");
        $st->execute([$col]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$has('birthday_display')) {
        $pdo->exec("ALTER TABLE animals ADD COLUMN birthday_display VARCHAR(8) NOT NULL DEFAULT 'age' COMMENT 'website: age, birthday, none'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_photos (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        animal_id   INT NOT NULL,
        path        VARCHAR(255) NOT NULL COMMENT 'web path, e.g. /img/animals/hazel-12-ab12cd.webp',
        alt_text    VARCHAR(255) NULL,
        width       INT NOT NULL,
        height      INT NOT NULL,
        sort_order  INT NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        created_by  INT NULL,
        KEY idx_animal (animal_id, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
