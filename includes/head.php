<?php
// includes/head.php — <head> block, asset version
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
// Ensure a DB handle (and session) exist so the seasonal theme resolves on EVERY page,
// including static pages (gallery, about, faq, legal) that don't open the DB themselves.
require_once __DIR__ . '/../config.php';
if (!function_exists('site_sameas')) require_once __DIR__ . '/site_info.php';   // social links for schema sameAs (Nov 2026)
// Asset ?v= values are now automatic (bcrf_asset_v() in config.php, file modified time).
// BCRF_ASSET_V is only the fallback for a file that can't be found — no need to bump it.
if (!defined('BCRF_ASSET_V')) define('BCRF_ASSET_V', '20261103c');
// Default canonical to the CURRENT request path (not the homepage) when a
// page hasn't set one, and let pages that use \$pageDescription still work.
if (!isset($canonicalUrl)) {
    $__p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $__p = preg_replace('#/index\.php$#', '/', $__p);
    if ($__p === '') { $__p = '/'; }
    if (substr($__p, -1) !== '/' && !preg_match('#\.[a-z0-9]+$#i', $__p)) { $__p .= '/'; }
    $canonicalUrl = (bcrf_farm_url()) . $__p;
}
if (!isset($metaDesc) && isset($pageDescription)) { $metaDesc = $pageDescription; }
// Keep default SEO text evergreen; business hours belong to the live Settings schedule.
$defaultMetaDesc = 'Family-owned petting zoo in Old Fort, NC with goats, donkeys and more, plus a seasonal farmstand and thrift shop. Check our website for current visiting hours.';
// Seasonal theme: resolve once here so header.php can print the body class + banner.
require_once __DIR__ . '/themes.php';
$bcrfTheme      = bcrf_active_theme($pdo ?? null);
$bcrfThemeGlyph = $bcrfTheme !== '' ? bcrf_theme_glyph($bcrfTheme) : '';
require_once __DIR__ . '/ga.php';   // GA4: staff visits tagged internal, queued lead events (Nov 2026)
?>
 <!doctype html>
<html lang="en"<?= bcrf_ga_html_attrs() ?>>

    <head>
        <!-- Google tag (gtag.js): the library now loads after the page finishes loading, from js/min.gtag.js (bundled in min.site.js). Nov 11, 2026 performance fix. -->
<!-- Fix pack M: one combined file replaces min.gtag/nav/app/hours/subscribe/analytics/theme.js (built by tools/build-js.mjs) -->
<script defer src="<?= bcrf_farm_url() ?>/js/min.site.js?v=<?= bcrf_asset_v('/js/min.site.js') ?>"></script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php
        // Hero photo preload. Hero photos sit at the top of /img/ and are CSS backgrounds
        // (--hero-img). Phones get an 828px copy (bcrf_img_thumb) instead of the full desktop
        // file: PageSpeed's mobile LCP is the hero, and its size competes for the slow-4G
        // connection (Nov 11, 2026). Item pages preload images from subfolders: unchanged.
        $__heroMobile = '';
        if (!empty($preloadImage) && preg_match('#^(?:https?://[^/]+)?/img/[^/]+\.(?:webp|jpe?g|png)$#i', (string)$preloadImage)) {
            require_once __DIR__ . '/img_dims.php';
            $__t = bcrf_img_thumb((string)$preloadImage, 828, 75);   // q75 (was 80): PageSpeed flagged ~12 KB of compression savings (Nov 13, 2026)
            if ($__t !== $preloadImage && preg_match('#^[A-Za-z0-9:/._%?=\-]+$#', $__t)) $__heroMobile = $__t;
        }
        if ($__heroMobile !== ''): ?>
        <link rel="preload" as="image" href="<?= htmlspecialchars($__heroMobile) ?>" media="(max-width: 600px)" fetchpriority="high">
        <link rel="preload" as="image" href="<?= htmlspecialchars($preloadImage) ?>" media="(min-width: 601px)" fetchpriority="high">
        <style>@media (max-width:600px){.hero-photo{--hero-img:url('<?= $__heroMobile ?>') !important}}</style>
        <?php elseif (!empty($preloadImage)): ?><link rel="preload" as="image" href="<?php echo htmlspecialchars($preloadImage); ?>" fetchpriority="high"><?php endif; ?>
        <?php
        // Page background on phones (Nov 13, 2026): body uses background-size:cover over the whole
        // page, so a phone stretches it many times over anyway. A 400px copy looks the same and
        // frees ~45 KB of the slow-4G connection for the hero photo (homepage LCP).
        require_once __DIR__ . '/img_dims.php';
        $__bgPhone = bcrf_img_thumb('/img/background2.webp', 400);
        if ($__bgPhone !== '/img/background2.webp' && preg_match('#^/img/_thumbs/400/background2\.webp\?v=\d+$#', $__bgPhone)): ?>
        <style>@media (max-width:600px){body{background-image:url('<?= bcrf_farm_url() . $__bgPhone ?>') !important}}</style>
        <?php endif; ?>
        <?php
        // Header/nav wood texture re-saved at WebP quality 75, same 800px size (PageSpeed: ~22 KB savings, Nov 13, 2026).
        $__bgTex = bcrf_img_thumb('/img/background.webp', 800, 75, true);
        if ($__bgTex !== '/img/background.webp' && preg_match('#^/img/_thumbs/800q75/background\.webp\?v=\d+$#', $__bgTex)): ?>
        <style>header,nav{background-image:url('<?= bcrf_farm_url() . $__bgTex ?>') !important}</style>
        <?php endif; ?>
        <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "Bat Cave Road Farm | Petting Zoo, Farmstand & Thrift Shop — Old Fort, NC"; ?></title>
        <?php require_once __DIR__ . '/fonts.php'; echo bcrf_fonts_head(!empty($preloadImage) && preg_match('#^(?:https?://[^/]+)?/img/[^/]+\.(?:webp|jpe?g|png)$#i', (string)$preloadImage) ? ['rye-latin-400-normal', 'lora-latin-400-normal', 'lora-latin-400-italic'] : ['rye-latin-400-normal', 'lora-latin-400-normal']); /* self-hosted fonts (Nov 11, 2026), was Google Fonts. Hero pages also preload Lora italic (hero subtitle) so the hero doesn't resize when it arrives (CLS, Nov 13 2026) */ ?>

        <link rel="stylesheet" href="<?= bcrf_farm_url() ?>/css/min.style.css?v=<?= bcrf_asset_v('/css/min.style.css') ?>">
        <link rel="stylesheet" href="<?= bcrf_farm_url() ?>/css/min.print.css?v=<?= bcrf_asset_v('/css/min.print.css') ?>" media="print">
      
        <?php if (isset($pageCSS)): foreach ((array)$pageCSS as $__pcss): ?>
    		<link rel="stylesheet" href="<?php echo $__pcss; ?>?v=<?= bcrf_asset_v((string)$__pcss) ?>">
	<?php endforeach; endif; ?>
        <meta name="description" content="<?php echo isset($metaDesc) ? htmlspecialchars($metaDesc) : $defaultMetaDesc; ?>">
        <meta name="author" content="Bat Cave Road Farm">
        <link rel="icon" href="<?= bcrf_farm_url() ?>/img/favicon.ico" sizes="any">
        <link rel="icon" href="<?= bcrf_farm_url() ?>/img/icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="<?= bcrf_farm_url() ?>/img/icon.png">
        <link rel="manifest" href="<?= bcrf_farm_url() ?>/site.webmanifest">
        <link rel="canonical" href="<?php echo isset($canonicalUrl) ? htmlspecialchars($canonicalUrl) : (bcrf_farm_url() . '/'); ?>">
        <link rel="alternate" type="application/rss+xml" title="Bat Cave Road Farm — Farmstead Journal" href="<?= bcrf_farm_url() ?>/blog/feed.php">
        <link type="text/plain" rel="author" href="<?= bcrf_farm_url() ?>/humans.txt" />
        <meta name="theme-color" content="#3b2010">
        
        <!-- Open Graph (Facebook, Instagram, iMessage, WhatsApp) -->
        <meta property="og:type"        content="website">
        <meta property="og:site_name"   content="Bat Cave Road Farm">
        <meta property="og:title"       content="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "Bat Cave Road Farm | Petting Zoo, Farmstand & Thrift Shop — Old Fort, NC"; ?>">
        <meta property="og:description" content="<?php echo isset($metaDesc) ? htmlspecialchars($metaDesc) : $defaultMetaDesc; ?>">
        <meta property="og:url"         content="<?php echo isset($canonicalUrl) ? htmlspecialchars($canonicalUrl) : (bcrf_farm_url() . '/'); ?>">
        <meta property="og:image"       content="<?php echo isset($ogImage) ? htmlspecialchars($ogImage) : (bcrf_farm_url() . "/img/og-home.jpg"); ?>">
        <?php if (empty($ogImageNoSize)): ?>
        <meta property="og:image:width"  content="<?php echo !empty($ogImageWidth) ? (int)$ogImageWidth : 1200; ?>">
        <meta property="og:image:height" content="<?php echo !empty($ogImageHeight) ? (int)$ogImageHeight : 630; ?>">
        <?php endif; ?>
        <meta property="og:image:alt"   content="<?php echo isset($ogImageAlt) && $ogImageAlt !== '' ? htmlspecialchars($ogImageAlt) : 'Bat Cave Road Farm — Petting Zoo, Farmstand, and Thrift Shop in Old Fort, NC'; ?>">
        <meta property="og:locale"      content="en_US">
        <?php if (!function_exists('bcrf_farm')) require_once __DIR__ . '/farm.php';
              if (($bcrfFbApp = bcrf_farm('meta_app_id')) !== ''): ?>
        <meta property="fb:app_id"      content="<?= htmlspecialchars($bcrfFbApp, ENT_QUOTES) ?>"><!-- Meta app ID from Settings (Farm OS Phase 0); public, not a secret -->
        <?php endif; ?>
        
        <!-- Twitter / X Card -->
        <meta name="twitter:card"        content="summary_large_image">
        <meta name="twitter:title"       content="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "Bat Cave Road Farm | Petting Zoo, Farmstand & Thrift Shop — Old Fort, NC"; ?>">
        <meta name="twitter:description" content="<?php echo isset($metaDesc) ? htmlspecialchars($metaDesc) : $defaultMetaDesc; ?>">
        <meta name="twitter:image"       content="<?php echo isset($ogImage) ? htmlspecialchars($ogImage) : (bcrf_farm_url() . "/img/og-home.jpg"); ?>">
        <meta name="twitter:image:alt"   content="Bat Cave Road Farm in Old Fort, NC">  
        
        <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": [
        "LocalBusiness",
        "TouristAttraction"
      ],
      "@id": "<?= bcrf_farm_url() ?>/#business",
      "name": "Bat Cave Road Farm",
      "alternateName": "Bat Cave Road Petting Zoo",
      "description": "Family-owned farm in Old Fort, NC offering a hands-on petting zoo with goats, pigs, ducks, chickens, peacocks, and donkeys, a seasonal farmstand with fresh vegetables, eggs, honey, and baked goods, and a charming on-farm thrift shop. Near Asheville, Western North Carolina.",
      "url": "<?= bcrf_farm_url() ?>/",
      "logo": "<?= bcrf_farm_url() ?>/img/logo.png",
      "image": [
        "<?= bcrf_farm_url() ?>/img/og-home.jpg",
        "<?= bcrf_farm_url() ?>/img/og-pettingzoo.jpg",
        "<?= bcrf_farm_url() ?>/img/og-farmstand.jpg",
        "<?= bcrf_farm_url() ?>/img/og-thriftshop.jpg"
      ],
      "telephone": <?= json_encode(site_phone_e164('general'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      "email": "<?= htmlspecialchars(bcrf_farm_email('support'), ENT_QUOTES) ?>",
      "address": <?= json_encode(site_schema_address(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      "geo": <?= json_encode(($__geo = site_geo()) && $__geo['lat'] !== '' && $__geo['lng'] !== '' ? ['@type' => 'GeoCoordinates', 'latitude' => (float)$__geo['lat'], 'longitude' => (float)$__geo['lng']] : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      "openingHoursSpecification": <?= json_encode(site_hours_schema('general'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      "currenciesAccepted": "USD",
      "paymentAccepted": "Cash, Credit Card, Debit Card, Venmo, PayPal, CashApp",
      "priceRange": "$",
      "sameAs": <?= json_encode(array_values(site_sameas()), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>,
      "hasMap": <?= json_encode(site_map_query_url(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      "foundingDate": "2023",
      "keywords": "petting zoo, farmstand, thrift shop, family farm, Old Fort NC, Asheville area farm, farm fresh eggs, farm animals",
      "areaServed": {
        "@type": "GeoCircle",
        "geoMidpoint": {
          "@type": "GeoCoordinates",
          "latitude": <?= json_encode(($__geo['lat'] ?? '') !== '' ? (float)$__geo['lat'] : null) ?>,
          "longitude": <?= json_encode(($__geo['lng'] ?? '') !== '' ? (float)$__geo['lng'] : null) ?>
        },
        "geoRadius": "80000"
      },
      <?php
        $__gr  = function_exists('site_setting') ? trim((string)(site_setting('google_rating') ?? ''))       : '';
        $__grc = function_exists('site_setting') ? (int)(site_setting('google_review_count') ?? 0) : 0;
        if ($__gr !== '' && $__grc >= 5):
      ?>"aggregateRating": {
        "@type": "AggregateRating",
        "ratingValue": "<?= htmlspecialchars($__gr, ENT_QUOTES) ?>",
        "reviewCount": <?= $__grc ?>,
        "bestRating": "5",
        "worstRating": "1"
      },
      <?php endif; ?>
      "amenityFeature": [
        {
          "@type": "LocationFeatureSpecification",
          "name": "Free Parking",
          "value": true
        },
        {
          "@type": "LocationFeatureSpecification",
          "name": "Stroller Accessible",
          "value": true
        },
        {
          "@type": "LocationFeatureSpecification",
          "name": "Dog Friendly",
          "value": true
        },
        {
          "@type": "LocationFeatureSpecification",
          "name": "Hand Washing Stations",
          "value": true
        }
      ]
    },
    {
      "@type": "WebSite",
      "@id": "<?= bcrf_farm_url() ?>/#website",
      "url": "<?= bcrf_farm_url() ?>/",
      "name": "Bat Cave Road Farm",
      "publisher": {
        "@id": "<?= bcrf_farm_url() ?>/#business"
      }
    }
  ]
}
</script>