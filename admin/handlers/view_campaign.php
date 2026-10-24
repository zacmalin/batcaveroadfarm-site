<?php
// admin/handlers/view_campaign.php
// Renders a previously sent campaign as a full HTML email preview.

require_once '../../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();   // config.php usually started it already (fix pack O)
// Staff only — public customer accounts also have user_id, so check the role.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['administrator', 'moderator', 'Shopkeep', 'Media'], true)) {
    http_response_code(403); exit('Access denied.');
}

date_default_timezone_set('America/New_York');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); exit('Invalid campaign ID.'); }

$campaign = null;
try {
    // SELECT * so missing columns never cause a query error
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
    $stmt->execute([$id]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ERP] view_campaign failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Could not load this campaign. Details were written to the error log.');
}

if (!$campaign) { http_response_code(404); exit('Campaign not found.'); }

require_once __DIR__ . '/../includes/email_template.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache');

$body = $campaign['body'] ?? '<p style="color:#f5ead6;font-family:Lora,Georgia,serif;">(No body stored for this campaign)</p>';

echo bcf_email_header();
echo $body;
echo bcf_email_footer();
