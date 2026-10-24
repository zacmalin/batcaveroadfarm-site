<?php
// jobs/thanks.php
// Shown after an application is sent: /jobs/thanks/?j={slug}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/jobs/repo.php';
require_once __DIR__ . '/../includes/site_info.php';

$flash = $_SESSION['jobs_thanks'] ?? null;
unset($_SESSION['jobs_thanks']);
$job = null;
$slug = strtolower((string)($_GET['j'] ?? ''));
if (preg_match('/^[a-z0-9-]{1,160}$/', $slug)) {
    try { $job = jobs_find_by_slug($pdo, $slug); } catch (Throwable $e) { $job = null; }
}

$pageTitle    = 'Application sent | Bat Cave Road Farm';
$metaDesc     = 'Thanks for applying to work at Bat Cave Road Farm.';
$canonicalUrl = 'https://batcaveroadfarm.com/jobs/thanks/';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.jobs.css';
$breadcrumbs  = [['label' => 'Jobs', 'url' => '/jobs/'], ['label' => 'Application sent']];
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

require __DIR__ . '/../includes/head.php';
include __DIR__ . '/../includes/header.php';
$first = $flash['first'] ?? '';
?>
<div class="jb-wrap">
    <div class="jb-hero">
        <h1>Thanks<?= $first !== '' ? ', ' . htmlspecialchars($first) : '' ?></h1>
        <p>Your application<?= $job ? ' for <strong>' . htmlspecialchars($job['title']) . '</strong>' : '' ?> is in.</p>
    </div>
    <div class="jb-card">
        <h2>What happens next</h2>
        <ul>
            <li>We'll email you a copy in the next few minutes. If you don't see it, check your spam folder.</li>
            <li>Zac or Casey will read your application. If we'd like to talk, we'll email or call to set up a time.</li>
            <?php if (!empty($flash['minor'])): ?><li>We copied your parent or guardian on the email. If we offer you the job, we'll start an NC youth employment certificate together before your first shift.</li><?php endif; ?>
            <li>Need to add something or have a question? Email <a href="mailto:jobs@batcaveroadfarm.com">jobs@batcaveroadfarm.com</a> or call <?= htmlspecialchars(site_phone_display()) ?>.</li>
        </ul>
        <div class="jb-actions"><a class="cta-btn" href="/jobs/">See other jobs</a> <a href="/">Back to the farm</a></div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
