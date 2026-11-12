<?php
// admin/calendar.php — Farm OS master calendar (Phase 3, Nov 12, 2026; includes/calendar.php; 12 › Master calendar).
//   GET  calendar.php[?view=list|month&date=Y-m-d&l[]=layer…]   day list (7 days, the default; best on phones) or month
//   POST act=feed_make / feed_reset    make or replace my phone calendar link (the new link shows once)
//        act=feed_events               parties and group bookings in my feed too, on or off
//        act=feed_off                  turn my feed off (the link stops working)
// Layers follow each source's own permission, so nobody sees items they couldn't open. Editing happens on the source page.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'calendar']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';
require_once __DIR__ . '/../includes/tasks.php';
require_once __DIR__ . '/../includes/calendar.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(bcrf_cal_tz()->getName());

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$feedReady = bcrf_cal_feed_ready($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = 'calendar.php#feed';
    $act = (string)($_POST['act'] ?? '');
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['cal_flash'] = ['err', 'Security token expired; try again.'];
    } elseif (!$feedReady || $uid <= 0) {
        $_SESSION['cal_flash'] = ['err', 'Run migration 0034 in Settings › Database first.'];
    } else {
        try {
            if ($act === 'feed_make' || $act === 'feed_reset') {
                $token = bcrf_cal_feed_reset($pdo, $uid, $act === 'feed_make' ? true : null);
                $_SESSION['cal_new_feed'] = bcrf_cal_feed_url($token);
                $_SESSION['cal_flash'] = ['ok', $act === 'feed_make' ? 'Your calendar link is ready.' : 'New link made. The old one has stopped working.'];
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'calendar_feeds', $uid, 'Phone calendar link ' . ($act === 'feed_make' ? 'made' : 'reset'));
            } elseif ($act === 'feed_events') {
                bcrf_cal_feed_set_events($pdo, $uid, ($_POST['on'] ?? '') === '1');
                $_SESSION['cal_flash'] = ['ok', 'Saved. Your phone picks it up within a few hours.'];
            } elseif ($act === 'feed_off') {
                bcrf_cal_feed_delete($pdo, $uid);
                $_SESSION['cal_flash'] = ['ok', 'Your phone calendar link is off.'];
                if (function_exists('erp_audit')) erp_audit($pdo, 'delete', 'calendar_feeds', $uid, 'Phone calendar link turned off');
            }
        } catch (Throwable $e) {
            error_log('[calendar] ' . $act . ': ' . $e->getMessage());
            $_SESSION['cal_flash'] = ['err', 'Could not do that. Try again.'];
        }
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['cal_flash'] ?? null; unset($_SESSION['cal_flash']);
$newFeed = (string)($_SESSION['cal_new_feed'] ?? ''); unset($_SESSION['cal_new_feed']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$today = bcrf_cal_today();
$view = ($_GET['view'] ?? '') === 'month' ? 'month' : 'list';
$date = (string)($_GET['date'] ?? $today);
if (!bcrf_cal_valid_date($date)) $date = $today;
$allowed = bcrf_cal_layers_for($role);
$picked = array_values(array_intersect((array)($_GET['l'] ?? []), $allowed));
$layers = isset($_GET['l']) ? $picked : $allowed;   // first visit: every layer you can see
$viewer = ['user_id' => $uid, 'role' => $role, 'manager' => function_exists('bcrf_tasks_is_manager') && bcrf_tasks_is_manager()];

if ($view === 'month') {
    $first = (new DateTimeImmutable(substr($date, 0, 8) . '01'));
    $gridStart = $first->modify('-' . (int)$first->format('w') . ' days');
    $gridEnd = $first->modify('last day of this month');
    $gridEnd = $gridEnd->modify('+' . (6 - (int)$gridEnd->format('w')) . ' days');
    $from = $gridStart->format('Y-m-d'); $to = $gridEnd->format('Y-m-d');
    $prev = $first->modify('-1 month')->format('Y-m-d'); $next = $first->modify('+1 month')->format('Y-m-d');
    $heading = $first->format('F Y');
} else {
    $from = $date; $to = (new DateTimeImmutable($date))->modify('+6 days')->format('Y-m-d');
    $prev = (new DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d'); $next = (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');
    $heading = date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
}
$items = bcrf_cal_items($pdo, $from, $to, $layers, $viewer);
$byDay = [];
foreach ($items as $it) $byDay[$it['date']][] = $it;
$feed = $feedReady && $uid > 0 ? bcrf_cal_feed_get($pdo, $uid) : null;

$tag = [   // layer colours (dot and label)
    'parties' => 'bg-pink-100 text-pink-800 border-pink-300', 'bookings' => 'bg-sky-100 text-sky-800 border-sky-300',
    'events' => 'bg-violet-100 text-violet-800 border-violet-300', 'tasks' => 'bg-amber-100 text-amber-900 border-amber-300',
    'orders' => 'bg-teal-100 text-teal-800 border-teal-300', 'interviews' => 'bg-indigo-100 text-indigo-800 border-indigo-300',
    'closures' => 'bg-stone-200 text-stone-700 border-stone-400',
];
$q = function (array $over) use ($view, $date, $layers) {
    $p = array_merge(['view' => $view, 'date' => $date], $over);
    $s = http_build_query(['view' => $p['view'], 'date' => $p['date']]);
    foreach ($layers as $l) $s .= '&l%5B%5D=' . rawurlencode($l);
    return 'calendar.php?' . $s;
};
$time = function (array $it): string {   // "9:30–11 AM", "11:30 AM–1 PM", "2 PM"
    if ($it['all_day']) return 'All day';
    $f = fn($ts) => date((int)date('i', $ts) ? 'g:i' : 'g', $ts);
    $s = strtotime($it['start']);
    if (!$it['end'] || substr($it['end'], 0, 10) !== $it['date']) return $f($s) . ' ' . date('A', $s);
    $e = strtotime($it['end']);
    return date('A', $s) === date('A', $e) ? $f($s) . '–' . $f($e) . ' ' . date('A', $e) : $f($s) . ' ' . date('A', $s) . '–' . $f($e) . ' ' . date('A', $e);
};
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$btnOn   = 'px-3 py-2 rounded text-sm border border-stone-800 bg-stone-800 text-white';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';

bcrf_shell_open('Calendar', ['toast' => $toast, 'width' => 'max-w-5xl', 'js' => ['quotes.js']]);
?>
  <div class="flex flex-wrap items-end justify-between gap-2 mb-3">
    <div>
      <h1 class="text-2xl font-bold text-stone-800">Calendar</h1>
      <p class="text-sm text-stone-500">Everything on the farm in one place. Tap an item to open it.</p>
    </div>
    <div class="flex gap-1">
      <a href="<?= $h($q(['view' => 'list'])) ?>" class="<?= $view === 'list' ? $btnOn : $btnSec ?>">Week list</a>
      <a href="<?= $h($q(['view' => 'month'])) ?>" class="<?= $view === 'month' ? $btnOn : $btnSec ?>">Month</a>
    </div>
  </div>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <form method="GET" action="calendar.php" class="<?= $card ?> flex flex-wrap gap-x-4 gap-y-2 items-center">
    <input type="hidden" name="view" value="<?= $h($view) ?>"><input type="hidden" name="date" value="<?= $h($date) ?>"><input type="hidden" name="l[]" value="">
    <?php foreach ($allowed as $l): ?>
      <label class="flex items-center gap-1.5 text-sm text-stone-700"><input type="checkbox" name="l[]" value="<?= $h($l) ?>" <?= in_array($l, $layers, true) ? 'checked' : '' ?>>
        <span class="inline-block w-2.5 h-2.5 rounded-full border <?= $tag[$l] ?>"></span><?= $h(BCRF_CAL_LAYERS[$l]['label']) ?></label>
    <?php endforeach; ?>
    <button class="<?= $btnSec ?>">Show</button>
  </form>

  <div class="flex flex-wrap items-center gap-2 mb-3">
    <a href="<?= $h($q(['date' => $prev])) ?>" class="<?= $btnSec ?>" aria-label="Earlier">‹</a>
    <a href="<?= $h($q(['date' => $today])) ?>" class="<?= $btnSec ?>">Today</a>
    <a href="<?= $h($q(['date' => $next])) ?>" class="<?= $btnSec ?>" aria-label="Later">›</a>
    <h2 class="text-lg font-semibold text-stone-800 ml-1"><?= $h($heading) ?></h2>
  </div>

  <?php if ($view === 'list'): ?>
  <div class="<?= $card ?> p-0 overflow-hidden">
    <?php for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')): $ymd = $d->format('Y-m-d'); $hours = bcrf_cal_hours($pdo, $ymd); ?>
    <section class="border-b border-stone-100 last:border-b-0">
      <div class="flex justify-between gap-2 px-4 py-2 <?= $ymd === $today ? 'bg-amber-50' : ($hours === '' ? 'bg-stone-100' : 'bg-stone-50') ?>">
        <h3 class="text-sm font-semibold text-stone-800"><?= $h($d->format('l, M j')) ?><?= $ymd === $today ? ' · Today' : '' ?></h3>
        <span class="text-xs <?= $hours === '' ? 'text-stone-500' : 'text-emerald-700' ?>"><?= $hours === '' ? 'Closed to visitors' : 'Open ' . $h($hours) ?></span>
      </div>
      <?php if (empty($byDay[$ymd])): ?>
        <p class="px-4 py-2 text-sm text-stone-400">Nothing scheduled.</p>
      <?php else: ?>
      <ul class="divide-y divide-stone-100">
        <?php foreach ($byDay[$ymd] as $it): ?>
        <li><a href="<?= $h($it['url']) ?>" class="flex gap-3 px-4 py-2 hover:bg-stone-50">
          <span class="w-24 shrink-0 text-xs text-stone-500 pt-0.5"><?= $h($time($it)) ?></span>
          <span class="min-w-0">
            <span class="inline-block text-[11px] px-1.5 rounded border <?= $tag[$it['layer']] ?>"><?= $h(BCRF_CAL_LAYERS[$it['layer']]['label']) ?></span>
            <span class="block text-sm text-stone-800 <?= $it['tentative'] ? 'italic' : 'font-medium' ?>"><?= $h($it['title']) ?><?= $it['tentative'] ? ' (not confirmed)' : '' ?></span>
            <?php if ($it['note'] !== ''): ?><span class="block text-xs text-stone-500"><?= $h($it['note']) ?></span><?php endif; ?>
          </span>
        </a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <?php endfor; ?>
  </div>

  <?php else: ?>
  <div class="<?= $card ?> p-2">
    <div class="grid grid-cols-7 text-center text-[11px] font-semibold text-stone-500 mb-1">
      <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dn): ?><div><?= $dn ?></div><?php endforeach; ?>
    </div>
    <div class="grid grid-cols-7 gap-px bg-stone-200 border border-stone-200 rounded overflow-hidden">
      <?php for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')): $ymd = $d->format('Y-m-d'); $inMonth = $d->format('m') === substr($date, 5, 2); $dayItems = $byDay[$ymd] ?? []; $closed = bcrf_cal_hours($pdo, $ymd) === ''; ?>
      <a href="<?= $h($q(['view' => 'list', 'date' => $ymd])) ?>" class="block min-h-16 md:min-h-24 p-1 <?= $ymd === $today ? 'bg-amber-50' : ($closed ? 'bg-stone-50' : 'bg-white') ?> hover:bg-stone-100">
        <span class="block text-xs <?= $inMonth ? 'text-stone-800 font-semibold' : 'text-stone-400' ?>"><?= (int)$d->format('j') ?></span>
        <span class="flex flex-wrap gap-0.5 md:hidden"><?php foreach (array_slice($dayItems, 0, 6) as $it): ?><span class="w-2 h-2 rounded-full border <?= $tag[$it['layer']] ?>"></span><?php endforeach; ?></span>
        <span class="hidden md:block">
          <?php foreach (array_slice($dayItems, 0, 3) as $it): ?><span class="block truncate text-[11px] px-1 mb-0.5 rounded border <?= $tag[$it['layer']] ?>"><?= $it['all_day'] ? '' : $h(date('g:ia', strtotime($it['start']))) . ' ' ?><?= $h($it['title']) ?></span><?php endforeach; ?>
          <?php if (count($dayItems) > 3): ?><span class="block text-[11px] text-stone-500">+<?= count($dayItems) - 3 ?> more</span><?php endif; ?>
        </span>
      </a>
      <?php endfor; ?>
    </div>
    <p class="text-xs text-stone-500 mt-2">Tap a day to see it in the week list. Grey days are closed to visitors.</p>
  </div>
  <?php endif; ?>

  <div class="<?= $card ?>" id="feed">
    <h2 class="text-base font-semibold text-stone-800 mb-1">On your phone</h2>
    <?php if (!$feedReady): ?>
      <p class="text-sm text-stone-500">Run migration 0034 in Settings › Database to turn on phone calendar links.</p>
    <?php elseif ($newFeed !== ''): ?>
      <p class="text-sm text-stone-700 mb-2">Here's your private link. It's shown only this once, so add it now. Anyone with it can see your calendar, so don't share it.</p>
      <input readonly value="<?= $h($newFeed) ?>" aria-label="Your calendar link" class="w-full p-2 border border-stone-300 rounded bg-stone-50 text-sm font-mono select-all mb-2">
      <div class="flex flex-wrap gap-2 mb-3">
        <a href="<?= $h(preg_replace('#^https://#', 'webcal://', $newFeed)) ?>" class="<?= $btnMain ?>">Add to iPhone calendar</a>
        <a href="https://calendar.google.com/calendar/r?cid=<?= $h(rawurlencode(preg_replace('#^https://#', 'webcal://', $newFeed))) ?>" class="<?= $btnSec ?>" rel="noopener">Add to Google Calendar</a>
      </div>
      <p class="text-xs text-stone-500">Your phone checks for changes every hour or so. It's read-only: changes are made here, not on the phone.</p>
    <?php elseif (!$feed): ?>
      <p class="text-sm text-stone-600 mb-3">See your tasks, parties and group bookings in your phone's own calendar app. It updates by itself.</p>
      <form method="POST" action="calendar.php"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <button name="act" value="feed_make" class="<?= $btnMain ?>">Make my calendar link</button></form>
    <?php else: ?>
      <p class="text-sm text-stone-600 mb-2">Your phone calendar link is on<?= $feed['last_used_at'] ? ', last checked ' . $h((new DateTimeImmutable($feed['last_used_at'], new DateTimeZone('UTC')))->setTimezone(bcrf_cal_tz())->format('M j, g:i A')) : ' (no phone has used it yet)' ?>. Lost it, or want to add it to another phone? Make a new one; the old one stops working.</p>
      <form method="POST" action="calendar.php" class="mb-3"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="feed_events">
        <input type="hidden" name="on" value="<?= $feed['with_events'] ? '0' : '1' ?>">
        <p class="text-sm text-stone-700"><?= $feed['with_events'] ? 'It shows your tasks, parties, group bookings and closed days.' : 'It shows your tasks and closed days only.' ?>
          <button class="underline text-amber-800"><?= $feed['with_events'] ? 'Show only my tasks' : 'Add parties and group bookings' ?></button></p>
      </form>
      <form method="POST" action="calendar.php" class="flex flex-wrap gap-2"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <button name="act" value="feed_reset" class="<?= $btnSec ?>" data-confirm="Make a new link? The old one stops working on every phone that has it.">Make a new link</button>
        <button name="act" value="feed_off" class="<?= $btnSec ?>" data-confirm="Turn off your phone calendar link?">Turn off</button>
      </form>
    <?php endif; ?>
  </div>
<?php
bcrf_shell_close();
