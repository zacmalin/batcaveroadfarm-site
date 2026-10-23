<?php $bcrfTheme = $bcrfTheme ?? ''; $bcrfThemeGlyph = $bcrfThemeGlyph ?? ''; ?>
<?php
    require_once __DIR__ . '/site_info.php'; // ensure accessor (idempotent; head.php usually loaded it)
    $bcrfStatus = function_exists('site_open_status') ? site_open_status('general') : ['open' => false, 'label' => '', 'detail' => ''];
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
    <body class="<?= $bcrfTheme !== '' ? 'theme-' . htmlspecialchars($bcrfTheme) : '' ?>">
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
                <a class="logo-wrap" href="https://batcaveroadfarm.com/" aria-label="Bat Cave Road Farm — home">
                    <img src="https://batcaveroadfarm.com/img/logo.png" alt="Bat Cave Road Farm Logo" width="400" height="299" decoding="async">
                </a>
                <div class="site-title">
                    <div class="main">Bat Cave Road Farm</div>
                    <div class="sub">Farm Fresh · Petting Zoo · Thrift & More</div>
                </div>
                <div class="header-status">
                    <span class="bcrf-status <?= $bcrfStatus['open'] ? 'is-open' : 'is-closed' ?>"
                          data-bcrf-hours='<?= htmlspecialchars(json_encode(["tz" => $bcrfTz, "h" => $bcrfHoursJs]), ENT_QUOTES) ?>'
                          aria-label="<?= htmlspecialchars($bcrfStatus['label'] . ($bcrfStatus['detail'] ? ' — ' . $bcrfStatus['detail'] : '')) ?>">
                        <span class="bcrf-status-dot" aria-hidden="true"></span>
                        <span class="bcrf-status-label"><?= htmlspecialchars($bcrfStatus['label']) ?></span>
                        <span class="bcrf-status-detail"><?= htmlspecialchars($bcrfStatus['detail']) ?></span>
                    </span>
                    <a class="header-directions" href="https://batcaveroadfarm.com/directions/" aria-label="Map and directions to Bat Cave Road Farm">
                        <span aria-hidden="true">📍</span> Directions
                    </a>
                </div>
           </div>
    	</header>

        <?php
        	include __DIR__ . '/nav.php';
        ?>
        <main id="main-content">
        <?php include __DIR__ . '/breadcrumbs.php'; ?>
