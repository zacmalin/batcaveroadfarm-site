<?php
// admin/migrations/0001_baseline.php — Farm OS Phase 0 (Nov 5, 2026)
// The database structure of the live Bat Cave Road Farm site as captured on Nov 5, 2026 (78 tables, no data),
// via admin → Settings → Database → Capture structure.
// On the live database this is recorded as the starting point ("baseline") and is never run.
// On a new, empty database it creates every table. Never edit this file: put changes in 0002 and later.
return <<<'SQL'
-- Bat Cave Road Farm / Farm OS: database structure (no data)
-- Captured 2026-11-05 15:46 UTC from the live database
-- 78 tables, 0 views

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `animals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `bio` text,
  `fun_fact` text,
  `breed_id` int NOT NULL,
  `classification_id` int NOT NULL,
  `date_of_birth` date NOT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `photo_alt_text` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `social_shared_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `breed_id` (`breed_id`),
  KEY `classification_id` (`classification_id`),
  CONSTRAINT `animals_ibfk_1` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `animals_ibfk_2` FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `application_answers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_id` int NOT NULL,
  `question_id` int DEFAULT NULL,
  `question_label_snapshot` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `answer_text` text COLLATE utf8mb4_unicode_ci,
  `answer_option_ids` json DEFAULT NULL,
  `is_knockout_hit` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ans_app` (`application_id`),
  CONSTRAINT `fk_ans_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_id` int NOT NULL,
  `stored_name` char(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'random hex; file lives outside public_html',
  `original_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'application/pdf',
  `size_bytes` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_file_stored` (`stored_name`),
  KEY `idx_file_app` (`application_id`),
  CONSTRAINT `fk_file_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `user_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_note_app` (`application_id`),
  CONSTRAINT `fk_note_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_status_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_id` int NOT NULL,
  `from_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by_user_id` int DEFAULT NULL,
  `changed_by_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `changed_at` datetime NOT NULL COMMENT 'Eastern time',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hist_app` (`application_id`),
  CONSTRAINT `fk_hist_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `applications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_cycle_id` int NOT NULL,
  `job_id` int NOT NULL,
  `user_id` int DEFAULT NULL COMMENT 'Set on hire',
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `age_bracket` enum('14_15','16_17','18_plus') COLLATE utf8mb4_unicode_ci NOT NULL,
  `guardian_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guardian_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guardian_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guardian_consent_at` datetime DEFAULT NULL,
  `availability` json DEFAULT NULL,
  `earliest_start` date DEFAULT NULL,
  `cover_note` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `is_spam` tinyint(1) NOT NULL DEFAULT '0',
  `is_returning` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Applicant ticked: applied or worked here before',
  `prior_match` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Email matched an earlier application or user (admin-only)',
  `knockout_flag` tinyint(1) NOT NULL DEFAULT '0',
  `source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interview_at` datetime DEFAULT NULL,
  `interview_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` datetime NOT NULL COMMENT 'Eastern time',
  `decided_at` datetime DEFAULT NULL COMMENT 'Entered a closed status; retention clock starts here',
  `archived_at` datetime DEFAULT NULL,
  `anonymized_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_app_status` (`status`,`is_spam`),
  KEY `idx_app_cycle` (`job_cycle_id`),
  KEY `idx_app_job` (`job_id`),
  KEY `idx_app_email` (`email`),
  CONSTRAINT `fk_app_cycle` FOREIGN KEY (`job_cycle_id`) REFERENCES `job_cycles` (`id`),
  CONSTRAINT `fk_app_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `user_name` varchar(100) NOT NULL DEFAULT '',
  `action` varchar(20) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` int DEFAULT NULL,
  `record_label` varchar(255) NOT NULL DEFAULT '',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_table` (`table_name`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_ts` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(110) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `blog_post_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `category_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_post_category` (`post_id`,`category_id`),
  KEY `fk_bpc_category` (`category_id`),
  CONSTRAINT `fk_bpc_category` FOREIGN KEY (`category_id`) REFERENCES `blog_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bpc_post` FOREIGN KEY (`post_id`) REFERENCES `blog_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int DEFAULT NULL,
  `linked_recipe_id` int DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `slug` varchar(200) NOT NULL,
  `date_published` date NOT NULL,
  `author` varchar(100) NOT NULL,
  `feature_image` varchar(255) DEFAULT NULL,
  `article` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `image_url` varchar(500) DEFAULT NULL,
  `publish_at` datetime DEFAULT NULL COMMENT 'Scheduled publish time; NULL = publish immediately',
  `newsletter_sent_at` datetime DEFAULT NULL,
  `social_shared_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `booking_blackouts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `d_date` date NOT NULL,
  `reason` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_date` (`d_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `booking_types` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `min_party` int NOT NULL DEFAULT '1',
  `max_party` int NOT NULL DEFAULT '25',
  `duration_min` int NOT NULL DEFAULT '120',
  `is_exclusive` tinyint(1) NOT NULL DEFAULT '0',
  `self_serve` tinyint(1) NOT NULL DEFAULT '1',
  `lead_time_hours` int NOT NULL DEFAULT '24',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `pricing_model` enum('flat','per_person') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `unit_price` decimal(8,2) NOT NULL DEFAULT '0.00',
  `requires_payment` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_key` (`type_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bookings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type_id` int unsigned NOT NULL,
  `customer_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `party_size` int NOT NULL DEFAULT '1',
  `amount_total` decimal(8,2) DEFAULT NULL,
  `payment_status` enum('pending','paid','refunded','waived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'waived',
  `payment_provider` enum('stripe','paypal') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `booking_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_exclusive` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('pending','confirmed','cancelled','completed','no_show') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `ticket_breakdown` text COLLATE utf8mb4_unicode_ci,
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `review_request_sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` datetime DEFAULT NULL,
  `date_flexible` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_date_status` (`booking_date`,`status`),
  KEY `idx_token` (`token`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `breed_classifications` (
  `breed_id` int NOT NULL,
  `classification_id` int NOT NULL,
  PRIMARY KEY (`breed_id`,`classification_id`),
  KEY `classification_id` (`classification_id`),
  CONSTRAINT `breed_classifications_ibfk_1` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `breed_classifications_ibfk_2` FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `breeds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `breed_name` varchar(100) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `image_alt_text` varchar(255) DEFAULT NULL,
  `tag_line` varchar(150) DEFAULT NULL,
  `description` text,
  `status_badge` enum('Standard','New Born','Featured','Sponsored') DEFAULT 'Standard',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `wiki_title` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `breed_name` (`breed_name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) DEFAULT NULL,
  `segment` varchar(50) NOT NULL DEFAULT 'all',
  `from_name` varchar(100) DEFAULT NULL,
  `reply_to` varchar(150) DEFAULT NULL,
  `body` text,
  `recipient_count` int NOT NULL DEFAULT '0',
  `is_test` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('Draft','Scheduled','Sent') DEFAULT 'Draft',
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `classifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_name` (`class_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `cron_runs` (
  `job` varchar(60) NOT NULL,
  `last_start` datetime DEFAULT NULL,
  `last_ok` datetime DEFAULT NULL,
  `last_fail` datetime DEFAULT NULL,
  `last_message` varchar(500) DEFAULT NULL,
  `last_ms` int DEFAULT NULL,
  `fails_in_row` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`job`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `cuisines` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(110) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `email_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_id` int DEFAULT NULL,
  `to_email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cc_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('sent','failed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` datetime NOT NULL COMMENT 'Eastern time',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_elog_app` (`application_id`),
  KEY `idx_elog_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_id` int DEFAULT NULL,
  `template_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cc_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rendered_subject` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rendered_body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','sent','cancelled','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `queued_by` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `approved_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_queue_status` (`status`),
  KEY `idx_queue_app` (`application_id`),
  CONSTRAINT `fk_queue_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tpl_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_html` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_text` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'Optional; plain text is made from the HTML when empty',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tpl_key` (`tpl_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ev_token` (`token_hash`),
  KEY `idx_ev_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `event_inquiries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `preferred_date` date DEFAULT NULL,
  `date_flexible` tinyint(1) NOT NULL DEFAULT '0',
  `adult_count` smallint NOT NULL DEFAULT '0',
  `child_count` smallint NOT NULL DEFAULT '0',
  `birthday_age` smallint DEFAULT NULL,
  `addons` text COLLATE utf8mb4_unicode_ci,
  `special_requests` text COLLATE utf8mb4_unicode_ci,
  `heard_from` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('new','contacted','quoted','confirmed','declined','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_date` (`preferred_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `description` text,
  `image_url` varchar(255) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT '0',
  `recurrence` enum('none','range','weekly','annual') NOT NULL DEFAULT 'none',
  `recur_days` varchar(20) DEFAULT NULL,
  `recur_until` date DEFAULT NULL,
  `recurrence_note` varchar(160) DEFAULT NULL,
  `is_free` tinyint(1) NOT NULL DEFAULT '1',
  `price` decimal(10,2) DEFAULT NULL,
  `location_note` varchar(160) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `farm_tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `task_name` varchar(255) NOT NULL,
  `priority` enum('Low','Medium','High') DEFAULT 'Medium',
  `status` enum('Pending','Completed') DEFAULT 'Pending',
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `category` varchar(50) DEFAULT 'General',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `gbp_auth` (
  `id` tinyint unsigned NOT NULL,
  `refresh_token_enc` text,
  `google_email` varchar(190) DEFAULT NULL,
  `account_name` varchar(120) DEFAULT NULL,
  `location_name` varchar(120) DEFAULT NULL,
  `location_title` varchar(190) DEFAULT NULL,
  `connected_at` datetime DEFAULT NULL,
  `last_ok_at` datetime DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `google_report_cache` (
  `report_key` varchar(20) NOT NULL,
  `data` longtext,
  `fetched_at` datetime DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `error_at` datetime DEFAULT NULL,
  PRIMARY KEY (`report_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ingredients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `singular_name` varchar(100) NOT NULL,
  `plural_name` varchar(100) NOT NULL,
  `calories_per_100g` decimal(8,2) DEFAULT NULL COMMENT 'kcal per 100g (USDA nutrient 1008)',
  `protein_per_100g` decimal(8,2) DEFAULT NULL COMMENT 'g per 100g (USDA nutrient 1003)',
  `fat_per_100g` decimal(8,2) DEFAULT NULL COMMENT 'g per 100g (USDA nutrient 1004)',
  `carbs_per_100g` decimal(8,2) DEFAULT NULL COMMENT 'g per 100g (USDA nutrient 1005)',
  `fiber_per_100g` decimal(8,2) DEFAULT NULL COMMENT 'g per 100g (USDA nutrient 1079)',
  `sugar_per_100g` decimal(8,2) DEFAULT NULL COMMENT 'g per 100g (USDA nutrient 2000)',
  `sodium_per_100g` decimal(8,2) DEFAULT NULL COMMENT 'mg per 100g (USDA nutrient 1093)',
  `density_g_per_ml` decimal(8,4) DEFAULT NULL COMMENT 'Density for volume→weight conversion (e.g. water=1, flour=0.53)',
  `weight_per_unit_g` decimal(8,2) DEFAULT NULL COMMENT 'Avg weight for count units (e.g. 1 egg=50g, 1 garlic clove=5g)',
  `usda_fdc_id` int DEFAULT NULL COMMENT 'USDA FoodData Central food ID for reference',
  `nutrition_verified` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=manually verified, 0=auto-looked-up',
  `allergens` varchar(255) DEFAULT NULL COMMENT 'Major allergens for labels; NULL = not checked, empty = none',
  `sub_ingredients` text COMMENT 'Ingredient list printed on the bought product, for labels',
  `pairs_with_recipe_id` int DEFAULT NULL COMMENT 'Optional: recipe this ingredient pairs well with',
  PRIMARY KEY (`id`),
  UNIQUE KEY `singular_name` (`singular_name`),
  UNIQUE KEY `plural_name` (`plural_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `inventory` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category` enum('Produce','Bakery','Pantry','Zoo','Merchandise') NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `name` varchar(255) NOT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `image_alt` varchar(255) DEFAULT NULL,
  `ingredients` text,
  `description` text,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stock_quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `size_value` decimal(10,2) DEFAULT NULL,
  `size_unit_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `min_stock_level` decimal(10,2) DEFAULT '1.00',
  `is_alert_enabled` tinyint(1) DEFAULT '1',
  `sku` varchar(50) DEFAULT NULL COMMENT 'Barcode SKU — auto-generated as BCF-NNN if blank',
  `category_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `fk_inv_cat` (`category_id`),
  CONSTRAINT `fk_inv_cat` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `inventory_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(110) NOT NULL,
  `icon` varchar(10) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `job_alert_subscribers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_ids` json DEFAULT NULL COMMENT 'empty = all categories',
  `engagement_types` json DEFAULT NULL COMMENT 'empty = all types',
  `confirmed_at` datetime DEFAULT NULL,
  `unsubscribed_at` datetime DEFAULT NULL,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alert_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(90) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allows_minors` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Thrift Shop + Farmstand only (non-farm work, NC youth certificate needed)',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_cycles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `opened_at` datetime NOT NULL COMMENT 'Eastern time',
  `closes_at` date DEFAULT NULL COMMENT 'Last day to apply (Eastern); JobPosting validThrough',
  `closed_at` datetime DEFAULT NULL,
  `openings` smallint unsigned NOT NULL DEFAULT '1',
  `auto_close_when_filled` tinyint(1) NOT NULL DEFAULT '1',
  `hires_count` smallint unsigned NOT NULL DEFAULT '0',
  `alerts_sent_at` datetime DEFAULT NULL,
  `talent_pool_notified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cycles_job` (`job_id`,`closed_at`),
  CONSTRAINT `fk_cycles_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `question_id` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_required` tinyint(1) NOT NULL DEFAULT '1',
  `knockout_answer` enum('none','yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'yes/no questions: this answer auto-rejects',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_question` (`job_id`,`question_id`),
  KEY `fk_jq_question` (`question_id`),
  CONSTRAINT `fk_jq_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jq_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `engagement_type` enum('paid','work_trade','internship') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paid' COMMENT 'For-profit farm: no unpaid volunteer roles',
  `schedule_type` enum('full_time','part_time','seasonal','temporary') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'part_time',
  `summary` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `responsibilities` text COLLATE utf8mb4_unicode_ci,
  `requirements` text COLLATE utf8mb4_unicode_ci,
  `physical_requirements` text COLLATE utf8mb4_unicode_ci,
  `schedule_notes` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pay_min` decimal(8,2) DEFAULT NULL,
  `pay_max` decimal(8,2) DEFAULT NULL,
  `pay_unit` enum('hour','week','month','stipend') COLLATE utf8mb4_unicode_ci DEFAULT 'hour',
  `work_trade_terms` text COLLATE utf8mb4_unicode_ci,
  `work_trade_hourly_value` decimal(8,2) DEFAULT NULL COMMENT 'Value of work-trade per hour worked (must be at least minimum wage)',
  `min_age` tinyint unsigned NOT NULL DEFAULT '18' COMMENT '14, 16 or 18',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `default_openings` smallint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobs_slug` (`slug`),
  KEY `idx_jobs_category` (`category_id`),
  CONSTRAINT `fk_jobs_category` FOREIGN KEY (`category_id`) REFERENCES `job_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mailing_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `is_subscribed` tinyint(1) DEFAULT '1',
  `track_nursery_updates` tinyint(1) DEFAULT '1',
  `track_inventory_alerts` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_mailing_segmentation` (`is_subscribed`,`track_nursery_updates`,`track_inventory_alerts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `mcp_oauth_clients` (
  `client_id` varchar(64) NOT NULL,
  `secret_hash` char(64) DEFAULT NULL,
  `client_name` varchar(120) NOT NULL DEFAULT '',
  `redirect_uris` text NOT NULL,
  `auth_method` varchar(32) NOT NULL DEFAULT 'client_secret_post',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `mcp_oauth_codes` (
  `code_hash` char(64) NOT NULL,
  `client_id` varchar(64) NOT NULL,
  `user_id` int NOT NULL,
  `redirect_uri` text NOT NULL,
  `code_challenge` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`code_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `mcp_oauth_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` varchar(64) NOT NULL,
  `user_id` int NOT NULL,
  `access_hash` char(64) NOT NULL,
  `refresh_hash` char(64) NOT NULL,
  `access_expires` datetime NOT NULL,
  `refresh_expires` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_access` (`access_hash`),
  UNIQUE KEY `uq_refresh` (`refresh_hash`),
  KEY `k_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `measurement_units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `system_type` enum('US','Metric') DEFAULT 'US',
  `abbreviation` varchar(20) DEFAULT NULL,
  `unit_type` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `newsletter_campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `target_segment` varchar(50) NOT NULL,
  `emails_sent` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `newsletter_log` (
  `post_id` int NOT NULL,
  `email` varchar(255) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`post_id`,`email`),
  KEY `idx_nl_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pantry` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `size` decimal(10,2) DEFAULT NULL,
  `size_unit_id` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pass_types` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type_key` varchar(50) NOT NULL,
  `label` varchar(120) NOT NULL,
  `tagline` varchar(200) DEFAULT NULL,
  `price` decimal(8,2) NOT NULL DEFAULT '0.00',
  `max_guests` tinyint NOT NULL DEFAULT '1',
  `duration_months` tinyint unsigned NOT NULL DEFAULT '12',
  `perks` text,
  `is_founding` tinyint(1) NOT NULL DEFAULT '0',
  `founding_qty_limit` smallint DEFAULT NULL,
  `sort_order` tinyint NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_key` (`type_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pass_visits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pass_id` int unsigned NOT NULL,
  `visited_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `guest_count` tinyint NOT NULL DEFAULT '1',
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pass_visited` (`pass_id`,`visited_at`),
  CONSTRAINT `pass_visits_ibfk_1` FOREIGN KEY (`pass_id`) REFERENCES `passes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `passes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pass_type_id` int unsigned NOT NULL,
  `holder_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `token` char(48) NOT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `status` enum('pending_payment','active','expired','cancelled','suspended') NOT NULL DEFAULT 'pending_payment',
  `payment_status` enum('pending','paid','waived','refunded') NOT NULL DEFAULT 'pending',
  `payment_provider` enum('stripe','cash','comp') DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `amount_paid` decimal(8,2) DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_email` (`email`),
  KEY `idx_holder` (`holder_name`),
  KEY `idx_status` (`status`),
  KEY `idx_valid_until` (`valid_until`),
  KEY `pass_type_id` (`pass_type_id`),
  CONSTRAINT `passes_ibfk_1` FOREIGN KEY (`pass_type_id`) REFERENCES `pass_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `purpose` varchar(20) NOT NULL DEFAULT 'reset',
  PRIMARY KEY (`id`),
  KEY `idx_pr_token` (`token_hash`),
  KEY `idx_pr_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `petting_zoo_products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `description` text,
  `photo_url` varchar(255) DEFAULT NULL,
  `photo_alt_text` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `size` decimal(10,2) DEFAULT NULL,
  `size_unit_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `idx_pz_prod_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pos_receipts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(50) NOT NULL,
  `cart_json` longtext NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) NOT NULL,
  `tax` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `payment_method` varchar(30) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `tax_exempt` tinyint(1) NOT NULL DEFAULT '0',
  `was_printed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_number` (`receipt_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `product_ingredients` (
  `product_type` enum('fruits_vegetables','baked_goods','pantry') NOT NULL,
  `product_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`product_type`,`product_id`,`ingredient_id`),
  KEY `ingredient_id` (`ingredient_id`),
  CONSTRAINT `product_ingredients_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `question_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `question_id` int NOT NULL,
  `label` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_knockout` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qopt_question` (`question_id`),
  CONSTRAINT `fk_qopt_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `help_text` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('yes_no','short_text','long_text','single_choice','multi_choice','number','date') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'short_text',
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_categories_junction` (
  `recipe_id` int NOT NULL,
  `category_id` int NOT NULL,
  PRIMARY KEY (`recipe_id`,`category_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `recipe_categories_junction_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_categories_junction_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `recipe_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `user_id` int NOT NULL,
  `comment_text` text NOT NULL,
  `status` enum('published','pending','rejected','flagged') NOT NULL DEFAULT 'published',
  `manually_approved` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipe` (`recipe_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `recipe_ingredients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `ingredient_id` int DEFAULT NULL,
  `linked_recipe_id` int DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_id` int DEFAULT NULL,
  `notes` varchar(120) DEFAULT NULL,
  `ingredient_group` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ri_recipe` (`recipe_id`),
  KEY `fk_ri_ingredient` (`ingredient_id`),
  KEY `fk_ri_unit` (`unit_id`),
  CONSTRAINT `fk_ri_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `fk_ri_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`),
  CONSTRAINT `fk_ri_unit` FOREIGN KEY (`unit_id`) REFERENCES `measurement_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `recipe_pairings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `paired_recipe_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recipe_pair` (`recipe_id`,`paired_recipe_id`),
  KEY `idx_pair_recipe` (`recipe_id`),
  KEY `idx_pair_paired` (`paired_recipe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_ratings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `rating` tinyint NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_recipe` (`recipe_id`,`user_id`),
  KEY `idx_recipe` (`recipe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `recipes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `featured_image` varchar(500) DEFAULT NULL,
  `slug` varchar(150) NOT NULL,
  `prep_time` int DEFAULT '0',
  `cook_time` int DEFAULT '0',
  `recipe_yield` varchar(50) DEFAULT '4 servings',
  `author` varchar(100) NOT NULL DEFAULT 'Bat Cave Road Farm Staff',
  `date_published` date NOT NULL,
  `description` text,
  `instructions` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `calories` int DEFAULT NULL COMMENT 'Calories per serving (schema nutrition.calories)',
  `cuisine_id` int DEFAULT NULL,
  `cuisine` varchar(60) DEFAULT NULL COMMENT 'Recipe cuisine e.g. American (schema recipeCuisine)',
  `protein_g` decimal(8,2) DEFAULT NULL,
  `fat_g` decimal(8,2) DEFAULT NULL,
  `carbs_g` decimal(8,2) DEFAULT NULL,
  `fiber_g` decimal(8,2) DEFAULT NULL,
  `sugar_g` decimal(8,2) DEFAULT NULL,
  `sodium_mg` decimal(8,2) DEFAULT NULL,
  `nutrition_source` varchar(30) DEFAULT NULL,
  `nutrition_updated_at` timestamp NULL DEFAULT NULL,
  `servings` int DEFAULT NULL,
  `difficulty` varchar(30) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `social_shared_at` datetime DEFAULT NULL,
  `nutrition_servings` int DEFAULT NULL COMMENT 'Servings the stored nutrition was divided by',
  `pairs_with_recipe_id` int DEFAULT NULL COMMENT 'Recipe this pairs well with',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `fk_recipe_cuisine` (`cuisine_id`),
  CONSTRAINT `fk_recipe_cuisine` FOREIGN KEY (`cuisine_id`) REFERENCES `cuisines` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role` enum('user','moderator','administrator','Shopkeep','Media') NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `can_view` int DEFAULT '0',
  `can_edit` int DEFAULT '0',
  PRIMARY KEY (`role`,`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `sales_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inventory_id` int NOT NULL,
  `quantity_sold` decimal(10,2) NOT NULL,
  `sale_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `sold_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `site_audit` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` int unsigned DEFAULT NULL,
  `admin_label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `area` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `change_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `old_value` text COLLATE utf8mb4_unicode_ci,
  `new_value` text COLLATE utf8mb4_unicode_ci,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_area` (`area`),
  KEY `idx_changed` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_contacts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `contact_type` enum('phone','email') COLLATE utf8mb4_unicode_ci NOT NULL,
  `slot` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `value` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `inherit_general` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contact` (`contact_type`,`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_hours` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `day_of_week` tinyint NOT NULL,
  `block_order` tinyint NOT NULL DEFAULT '0',
  `open_time` time DEFAULT NULL,
  `close_time` time DEFAULT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_scope_day` (`scope`,`day_of_week`,`block_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_hours_scopes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `inherit_general` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_settings` (
  `setting_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_socials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `content_type` enum('recipe','blog','animal') NOT NULL,
  `content_id` int NOT NULL,
  `platforms` varchar(64) NOT NULL DEFAULT 'facebook,instagram',
  `fb_caption` text,
  `ig_caption` text,
  `ig_hashtags` text,
  `status` enum('pending','sent','failed','skipped','partial') NOT NULL DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT '0',
  `fb_post_id` varchar(64) DEFAULT NULL,
  `ig_media_id` varchar(64) DEFAULT NULL,
  `last_error` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  `gbp_caption` text,
  `gbp_post_id` varchar(190) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_content` (`content_type`,`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `theme_schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `theme_slug` varchar(32) NOT NULL,
  `mode` enum('annual','range') NOT NULL DEFAULT 'annual',
  `start_md` char(5) DEFAULT NULL,
  `end_md` char(5) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `priority` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `theme_settings` (
  `skey` varchar(40) NOT NULL,
  `sval` varchar(64) NOT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `thrift_finds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `slug` varchar(150) NOT NULL,
  `icon` varchar(50) DEFAULT 0xF09F8FBA,
  `image_url` varchar(255) DEFAULT NULL,
  `image_alt_text` varchar(255) DEFAULT NULL,
  `description` text,
  `stock_quantity` int DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `size` decimal(10,2) DEFAULT NULL,
  `size_unit_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `idx_thrift_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `time_cards` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `time_cards_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `unit_conversions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `from_unit_id` int NOT NULL,
  `to_unit_id` int NOT NULL,
  `conversion_factor` decimal(10,6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_from_to` (`from_unit_id`,`to_unit_id`),
  KEY `to_unit_id` (`to_unit_id`),
  CONSTRAINT `unit_conversions_ibfk_1` FOREIGN KEY (`from_unit_id`) REFERENCES `measurement_units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `unit_conversions_ibfk_2` FOREIGN KEY (`to_unit_id`) REFERENCES `measurement_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `user_employment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `job_id` int DEFAULT NULL,
  `application_id` int DEFAULT NULL,
  `staff_position` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `engagement_type` enum('paid','work_trade','internship') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paid',
  `pay_rate` decimal(8,2) DEFAULT NULL,
  `pay_unit` enum('hour','week','month','stipend') COLLATE utf8mb4_unicode_ci DEFAULT 'hour',
  `work_trade_terms` text COLLATE utf8mb4_unicode_ci,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `termination_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_minor` tinyint(1) NOT NULL DEFAULT '0',
  `dob` date DEFAULT NULL COMMENT 'Minors only (youth certificate needs age check)',
  `youth_cert_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `youth_cert_received_at` datetime DEFAULT NULL,
  `created_by` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_emp_user` (`user_id`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('user','moderator','administrator','Shopkeep','Media','Staff') DEFAULT 'user',
  `salary` decimal(10,2) DEFAULT NULL,
  `last_salary_change` date DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `date_terminated` date DEFAULT NULL,
  `termination_reason` varchar(500) DEFAULT NULL,
  `comment_restricted_until` date DEFAULT NULL,
  `staff_position` varchar(150) DEFAULT NULL,
  `engagement_type` enum('paid','work_trade','internship') DEFAULT NULL,
  `account_status` enum('invited','active','disabled') NOT NULL DEFAULT 'active',
  `source_application_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `view_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL,
  `view_name` varchar(50) NOT NULL,
  `can_access` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_view` (`role`,`view_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `wiki_cache` (
  `cache_key` varchar(190) NOT NULL,
  `data` mediumtext,
  `fetched_at` int NOT NULL,
  PRIMARY KEY (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS=1;
SQL;
