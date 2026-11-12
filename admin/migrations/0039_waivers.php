<?php
// admin/migrations/0039_waivers.php — Farm OS Phase 3: waivers (Nov 12, 2026; 07 › Waivers; includes/waivers.php).
//   waiver_templates   the wording, versioned. A version is a draft until an administrator publishes it, confirming an NC
//                      attorney reviewed it; published versions are never edited or removed, so every signature points to
//                      the exact text that was signed. One placeholder draft is added; it can't be signed.
//   waivers            one signature: the adult who signed, the minors in their care, the typed name and (optional) drawn
//                      signature, the version and a hash of its text, when and how (online, gate, portal), and when it
//                      runs out. Signatures are never deleted; staff can void one with a reason.
//   site_settings      waiver_mode = off (default: nothing changes at the gate), warn, or block; waiver_valid_months = 12.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS waiver_templates (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version       INT UNSIGNED NULL COMMENT 'set when published: 1, 2, 3…',
        title         VARCHAR(160) NOT NULL,
        body          MEDIUMTEXT NOT NULL COMMENT 'plain text; blank lines separate paragraphs',
        effective_at  DATETIME NULL COMMENT 'UTC; signing uses the newest published version in effect',
        published_at  DATETIME NULL COMMENT 'UTC; NULL = draft',
        published_by  INT NULL,
        reviewed_note VARCHAR(255) NULL COMMENT 'who reviewed it (e.g. the attorney and date)',
        created_at    DATETIME NOT NULL COMMENT 'UTC',
        created_by    INT NULL,
        updated_at    DATETIME NULL COMMENT 'UTC',
        UNIQUE KEY uq_version (version)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS waivers (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        template_id      INT UNSIGNED NOT NULL,
        body_sha256      CHAR(64) NOT NULL COMMENT 'hash of the exact title and text signed',
        customer_id      INT NULL,
        signer_name      VARCHAR(120) NOT NULL,
        signer_email     VARCHAR(190) NOT NULL,
        signer_phone     VARCHAR(30) NULL,
        minors_json      TEXT NULL COMMENT '[{name, age}] children in the signer''s care',
        typed_signature  VARCHAR(120) NOT NULL,
        signature_png    MEDIUMTEXT NULL COMMENT 'drawn signature, base64 PNG',
        via              VARCHAR(10) NOT NULL COMMENT 'online, gate, portal',
        staff_user_id    INT NULL COMMENT 'staff who handed over the device (gate)',
        booking_id       INT NULL,
        event_inquiry_id INT NULL,
        ip               VARCHAR(45) NULL,
        user_agent       VARCHAR(255) NULL,
        signed_at        DATETIME NOT NULL COMMENT 'UTC',
        expires_at       DATETIME NOT NULL COMMENT 'UTC',
        voided_at        DATETIME NULL COMMENT 'UTC',
        voided_by        INT NULL,
        void_reason      VARCHAR(255) NULL,
        KEY idx_email (signer_email, expires_at),
        KEY idx_customer (customer_id),
        KEY idx_signed (signed_at)
    ) $cs");
    $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('waiver_mode', 'off'), ('waiver_valid_months', '12')");
    if (!(int)$pdo->query("SELECT COUNT(*) FROM waiver_templates")->fetchColumn()) {
        $pdo->prepare("INSERT INTO waiver_templates (title, body, created_at) VALUES (?, ?, UTC_TIMESTAMP())")->execute([
            'Release and waiver of liability',
            "PLACEHOLDER: replace this with the wording your NC attorney approves. Nobody can sign it until a reviewed version is published.\n\n"
            . "This is where the release goes: the risks of being around farm animals, what the visitor agrees to, and what they release the farm from.\n\n"
            . "Children: the adult signing confirms they are the parent or guardian of the minors listed, or has their permission, and will supervise them at all times.\n\n"
            . "Photos, medical care, and anything else the attorney advises.",
        ]);
    }
};
