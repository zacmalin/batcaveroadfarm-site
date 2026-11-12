<?php
// admin/migrations/0032_event_quotes.php — Farm OS Phase 3 slice 4: quotes and payments for bookings and parties
// (Nov 12, 2026; 07 › Bookings and Private events: quote, deposit, balance, Stripe link, cash/P2P recorded by hand).
//   event_quotes     one quote per booking or party (subject_type 'booking' / 'event_inquiry'): price lines, total,
//                    optional deposit, when the balance is due, whether the customer may pay online, and a random
//                    token for the customer's quote page (booking/quote.php?q=TOKEN).
//   event_payments   the money ledger for a quote: payments (+) and refunds (−) by method, with the Stripe Checkout
//                    session (unique, so a webhook retry can't record a payment twice) or the register receipt.
//                    Mistakes are voided, never deleted.
//   site_settings    party_price_per_guest_cents = 1000 (the farm's party rate: $10 a guest covers admission for
//                    everyone plus a souvenir feed cup for each child; pay in full on arrival, no deposit — Zac, Nov 12, 2026).
// New tables: integer cents, UTC. Nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_quotes (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        subject_type   VARCHAR(20) NOT NULL COMMENT 'booking or event_inquiry',
        subject_id     INT UNSIGNED NOT NULL,
        token          CHAR(32) NOT NULL COMMENT 'customer quote page link',
        lines_json     TEXT NOT NULL COMMENT '[{label, qty, unit_cents}]',
        total_cents    INT NOT NULL DEFAULT 0,
        deposit_cents  INT NOT NULL DEFAULT 0,
        deposit_due    DATE NULL COMMENT 'farm-local date the deposit is due by',
        balance_when   VARCHAR(10) NOT NULL DEFAULT 'arrival' COMMENT 'arrival, or before (the day of the event)',
        pay_online     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = the quote page offers Stripe',
        customer_note  TEXT NULL COMMENT 'shown on the quote page and email',
        sent_at        DATETIME NULL COMMENT 'UTC, last time the quote was emailed or marked sent',
        sent_count     SMALLINT NOT NULL DEFAULT 0,
        viewed_at      DATETIME NULL COMMENT 'UTC, first time the customer opened the quote page',
        stripe_session_id VARCHAR(255) NULL COMMENT 'last checkout started from the quote page',
        created_at     DATETIME NOT NULL,
        created_by     INT NULL,
        updated_at     DATETIME NULL,
        updated_by     INT NULL,
        UNIQUE KEY uq_subject (subject_type, subject_id),
        UNIQUE KEY uq_token (token)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_payments (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        quote_id          INT UNSIGNED NOT NULL,
        subject_type      VARCHAR(20) NOT NULL,
        subject_id        INT UNSIGNED NOT NULL,
        kind              VARCHAR(10) NOT NULL DEFAULT 'payment' COMMENT 'payment or refund',
        method            VARCHAR(12) NOT NULL COMMENT 'stripe, cash, check, paypal, venmo, cashapp, register, other',
        amount_cents      INT NOT NULL COMMENT 'payments +, refunds -',
        reference         VARCHAR(120) NULL COMMENT 'P2P reference, check number, receipt number',
        sale_id           BIGINT UNSIGNED NULL COMMENT 'register sale this was rung up on',
        stripe_session_id VARCHAR(255) NULL,
        stripe_refund_id  VARCHAR(255) NULL,
        refund_of         BIGINT UNSIGNED NULL COMMENT 'payment row a refund gives money back from',
        note              VARCHAR(255) NULL,
        recorded_by       INT NULL COMMENT 'NULL = Stripe (automatic)',
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        voided_at         DATETIME NULL,
        voided_by         INT NULL,
        void_reason       VARCHAR(255) NULL,
        UNIQUE KEY uq_stripe_session (stripe_session_id),
        UNIQUE KEY uq_stripe_refund (stripe_refund_id),
        KEY idx_quote (quote_id),
        KEY idx_subject (subject_type, subject_id),
        KEY idx_created (created_at)
    ) $cs");

    $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('party_price_per_guest_cents', '1000')");
};
