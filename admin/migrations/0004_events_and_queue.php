<?php
// admin/migrations/0004_events_and_queue.php — Farm OS Phase 0 (Nov 5, 2026)
// domain_events: what happened (content.published, booking.confirmed …). The runner turns each new event
//   into jobs and sets processed_at. Handled events are deleted after 90 days.
// queue_jobs: work for the runner (admin/cron/run.php) with retries. pending → running → done, or back to
//   pending with a later run_after after a failure, or dead after max_attempts. Done jobs are deleted after
//   90 days; dead ones stay until someone retries or discards them on System Health.
// Times are UTC. See includes/queue.php.
return "
CREATE TABLE IF NOT EXISTS domain_events (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    occurred_at  DATETIME(3)  NOT NULL COMMENT 'UTC',
    type         VARCHAR(80)  NOT NULL COMMENT 'thing.happened, e.g. content.published',
    object_type  VARCHAR(100) NOT NULL DEFAULT '',
    object_id    VARCHAR(64)  NULL,
    actor_type   VARCHAR(20)  NOT NULL DEFAULT 'system' COMMENT 'staff, system, agent, customer',
    actor_id     BIGINT UNSIGNED NULL,
    payload_json JSON NULL,
    processed_at DATETIME NULL COMMENT 'UTC; set once its jobs are queued',
    KEY idx_unprocessed (processed_at, id),
    KEY idx_type (type, occurred_at),
    KEY idx_object (object_type, object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_jobs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job          VARCHAR(80)  NOT NULL COMMENT 'handler name in includes/queue.php, e.g. email.send',
    payload_json JSON NULL,
    status       ENUM('pending','running','done','dead') NOT NULL DEFAULT 'pending',
    attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    run_after    DATETIME NOT NULL COMMENT 'UTC',
    locked_at    DATETIME NULL,
    locked_by    VARCHAR(32) NULL,
    last_error   TEXT NULL,
    dedupe_key   VARCHAR(190) NULL COMMENT 'skip a new job while one with this key is pending',
    event_id     BIGINT UNSIGNED NULL,
    created_at   DATETIME NOT NULL COMMENT 'UTC',
    finished_at  DATETIME NULL,
    KEY idx_due (status, run_after),
    KEY idx_locked (locked_by),
    KEY idx_dedupe (dedupe_key, status),
    KEY idx_finished (status, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
