<?php
    require('../config.php');
    require_once('../includes/events.php');

    $pageTitle    = "Farm Events & Seasonal Happenings | Bat Cave Road Farm";
    $metaDesc     = "Upcoming events at Bat Cave Road Farm in Old Fort, NC — seasonal celebrations, markets, and family farm days near Asheville. Open Tue–Sun, 10AM–4PM.";
    $canonicalUrl = "https://batcaveroadfarm.com/events/";
    $ogImage      = "https://batcaveroadfarm.com/img/og-home.jpg";

    $events = bcrf_events_fetch_upcoming($pdo, 50);

    require('../includes/head.php');
    include('../includes/header.php');

    // ── Structured data: CollectionPage + Event nodes ──
    $__graph = [[
        '@type'    => 'CollectionPage',
        '@id'      => 'https://batcaveroadfarm.com/events/#collection',
        'url'      => 'https://batcaveroadfarm.com/events/',
        'name'     => $pageTitle,
        'isPartOf' => ['@id' => 'https://batcaveroadfarm.com/#website'],
        'about'    => ['@id' => 'https://batcaveroadfarm.com/#business'],
    ]];
    foreach ($events as $__ev) {
        $__node = bcrf_event_schema($__ev);
        if ($__node) $__graph[] = $__node;
    }
    echo '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $__graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
?>

        <div id="page-events" class="page1">
          <div class="hero hero-photo">
            <span class="hero-deco" aria-hidden="true">📅</span>
            <div class="hero-content">
              <h1>Farm Events</h1>
              <p>Seasonal celebrations, markets, and family days down on the farm.</p>
            </div>
          </div>

          <div class="content-wrap">
            <?= bcrf_events_render($events) ?>

            <section class="events-host" aria-label="Host your own event">
              <h2 class="visit-section-title">Host your own gathering</h2>
              <p>We welcome birthday parties, school field trips, homeschool groups, and family reunions — guided tours, animal feeding, and private barn access for groups up to 20. <a href="/private-events/">Tell us about your event</a> and we'll put together a custom package, or <a href="/plan-your-visit/">plan a regular visit</a>.</p>
            </section>
          </div>
        </div>

<?php require('../includes/footer.php'); ?>
