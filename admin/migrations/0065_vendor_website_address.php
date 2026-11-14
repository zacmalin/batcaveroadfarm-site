<?php
// admin/migrations/0065_vendor_website_address.php — vendor website and address (Nov 14, 2026).
//   vendors.website   VARCHAR(255) NULL   full https:// link (Open button on the vendor page)
//   vendors.address   VARCHAR(255) NULL   street address (Map button opens it in Google Maps)
// Logic: includes/vendors.php; page: admin/vendors.php. Adds only; safe to repeat.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$col('vendors', 'website')) $pdo->exec("ALTER TABLE vendors ADD COLUMN website VARCHAR(255) NULL COMMENT 'https:// link' AFTER phone");
    if (!$col('vendors', 'address')) $pdo->exec("ALTER TABLE vendors ADD COLUMN address VARCHAR(255) NULL COMMENT 'street address for maps' AFTER website");
};
