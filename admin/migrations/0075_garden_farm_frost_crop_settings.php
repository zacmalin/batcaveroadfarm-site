<?php
// admin/migrations/0075_garden_farm_frost_crop_settings.php — Garden: frost dates once for the farm, and how each crop grows
//   (Nov 19, 2026; includes/garden_crop_settings.php, admin/garden/crops.php, admin/garden/areas.php).
//   garden_organizations.last_spring_frost / first_fall_frost   the farm's average frost dates; only month and day count
//       (every year uses them). Filled from the first garden that had dates; gardens with the same dates are cleared so they
//       follow the farm. A garden keeps its own dates only when they differ (a field across the road in a frost pocket).
//   garden_crop_settings   one row per crop for this farm (starter-list crop_id or the farm's own organization_crop_id):
//       when to start and plant out around frost, days to harvest, spacing, depth, sprouting, sun, water, pH, frost tolerance.
//       source = where the numbers came from ('Growli reference', or NULL when typed in).
//   garden_reference_plants   the farm's copy of OpenPlantDB (public domain), loaded from Garden › Crops; only the numbers
//       Farm OS uses, as JSON in garden_crop_settings column names (includes/garden_openplantdb.php).
//   garden_seed_lots.remind_at          bell reminder when the amount left drops to this (in the lot's unit); NULL = no reminder
//   garden_seed_lots.photo_front/back   seed packet photos (/img/garden/seeds/…webp)
// Safe to repeat.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$col('garden_organizations', 'last_spring_frost')) {
        $pdo->exec("ALTER TABLE garden_organizations ADD COLUMN last_spring_frost DATE NULL COMMENT 'average; month and day count' AFTER name,
                                                     ADD COLUMN first_fall_frost DATE NULL COMMENT 'average; month and day count' AFTER last_spring_frost");
        // The farm takes the first garden's dates; gardens with those same dates follow the farm from now on.
        foreach ($pdo->query('SELECT id FROM garden_organizations')->fetchAll(PDO::FETCH_COLUMN) as $org) {
            $st = $pdo->prepare('SELECT last_spring_frost, first_fall_frost FROM garden_growing_sites WHERE organization_id = ? AND (last_spring_frost IS NOT NULL OR first_fall_frost IS NOT NULL) ORDER BY id LIMIT 1');
            $st->execute([$org]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
            if (!$d) continue;
            $pdo->prepare('UPDATE garden_organizations SET last_spring_frost = ?, first_fall_frost = ? WHERE id = ?')->execute([$d['last_spring_frost'], $d['first_fall_frost'], $org]);
            $pdo->prepare('UPDATE garden_growing_sites SET last_spring_frost = NULL, first_fall_frost = NULL
                           WHERE organization_id = ? AND last_spring_frost <=> ? AND first_fall_frost <=> ?')->execute([$org, $d['last_spring_frost'], $d['first_fall_frost']]);
        }
    }
    if (!$col('garden_seed_lots', 'remind_at')) $pdo->exec("ALTER TABLE garden_seed_lots ADD COLUMN remind_at DECIMAL(12,3) NULL COMMENT 'bell when this much is left' AFTER unit");
    if (!$col('garden_seed_lots', 'photo_front')) $pdo->exec("ALTER TABLE garden_seed_lots ADD COLUMN photo_front VARCHAR(255) NULL COMMENT 'packet photo' AFTER remind_at, ADD COLUMN photo_back VARCHAR(255) NULL COMMENT 'packet back photo' AFTER photo_front");
    $pdo->exec("CREATE TABLE IF NOT EXISTS garden_crop_settings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organization_id BIGINT UNSIGNED NOT NULL,
        crop_id BIGINT UNSIGNED NULL COMMENT 'starter-list crop',
        organization_crop_id BIGINT UNSIGNED NULL COMMENT 'the farm''s own crop',
        season ENUM('warm','cool') NULL,
        start_indoors_weeks TINYINT UNSIGNED NULL COMMENT 'weeks before last spring frost; NULL = sown straight in the ground',
        plant_out_days SMALLINT NULL COMMENT 'days from the frost below to sow or plant out; negative = before',
        plant_out_from ENUM('spring','fall') NOT NULL DEFAULT 'spring' COMMENT 'spring = last spring frost, fall = first fall frost (garlic)',
        days_to_harvest SMALLINT UNSIGNED NULL,
        germination_min_days SMALLINT UNSIGNED NULL,
        germination_max_days SMALLINT UNSIGNED NULL,
        germ_temp_min_c DECIMAL(4,1) NULL,
        germ_temp_max_c DECIMAL(4,1) NULL,
        spacing_cm DECIMAL(6,1) NULL,
        row_spacing_cm DECIMAL(6,1) NULL,
        sow_depth_cm DECIMAL(5,1) NULL,
        sun_exposure ENUM('unknown','full_sun','partial_shade','shade') NOT NULL DEFAULT 'unknown',
        water_need ENUM('unknown','low','moderate','high') NOT NULL DEFAULT 'unknown',
        soil_ph_min DECIMAL(3,1) NULL,
        soil_ph_max DECIMAL(3,1) NULL,
        frost_tolerance ENUM('tender','half_hardy','hardy') NULL,
        notes TEXT NULL,
        source VARCHAR(60) NULL COMMENT 'where the numbers came from; NULL = typed in',
        updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
        PRIMARY KEY (id),
        UNIQUE KEY uq_crop_settings_shared (organization_id, crop_id),
        UNIQUE KEY uq_crop_settings_own (organization_id, organization_crop_id),
        CONSTRAINT gfk_crop_settings_org FOREIGN KEY (organization_id) REFERENCES garden_organizations (id),
        CONSTRAINT gfk_crop_settings_shared FOREIGN KEY (crop_id) REFERENCES garden_crops (id),
        CONSTRAINT gchk_crop_settings_one CHECK ((crop_id IS NOT NULL) <> (organization_crop_id IS NOT NULL))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS garden_reference_plants (
        slug VARCHAR(120) NOT NULL,
        name VARCHAR(160) NOT NULL,
        name_norm VARCHAR(160) NOT NULL COMMENT 'lower case, singular, for matching crop names',
        category VARCHAR(30) NOT NULL DEFAULT '',
        data TEXT NOT NULL COMMENT 'JSON, garden_crop_settings column names',
        imported_at DATETIME NOT NULL COMMENT 'UTC',
        PRIMARY KEY (slug),
        KEY idx_reference_plants_name (name_norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
