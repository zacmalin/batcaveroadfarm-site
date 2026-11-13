<?php
// includes/header.php — site header (logo, open/closed status, Directions, We're hiring)
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
?>
<?php $bcrfTheme = $bcrfTheme ?? ''; $bcrfThemeGlyph = $bcrfThemeGlyph ?? ''; ?>
<?php
    require_once __DIR__ . '/site_info.php'; // ensure accessor (idempotent; head.php usually loaded it)
    $bcrfStatus = function_exists('site_open_status') ? site_open_status('general') : ['open' => false, 'label' => '', 'detail' => ''];
    // "We're hiring" pill: only while a job is taking applications (same rule as the Jobs menu item).
    $bcrfHiring = false;
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            require_once __DIR__ . '/jobs/repo.php';
            $bcrfHiring = jobs_any_open($pdo);
        } catch (Throwable $e) { $bcrfHiring = false; }
    }
    $bcrfTz     = function_exists('site_setting') ? site_setting('timezone', 'America/New_York') : 'America/New_York';
    $bcrfHoursJs = [];
    if (function_exists('site_hours')) {
        $__wk = site_hours('general');
        for ($__d = 0; $__d <= 6; $__d++) {
            $__b = $__wk[$__d]['blocks'][0] ?? null;
            $bcrfHoursJs[] = ($__wk[$__d]['closed'] || !$__b) ? null : [substr($__b['open'], 0, 5), substr($__b['close'], 0, 5)];
        }
    }
?>
    </head>
    <body class="<?= $bcrfTheme !== '' ? 'theme-' . htmlspecialchars($bcrfTheme) : '' ?>"<?= function_exists('bcrf_ga_body_attrs') ? bcrf_ga_body_attrs((string)($gaEvent ?? ''), (string)($gaForm ?? '')) : '' ?>>
        <a href="#main-content" class="skip-link">Skip to main content</a>
<?php if (function_exists('bcrf_theme_banner_html') && $bcrfTheme !== ''): ?>
        <?= bcrf_theme_banner_html($bcrfTheme) ?>
        <div class="theme-particles" data-theme-glyph="<?= htmlspecialchars($bcrfThemeGlyph, ENT_QUOTES) ?>" aria-hidden="true"></div>
<?php endif; ?>
<?php
    // ── Site announcement bar (admin → Site Info → Announcement) ────────────
    $__annText  = function_exists('site_setting') ? trim((string)(site_setting('announcement_text')  ?? '')) : '';
    $__annUntil = function_exists('site_setting') ? trim((string)(site_setting('announcement_until') ?? '')) : '';
    $__annShow  = $__annText !== '' && ($__annUntil === '' || $__annUntil >= date('Y-m-d'));
    if ($__annShow):
?>
        <div id="site-announcement" class="site-announcement" role="status" hidden
             data-ann-key="<?= substr(md5($__annText . '|' . $__annUntil), 0, 10) ?>">
            <?= htmlspecialchars($__annText) ?>
            <button type="button" id="announcement-dismiss" class="site-announcement-dismiss" aria-label="Dismiss announcement">×</button>
        </div>
<?php endif; ?>
    	<header>
            <div class="header-inner">
                <a class="logo-wrap" href="<?= bcrf_farm_url() ?>/">
<?php
                    // Fix pack M: smaller WebP logo when available (falls back to the PNG).
                    require_once __DIR__ . '/webp.php';
                    $__logoWebp = bcrf_webp_src('/img/logo.png');
                    if ($__logoWebp): ?>
<?php
                    // Homepage LCP (Nov 13 2026): the logo shows 80-110px tall (about 150px wide), so most screens get a 300px copy (~10 KB, was 38 KB).
                    require_once __DIR__ . '/img_dims.php';
                    $__logoFull  = bcrf_farm_url() . $__logoWebp . '?v=' . bcrf_asset_v((string)$__logoWebp);
                    $__logoSmall = bcrf_img_thumb((string)$__logoWebp, 300);
                    $__logoSet   = ($__logoSmall !== $__logoWebp && preg_match('#^/img/_thumbs/300/[A-Za-z0-9._%/\-]+\?v=\d+$#', $__logoSmall))
                        ? bcrf_farm_url() . $__logoSmall . ' 300w, ' . $__logoFull . ' 400w'
                        : $__logoFull; ?>
                    <picture style="display:contents">
                        <source srcset="<?= htmlspecialchars($__logoSet, ENT_QUOTES) ?>"<?= strpos($__logoSet, ' 300w') !== false ? ' sizes="(max-width: 700px) 107px, 148px"' : '' ?> type="image/webp">
                        <img src="<?= bcrf_farm_url() ?>/img/logo.png" alt="Bat Cave Road Farm — home" width="400" height="299" decoding="async">
                    </picture>
<?php else: ?>
                    <img src="<?= bcrf_farm_url() ?>/img/logo.png" alt="Bat Cave Road Farm — home" width="400" height="299" decoding="async">
<?php endif; ?>
                </a>
                <div class="site-title">
                    <div class="main">Bat Cave Road Farm</div>
                    <div class="sub">Farm Fresh · Petting Zoo · Thrift & More</div>
                </div>
                <div class="header-status">
                    <span class="bcrf-status <?= $bcrfStatus['open'] ? 'is-open' : 'is-closed' ?>"
                          data-bcrf-hours='<?= htmlspecialchars(json_encode(["tz" => $bcrfTz, "h" => $bcrfHoursJs]), ENT_QUOTES) ?>'>
                        <span class="bcrf-status-dot" aria-hidden="true"></span>
                        <span class="bcrf-status-label"><?= htmlspecialchars($bcrfStatus['label']) ?></span>
                        <span class="bcrf-status-detail"><?= htmlspecialchars($bcrfStatus['detail']) ?></span>
                    </span>
                    <a class="header-directions" href="<?= bcrf_farm_url() ?>/directions/" aria-label="Directions to Bat Cave Road Farm">
                        <span aria-hidden="true">📍</span> Directions
                    </a>
<?php if ($bcrfHiring): ?>
                    <a class="header-hiring" href="<?= bcrf_farm_url() ?>/jobs/" aria-label="We're hiring: see open jobs at Bat Cave Road Farm">
                        <span class="header-hiring-dot" aria-hidden="true"></span> We're hiring
                    </a>
<?php endif; ?>
                </div>
           </div>
    	</header>

        <?php
        	include __DIR__ . '/nav.php';
        ?>
        <main id="main-content">
        <?php include __DIR__ . '/breadcrumbs.php'; ?>
