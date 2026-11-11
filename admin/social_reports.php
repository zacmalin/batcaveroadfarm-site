<?php
// admin/social_reports.php — Reports › Social Media (Nov 11, 2026). Step 1: Meta access check.
// Calls each Facebook/Instagram read endpoint the dashboard would need with the stored Page token and
// shows which ones Meta allows, plus the scopes on the token. Read-only; the token is never shown.
// Pinterest stays gated (Trial access only). Card: "Social analytics dashboard" (due Dec 9).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'social']]);
require_once __DIR__ . '/../includes/admin_shell.php';
require_once __DIR__ . '/../includes/adapter_meta.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

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

$want = ['pages_read_engagement', 'read_insights', 'instagram_basic', 'instagram_manage_insights'];

bcrf_shell_open('Social Media — Reports', ['width' => 'max-w-4xl']);
?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Social Media</h1>
  <p class="text-sm text-stone-500 mb-4">Facebook and Instagram numbers for the Petting Zoo page. Pinterest waits on Standard access.</p>

  <section class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4">
    <div class="flex items-center justify-between gap-3 mb-2">
      <h2 class="text-base font-semibold text-stone-800">Meta access check</h2>
      <a href="?check=1" class="px-4 py-1.5 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold">Run check</a>
    </div>
    <?php if (!bcrf_meta_ready()): ?>
      <p class="text-sm text-red-700">FB_PAGE_ID / FB_PAGE_TOKEN aren't set in bcrf-secrets.php.</p>
    <?php elseif (!$run): ?>
      <p class="text-sm text-stone-500">Asks Facebook which numbers this site's token can read. Takes a few seconds.</p>
    <?php else: ?>
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
  </section>
<?php
bcrf_shell_close();
