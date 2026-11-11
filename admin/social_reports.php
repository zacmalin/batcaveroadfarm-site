<?php
// admin/social_reports.php — Reports › Social Media (Nov 11, 2026).
// Facebook Page + Instagram numbers from social_metrics_daily / social_posts (filled daily by
// admin/cron/fetch_social_reports.php via includes/social_reports.php, or the Refresh button).
// Below the dashboard: the Meta access check, which calls each endpoint live with the stored Page token
// and shows what Meta allows (the token itself is never shown). Pinterest stays gated (Trial access only).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'social']]);
require_once __DIR__ . '/../includes/admin_shell.php';
require_once __DIR__ . '/../includes/adapter_meta.php';
require_once __DIR__ . '/../includes/social_reports.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf  = $_SESSION['csrf_token'];
$ready = bcrf_socrep_tables_ready($pdo);

// Refresh now (POST → redirect → GET)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $msg = ['err', 'Your session expired. Try again.'];
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? '')) && $ready) {
        $last = bcrf_socrep_status($pdo)['fetched_at'] ?? null;
        if ($last && strtotime($last) > time() - 120) $msg = ['warning', 'Refreshed less than 2 minutes ago.'];
        else {
            @set_time_limit(120);
            $r = bcrf_socrep_refresh($pdo);
            $msg = ($r['facebook'] === 'ok' && $r['instagram'] === 'ok') ? ['ok', 'Social numbers refreshed.']
                 : ['warning', 'Refreshed with problems — Facebook: ' . $r['facebook'] . ' · Instagram: ' . $r['instagram']];
        }
    }
    $_SESSION['socrep_flash'] = $msg;
    header('Location: /admin/social_reports.php?days=' . (int)($_POST['days'] ?? 28));
    exit;
}
$toast = $_SESSION['socrep_flash'] ?? null; unset($_SESSION['socrep_flash']);

// What the dashboard wants, and which permission Meta asks for. Run only when "Run check" is pressed.
$probes = [];
$run = isset($_GET['check']);
$scopes = null; $scopeErr = '';
if ($run && bcrf_meta_ready()) {
    $tok  = FB_PAGE_TOKEN;
    $page = (string)FB_PAGE_ID;
    $ig   = bcrf_meta_ig_user();
    $call = function (string $path, array $p = []) use ($tok) {
        $r = bcrf_meta_request('GET', $path, $p + ['access_token' => $tok], 20);
        $ok = $r['code'] >= 200 && $r['code'] < 300 && empty($r['json']['error']);
        return [$ok, $ok ? $r['json'] : bcrf_meta_error($r)];
    };
    $add = function (string $group, string $what, string $needs, array $res) use (&$probes) {
        [$ok, $out] = $res;
        if ($ok && is_array($out)) unset($out['paging']);   // paging links carry the access token — never show them
        $sample = $ok ? substr(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 300) : (string)$out;
        $sample = preg_replace('/access_token=[^&"\s]+/', 'access_token=…', $sample);   // belt and braces
        $probes[] = compact('group', 'what', 'needs', 'ok', 'sample');
    };

    // Scopes on the token (needs the app secret)
    if (defined('META_APP_ID') && META_APP_ID !== '' && defined('META_APP_SECRET') && META_APP_SECRET !== '') {
        $r = bcrf_meta_request('GET', '/debug_token', ['input_token' => $tok, 'access_token' => META_APP_ID . '|' . META_APP_SECRET], 20);
        if (!empty($r['json']['data'])) $scopes = (array)($r['json']['data']['scopes'] ?? []);
        else $scopeErr = bcrf_meta_error($r);
    } else $scopeErr = 'META_APP_ID / META_APP_SECRET not set';

    // Facebook Page
    $add('Facebook', 'Page followers / likes', 'pages_read_engagement', $call("/$page", ['fields' => 'name,followers_count,fan_count']));
    $add('Facebook', 'Recent posts with reactions, comments, shares', 'pages_read_engagement',
        $call("/$page/posts", ['fields' => 'id,created_time,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares', 'limit' => 3]));
    foreach (['page_media_view', 'page_post_engagements', 'page_daily_follows_unique', 'page_impressions_unique'] as $m) {
        $add('Facebook', "Page insight: $m (last 7 days)", 'read_insights + pages_read_engagement',
            $call("/$page/insights", ['metric' => $m, 'period' => 'day', 'date_preset' => 'last_7d']));
    }
    $fp = $call("/$page/posts", ['fields' => 'id', 'limit' => 1]);
    if ($fp[0] && !empty($fp[1]['data'][0]['id'])) {
        $add('Facebook', 'Post insight: post_media_view on newest post', 'read_insights',
            $call('/' . $fp[1]['data'][0]['id'] . '/insights', ['metric' => 'post_media_view,post_clicks']));
    }

    // Instagram
    if ($ig) {
        $add('Instagram', 'Account followers / media count', 'instagram_basic', $call("/$ig", ['fields' => 'username,followers_count,media_count']));
        $media = $call("/$ig/media", ['fields' => 'id,timestamp,media_type,like_count,comments_count', 'limit' => 3]);
        $add('Instagram', 'Recent media with likes / comments', 'instagram_basic', $media);
        $add('Instagram', 'Account insight: reach (daily)', 'instagram_manage_insights',
            $call("/$ig/insights", ['metric' => 'reach', 'period' => 'day']));
        $add('Instagram', 'Account insight: views, accounts_engaged, total_interactions', 'instagram_manage_insights',
            $call("/$ig/insights", ['metric' => 'views,accounts_engaged,total_interactions', 'metric_type' => 'total_value', 'period' => 'day']));
        $add('Instagram', 'Account insight: follower_count (daily)', 'instagram_manage_insights',
            $call("/$ig/insights", ['metric' => 'follower_count', 'period' => 'day']));
        if ($media[0] && !empty($media[1]['data'][0]['id'])) {
            $add('Instagram', 'Media insight on newest post: reach, views, saved, shares', 'instagram_manage_insights',
                $call('/' . $media[1]['data'][0]['id'] . '/insights', ['metric' => 'reach,views,saved,shares,total_interactions']));
        }
    } else {
        $probes[] = ['group' => 'Instagram', 'what' => 'IG_USER_ID', 'needs' => '—', 'ok' => false, 'sample' => 'IG_USER_ID is not set in bcrf-secrets.php'];
    }
}

$want = ['pages_read_engagement', 'pages_read_user_content', 'read_insights', 'instagram_basic', 'instagram_manage_insights'];

// ── Dashboard data ───────────────────────────────────────────────────────────
$days = in_array((int)($_GET['days'] ?? 28), [7, 28, 90], true) ? (int)$_GET['days'] : 28;
$tzPT = new DateTimeZone('America/Los_Angeles');                                  // Meta's day boundary
$to   = (new DateTimeImmutable('yesterday', $tzPT));
$from = $to->modify('-' . ($days - 1) . ' day');
$pTo  = $from->modify('-1 day'); $pFrom = $pTo->modify('-' . ($days - 1) . ' day');
$dayList = []; for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) $dayList[] = $d->format('Y-m-d');
$cards = []; $posts = []; $status = [];
if ($ready) {
    $cur  = bcrf_socrep_series($pdo, $from->format('Y-m-d'), (new DateTimeImmutable('today', $tzPT))->format('Y-m-d'));
    $prev = bcrf_socrep_series($pdo, $pFrom->format('Y-m-d'), $pTo->format('Y-m-d'));
    foreach (bcrf_socrep_metrics() as $net => $metrics) {
        foreach ($metrics as $m => [$label, $agg]) {
            $vals = $cur[$net][$m] ?? [];
            if ($agg === 'last') {
                if (!$vals) { $lv = $pdo->prepare('SELECT value FROM social_metrics_daily WHERE network = ? AND metric = ? ORDER BY day DESC LIMIT 1');
                              $lv->execute([$net, $m]); $v = $lv->fetchColumn(); $total = $v === false ? null : (int)$v; }
                else $total = end($vals);
                $prior = null; $series = [];
                foreach ($dayList as $d) if (isset($vals[$d])) $series[] = $vals[$d];
            } else {
                $series = array_map(fn($d) => $vals[$d] ?? 0, $dayList);
                $total  = $vals ? array_sum(array_intersect_key($vals, array_flip($dayList))) : null;
                $prior  = !empty($prev[$net][$m]) ? array_sum($prev[$net][$m]) : null;
            }
            $cards[$net][] = compact('label', 'total', 'prior', 'series');
        }
    }
    $st = $pdo->prepare('SELECT * FROM social_posts WHERE posted_at >= ? ORDER BY posted_at DESC LIMIT 40');
    $st->execute([$from->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    $posts  = $st->fetchAll(PDO::FETCH_ASSOC);
    $status = bcrf_socrep_status($pdo);
}
$fmt = fn($n) => $n === null ? '—' : number_format((int)$n);
$spark = function (array $vals): string {   // SVG sparkline (geometry attributes only: fine under the strict policy)
    if (count($vals) < 2) return '';
    $w = 120; $hgt = 28; $max = max(max($vals), 1); $n = count($vals) - 1; $pts = [];
    foreach (array_values($vals) as $i => $v) $pts[] = round($i * $w / $n, 1) . ',' . round($hgt - 2 - ($v / $max) * ($hgt - 4), 1);
    return '<svg viewBox="0 0 ' . $w . ' ' . $hgt . '" class="w-full h-7 mt-1" aria-hidden="true"><polyline fill="none" stroke="#b45309" stroke-width="1.5" points="' . implode(' ', $pts) . '"/></svg>';
};
$delta = function ($cur, $prior): string {
    if ($cur === null || $prior === null || $prior == 0) return '';
    $p = round(($cur - $prior) / $prior * 100);
    return '<span class="' . ($p >= 0 ? 'text-green-700' : 'text-red-700') . '">' . ($p >= 0 ? '▲' : '▼') . ' ' . abs($p) . '%</span>';
};


bcrf_shell_open('Social Media — Reports', ['width' => 'max-w-4xl', 'toast' => $toast]);
?>
  <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
    <div>
      <h1 class="text-2xl font-bold text-stone-800 mb-1">Social Media</h1>
      <p class="text-sm text-stone-500">Petting Zoo Facebook page and @batcaveroadfarm on Instagram. Pinterest waits on Standard access.</p>
    </div>
    <?php if ($ready): ?>
    <form method="POST" class="flex items-center gap-2">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="days" value="<?= $days ?>">
      <button class="px-4 py-1.5 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold">Refresh now</button>
    </form>
    <?php endif; ?>
  </div>

<?php if (!$ready): ?>
  <section class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4">
    <p class="text-sm text-red-700">Run migration 0026 in Settings › Database first, then come back and tap Refresh now.</p>
  </section>
<?php else: ?>
  <nav class="flex flex-wrap items-center gap-2 mb-2 text-sm" aria-label="Period">
    <?php foreach ([7, 28, 90] as $o): ?>
      <a href="?days=<?= $o ?>" class="px-3 py-1 rounded border <?= $o === $days ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-700 border-stone-300' ?>"><?= $o ?> days</a>
    <?php endforeach; ?>
    <span class="text-xs text-stone-500"><?= $h($from->format('M j')) ?> – <?= $h($to->format('M j, Y')) ?> · vs the <?= $days ?> days before</span>
  </nav>
  <p class="text-xs text-stone-500 mb-4">
    Last refresh: <?= !empty($status['fetched_at']) ? $h(date('M j, g:i A', strtotime($status['fetched_at']))) : 'never' ?>
    <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $n => $lab): if (!empty($status[$n]) && $status[$n] !== 'ok'): ?>
      <br><span class="text-red-700"><?= $lab ?>: <?= $h($status[$n]) ?></span>
    <?php endif; endforeach; ?>
  </p>

  <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $net => $lab): ?>
  <h2 class="text-base font-semibold text-stone-800 mb-2"><?= $lab ?></h2>
  <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4">
    <?php foreach ($cards[$net] ?? [] as $c): ?>
      <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm">
        <div class="text-xs text-stone-500"><?= $h($c['label']) ?></div>
        <div class="text-2xl font-bold text-stone-800"><?= $fmt($c['total']) ?></div>
        <div class="text-xs"><?= $delta($c['total'], $c['prior']) ?></div>
        <?= $spark($c['series']) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <p class="text-xs text-stone-500 -mt-2 mb-4">Instagram "Accounts reached" adds up each day's reach, so someone seen on two days counts twice. Followers start building history from the first refresh.</p>

  <section class="bg-white border border-stone-200 rounded-lg shadow-sm mb-4">
    <h2 class="text-base font-semibold text-stone-800 px-4 pt-3 pb-2">Posts in this period</h2>
    <?php if (!$posts): ?>
      <p class="text-sm text-stone-500 px-4 pb-4">No posts in this period yet.</p>
    <?php else: ?>
    <ul class="divide-y divide-stone-200">
      <?php foreach ($posts as $p): $ig = $p['network'] === 'instagram'; ?>
        <li class="px-4 py-2">
          <div class="flex items-baseline justify-between gap-2">
            <span class="text-xs font-semibold <?= $ig ? 'text-amber-800' : 'text-stone-700' ?>"><?= $ig ? 'Instagram' : 'Facebook' ?><?= $p['kind'] ? ' · ' . $h(str_replace('_', ' ', $p['kind'])) : '' ?></span>
            <span class="text-xs text-stone-500 shrink-0"><?= $h(date('M j, g:i A', strtotime($p['posted_at'] . ' UTC'))) ?></span>
          </div>
          <div class="text-sm text-stone-800 truncate">
            <?php if ($p['permalink']): ?><a class="underline decoration-stone-300 hover:text-amber-800" href="<?= $h($p['permalink']) ?>" target="_blank" rel="noopener"><?= $h($p['caption'] ?: '(no caption)') ?></a>
            <?php else: ?><?= $h($p['caption'] ?: '(no caption)') ?><?php endif; ?>
          </div>
          <div class="flex flex-wrap gap-x-3 text-xs text-stone-600">
            <?php foreach (['views' => 'views', 'reach' => 'reached', 'likes' => $ig ? 'likes' : 'reactions', 'comments' => 'comments', 'shares' => 'shares', 'saves' => 'saves'] as $k => $w):
                  if ($p[$k] === null) continue; ?>
              <span><b class="text-stone-800"><?= $fmt($p[$k]) ?></b> <?= $w ?></span>
            <?php endforeach; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>

  <details class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4"<?= $run ? ' open' : '' ?>>
    <summary class="text-base font-semibold text-stone-800 cursor-pointer">Meta access check</summary>
    <div class="flex items-center justify-between gap-3 my-2">
      <p class="text-sm text-stone-500">Asks Facebook which numbers this site's token can read. Run it after making a new token.</p>
      <a href="?check=1" class="px-4 py-1.5 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold shrink-0">Run check</a>
    </div>
    <?php if (!bcrf_meta_ready()): ?>
      <p class="text-sm text-red-700">FB_PAGE_ID / FB_PAGE_TOKEN aren't set in bcrf-secrets.php.</p>
    <?php elseif ($run): ?>
      <h3 class="text-sm font-semibold text-stone-700 mt-2 mb-1">Permissions on the token</h3>
      <?php if ($scopes === null): ?>
        <p class="text-sm text-red-700">Couldn't read scopes: <?= $h($scopeErr) ?></p>
      <?php else: ?>
        <ul class="text-sm space-y-0.5 mb-1">
          <?php foreach ($want as $w): $has = in_array($w, $scopes, true); ?>
            <li class="<?= $has ? 'text-green-700' : 'text-red-700' ?>"><?= $has ? '✓' : '✗' ?> <?= $h($w) ?></li>
          <?php endforeach; ?>
        </ul>
        <p class="text-xs text-stone-500 break-words">All scopes: <?= $h(implode(', ', $scopes)) ?></p>
      <?php endif; ?>

      <h3 class="text-sm font-semibold text-stone-700 mt-4 mb-1">Endpoints</h3>
      <div class="space-y-2">
        <?php foreach ($probes as $p): ?>
          <div class="border rounded p-2 <?= $p['ok'] ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' ?>">
            <div class="text-sm font-semibold <?= $p['ok'] ? 'text-green-800' : 'text-red-800' ?>"><?= $p['ok'] ? '✓' : '✗' ?> <?= $h($p['group']) ?> — <?= $h($p['what']) ?></div>
            <div class="text-xs text-stone-500">Needs: <?= $h($p['needs']) ?></div>
            <div class="text-xs text-stone-700 break-all font-mono mt-1"><?= $h($p['sample']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </details>
<?php
bcrf_shell_close();
