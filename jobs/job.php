<?php
// jobs/job.php
// One job posting: /jobs/{slug}/  (rewritten from .htaccess to job.php?slug=).
// Unknown slug → 404. A job that's closed, inactive or filled → 410 with a link back to /jobs/.
// Open jobs print JobPosting JSON-LD for Google's job search.
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/jobs/repo.php';
require_once __DIR__ . '/../includes/site_info.php';

$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
$job = null;
if (preg_match('/^[a-z0-9-]{1,160}$/', $slug)) {
    try { $job = jobs_find_by_slug($pdo, $slug); } catch (Throwable $e) { error_log('[jobs] detail: ' . $e->getMessage()); }
}
if (!$job) { http_response_code(404); ob_clean(); require __DIR__ . '/../404.php'; exit; }

$open = jobs_public_accepting($job);
$url  = 'https://batcaveroadfarm.com/jobs/' . rawurlencode($job['slug']) . '/';
$engLabels = jobs_engagement_labels();
$schLabels = jobs_schedule_labels();
$pay = jobs_pay_summary($job);

$pageTitle    = $job['title'] . ' | Jobs at Bat Cave Road Farm';
$metaDesc     = mb_substr($job['summary'] ?: ($job['title'] . ' at Bat Cave Road Farm in Old Fort, NC. ' . $pay), 0, 160);
$canonicalUrl = $url;
$ogImage      = 'https://batcaveroadfarm.com/img/og-home.jpg';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.jobs.css';
$breadcrumbs  = [['label' => 'Jobs', 'url' => '/jobs/'], ['label' => $job['title']]];

if (!$open) {
    http_response_code(410);
    header('X-Robots-Tag: noindex');
    require __DIR__ . '/../includes/head.php';
    include __DIR__ . '/../includes/header.php';
    ?>
<div class="jb-wrap">
    <div class="jb-hero"><h1><?= htmlspecialchars($job['title']) ?></h1></div>
    <div class="jb-card jb-empty">
        <h2>This job is closed</h2>
        <p>We're not taking applications for this position right now. It may open again next season.</p>
        <p><a class="cta-btn" href="/jobs/">See open jobs</a></p>
    </div>
</div>
<?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// ── JobPosting structured data ───────────────────────────────────────────
$typeMap = ['full_time' => 'FULL_TIME', 'part_time' => 'PART_TIME', 'seasonal' => 'TEMPORARY', 'temporary' => 'TEMPORARY'];
// Work-trade is paid-equivalent work (valued at minimum wage or more), not volunteering, so it uses the schedule type.
$empType = $job['engagement_type'] === 'internship' ? 'INTERN' : ($typeMap[$job['schedule_type']] ?? 'PART_TIME');
$descHtml = jobs_text_html($job['description']);
if ($job['responsibilities'])      $descHtml .= '<h3>What you\'ll do</h3>' . jobs_text_html($job['responsibilities']);
if ($job['requirements'])          $descHtml .= '<h3>What you need</h3>' . jobs_text_html($job['requirements']);
if ($job['physical_requirements']) $descHtml .= '<h3>Physical requirements</h3>' . jobs_text_html($job['physical_requirements']);
if ($job['engagement_type'] === 'work_trade' && $job['work_trade_terms']) $descHtml .= '<h3>Work-trade</h3>' . jobs_text_html($job['work_trade_terms']);
if ($descHtml === '') $descHtml = '<p>' . htmlspecialchars($job['summary'] ?: $job['title']) . '</p>';

$ld = [
    '@context' => 'https://schema.org/',
    '@type' => 'JobPosting',
    'title' => $job['title'],
    'description' => $descHtml,
    'datePosted' => substr((string)$job['opened_at'], 0, 10),
    'employmentType' => $empType,
    'directApply' => true,
    'url' => $url,
    'identifier' => ['@type' => 'PropertyValue', 'name' => 'Bat Cave Road Farm', 'value' => 'BCRF-' . (int)$job['id']],
    'hiringOrganization' => [
        '@type' => 'Organization', 'name' => 'Bat Cave Road Farm',
        'sameAs' => 'https://batcaveroadfarm.com/', 'logo' => 'https://batcaveroadfarm.com/img/logo.png',
    ],
    'jobLocation' => ['@type' => 'Place', 'address' => site_schema_address()],
];
if (!empty($job['closes_at'])) $ld['validThrough'] = $job['closes_at'] . 'T23:59:59-05:00';
if ($job['engagement_type'] !== 'work_trade' && $job['pay_min'] !== null && ($job['pay_unit'] ?? 'hour') === 'hour') {
    $val = ['@type' => 'QuantitativeValue', 'unitText' => 'HOUR'];
    if ($job['pay_max'] !== null && (float)$job['pay_max'] > (float)$job['pay_min']) { $val['minValue'] = (float)$job['pay_min']; $val['maxValue'] = (float)$job['pay_max']; }
    else $val['value'] = (float)$job['pay_min'];
    $ld['baseSalary'] = ['@type' => 'MonetaryAmount', 'currency' => 'USD', 'value' => $val];
}

require __DIR__ . '/../includes/head.php';
include __DIR__ . '/../includes/header.php';
$applyUrl = '/jobs/' . rawurlencode($job['slug']) . '/apply/';
?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div class="jb-wrap">
    <a class="jb-back" href="/jobs/">&larr; All jobs</a>
    <div class="jb-hero">
        <h1><?= htmlspecialchars($job['title']) ?></h1>
        <?php if (!empty($job['summary'])): ?><p><?= htmlspecialchars($job['summary']) ?></p><?php endif; ?>
    </div>

    <div class="jb-card">
        <ul class="jb-meta">
            <li><span class="jb-tag"><?= htmlspecialchars($job['category_name']) ?></span></li>
            <li><span class="jb-tag"><?= htmlspecialchars($engLabels[$job['engagement_type']] ?? '') ?> · <?= htmlspecialchars($schLabels[$job['schedule_type']] ?? '') ?></span></li>
            <?php if ($pay !== ''): ?><li><span class="jb-tag pay"><?= htmlspecialchars($pay) ?></span></li><?php endif; ?>
            <?php if ((int)$job['openings'] > 1 || (int)$job['hires_count'] > 0): ?><li><span class="jb-tag filled"><?= (int)$job['hires_count'] ?> of <?= (int)$job['openings'] ?> filled</span></li><?php endif; ?>
        </ul>
        <p><strong>Ages:</strong> <?= htmlspecialchars(jobs_min_age_labels()[(int)$job['min_age']] ?? '18 and older') ?><?php if ((int)$job['min_age'] < 18): ?>. Under 18 needs a parent or guardian's OK, and before the first shift we'll start an <a href="https://www.labor.nc.gov/workplace-rights/youth-employment-rules/apply-youth-employment-certificate" rel="noopener" target="_blank">NC youth employment certificate</a> with you.<?php endif; ?></p>
        <?php if (!empty($job['schedule_notes'])): ?><p><strong>Schedule:</strong> <?= htmlspecialchars($job['schedule_notes']) ?></p><?php endif; ?>
        <p><strong>Apply by:</strong> <?= !empty($job['closes_at']) ? htmlspecialchars(date('l, F j, Y', strtotime($job['closes_at']))) : 'Open until filled' ?></p>
        <div class="jb-actions"><a class="cta-btn" href="<?= $applyUrl ?>">Apply now</a></div>
    </div>

    <?php if ($job['description']): ?>
    <div class="jb-card"><h2>About the job</h2><?= jobs_text_html($job['description']) ?></div>
    <?php endif; ?>
    <?php if ($job['responsibilities']): ?>
    <div class="jb-card"><h2>What you'll do</h2><?= jobs_text_html($job['responsibilities']) ?></div>
    <?php endif; ?>
    <?php if ($job['requirements'] || $job['physical_requirements']): ?>
    <div class="jb-card">
        <?php if ($job['requirements']): ?><h2>What you need</h2><?= jobs_text_html($job['requirements']) ?><?php endif; ?>
        <?php if ($job['physical_requirements']): ?><h3>Physical requirements</h3><?= jobs_text_html($job['physical_requirements']) ?><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="jb-card">
        <h2><?= $job['engagement_type'] === 'work_trade' ? 'Work-trade' : 'Pay' ?></h2>
        <?php if ($job['engagement_type'] === 'work_trade'): ?>
            <?= jobs_text_html($job['work_trade_terms']) ?>
            <?php if ($job['work_trade_hourly_value'] !== null): ?><p>Valued at $<?= number_format((float)$job['work_trade_hourly_value'], 2) ?> per hour worked.</p><?php endif; ?>
        <?php else: ?>
            <p><?= htmlspecialchars($pay) ?></p>
        <?php endif; ?>
        <div class="jb-actions"><a class="cta-btn" href="<?= $applyUrl ?>">Apply now</a></div>
        <p class="jb-eeo">Bat Cave Road Farm is an equal opportunity employer. We don't discriminate based on race, color, religion, sex, sexual orientation, gender identity, national origin, age, disability, genetic information or veteran status.</p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
