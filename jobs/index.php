<?php
// jobs/index.php
// Public job listing: every position taking applications right now,
// with filter chips by area (category) and type (paid / work-trade / internship).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/jobs/repo.php';

$jobs = [];
try { $jobs = jobs_public_open($pdo); } catch (Throwable $e) { error_log('[jobs] listing: ' . $e->getMessage()); }

// Chips only for areas/types that have an opening.
$cats = []; $types = [];
foreach ($jobs as $j) { $cats[$j['category_slug']] = $j['category_name']; $types[$j['engagement_type']] = true; }
$engLabels = jobs_engagement_labels();
$schLabels = jobs_schedule_labels();

$fCat  = (string)($_GET['area'] ?? '');
$fType = (string)($_GET['type'] ?? '');
if (!isset($cats[$fCat]))   $fCat = '';
if (!isset($types[$fType])) $fType = '';
$shown = array_values(array_filter($jobs, fn($j) => ($fCat === '' || $j['category_slug'] === $fCat) && ($fType === '' || $j['engagement_type'] === $fType)));

$chipUrl = function (string $area, string $type): string {
    $q = array_filter(['area' => $area, 'type' => $type], fn($v) => $v !== '');
    return '/jobs/' . ($q ? '?' . http_build_query($q) : '');
};

$pageTitle    = 'Jobs at Bat Cave Road Farm | Old Fort, NC';
$metaDesc     = 'Work at Bat Cave Road Farm in Old Fort, NC. See open farm, petting zoo, farmstand and thrift shop jobs, pay, and apply online in a few minutes.';
$canonicalUrl = 'https://batcaveroadfarm.com/jobs/';
$ogImage      = 'https://batcaveroadfarm.com/img/og-home.jpg';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.jobs.css';
if ($fCat !== '' || $fType !== '') header('X-Robots-Tag: noindex, follow');

require __DIR__ . '/../includes/head.php';
include __DIR__ . '/../includes/header.php';
?>
<div class="jb-wrap">
    <div class="jb-hero">
        <h1>Work at the Farm</h1>
        <p>We're a small family farm in Old Fort with a petting zoo, a farmstand and a thrift shop. When we're hiring, the open jobs are listed here and you can apply right from your phone.</p>
    </div>

<?php if (!$jobs): ?>
    <div class="jb-card jb-empty">
        <h2>No openings right now</h2>
        <p>We're not hiring at the moment. Jobs usually open before the busy season. <a href="/jobs/alerts/">Get an email when we post a job</a>.</p>
        <p>Questions about working here? Email <a href="mailto:jobs@batcaveroadfarm.com">jobs@batcaveroadfarm.com</a>.</p>
    </div>
<?php else: ?>
    <p class="jb-alert-link"><a href="/jobs/alerts/">Get job alerts by email</a></p>
    <?php if (count($cats) > 1 || count($types) > 1): ?>
    <div class="jb-chips" role="group" aria-label="Filter jobs">
        <a class="jb-chip<?= $fCat === '' && $fType === '' ? ' on' : '' ?>" href="/jobs/"<?= $fCat === '' && $fType === '' ? ' aria-current="page"' : '' ?>>All jobs</a>
        <?php if (count($cats) > 1): foreach ($cats as $slug => $name): $on = $fCat === $slug; ?>
            <a class="jb-chip<?= $on ? ' on' : '' ?>" href="<?= htmlspecialchars($chipUrl($on ? '' : $slug, $fType)) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($name) ?></a>
        <?php endforeach; endif; ?>
        <?php if (count($types) > 1): foreach ($engLabels as $k => $lbl): if (!isset($types[$k])) continue; $on = $fType === $k; ?>
            <a class="jb-chip<?= $on ? ' on' : '' ?>" href="<?= htmlspecialchars($chipUrl($fCat, $on ? '' : $k)) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($lbl) ?></a>
        <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$shown): ?>
        <div class="jb-card jb-empty"><p>No jobs match that filter. <a href="/jobs/">See all jobs</a>.</p></div>
    <?php endif; ?>

    <?php foreach ($shown as $j): $url = '/jobs/' . rawurlencode($j['slug']) . '/'; $pay = jobs_pay_summary($j); ?>
    <article class="jb-card">
        <h2 class="jb-title"><a href="<?= $url ?>"><?= htmlspecialchars($j['title']) ?></a></h2>
        <ul class="jb-meta">
            <li><span class="jb-tag"><?= htmlspecialchars($j['category_name']) ?></span></li>
            <li><span class="jb-tag"><?= htmlspecialchars($engLabels[$j['engagement_type']] ?? '') ?> · <?= htmlspecialchars($schLabels[$j['schedule_type']] ?? '') ?></span></li>
            <?php if ($pay !== ''): ?><li><span class="jb-tag pay"><?= htmlspecialchars($pay) ?></span></li><?php endif; ?>
            <?php if ((int)$j['openings'] > 1 || (int)$j['hires_count'] > 0): ?><li><span class="jb-tag filled"><?= (int)$j['hires_count'] ?> of <?= (int)$j['openings'] ?> filled</span></li><?php endif; ?>
            <?php if (!empty($j['closes_at'])): ?><li><span class="jb-tag close">Apply by <?= htmlspecialchars(date('M j', strtotime($j['closes_at']))) ?></span></li><?php endif; ?>
            <?php if ((int)$j['min_age'] < 18): ?><li><span class="jb-tag">Ages <?= (int)$j['min_age'] ?>+</span></li><?php endif; ?>
        </ul>
        <?php if (!empty($j['summary'])): ?><p class="jb-summary"><?= htmlspecialchars($j['summary']) ?></p><?php endif; ?>
        <div class="jb-actions">
            <a class="cta-btn" href="<?= $url ?>apply/">Apply now</a>
            <a href="<?= $url ?>">Read the full job</a>
        </div>
    </article>
    <?php endforeach; ?>
<?php endif; ?>

    <p class="jb-note">Bat Cave Road Farm is an equal opportunity employer. See how we handle applicant information in our <a href="/privacy/#jobs">privacy policy</a>.</p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
