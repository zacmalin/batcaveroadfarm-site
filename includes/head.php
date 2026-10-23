<?php
// Ensure a DB handle (and session) exist so the seasonal theme resolves on EVERY page,
// including static pages (gallery, about, faq, legal) that don't open the DB themselves.
require_once __DIR__ . '/../config.php';
// Single source of truth for cache-busting. Bump this date on ANY css/js change.
if (!defined('BCRF_ASSET_V')) define('BCRF_ASSET_V', '20261023k');
// Default canonical to the CURRENT request path (not the homepage) when a
// page hasn't set one, and let pages that use \$pageDescription still work.
if (!isset($canonicalUrl)) {
    $__p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $__p = preg_replace('#/index\.php$#', '/', $__p);
    if ($__p === '') { $__p = '/'; }
    if (substr($__p, -1) !== '/' && !preg_match('#\.[a-z0-9]+$#i', $__p)) { $__p .= '/'; }
    $canonicalUrl = 'https://batcaveroadfarm.com' . $__p;
}
if (!isset($metaDesc) && isset($pageDescription)) { $metaDesc = $pageDescription; }
// Seasonal theme: resolve once here so header.php can print the body class + banner.
require_once __DIR__ . '/themes.php';
$bcrfTheme      = bcrf_active_theme($pdo ?? null);
$bcrfThemeGlyph = $bcrfTheme !== '' ? bcrf_theme_glyph($bcrfTheme) : '';
?>
 <!doctype html>
<html lang="en">

    <head>
        <!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-DLB6LEFWQZ"></script>
<script defer src="https://batcaveroadfarm.com/js/min.gtag.js?v=<?= BCRF_ASSET_V ?>"></script>
<script defer src="https://batcaveroadfarm.com/js/min.nav.js?v=<?= BCRF_ASSET_V ?>"></script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php if (!empty($preloadImage)): ?><link rel="preload" as="image" href="<?php echo htmlspecialchars($preloadImage); ?>" fetchpriority="high"><?php endif; ?>
        <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "Bat Cave Road Farm | Petting Zoo, Farmstand & Thrift Shop — Old Fort, NC"; ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@400;600&family=Lora:ital,wght@0,400;0,600;1,400&family=Playfair+Display:wght@700&family=Rye&display=swap" rel="stylesheet">

        <link rel="stylesheet" href="https://batcaveroadfarm.com/css/min.style.css?v=<?= BCRF_ASSET_V ?>">
        <link rel="stylesheet" href="https://batcaveroadfarm.com/css/min.print.css?v=<?= BCRF_ASSET_V ?>" media="print">
      
        <?php if (isset($pageCSS)): foreach ((array)$pageCSS as $__pcss): ?>
    		<link rel="stylesheet" href="<?php echo $__pcss; ?>?v=<?= BCRF_ASSET_V ?>">
	<?php endforeach; endif; ?>
        <meta name="description" content="<?php echo isset($metaDesc) ? htmlspecialchars($metaDesc) : 'Bat Cave Road Farm in Old Fort, NC — family-owned petting zoo with goats, donkeys & more. Seasonal farmstand, and on-farm thrift shop. Open Tue–Sun 10AM–4PM.'; ?>">
        <meta name="author" content="Bat Cave Road Farm">
        <link rel="icon" href="https://batcaveroadfarm.com/img/favicon.ico" sizes="any">
        <link rel="icon" href="https://batcaveroadfarm.com/img/icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="https://batcaveroadfarm.com/img/icon.png">
        <link rel="manifest" href="https://batcaveroadfarm.com/site.webmanifest">
        <link rel="canonical" href="<?php echo isset($canonicalUrl) ? htmlspecialchars($canonicalUrl) : 'https://batcaveroadfarm.com/'; ?>">
        <link rel="alternate" type="application/rss+xml" title="Bat Cave Road Farm — Farmstead Journal" href="https://batcaveroadfarm.com/blog/feed.php">
        <link type="text/plain" rel="author" href="https://batcaveroadfarm.com/humans.txt" />
        <meta name="theme-color" content="#3b2010">
        
        <!-- Open Graph (Facebook, Instagram, iMessage, WhatsApp) -->
        <meta property="og:type"        content="website">
        <meta property="og:site_name"   content="Bat Cave Road Farm">
        <meta property="og:title"       content="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "Bat Cave Road Farm | Petting Zoo, Farmstand & Thrift Shop — Old Fort, NC"; ?>">
        <meta property="og:description" content="<?php echo isset($metaDesc) ? htmlspecialchars($metaDesc) : 'Bat Cave Road Farm in Old Fort, NC — family-owned petting zoo with goats, donkeys & more. Seasonal farmstand, and on-farm thrift shop. Open Tue–Sun 10AM–4PM.'; ?>">
        <meta property="og:url"         content="<?php echo isset($canonicalUrl) ? htmlspecialchars($canonicalUrl) : 'https://batcaveroadfarm.com/'; ?>">
        <meta property="og:image"       content="<?php echo isset($ogImage) ? htmlspecialchars($ogImage) : "https://batcaveroadfarm.com/img/og-home.jpg"; ?>">
        <?php if (empty($ogImageNoSize)): ?>
        <meta property="og:image:width"  content="<?php echo !empty($ogImageWidth) ? (int)$ogImageWidth : 1200; ?>">
        <meta property="og:image:height" content="<?php echo !empty($ogImageHeight) ? (int)$ogImageHeight : 630; ?>">
        <?php endif; ?>
        <meta property="og:image:alt"   content="Bat Cave Road Farm — Petting Zoo, Farmstand, and Thrift Shop in Old Fort, NC">
        <meta property="og:locale"      content="en_US">
        
        <!-- Twitter / X Card -->
        <meta name="twitter:card"        content="summary_large_image">
        <meta name="twitter:title"       content="<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "Bat Cave Road Farm | Petting Zoo, Farmstand & Thrift Shop — Old Fort, NC"; ?>">
        <meta name="twitter:description" content="<?php echo isset($metaDesc) ? htmlspecialchars($metaDesc) : 'Bat Cave Road Farm in Old Fort, NC — family-owned petting zoo with goats, donkeys & more. Seasonal farmstand, and on-farm thrift shop. Open Tue–Sun 10AM–4PM.'; ?>">
        <meta name="twitter:image"       content="<?php echo isset($ogImage) ? htmlspecialchars($ogImage) : "https://batcaveroadfarm.com/img/og-home.jpg"; ?>">
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
      "@id": "https://batcaveroadfarm.com/#business",
      "name": "Bat Cave Road Farm",
      "alternateName": "Bat Cave Road Petting Zoo",
      "description": "Family-owned farm in Old Fort, NC offering a hands-on petting zoo with goats, pigs, ducks, chickens, peacocks, and donkeys, a seasonal farmstand with fresh vegetables, eggs, honey, and baked goods, and a charming on-farm thrift shop. Near Asheville, Western North Carolina.",
      "url": "https://batcaveroadfarm.com/",
      "logo": "https://batcaveroadfarm.com/img/logo.png",
      "image": [
        "https://batcaveroadfarm.com/img/og-home.jpg",
        "https://batcaveroadfarm.com/img/og-pettingzoo.jpg",
        "https://batcaveroadfarm.com/img/og-farmstand.jpg",
        "https://batcaveroadfarm.com/img/og-thriftshop.jpg"
      ],
      "telephone": "+18282165488",
      "email": "support@batcaveroadfarm.com",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "4132 Bat Cave Road",
        "addressLocality": "Old Fort",
        "addressRegion": "NC",
        "postalCode": "28762",
        "addressCountry": "US"
      },
      "geo": {
        "@type": "GeoCoordinates",
        "latitude": 35.619,
        "longitude": -82.259
      },
      "openingHoursSpecification": [
        {
          "@type": "OpeningHoursSpecification",
          "dayOfWeek": [
            "Tuesday",
            "Wednesday",
            "Thursday",
            "Friday",
            "Saturday",
            "Sunday"
          ],
          "opens": "10:00",
          "closes": "16:00"
        }
      ],
      "currenciesAccepted": "USD",
      "paymentAccepted": "Cash, Credit Card, Debit Card, Venmo, PayPal, CashApp",
      "priceRange": "$",
      "sameAs": [
        "https://www.facebook.com/batcaveroadfarm",
        "https://www.instagram.com/batcaveroadfarm"
      ],
      "hasMap": "https://maps.google.com/?q=4132+Bat+Cave+Road,+Old+Fort,+NC+28762",
      "foundingDate": "2023",
      "keywords": "petting zoo, farmstand, thrift shop, family farm, Old Fort NC, Asheville area farm, farm fresh eggs, farm animals",
      "areaServed": {
        "@type": "GeoCircle",
        "geoMidpoint": {
          "@type": "GeoCoordinates",
          "latitude": 35.619,
          "longitude": -82.259
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
      "@id": "https://batcaveroadfarm.com/#website",
      "url": "https://batcaveroadfarm.com/",
      "name": "Bat Cave Road Farm",
      "publisher": {
        "@id": "https://batcaveroadfarm.com/#business"
      }
    }
  ]
}
</script>