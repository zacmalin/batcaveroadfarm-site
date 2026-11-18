<?php
// admin/migrations/0072_garden.php — Garden: the garden & crop manager moved into Farm OS (Nov 18, 2026).
//   Merged from the standalone repo zacmalin/garden-crop-manager (its migrations 001-026, in their final shape).
//   Every table is garden_* (the standalone app's users and organizations tables would clash with Farm OS).
//   Not brought over: its users, memberships, login limits and its own task list (Farm OS sign-in, Permissions
//   and Tasks do those jobs; a garden task is a Farm OS task linked to a bed).
//   garden_organizations  one row: this farm (name from Site Info). Every garden table keeps organization_id so the
//                         module can serve more than one farm later; screens always use this row (garden_org()).
//   garden_crops / garden_varieties  shared reference crops (3 starters); the farm's own are garden_organization_*.
//   Lengths, areas and temperatures are stored metric (m, m², °C, cm); screens show feet, inches and °F.
//   Times are UTC (created_at defaults to UTC_TIMESTAMP()); dates (planted_on, harvested_on...) are farm dates.
//   garden_seed_lots.vendor_id          the Farm OS vendor the seed came from (optional).
//   garden_planting_harvest_events.product_id / stock_added   usable harvest added to a farmstand product's stock.
//   view_permissions  'garden' (see and log) and 'garden_manage' (beds, crops, setup) for Managers. Owners always have both.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ([
"CREATE TABLE IF NOT EXISTS `garden_care_application_locations` (
  `organization_id` bigint(20) unsigned NOT NULL,
  `application_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`application_id`,`location_id`),
  KEY `idx_care_application_location_tenant` (`organization_id`,`location_id`),
  KEY `gfk_care_application_location_parent` (`application_id`,`organization_id`),
  KEY `gfk_care_application_location` (`location_id`,`organization_id`),
  CONSTRAINT `gfk_care_application_location` FOREIGN KEY (`location_id`, `organization_id`) REFERENCES `garden_growing_locations` (`id`, `organization_id`),
  CONSTRAINT `gfk_care_application_location_parent` FOREIGN KEY (`application_id`, `organization_id`) REFERENCES `garden_care_applications` (`id`, `organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_care_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `site_id` bigint(20) unsigned NOT NULL,
  `kind` enum('soil_amendment','fertilizing','weeding','mulching','pruning','other') NOT NULL,
  `applied_on` date NOT NULL,
  `material` varchar(160) DEFAULT NULL,
  `quantity` decimal(12,3) DEFAULT NULL,
  `unit` varchar(32) DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_care_application_tenant` (`id`,`organization_id`),
  KEY `idx_care_application_site` (`organization_id`,`site_id`,`applied_on`),
  KEY `gfk_care_application_site` (`site_id`,`organization_id`),
  CONSTRAINT `gfk_care_application_site` FOREIGN KEY (`site_id`, `organization_id`) REFERENCES `garden_growing_sites` (`id`, `organization_id`),
  CONSTRAINT `gchk_care_application_qty` CHECK (`quantity` is null or `quantity` > 0),
  CONSTRAINT `gchk_care_application_pair` CHECK ((`quantity` is null) = (`unit` is null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_crop_growing_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `variety_id` bigint(20) unsigned DEFAULT NULL,
  `organization_variety_id` bigint(20) unsigned DEFAULT NULL,
  `sun_exposure` enum('unknown','full_sun','partial_shade','shade') NOT NULL DEFAULT 'unknown',
  `water_need` enum('unknown','low','moderate','high') NOT NULL DEFAULT 'unknown',
  `soil_ph_min` decimal(3,1) DEFAULT NULL,
  `soil_ph_max` decimal(3,1) DEFAULT NULL,
  `spacing_cm` decimal(6,1) DEFAULT NULL,
  `row_spacing_cm` decimal(6,1) DEFAULT NULL,
  `sow_depth_cm` decimal(5,1) DEFAULT NULL,
  `germination_min_days` smallint(5) unsigned DEFAULT NULL,
  `germination_max_days` smallint(5) unsigned DEFAULT NULL,
  `hardiness_min_zone` tinyint(3) unsigned DEFAULT NULL,
  `hardiness_max_zone` tinyint(3) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crop_profile_global` (`organization_id`,`variety_id`),
  UNIQUE KEY `uq_crop_profile_private` (`organization_id`,`organization_variety_id`),
  KEY `gfk_crop_profile_global` (`variety_id`),
  KEY `gfk_crop_profile_private` (`organization_variety_id`,`organization_id`),
  CONSTRAINT `gfk_crop_profile_global` FOREIGN KEY (`variety_id`) REFERENCES `garden_varieties` (`id`),
  CONSTRAINT `gfk_crop_profile_org` FOREIGN KEY (`organization_id`) REFERENCES `garden_organizations` (`id`),
  CONSTRAINT `gfk_crop_profile_private` FOREIGN KEY (`organization_variety_id`, `organization_id`) REFERENCES `garden_organization_varieties` (`id`, `organization_id`),
  CONSTRAINT `gchk_crop_profile_variety` CHECK ((`variety_id` is not null) <> (`organization_variety_id` is not null)),
  CONSTRAINT `gchk_crop_profile_ph` CHECK (`soil_ph_min` is null and `soil_ph_max` is null or `soil_ph_min` between 0 and 14 and `soil_ph_max` between `soil_ph_min` and 14),
  CONSTRAINT `gchk_crop_profile_germination` CHECK (`germination_min_days` is null and `germination_max_days` is null or `germination_min_days` between 1 and 365 and `germination_max_days` between `germination_min_days` and 365),
  CONSTRAINT `gchk_crop_profile_zones` CHECK (`hardiness_min_zone` is null and `hardiness_max_zone` is null or `hardiness_min_zone` between 1 and 13 and `hardiness_max_zone` between `hardiness_min_zone` and 13),
  CONSTRAINT `gchk_crop_profile_spacing` CHECK ((`spacing_cm` is null or `spacing_cm` > 0) and (`row_spacing_cm` is null or `row_spacing_cm` > 0) and (`sow_depth_cm` is null or `sow_depth_cm` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_crop_health_incidents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `planting_id` bigint(20) unsigned NOT NULL,
  `category` enum('pest','disease') NOT NULL,
  `identified_as` varchar(160) NOT NULL,
  `severity` enum('low','moderate','high','critical','unknown') NOT NULL DEFAULT 'unknown',
  `observed_on` date NOT NULL,
  `status` enum('open','monitoring','resolved') NOT NULL DEFAULT 'open',
  `notes` varchar(2000) DEFAULT NULL,
  `resolved_on` date DEFAULT NULL,
  `outcome` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crop_health_tenant` (`id`,`organization_id`),
  KEY `idx_crop_health_planting` (`organization_id`,`planting_id`,`observed_on`),
  KEY `gfk_crop_health_planting` (`planting_id`,`organization_id`),
  CONSTRAINT `gfk_crop_health_planting` FOREIGN KEY (`planting_id`, `organization_id`) REFERENCES `garden_plantings` (`id`, `organization_id`),
  CONSTRAINT `gchk_crop_health_resolution` CHECK ((`status` = 'resolved') = (`resolved_on` is not null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_crop_health_interventions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `incident_id` bigint(20) unsigned NOT NULL,
  `applied_on` date NOT NULL,
  `method` varchar(160) NOT NULL,
  `product` varchar(160) DEFAULT NULL,
  `quantity` decimal(12,3) DEFAULT NULL,
  `unit` varchar(32) DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_crop_health_intervention` (`organization_id`,`incident_id`,`applied_on`),
  KEY `gfk_crop_health_intervention` (`incident_id`,`organization_id`),
  CONSTRAINT `gfk_crop_health_intervention` FOREIGN KEY (`incident_id`, `organization_id`) REFERENCES `garden_crop_health_incidents` (`id`, `organization_id`),
  CONSTRAINT `gchk_crop_health_intervention_qty` CHECK (`quantity` is null or `quantity` > 0),
  CONSTRAINT `gchk_crop_health_intervention_pair` CHECK ((`quantity` is null) = (`unit` is null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_crops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `common_name` varchar(160) NOT NULL,
  `scientific_name` varchar(200) DEFAULT NULL,
  `crop_family` varchar(120) DEFAULT NULL,
  `lifecycle` enum('annual','biennial','perennial') NOT NULL DEFAULT 'annual',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crop_scientific` (`scientific_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_greenhouse_readings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `observed_on` date NOT NULL,
  `temperature_c` decimal(5,1) DEFAULT NULL,
  `humidity_pct` decimal(5,1) DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_greenhouse_reading` (`organization_id`,`location_id`,`observed_on`),
  KEY `gfk_greenhouse_reading_location` (`location_id`,`organization_id`),
  CONSTRAINT `gfk_greenhouse_reading_location` FOREIGN KEY (`location_id`, `organization_id`) REFERENCES `garden_growing_locations` (`id`, `organization_id`),
  CONSTRAINT `gchk_greenhouse_humidity` CHECK (`humidity_pct` is null or `humidity_pct` between 0 and 100),
  CONSTRAINT `gchk_greenhouse_temperature` CHECK (`temperature_c` is null or `temperature_c` between -80 and 80)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_growing_locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `site_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `kind` enum('field','plot','row','raised_bed','container','greenhouse','zone','orchard','block','tree_position') NOT NULL,
  `name` varchar(160) NOT NULL,
  `area_sq_m` decimal(12,3) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `length_m` decimal(10,3) DEFAULT NULL,
  `width_m` decimal(10,3) DEFAULT NULL,
  `layout_x_m` decimal(10,3) DEFAULT NULL,
  `layout_y_m` decimal(10,3) DEFAULT NULL,
  `rotation_degrees` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_location_tenant` (`id`,`organization_id`),
  KEY `idx_location_parent` (`parent_id`,`organization_id`),
  KEY `idx_location_site` (`site_id`,`organization_id`),
  CONSTRAINT `gfk_location_parent` FOREIGN KEY (`parent_id`, `organization_id`) REFERENCES `garden_growing_locations` (`id`, `organization_id`),
  CONSTRAINT `gfk_location_site` FOREIGN KEY (`site_id`, `organization_id`) REFERENCES `garden_growing_sites` (`id`, `organization_id`),
  CONSTRAINT `gchk_location_length` CHECK (`length_m` is null or `length_m` > 0),
  CONSTRAINT `gchk_location_width` CHECK (`width_m` is null or `width_m` > 0),
  CONSTRAINT `gchk_location_pair` CHECK ((`length_m` is null) = (`width_m` is null)),
  CONSTRAINT `gchk_location_xy` CHECK ((`layout_x_m` is null) = (`layout_y_m` is null)),
  CONSTRAINT `gchk_location_rotation` CHECK (`rotation_degrees` < 360)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_growing_sites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(160) NOT NULL,
  `timezone` varchar(80) NOT NULL DEFAULT 'America/New_York',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `last_spring_frost` date DEFAULT NULL,
  `first_fall_frost` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_tenant` (`id`,`organization_id`),
  KEY `idx_site_tenant` (`organization_id`),
  CONSTRAINT `gfk_site_org` FOREIGN KEY (`organization_id`) REFERENCES `garden_organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_irrigation_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `watered_on` date NOT NULL,
  `amount` decimal(12,3) DEFAULT NULL,
  `unit` varchar(32) DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_irrigation_events` (`organization_id`,`plan_id`,`watered_on`),
  KEY `gfk_irrigation_event_plan` (`plan_id`,`organization_id`),
  CONSTRAINT `gfk_irrigation_event_plan` FOREIGN KEY (`plan_id`, `organization_id`) REFERENCES `garden_irrigation_plans` (`id`, `organization_id`),
  CONSTRAINT `gchk_irrigation_amount` CHECK (`amount` is null or `amount` > 0),
  CONSTRAINT `gchk_irrigation_pair` CHECK ((`amount` is null) = (`unit` is null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_irrigation_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `interval_days` smallint(5) unsigned NOT NULL,
  `next_due_on` date NOT NULL,
  `method` varchar(80) NOT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_irrigation_tenant` (`id`,`organization_id`),
  KEY `idx_irrigation_location` (`organization_id`,`location_id`,`next_due_on`),
  KEY `gfk_irrigation_location` (`location_id`,`organization_id`),
  CONSTRAINT `gfk_irrigation_location` FOREIGN KEY (`location_id`, `organization_id`) REFERENCES `garden_growing_locations` (`id`, `organization_id`),
  CONSTRAINT `gchk_irrigation_interval` CHECK (`interval_days` between 1 and 365)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_organization_crops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(160) NOT NULL,
  `lifecycle` enum('annual','biennial','perennial') NOT NULL DEFAULT 'annual',
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `archived_at` datetime NULL DEFAULT NULL COMMENT 'UTC',
  `crop_family` varchar(120) DEFAULT NULL,
  `scientific_name` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_crop_id` (`id`,`organization_id`),
  UNIQUE KEY `uq_org_crop_name` (`organization_id`,`name`),
  KEY `idx_org_crop_active` (`organization_id`,`archived_at`),
  CONSTRAINT `gfk_org_crop_owner` FOREIGN KEY (`organization_id`) REFERENCES `garden_organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_organization_varieties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `crop_id` bigint(20) unsigned NOT NULL,
  `name` varchar(160) NOT NULL,
  `days_to_maturity` smallint(5) unsigned DEFAULT NULL,
  `maturity_basis` enum('from_sowing','from_transplant','unknown') NOT NULL DEFAULT 'unknown',
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `archived_at` datetime NULL DEFAULT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_variety_id` (`id`,`organization_id`),
  UNIQUE KEY `uq_org_variety_name` (`organization_id`,`crop_id`,`name`),
  KEY `gfk_org_variety_crop` (`crop_id`,`organization_id`),
  KEY `idx_org_variety_active` (`organization_id`,`archived_at`),
  CONSTRAINT `gfk_org_variety_crop` FOREIGN KEY (`crop_id`, `organization_id`) REFERENCES `garden_organization_crops` (`id`, `organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_organization_variety_preferences` (
  `organization_id` bigint(20) unsigned NOT NULL,
  `variety_id` bigint(20) unsigned NOT NULL,
  `display_name` varchar(160) DEFAULT NULL,
  `days_to_maturity` smallint(5) unsigned DEFAULT NULL,
  `maturity_basis` enum('from_sowing','from_transplant','unknown') NOT NULL DEFAULT 'unknown',
  `notes` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`organization_id`,`variety_id`),
  KEY `gfk_org_variety_pref_variety` (`variety_id`),
  CONSTRAINT `gfk_org_variety_pref_org` FOREIGN KEY (`organization_id`) REFERENCES `garden_organizations` (`id`),
  CONSTRAINT `gfk_org_variety_pref_variety` FOREIGN KEY (`variety_id`) REFERENCES `garden_varieties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_organizations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_perennial_observations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `perennial_id` bigint(20) unsigned NOT NULL,
  `observed_on` date NOT NULL,
  `stage` enum('dormant','bud_break','flowering','fruit_set','fruit_development','harvest_ready','leaf_drop','other') NOT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_perennial_observation` (`organization_id`,`perennial_id`,`observed_on`),
  KEY `gfk_perennial_observation_parent` (`perennial_id`,`organization_id`),
  CONSTRAINT `gfk_perennial_observation_parent` FOREIGN KEY (`perennial_id`, `organization_id`) REFERENCES `garden_perennial_records` (`id`, `organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_perennial_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `planting_id` bigint(20) unsigned NOT NULL,
  `label` varchar(120) NOT NULL,
  `planted_year` smallint(5) unsigned DEFAULT NULL,
  `rootstock` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perennial_tenant` (`id`,`organization_id`),
  KEY `idx_perennial_planting` (`organization_id`,`planting_id`),
  KEY `gfk_perennial_planting` (`planting_id`,`organization_id`),
  CONSTRAINT `gfk_perennial_planting` FOREIGN KEY (`planting_id`, `organization_id`) REFERENCES `garden_plantings` (`id`, `organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_planting_care_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `planting_id` bigint(20) unsigned NOT NULL,
  `occurred_on` date NOT NULL,
  `kind` enum('watering','fertilizing','weeding','pruning','pest_observation','disease_observation','treatment','other') NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_care_planting_date` (`organization_id`,`planting_id`,`occurred_on`),
  KEY `gfk_care_planting` (`planting_id`,`organization_id`),
  CONSTRAINT `gfk_care_planting` FOREIGN KEY (`planting_id`, `organization_id`) REFERENCES `garden_plantings` (`id`, `organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_planting_harvest_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `planting_id` bigint(20) unsigned NOT NULL,
  `harvested_on` date NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit` varchar(32) NOT NULL,
  `destination` varchar(160) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL COMMENT 'Farm OS inventory.id the usable amount went into',
  `stock_added` decimal(12,3) DEFAULT NULL COMMENT 'amount added to that product stock, in the product unit',
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `grade` enum('premium','standard','processing','ungraded') NOT NULL DEFAULT 'ungraded',
  `loss_quantity` decimal(12,3) DEFAULT NULL,
  `loss_reason` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_harvest_planting_date` (`organization_id`,`planting_id`,`harvested_on`),
  KEY `gfk_harvest_planting` (`planting_id`,`organization_id`),
  CONSTRAINT `gfk_harvest_planting` FOREIGN KEY (`planting_id`, `organization_id`) REFERENCES `garden_plantings` (`id`, `organization_id`),
  CONSTRAINT `gchk_harvest_loss_nonnegative` CHECK (`loss_quantity` is null or `loss_quantity` >= 0 and `loss_quantity` <= `quantity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_planting_lifecycle_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `planting_id` bigint(20) unsigned NOT NULL,
  `event_type` enum('sown','germinated','hardened_off','transplanted','thinned','established','completed','failed') NOT NULL,
  `occurred_on` date NOT NULL,
  `quantity` decimal(12,3) DEFAULT NULL,
  `quantity_unit` varchar(32) DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `source_tray_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lifecycle_source_tray` (`source_tray_id`),
  KEY `idx_planting_lifecycle` (`organization_id`,`planting_id`,`occurred_on`,`id`),
  KEY `gfk_lifecycle_planting` (`planting_id`,`organization_id`),
  CONSTRAINT `gfk_lifecycle_planting` FOREIGN KEY (`planting_id`, `organization_id`) REFERENCES `garden_plantings` (`id`, `organization_id`),
  CONSTRAINT `gchk_lifecycle_quantity` CHECK (`quantity` is null or `quantity` > 0),
  CONSTRAINT `gchk_lifecycle_quantity_pair` CHECK ((`quantity` is null) = (`quantity_unit` is null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_plantings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `variety_id` bigint(20) unsigned DEFAULT NULL,
  `seed_lot_id` bigint(20) unsigned DEFAULT NULL,
  `planted_on` date NOT NULL,
  `method` enum('direct_sow','transplant','perennial_installation') NOT NULL,
  `quantity` decimal(12,3) DEFAULT NULL,
  `quantity_unit` varchar(32) DEFAULT NULL,
  `status` enum('planned','active','completed','failed') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `organization_variety_id` bigint(20) unsigned DEFAULT NULL,
  `allocated_area_sq_m` decimal(12,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planting_tenant` (`id`,`organization_id`),
  KEY `idx_planting_org_date` (`organization_id`,`planted_on`),
  KEY `gfk_planting_location` (`location_id`,`organization_id`),
  KEY `gfk_planting_variety` (`variety_id`),
  KEY `gfk_planting_seed` (`seed_lot_id`,`organization_id`),
  KEY `gfk_planting_org_variety` (`organization_variety_id`,`organization_id`),
  CONSTRAINT `gfk_planting_location` FOREIGN KEY (`location_id`, `organization_id`) REFERENCES `garden_growing_locations` (`id`, `organization_id`),
  CONSTRAINT `gfk_planting_org` FOREIGN KEY (`organization_id`) REFERENCES `garden_organizations` (`id`),
  CONSTRAINT `gfk_planting_org_variety` FOREIGN KEY (`organization_variety_id`, `organization_id`) REFERENCES `garden_organization_varieties` (`id`, `organization_id`),
  CONSTRAINT `gfk_planting_seed` FOREIGN KEY (`seed_lot_id`, `organization_id`) REFERENCES `garden_seed_lots` (`id`, `organization_id`),
  CONSTRAINT `gfk_planting_variety` FOREIGN KEY (`variety_id`) REFERENCES `garden_varieties` (`id`),
  CONSTRAINT `gchk_planting_one_variety` CHECK ((`variety_id` is not null) <> (`organization_variety_id` is not null)),
  CONSTRAINT `gchk_planting_allocated_area` CHECK (`allocated_area_sq_m` is null or `allocated_area_sq_m` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_propagation_trays` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `planting_id` bigint(20) unsigned NOT NULL,
  `label` varchar(120) NOT NULL,
  `cells_sown` int(10) unsigned NOT NULL,
  `germinated` int(10) unsigned DEFAULT NULL,
  `sown_on` date NOT NULL,
  `germination_checked_on` date DEFAULT NULL,
  `transplanted_on` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `transplanted_count` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_propagation_planting` (`organization_id`,`planting_id`),
  KEY `gfk_propagation_planting` (`planting_id`,`organization_id`),
  CONSTRAINT `gfk_propagation_planting` FOREIGN KEY (`planting_id`, `organization_id`) REFERENCES `garden_plantings` (`id`, `organization_id`),
  CONSTRAINT `gchk_propagation_cells` CHECK (`cells_sown` > 0 and (`germinated` is null or `germinated` <= `cells_sown`)),
  CONSTRAINT `gchk_tray_transplanted_count` CHECK (`transplanted_count` is null or `transplanted_count` <= `cells_sown`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_seed_germination_tests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `seed_lot_id` bigint(20) unsigned NOT NULL,
  `started_on` date NOT NULL,
  `checked_on` date NOT NULL,
  `seeds_tested` smallint(5) unsigned NOT NULL,
  `seeds_germinated` smallint(5) unsigned NOT NULL,
  `method` varchar(120) DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_germination_tenant` (`id`,`organization_id`),
  KEY `idx_germination_lot` (`organization_id`,`seed_lot_id`,`checked_on`),
  KEY `gfk_germination_seed` (`seed_lot_id`,`organization_id`),
  CONSTRAINT `gfk_germination_seed` FOREIGN KEY (`seed_lot_id`, `organization_id`) REFERENCES `garden_seed_lots` (`id`, `organization_id`),
  CONSTRAINT `gchk_germination_counts` CHECK (`seeds_tested` between 1 and 65535 and `seeds_germinated` <= `seeds_tested`),
  CONSTRAINT `gchk_germination_dates` CHECK (`checked_on` >= `started_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_seed_lots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `variety_id` bigint(20) unsigned DEFAULT NULL,
  `lot_code` varchar(120) DEFAULT NULL,
  `source` varchar(160) DEFAULT NULL,
  `acquired_on` date DEFAULT NULL,
  `quantity` decimal(12,3) DEFAULT NULL,
  `unit` varchar(32) DEFAULT NULL,
  `vendor_id` int(10) unsigned DEFAULT NULL COMMENT 'Farm OS vendors.id; source keeps the name as typed',
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `organization_variety_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seed_tenant` (`id`,`organization_id`),
  KEY `idx_seed_tenant` (`organization_id`),
  KEY `gfk_seed_variety` (`variety_id`),
  KEY `gfk_seed_org_variety` (`organization_variety_id`,`organization_id`),
  CONSTRAINT `gfk_seed_org` FOREIGN KEY (`organization_id`) REFERENCES `garden_organizations` (`id`),
  CONSTRAINT `gfk_seed_org_variety` FOREIGN KEY (`organization_variety_id`, `organization_id`) REFERENCES `garden_organization_varieties` (`id`, `organization_id`),
  CONSTRAINT `gfk_seed_variety` FOREIGN KEY (`variety_id`) REFERENCES `garden_varieties` (`id`),
  CONSTRAINT `gchk_seed_one_variety` CHECK ((`variety_id` is not null) <> (`organization_variety_id` is not null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_seed_stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `seed_lot_id` bigint(20) unsigned NOT NULL,
  `kind` enum('opening','received','used','adjustment','reversal') NOT NULL,
  `delta` decimal(12,3) NOT NULL,
  `occurred_on` date NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `reverses_movement_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seed_movement_tenant` (`id`,`organization_id`),
  UNIQUE KEY `uq_stock_reversed_once` (`reverses_movement_id`),
  KEY `idx_seed_movement_lot` (`organization_id`,`seed_lot_id`,`occurred_on`),
  KEY `gfk_seed_movement_lot` (`seed_lot_id`,`organization_id`),
  KEY `gfk_stock_actor` (`actor_user_id`),
  KEY `gfk_stock_reversal` (`reverses_movement_id`,`organization_id`),
  CONSTRAINT `gfk_seed_movement_lot` FOREIGN KEY (`seed_lot_id`, `organization_id`) REFERENCES `garden_seed_lots` (`id`, `organization_id`),
  CONSTRAINT `gfk_stock_reversal` FOREIGN KEY (`reverses_movement_id`, `organization_id`) REFERENCES `garden_seed_stock_movements` (`id`, `organization_id`),
  CONSTRAINT `gchk_seed_movement_nonzero` CHECK (`delta` <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_soil_observations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `observed_on` date NOT NULL,
  `ph` decimal(3,1) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_soil_location_date` (`organization_id`,`location_id`,`observed_on`),
  KEY `gfk_soil_location` (`location_id`,`organization_id`),
  CONSTRAINT `gfk_soil_location` FOREIGN KEY (`location_id`, `organization_id`) REFERENCES `garden_growing_locations` (`id`, `organization_id`),
  CONSTRAINT `gchk_soil_ph` CHECK (`ph` is null or `ph` >= 0 and `ph` <= 14)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_succession_rounds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `schedule_id` bigint(20) unsigned NOT NULL,
  `round_number` tinyint(3) unsigned NOT NULL,
  `due_on` date NOT NULL,
  `planting_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_succession_round` (`schedule_id`,`round_number`),
  KEY `idx_succession_round_tenant` (`organization_id`,`due_on`),
  KEY `gfk_succession_round_schedule` (`schedule_id`,`organization_id`),
  KEY `gfk_succession_round_planting` (`planting_id`,`organization_id`),
  CONSTRAINT `gfk_succession_round_planting` FOREIGN KEY (`planting_id`, `organization_id`) REFERENCES `garden_plantings` (`id`, `organization_id`),
  CONSTRAINT `gfk_succession_round_schedule` FOREIGN KEY (`schedule_id`, `organization_id`) REFERENCES `garden_succession_schedules` (`id`, `organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_succession_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `site_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `variety_id` bigint(20) unsigned DEFAULT NULL,
  `organization_variety_id` bigint(20) unsigned DEFAULT NULL,
  `start_on` date NOT NULL,
  `interval_days` smallint(5) unsigned NOT NULL,
  `rounds` tinyint(3) unsigned NOT NULL,
  `method` enum('direct_sow','transplant','perennial_installation') NOT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT (UTC_TIMESTAMP()) COMMENT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_succession_tenant` (`id`,`organization_id`),
  KEY `idx_succession_site` (`organization_id`,`site_id`,`start_on`),
  KEY `gfk_succession_site` (`site_id`,`organization_id`),
  KEY `gfk_succession_location` (`location_id`,`organization_id`),
  KEY `gfk_succession_global` (`variety_id`),
  KEY `gfk_succession_private` (`organization_variety_id`,`organization_id`),
  CONSTRAINT `gfk_succession_global` FOREIGN KEY (`variety_id`) REFERENCES `garden_varieties` (`id`),
  CONSTRAINT `gfk_succession_location` FOREIGN KEY (`location_id`, `organization_id`) REFERENCES `garden_growing_locations` (`id`, `organization_id`),
  CONSTRAINT `gfk_succession_private` FOREIGN KEY (`organization_variety_id`, `organization_id`) REFERENCES `garden_organization_varieties` (`id`, `organization_id`),
  CONSTRAINT `gfk_succession_site` FOREIGN KEY (`site_id`, `organization_id`) REFERENCES `garden_growing_sites` (`id`, `organization_id`),
  CONSTRAINT `gchk_succession_variety` CHECK ((`variety_id` is not null) <> (`organization_variety_id` is not null)),
  CONSTRAINT `gchk_succession_interval` CHECK (`interval_days` between 1 and 365 and `rounds` between 2 and 24)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS `garden_varieties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `crop_id` bigint(20) unsigned NOT NULL,
  `name` varchar(160) NOT NULL,
  `days_to_maturity` smallint(5) unsigned DEFAULT NULL,
  `maturity_basis` enum('from_sowing','from_transplant','unknown') NOT NULL DEFAULT 'unknown',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variety_crop` (`crop_id`,`name`),
  CONSTRAINT `gfk_variety_crop` FOREIGN KEY (`crop_id`) REFERENCES `garden_crops` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ] as $sql) $pdo->exec($sql);
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    $name = 'Our farm';
    try {
        $v = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'business_name' AND setting_value <> '' LIMIT 1")->fetchColumn();
        if (is_string($v) && trim($v) !== '') $name = mb_substr(trim($v), 0, 160);
    } catch (Throwable $e) { /* Site Info not set up yet */ }
    if (!(int)$pdo->query('SELECT COUNT(*) FROM garden_organizations')->fetchColumn()) {
        $st = $pdo->prepare('INSERT INTO garden_organizations (id, name) VALUES (1, ?)');
        $st->execute([$name]);
    }
    $pdo->exec("INSERT INTO garden_crops (common_name, scientific_name, crop_family, lifecycle) VALUES
        ('Basil', 'Ocimum basilicum', 'Lamiaceae', 'annual'),
        ('Lettuce', 'Lactuca sativa', 'Asteraceae', 'annual'),
        ('Tomato', 'Solanum lycopersicum', 'Solanaceae', 'annual')
        ON DUPLICATE KEY UPDATE common_name = VALUES(common_name)");
    $pdo->exec("INSERT INTO garden_varieties (crop_id, name, maturity_basis)
        SELECT id, 'Generic / unspecified', 'unknown' FROM garden_crops
        WHERE scientific_name IN ('Ocimum basilicum', 'Lactuca sativa', 'Solanum lycopersicum')
        ON DUPLICATE KEY UPDATE name = VALUES(name)");
    foreach (['garden', 'garden_manage'] as $v) {
        try { $st = $pdo->prepare("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES ('moderator', ?, 1)"); $st->execute([$v]); }
        catch (Throwable $e) { /* view_permissions not built yet (fresh test database) */ }
    }
};
