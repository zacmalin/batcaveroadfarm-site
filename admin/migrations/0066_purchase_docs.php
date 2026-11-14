<?php
// admin/migrations/0066_purchase_docs.php — Invoices & receipts (Nov 14, 2026; includes/documents.php, admin/documents.php).
//   purchase_docs        one invoice, receipt, packing slip or statement: vendor, date, total (cents), invoice #, note, and
//                        what it belongs to: a receive, a purchase order, an animal expense, an "other expense" (fuel,
//                        repairs...) or nothing yet (the Inbox). Never removed; an owner can delete (hide) and restore it.
//   purchase_doc_files   the pages: photos (saved upright as JPEG), PDFs, or HEIC kept as-is. Files live outside the
//                        website folder (bcrf-uploads/documents) and are only sent through admin/document_file.php.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_docs (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        doc_type          VARCHAR(12) NOT NULL DEFAULT 'invoice' COMMENT 'invoice, receipt, packing, statement, other',
        link_type         VARCHAR(16) NULL COMMENT 'receive, po, animal_expense, expense; NULL = Inbox (not linked yet)',
        link_id           INT UNSIGNED NULL COMMENT 'receives.id / purchase_orders.id / animal_expenses.id',
        expense_category  VARCHAR(16) NULL COMMENT 'for link_type expense: fuel, repairs, utilities, supplies, equipment, feed, services, fees, other',
        vendor_id         BIGINT UNSIGNED NULL COMMENT 'vendors.id',
        vendor_name       VARCHAR(120) NULL COMMENT 'typed name when the seller is not a saved vendor',
        doc_date          DATE NULL COMMENT 'farm date on the paper',
        total_cents       BIGINT NULL,
        ref               VARCHAR(80) NULL COMMENT 'invoice / receipt #',
        note              VARCHAR(255) NULL,
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        updated_at        DATETIME NULL COMMENT 'UTC',
        updated_by        INT NULL,
        deleted_at        DATETIME NULL COMMENT 'UTC; hidden by an owner, can be restored',
        deleted_by        INT NULL,
        KEY idx_link (link_type, link_id),
        KEY idx_vendor (vendor_id),
        KEY idx_date (doc_date),
        KEY idx_deleted (deleted_at)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_doc_files (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        doc_id            INT UNSIGNED NOT NULL,
        file              VARCHAR(80) NOT NULL COMMENT 'YYYY/MM/random.ext under bcrf-uploads/documents',
        original_name     VARCHAR(200) NULL,
        mime              VARCHAR(40) NOT NULL COMMENT 'image/jpeg, application/pdf, image/heic',
        bytes             INT UNSIGNED NOT NULL,
        sha256            CHAR(64) NOT NULL COMMENT 'of the file as uploaded (spots the same file twice)',
        width             SMALLINT UNSIGNED NULL,
        height            SMALLINT UNSIGNED NULL,
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        deleted_at        DATETIME NULL COMMENT 'UTC; page removed by an owner',
        deleted_by        INT NULL,
        KEY idx_doc (doc_id),
        KEY idx_sha (sha256),
        CONSTRAINT fk_pdf_doc FOREIGN KEY (doc_id) REFERENCES purchase_docs (id)
    ) $cs");
};
