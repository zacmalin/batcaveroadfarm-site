<?php
// jobs/alerts/unsubscribe.php
// Stop job alerts (Jobs pack P7). Link: ?e=<email>&t=<signature>
// GET  -> "Stop job alerts" button (scanners open links; a GET changes nothing)
// POST -> the button, or a mail app's one-click unsubscribe
//         (RFC 8058: body "List-Unsubscribe=One-Click"; no page needed).
ob_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/jobs/alerts.php';
require_once __DIR__ . '/../../admin/includes/audit.php';

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
// One-click POSTs keep e and t in the URL; the button sends them in the form.
$email  = strtolower(trim((string)($_POST['e'] ?? $_GET['e'] ?? '')));
$sig    = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$valid  = filter_var($email, FILTER_VALIDATE_EMAIL) && jobs_alert_unsub_verify($email, $sig);
$done   = false;

if ($isPost && $valid) {
    try {
        if (jobs_alert_unsubscribe($pdo, $email) && function_exists('erp_audit')) {
            erp_audit($pdo, 'update', 'job_alert_subscribers', 0, 'Job alerts stopped: ' . $email, 'Visitor');
        }
        $done = true;
    } catch (Throwable $e) {
        error_log('[job alerts unsub] ' . $e->getMessage());
    }
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        ob_end_clean();
        http_response_code($done ? 200 : 500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $done ? 'Unsubscribed' : 'Error';
        exit;
    }
}

ob_end_clean();
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$pageTitle    = 'Stop Job Alerts | Bat Cave Road Farm';
$metaDesc     = 'Stop Bat Cave Road Farm job alert emails.';
$canonicalUrl = 'https://batcaveroadfarm.com/jobs/alerts/';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.jobs.css';
$breadcrumbs  = [['label' => 'Jobs', 'url' => '/jobs/'], ['label' => 'Job alerts', 'url' => '/jobs/alerts/'], ['label' => 'Stop alerts']];
require __DIR__ . '/../../includes/head.php';
include __DIR__ . '/../../includes/header.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<div class="jb-wrap">
    <div class="jb-hero"><h1>Job Alerts</h1></div>
<?php if ($done): ?>
    <div class="jb-card jb-ok" role="status">
        <h2>Alerts stopped</h2>
        <p><strong><?= $h($email) ?></strong> won't get any more job alerts from us. Changed your mind? You can <a href="/jobs/alerts/">sign up again</a> anytime.</p>
        <div class="jb-actions"><a class="cta-btn" href="/jobs/">See current jobs</a></div>
    </div>
<?php elseif ($valid): ?>
    <div class="jb-card">
        <h2>Stop job alerts?</h2>
        <p>Stop sending job alerts to <strong><?= $h($email) ?></strong>?</p>
        <form method="post" action="/jobs/alerts/unsubscribe.php">
            <input type="hidden" name="e" value="<?= $h($email) ?>">
            <input type="hidden" name="t" value="<?= $h($sig) ?>">
            <div class="jb-submit"><button type="submit" class="cta-btn">Stop job alerts</button></div>
        </form>
    </div>
<?php else: ?>
    <div class="jb-error" role="alert">This link isn't valid. Your email app may have cut it off.</div>
    <p class="jb-note">To stop alerts, reply to any alert email or write to <a href="mailto:jobs@batcaveroadfarm.com">jobs@batcaveroadfarm.com</a> and we'll take you off.</p>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
