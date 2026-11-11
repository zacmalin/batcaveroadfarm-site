<?php
// admin/views/search_analytics.php
// Listings & Analytics (Nov 2026). Top: listings hub — open/closed state, the hours every listing
// should show, and one-tap links to Google, Microsoft and Apple dashboards (Phase 1, Nov 11 2026).
// Below: Search Console + Google Analytics 4 summary. Data: includes/google_reports.php,
// refreshed daily by admin/cron/fetch_google_reports.php or the Refresh button below.
require_once __DIR__ . '/../../includes/google_reports.php';
require_once __DIR__ . '/../../includes/site_info.php';

// ── Listings hub data (no third-party calls: everything here is local) ──
$openNow  = function_exists('site_open_status') ? site_open_status() : ['open' => false, 'label' => 'Unknown', 'detail' => ''];
$hoursStr = function_exists('site_hours_compact') ? site_hours_compact() : '';
$gcfg     = bcrf_greports_config();
$farmUrl  = rtrim(bcrf_farm_url(), '/');
$hub = [
    ['Google', 'Google account', [
        ['Business Profile', 'Search & Maps views, calls, direction requests', 'https://business.google.com/'],
        ['Analytics (GA4)',  'Website visits and key events', $gcfg['property_id'] !== ''
            ? 'https://analytics.google.com/analytics/web/#/p' . $gcfg['property_id'] . '/reports/intelligenthome'
            : 'https://analytics.google.com/analytics/web/'],
        ['Search Console',   'Searches, clicks and indexing', 'https://search.google.com/search-console?resource_id=' . rawurlencode($gcfg['gsc_site'])],
    ]],
    ['Microsoft', 'Microsoft account (or Google sign-in)', [
        ['Bing Places',          'The Bing / Bing Maps listing', 'https://www.bing.com/forbusiness'],
        ['Bing Webmaster Tools', 'Bing searches and clicks for the website', 'https://www.bing.com/webmasters/home?siteUrl=' . rawurlencode($farmUrl . '/')],
    ]],
    ['Apple', 'Apple ID', [
        ['Apple Business', 'Apple Maps place card and Insights', 'https://business.apple.com/'],
    ]],
];

$rep     = bcrf_greports_cached($pdo);
$missing = bcrf_greports_missing();
$gsc     = $rep['gsc']['data'];
$ga      = $rep['ga']['data'];
$csrf    = $_SESSION['csrf_token'] ?? '';

$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$n   = fn($v) => number_format((float)$v);
$pct = fn($v) => number_format((float)$v * 100, 1) . '%';
$pos = fn($v) => number_format((float)$v, 1);
$local = function (?string $utc): string {
    if (!$utc) return 'never';
    $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone('America/New_York'));
    return $d->format('M j, g:i A');
};
$fmtRange = fn(array $r) => date('M j', strtotime($r[0])) . ' – ' . date('M j', strtotime($r[1]));
// Change vs previous 28 days. $lowerBetter for average position.
$delta = function ($cur, $prev, bool $lowerBetter = false, bool $isRate = false): string {
    $cur = (float)$cur; $prev = (float)$prev;
    if ($prev == 0.0) return '<span class="text-stone-400">new</span>';
    $ch = $isRate ? ($cur - $prev) : (($cur - $prev) / $prev);
    if (abs($ch) < ($isRate ? 0.05 : 0.005)) return '<span class="text-stone-400">±0</span>';
    $good = $lowerBetter ? $ch < 0 : $ch > 0;
    $txt  = $isRate ? (($ch > 0 ? '+' : '') . number_format($ch, 1)) : (($ch > 0 ? '+' : '') . number_format($ch * 100, 0) . '%');
    return '<span class="' . ($good ? 'text-green-700' : 'text-red-700') . ' font-semibold">' . $txt . '</span>';
};
$path = fn(string $url) => preg_replace('#^https?://(www\.)?batcaveroadfarm\.com#', '', $url) ?: '/';

// Daily clicks sparkline (inline SVG, no JavaScript)
$spark = function (array $daily): string {
    usort($daily, fn($a, $b) => strcmp($a['date'], $b['date']));
    $vals = array_map(fn($d) => (int)$d['clicks'], $daily);
    if (count($vals) < 2) return '';
    $max = max(1, max($vals)); $w = 300; $hgt = 48; $step = $w / (count($vals) - 1);
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($i * $step, 1) . ',' . round($hgt - ($v / $max) * ($hgt - 4) - 2, 1);
    return '<svg viewBox="0 0 ' . $w . ' ' . $hgt . '" class="w-full h-12" role="img" aria-label="Daily clicks from Google search, last 28 days">'
         . '<polyline fill="none" stroke="#4a6741" stroke-width="2" points="' . implode(' ', $pts) . '"/></svg>';
};

// "Almost page 1": queries ranking 4–20 with real impressions. Better titles/content there pay off fastest.
$almost = [];
foreach ($gsc['queries'] ?? [] as $q) if ($q['position'] >= 4 && $q['position'] <= 20 && $q['impressions'] >= 20) $almost[] = $q;
usort($almost, fn($a, $b) => $b['impressions'] <=> $a['impressions']);
// Low click rate on pages that show up a lot: title/description worth rewriting.
$lowCtr = [];
foreach ($gsc['pages'] ?? [] as $p) if ($p['impressions'] >= 100 && $p['ctr'] < 0.02 && $p['position'] <= 15) $lowCtr[] = $p;

$eventLabels = ['purchase' => 'Purchases', 'begin_checkout' => 'Started checkout', 'generate_lead' => 'Leads (forms)', 'sign_up' => 'Account sign-ups',
    'job_application' => 'Job applications', 'newsletter_signup' => 'Newsletter sign-ups', 'click_to_call' => 'Tapped to call',
    'get_directions' => 'Directions clicks', 'recipe_to_stand' => 'Recipe → farm stand', 'recipe_to_subrecipe' => 'Recipe → sub-recipe', 'share' => 'Shares'];
?>
<div class="max-w-5xl mx-auto space-y-5 text-sm">

  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-stone-800">📊 Listings &amp; Analytics</h1>
      <p class="text-xs text-stone-500 mt-0.5">Listing dashboards in one tap, then Google Search Console and Google Analytics, last 28 days vs the 28 before.
        Updated: Search <?= $h($local($rep['gsc']['fetched_at'])) ?> · Analytics <?= $h($local($rep['ga']['fetched_at'])) ?>.</p>
    </div>
    <form method="POST" action="/admin/admin.php?view=search_analytics">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <input type="hidden" name="google_reports_action" value="refresh">
      <button type="submit" class="bg-stone-900 text-white font-bold px-4 py-2 rounded hover:bg-stone-700">↻ Refresh now</button>
    </form>
  </div>

  <!-- ── LISTINGS HUB ── -->
  <section class="bg-white border rounded-lg p-4 space-y-4" aria-labelledby="hubTitle">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 id="hubTitle" class="font-bold text-stone-800 text-base">Listings</h2>
      <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold <?= $openNow['open'] ? 'bg-green-100 text-green-800' : 'bg-stone-200 text-stone-700' ?>">
        <span aria-hidden="true"><?= $openNow['open'] ? '●' : '○' ?></span>
        <?= $h($openNow['label']) ?><?= $openNow['detail'] !== '' ? ' · ' . $h($openNow['detail']) : '' ?>
      </span>
    </div>
    <?php if ($hoursStr !== ''): ?>
    <p class="text-xs text-stone-600">Every listing should show: <strong><?= $h($hoursStr) ?></strong> (from Settings › Hours &amp; Contacts).</p>
    <?php endif; ?>

    <div class="grid gap-3 md:grid-cols-3">
      <?php foreach ($hub as [$group, $login, $links]): ?>
      <div class="border rounded-lg p-3 bg-stone-50">
        <div class="flex items-baseline justify-between gap-2 mb-2">
          <h3 class="font-bold text-stone-800 text-sm"><?= $h($group) ?></h3>
          <span class="text-[10px] text-stone-500">Sign in: <?= $h($login) ?></span>
        </div>
        <ul class="space-y-1.5">
          <?php foreach ($links as [$label, $what, $href]): ?>
          <li>
            <a href="<?= $h($href) ?>" target="_blank" rel="noopener noreferrer"
               class="flex items-center justify-between gap-2 rounded border bg-white px-3 py-2.5 hover:border-stone-500 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-stone-500">
              <span><span class="block font-bold text-stone-800"><?= $h($label) ?></span>
                    <span class="block text-[11px] text-stone-500"><?= $h($what) ?></span></span>
              <span class="text-stone-400 shrink-0" aria-hidden="true">↗</span>
              <span class="sr-only">(opens in a new tab)</span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="text-[11px] text-stone-500">Monthly check (the 1st): name, address, phone, hours and map pin on Apple and Bing match Google.
      Who owns each account, the second admin and 2FA are in the password manager and the Notion account inventory.
      Apple and Bing have no data feed for a single business, so their numbers stay on their own dashboards.</p>
  </section>

  <?php if ($missing): ?>
  <div class="p-4 rounded border border-amber-300 bg-amber-50 text-amber-900">
    <p class="font-bold mb-1">Setup needed</p>
    <ul class="list-disc ml-5 space-y-0.5"><?php foreach ($missing as $m): ?><li><?= $h($m) ?></li><?php endforeach; ?></ul>
    <p class="mt-2 text-xs">Steps are in <code>docs/GOOGLE-REPORTS.md</code> in the GitHub repo.</p>
  </div>
  <?php endif; ?>
  <?php foreach (['gsc' => 'Search Console', 'ga' => 'Analytics'] as $k => $label): if ($rep[$k]['last_error']): ?>
  <div class="p-3 rounded border border-red-300 bg-red-50 text-red-800 text-xs"><strong><?= $label ?> refresh failed</strong> (<?= $h($local($rep[$k]['error_at'])) ?>): <?= $h($rep[$k]['last_error']) ?></div>
  <?php endif; endforeach; ?>

  <!-- ── SEARCH CONSOLE ── -->
  <section class="bg-white border rounded-lg p-4 space-y-4">
    <div class="flex justify-between items-baseline flex-wrap gap-2">
      <h2 class="font-bold text-stone-800 text-base">Google Search</h2>
      <?php if ($gsc): ?><span class="text-xs text-stone-500"><?= $h($fmtRange($gsc['range']['cur'])) ?> (Search Console runs ~3 days behind)</span><?php endif; ?>
    </div>
    <?php if (!$gsc): ?>
      <p class="text-stone-500 italic">No Search Console data yet.</p>
    <?php else: ?>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <?php foreach ([['Clicks', $n($gsc['total']['clicks']), $delta($gsc['total']['clicks'], $gsc['prev']['clicks'])],
                        ['Impressions', $n($gsc['total']['impressions']), $delta($gsc['total']['impressions'], $gsc['prev']['impressions'])],
                        ['Click rate', $pct($gsc['total']['ctr']), $delta($gsc['total']['ctr'] * 100, $gsc['prev']['ctr'] * 100, false, true)],
                        ['Avg. position', $pos($gsc['total']['position']), $delta($gsc['total']['position'], $gsc['prev']['position'], true, true)]] as [$l, $v, $d]): ?>
        <div class="border rounded p-3 bg-stone-50"><div class="text-[11px] uppercase tracking-wide text-stone-500"><?= $l ?></div>
          <div class="text-xl font-bold text-stone-800"><?= $v ?></div><div class="text-xs"><?= $d ?> vs prior 28 days</div></div>
        <?php endforeach; ?>
      </div>
      <?= $spark($gsc['daily'] ?? []) ?>

      <?php if ($almost): ?>
      <div class="p-3 rounded bg-green-50 border border-green-200">
        <p class="font-bold text-green-900 text-xs mb-1">Almost on page 1: searches where you rank 4–20 (a better title, description or more content can move these up)</p>
        <ul class="text-xs text-green-900 space-y-0.5"><?php foreach (array_slice($almost, 0, 8) as $q): ?>
          <li>“<?= $h($q['query']) ?>” · position <?= $pos($q['position']) ?> · <?= $n($q['impressions']) ?> impressions · <?= $pct($q['ctr']) ?> clicked</li>
        <?php endforeach; ?></ul>
      </div>
      <?php endif; ?>
      <?php if ($lowCtr): ?>
      <div class="p-3 rounded bg-amber-50 border border-amber-200">
        <p class="font-bold text-amber-900 text-xs mb-1">Seen a lot, clicked little (under 2%): rewrite these pages' titles and meta descriptions</p>
        <ul class="text-xs text-amber-900 space-y-0.5"><?php foreach ($lowCtr as $p): ?>
          <li><a class="underline" href="<?= $h($p['page']) ?>" target="_blank" rel="noopener"><?= $h($path($p['page'])) ?></a> · <?= $n($p['impressions']) ?> impressions · <?= $pct($p['ctr']) ?> · position <?= $pos($p['position']) ?></li>
        <?php endforeach; ?></ul>
      </div>
      <?php endif; ?>

      <div class="grid md:grid-cols-2 gap-4">
        <div class="overflow-x-auto"><h3 class="font-bold text-stone-700 text-xs mb-1">Top searches</h3>
          <table class="w-full text-xs"><thead><tr class="text-left text-stone-500 border-b"><th class="py-1 pr-2">Search</th><th class="text-right">Clicks</th><th class="text-right">Impr.</th><th class="text-right pl-2">Pos.</th></tr></thead><tbody>
          <?php foreach ($gsc['queries'] as $q): ?><tr class="border-b border-stone-100"><td class="py-1 pr-2"><?= $h($q['query']) ?></td><td class="text-right"><?= $n($q['clicks']) ?></td><td class="text-right"><?= $n($q['impressions']) ?></td><td class="text-right pl-2"><?= $pos($q['position']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <div class="overflow-x-auto"><h3 class="font-bold text-stone-700 text-xs mb-1">Top pages</h3>
          <table class="w-full text-xs"><thead><tr class="text-left text-stone-500 border-b"><th class="py-1 pr-2">Page</th><th class="text-right">Clicks</th><th class="text-right">Impr.</th><th class="text-right pl-2">CTR</th></tr></thead><tbody>
          <?php foreach ($gsc['pages'] as $p): ?><tr class="border-b border-stone-100"><td class="py-1 pr-2 break-all"><?= $h($path($p['page'])) ?></td><td class="text-right"><?= $n($p['clicks']) ?></td><td class="text-right"><?= $n($p['impressions']) ?></td><td class="text-right pl-2"><?= $pct($p['ctr']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
      </div>

      <?php if (!empty($gsc['sitemaps'])): ?>
      <div class="text-xs text-stone-600"><span class="font-bold">Sitemaps:</span>
        <?php foreach ($gsc['sitemaps'] as $s): ?>
          <?= $h($path($s['path'])) ?> read by Google <?= $s['last_downloaded'] ? $h(date('M j', strtotime($s['last_downloaded']))) : 'not yet' ?><?= $s['errors'] ? ', <span class="text-red-700 font-bold">' . (int)$s['errors'] . ' error' . ($s['errors'] > 1 ? 's' : '') . '</span>' : '' ?><?= $s['warnings'] ? ', ' . (int)$s['warnings'] . ' warning' . ($s['warnings'] > 1 ? 's' : '') : '' ?><?= $s['pending'] ? ' (processing)' : '' ?>.
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- ── GOOGLE ANALYTICS ── -->
  <section class="bg-white border rounded-lg p-4 space-y-4">
    <div class="flex justify-between items-baseline flex-wrap gap-2">
      <h2 class="font-bold text-stone-800 text-base">Website visits (Google Analytics)</h2>
      <?php if ($ga): ?><span class="text-xs text-stone-500"><?= $h($fmtRange($ga['range']['cur'])) ?> · staff visits excluded</span><?php endif; ?>
    </div>
    <?php if (!$ga): ?>
      <p class="text-stone-500 italic">No Analytics data yet.</p>
    <?php else: ?>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <?php foreach ([['Visitors', $n($ga['total']['activeUsers'] ?? 0), $delta($ga['total']['activeUsers'] ?? 0, $ga['prev']['activeUsers'] ?? 0)],
                        ['Visits', $n($ga['total']['sessions'] ?? 0), $delta($ga['total']['sessions'] ?? 0, $ga['prev']['sessions'] ?? 0)],
                        ['Key events', $n($ga['total']['keyEvents'] ?? 0), $delta($ga['total']['keyEvents'] ?? 0, $ga['prev']['keyEvents'] ?? 0)],
                        ['Online sales', '$' . number_format((float)($ga['total']['purchaseRevenue'] ?? 0), 2), $delta($ga['total']['purchaseRevenue'] ?? 0, $ga['prev']['purchaseRevenue'] ?? 0)]] as [$l, $v, $d]): ?>
        <div class="border rounded p-3 bg-stone-50"><div class="text-[11px] uppercase tracking-wide text-stone-500"><?= $l ?></div>
          <div class="text-xl font-bold text-stone-800"><?= $v ?></div><div class="text-xs"><?= $d ?> vs prior 28 days</div></div>
        <?php endforeach; ?>
      </div>

      <div class="grid md:grid-cols-2 gap-4">
        <div class="overflow-x-auto"><h3 class="font-bold text-stone-700 text-xs mb-1">Where visits come from</h3>
          <table class="w-full text-xs"><thead><tr class="text-left text-stone-500 border-b"><th class="py-1 pr-2">Channel</th><th class="text-right">Visits</th><th class="text-right pl-2">Key events</th></tr></thead><tbody>
          <?php foreach ($ga['channels'] as $c): ?><tr class="border-b border-stone-100"><td class="py-1 pr-2"><?= $h($c['sessionDefaultChannelGroup']) ?></td><td class="text-right"><?= $n($c['sessions']) ?></td><td class="text-right pl-2"><?= $n($c['keyEvents']) ?></td></tr><?php endforeach; ?>
          </tbody></table>
          <h3 class="font-bold text-stone-700 text-xs mt-3 mb-1">Top sources</h3>
          <table class="w-full text-xs"><tbody>
          <?php foreach ($ga['sources'] as $s): ?><tr class="border-b border-stone-100"><td class="py-1 pr-2 break-all"><?= $h($s['sessionSourceMedium']) ?></td><td class="text-right"><?= $n($s['sessions']) ?></td><td class="text-right pl-2"><?= $n($s['keyEvents']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <div class="overflow-x-auto"><h3 class="font-bold text-stone-700 text-xs mb-1">What visitors did</h3>
          <table class="w-full text-xs"><tbody>
          <?php if (!$ga['events']): ?><tr><td class="py-1 text-stone-500 italic">None of the tracked actions yet.</td></tr><?php endif; ?>
          <?php foreach ($ga['events'] as $e): ?><tr class="border-b border-stone-100"><td class="py-1 pr-2"><?= $h($eventLabels[$e['eventName']] ?? $e['eventName']) ?></td><td class="text-right"><?= $n($e['eventCount']) ?></td></tr><?php endforeach; ?>
          </tbody></table>
          <h3 class="font-bold text-stone-700 text-xs mt-3 mb-1">Top landing pages</h3>
          <table class="w-full text-xs"><tbody>
          <?php foreach ($ga['landing'] as $l): ?><tr class="border-b border-stone-100"><td class="py-1 pr-2 break-all"><?= $h($l['landingPage'] ?: '/') ?></td><td class="text-right"><?= $n($l['sessions']) ?></td><td class="text-right pl-2"><?= $n($l['keyEvents']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
      </div>
    <?php endif; ?>
  </section>
</div>
