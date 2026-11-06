<?php
// admin/migrations/0007_pinterest_drafts.php — Pinterest Part A: bulk drafts (Nov 5, 2026)
// social_queue_targets: one row per platform per post. Pinterest uses it first; Facebook and Instagram keep
//   using social_queue as before (admin/cron/send_social.php never reads this table). social_queue_id stays
//   NULL until those platforms move over. One "first" Pin per record (uq_target); a later republish uses a
//   new variant_key. Status: draft → approved → (Part B: scheduled → sending) → sent, or failed / skipped.
//   Text edited by hand (edited_at set) is never overwritten by regeneration. Sent rows are history.
// pinterest_batches: bulk generation runs in chunks through the job queue (job pinterest.batch), with a
//   checkpoint in cursor_json so a cut-off run resumes where it stopped.
// Times are UTC. See includes/pinterest.php and includes/pinterest_image.php.
return "
CREATE TABLE IF NOT EXISTS social_queue_targets (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    social_queue_id    INT NULL COMMENT 'parent social_queue row, once Facebook/Instagram move onto targets',
    platform           VARCHAR(20)  NOT NULL COMMENT 'pinterest',
    content_type       ENUM('recipe','animal','blog') NOT NULL,
    content_id         INT NOT NULL,
    variant_key        VARCHAR(40)  NOT NULL DEFAULT 'first' COMMENT 'first = the first Pin for this record',
    origin             ENUM('bulk','auto','manual') NOT NULL DEFAULT 'bulk',
    batch_id           BIGINT UNSIGNED NULL,
    status             ENUM('draft','approved','scheduled','sending','sent','failed','skipped') NOT NULL DEFAULT 'draft',
    title              VARCHAR(100) NOT NULL DEFAULT '',
    description        VARCHAR(500) NOT NULL DEFAULT '',
    link_url           VARCHAR(500) NOT NULL DEFAULT '',
    alt_text           VARCHAR(500) NOT NULL DEFAULT '',
    board_key          VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'recipes or animals; turned into a board id when sending',
    board_id           VARCHAR(64)  NULL COMMENT 'per-record board override (Pinterest board id)',
    image_path         VARCHAR(255) NULL COMMENT 'generated 1000x1500 JPEG, from the site root',
    image_source       VARCHAR(500) NULL COMMENT 'the original photo (never changed)',
    image_source_hash  CHAR(64)     NULL,
    template_version   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    source_fingerprint CHAR(64)     NULL COMMENT 'record text + photo + template when last generated',
    source_changed     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = the record changed since; regenerate to pick it up',
    edited_at          DATETIME     NULL COMMENT 'UTC; text edited by hand',
    edited_by          INT          NULL,
    approved_at        DATETIME     NULL COMMENT 'UTC',
    approved_by        INT          NULL,
    scheduled_for      DATETIME     NULL COMMENT 'UTC',
    attempts           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code    VARCHAR(40)  NULL,
    last_error         TEXT         NULL,
    external_id        VARCHAR(64)  NULL COMMENT 'Pinterest Pin id',
    external_url       VARCHAR(500) NULL,
    sent_at            DATETIME     NULL COMMENT 'UTC',
    created_by         INT          NULL,
    created_at         DATETIME     NOT NULL COMMENT 'UTC',
    updated_at         DATETIME     NOT NULL COMMENT 'UTC',
    UNIQUE KEY uq_target (platform, content_type, content_id, variant_key),
    UNIQUE KEY uq_external (platform, external_id),
    KEY idx_status (platform, status, updated_at),
    KEY idx_batch (batch_id),
    KEY idx_parent (social_queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pinterest_batches (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    content_types  VARCHAR(40)  NOT NULL DEFAULT 'recipe,animal',
    status         ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
    total          INT UNSIGNED NOT NULL DEFAULT 0,
    processed      INT UNSIGNED NOT NULL DEFAULT 0,
    created_count  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count  INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count  INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count   INT UNSIGNED NOT NULL DEFAULT 0,
    cursor_json    JSON NULL COMMENT 'checkpoint: {type, after_id}',
    log_json       JSON NULL COMMENT 'last 50 per-record results',
    last_error     TEXT NULL,
    created_by     INT  NULL,
    created_at     DATETIME NOT NULL COMMENT 'UTC',
    started_at     DATETIME NULL,
    finished_at    DATETIME NULL,
    updated_at     DATETIME NOT NULL COMMENT 'UTC',
    KEY idx_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
