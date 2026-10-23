<?php
// Cron/command-line only — block web visitors from triggering alert emails
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);
// admin/handlers/check_stock_alerts.php
require_once '../../config.php';
require_once '../../vendor/autoload.php';
require_once __DIR__ . '/../includes/email_template.php';

use PHPMailer\PHPMailer\PHPMailer;

// Fetch all items below threshold
$low_stock_items = $pdo->query("
    SELECT name, stock_quantity, min_stock_level 
    FROM inventory 
    WHERE is_alert_enabled = 1 
    AND stock_quantity <= min_stock_level
")->fetchAll();

if (!empty($low_stock_items)) {
    // SMTP credentials come from bcrf-secrets.php via config.php — never hardcoded here.
    if (BCRF_SMTP_PASS === '') {
        error_log('[stock_alerts] BCRF_SMTP_PASS not set — add SMTP_PASS to bcrf-secrets.php. Skipping alert.');
        return;
    }
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = BCRF_SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = BCRF_SMTP_USER;
    $mail->Password   = BCRF_SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = BCRF_SMTP_PORT;

    $mail->setFrom('support@batcaveroadfarm.com', 'Bat Cave Road Farm');
    $mail->addAddress(BCRF_STOCK_ALERT_TO);
    $mail->Subject = 'Low Stock Alert: ' . count($low_stock_items) . ' items';

    $rows = '';
    foreach ($low_stock_items as $item) {
        $rows .= '<tr><td style="padding:4px 14px 4px 0;color:#e8b84b;font-weight:bold;">' . htmlspecialchars($item['name'])
               . '</td><td style="padding:4px 0;">' . (int)$item['stock_quantity'] . ' remaining (min ' . (int)$item['min_stock_level'] . ')</td></tr>';
    }
    $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">Low stock alert</h1>'
           . '<p style="margin:0 0 16px;">The following items are at or below their minimum stock level:</p>'
           . '<table style="border-collapse:collapse;margin:0;">' . $rows . '</table>';

    if (function_exists('bcf_email_header')) {
        $mail->isHTML(true);
        $mail->Body    = bcf_email_header('Inventory Alert') . $inner . bcf_email_footer(false);
        $mail->AltBody = trim(strip_tags(str_replace('</tr>', "\n", $inner)));
    } else {
        $body = "The following items are below your minimum stock level:\n\n";
        foreach ($low_stock_items as $item) {
            $body .= "- {$item['name']}: {$item['stock_quantity']} remaining (Min: {$item['min_stock_level']})\n";
        }
        $mail->Body = $body;
    }
    try {
        $mail->send();
    } catch (Throwable $e) {
        error_log('[stock_alerts] PHPMailer failed: ' . $e->getMessage());
    }
}
