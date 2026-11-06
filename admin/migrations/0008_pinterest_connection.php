<?php
// admin/migrations/0008_pinterest_connection.php — Pinterest Part B: connection and boards (Nov 5, 2026)
// pinterest_auth: one row (id = 1) for the farm's Pinterest business account. Access and refresh tokens are
//   encrypted with a key made from PINTEREST_APP_SECRET (includes/adapter_pinterest.php). Pinterest rotates the
//   refresh token on every refresh (continuous refresh), so both are saved each time. env = sandbox while the
//   app has Trial access, production after Standard access; switching needs a reconnect.
// pinterest_boards: the account's boards from the last sync. A cache only: Pinterest stays the source of truth,
//   and a board missing from the latest sync is marked is_active = 0.
// Settings (site_settings, no structure needed): pinterest_board_id_recipes, pinterest_board_id_animals,
//   pinterest_autopost_recipe, pinterest_autopost_animal (both default off).
// Times are UTC.
return "
CREATE TABLE IF NOT EXISTS pinterest_auth (
    id                 TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    env                ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    access_token_enc   TEXT NULL,
    access_expires_at  DATETIME NULL COMMENT 'UTC',
    refresh_token_enc  TEXT NULL,
    refresh_expires_at DATETIME NULL COMMENT 'UTC',
    scope              VARCHAR(255) NULL,
    username           VARCHAR(120) NULL,
    account_type       VARCHAR(40)  NULL,
    connected_at       DATETIME NULL COMMENT 'UTC',
    connected_by       INT NULL,
    last_ok_at         DATETIME NULL COMMENT 'UTC',
    last_error         VARCHAR(500) NULL,
    needs_reconnect    TINYINT(1) NOT NULL DEFAULT 0,
    updated_at         DATETIME NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pinterest_boards (
    board_id    VARCHAR(64)  NOT NULL PRIMARY KEY,
    env         ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
    name        VARCHAR(190) NOT NULL DEFAULT '',
    privacy     VARCHAR(20)  NOT NULL DEFAULT '',
    owner       VARCHAR(120) NOT NULL DEFAULT '',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    synced_at   DATETIME     NOT NULL COMMENT 'UTC',
    KEY idx_active (env, is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
