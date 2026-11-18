<?php
// admin/pos.php — retired Nov 18, 2026. The old till (replaced by admin/register.php on Nov 6, 2026) saved sales only
// into pos_receipts/sales_history, so they skipped the sales table: tax rules, Farm Reports and the kitchen-label check.
// Old bookmarks go to the register. A sale posted from a tab still open on the old till is refused, not lost silently.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    exit("The old register has been retired, so this sale was NOT saved.\nOpen the register (/admin/register.php) and ring it up there.");
}
header('Location: /admin/register.php', true, 302);
exit;
