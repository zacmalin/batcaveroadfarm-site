<?php
// jobs/alerts/confirm.php
// Step 2 of the job alert double opt-in (Jobs pack P7).
// GET  (link from the email) -> shows a "Yes, turn on alerts" button. Email
//                               security scanners open links on their own, so
//                               a bare GET never signs anyone up.
// POST (the button)           -> checks the signature and saves the signup.
ob_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/jobs/alerts.php';
require_once __DIR__ . '/../../admin/includes/audit.php';

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$src    = $isPost ? $_POST : $_GET;
$email  = strtolower(trim((string)($src['e'] ?? '')));
$split  = fn($v, $re) => array_values(array_filter(explode('.', (string)$v), fn($x) => $x !== '' && preg_match($re, $x)));
$cats   = array_map('intval', $split($src['c'] ?? '', '/^\d{1,6}$/'));
$types  = $split($src['y'] ?? '', '/^[a-z_]{1,20}$/');
$ts     = (int)($src['ts'] ?? 0);
$sig    = (string)($src['t'] ?? '');

$state = filter_var($email, FILTER_VALIDATE_EMAIL) ? jobs_alert_confirm_check($email, $cats, $types, $ts, $sig) : 'invalid';
$done  = false;
$fail  = false;
if ($state === 'ok' && $isPost) {
    try {
        [$c, $t] = jobs_alert_clean_choices($pdo, $cats, $types);   // drops an area that was removed since
        jobs_alert_subscribe($pdo, $email, $c, $t, bcrf_rl_ip());
        $done = true;
        if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'job_alert_subscribers', 0, 'Job alerts confirmed: ' . $email, 'Visitor');
    } catch (Throwable $e) {
        error_log('[job alerts confirm] ' . $e->getMessage());
        $fail = true;
    }
}

ob_end_clean();
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$pageTitle    = 'Confirm Job Alerts | Bat Cave Road Farm';
$metaDesc     = 'Confirm your Bat Cave Road Farm job alerts.';
$canonicalUrl = 'https://batcaveroadfarm.com/jobs/alerts/';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.jobs.css';
$breadcrumbs  = [['label' => 'Jobs', 'url' => '/jobs/'], ['label' => 'Job alerts', 'url' => '/jobs/alerts/'], ['label' => 'Confirm']];
require __DIR__ . '/../../includes/head.php';
include __DIR__ . '/../../includes/header.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$choices = $state === 'ok' ? jobs_alert_choice_text($pdo, $cats, $types) : '';
?>
<div class="jb-wrap">
    <div class="jb-hero"><h1>Job Alerts</h1></div>
<?php if ($done): ?>
    <div class="jb-card jb-ok" role="status">
        <h2>You're signed up</h2>
        <p>We'll email <strong><?= $h($email) ?></strong> when we post a job that matches: <?= $h($choices) ?>.</p>
        <p>Every alert has a link at the bottom to stop them.</p>
        <div class="jb-actions"><a class="cta-btn" href="/jobs/">See current jobs</a></div>
    </div>
<?php elseif ($fail): ?>
    <div class="jb-error" role="alert">Something went wrong on our end. Please tap the link in your email again in a moment, or email jobs@batcaveroadfarm.com.</div>
<?php elseif ($state === 'ok'): ?>
    <div class="jb-card">
        <h2>One more tap</h2>
        <p>Turn on job alerts for <strong><?= $h($email) ?></strong>?</p>
        <p class="jb-help">You picked: <?= $h($choices) ?></p>
        <form method="post" action="/jobs/alerts/confirm.php">
            <input type="hidden" name="e"  value="<?= $h($email) ?>">
            <input type="hidden" name="c"  value="<?= $h(implode('.', $cats)) ?>">
            <input type="hidden" name="y"  value="<?= $h(implode('.', $types)) ?>">
            <input type="hidden" name="ts" value="<?= $ts ?>">
            <input type="hidden" name="t"  value="<?= $h($sig) ?>">
            <div class="jb-submit"><button type="submit" class="cta-btn">Yes, turn on job alerts</button></div>
        </form>
    </div>
<?php elseif ($state === 'expired'): ?>
    <div class="jb-error" role="alert">This link has expired (links work for <?= JOBS_ALERT_LINK_DAYS ?> days).</div>
    <p class="jb-note"><a href="/jobs/alerts/">Sign up again</a></p>
<?php else: ?>
    <div class="jb-error" role="alert">This link isn't valid. Your email app may have cut it off.</div>
    <p class="jb-note"><a href="/jobs/alerts/">Sign up again</a></p>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
