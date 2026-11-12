<?php
// admin/weather.php — Weather day (Farm OS Phase 3 slice 5, Nov 12, 2026; includes/weather_days.php; 07 › Bookings).
//   GET  weather.php[?date=Y-m-d]   that day's group bookings and parties, the message, and (once called off) what was
//                                    done for each one
//   POST weather.php  act=call      email everyone on the day not told yet; optionally close the day to new bookings
//                     act=outcome   rescheduled / refunded / credit / cancelled / open, with a note
// Permission: Bookings or Private Events to look; calling a day off and outcomes need an Owner or Manager.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'bookings'], ['view', 'event_inquiries']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';
require_once __DIR__ . '/../includes/weather_days.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$mgr = bcrf_quote_is_manager();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_weather_ready($pdo)) {
    bcrf_shell_open('Weather day');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Weather day</h1>',
         bcrf_shell_notice('warning', 'Run migration 0033 in Settings › Database first.'),
         '<a href="/admin/migrations.php" class="text-sm text-amber-800 underline">Open Settings › Database</a>';
    bcrf_shell_close();
    exit;
}

$today = date('Y-m-d');
$date = (string)($_REQUEST['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) $date = $today;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = 'weather.php?date=' . rawurlencode($date);
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) { $_SESSION['w_flash'] = ['err', 'Security token expired; try again.']; header('Location: ' . $back); exit; }
    $act = (string)($_POST['act'] ?? '');
    try {
        if (!$mgr) throw new BcrfWeatherError('Only an Owner or Manager can do that.');
        if ($act === 'call') {
            $msg = (string)($_POST['message'] ?? '');
            if (!empty($_POST['save_default'])) {
                $clean = trim(str_replace("\r", '', strip_tags($msg)));
                if ($clean !== '') $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('weather_message', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$clean]);
            }
            $r = bcrf_weather_call($pdo, $date, $msg, !empty($_POST['close_day']), $uid);
            $bits = [$r['told'] . ' customer' . ($r['told'] === 1 ? '' : 's') . ' emailed.'];
            if ($r['closed']) $bits[] = 'The day is closed to new bookings.';
            if ($r['no_email']) $bits[] = 'No email for ' . implode(', ', $r['no_email']) . ': call them.';
            if ($r['failed']) $bits[] = 'Didn\'t send to ' . implode(', ', $r['failed']) . '. Press Send again to retry.';
            $_SESSION['w_flash'] = [$r['failed'] ? 'err' : 'ok', implode(' ', $bits)];
        } elseif ($act === 'outcome') {
            bcrf_weather_set_outcome($pdo, (int)($_POST['item_id'] ?? 0), (string)($_POST['outcome'] ?? ''), (string)($_POST['note'] ?? ''), $uid);
            $_SESSION['w_flash'] = ['ok', 'Saved.'];
        } else {
            throw new BcrfWeatherError('Unknown action.');
        }
    } catch (BcrfWeatherError $e) {
        $_SESSION['w_flash'] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[weather] ' . $act . ': ' . $e->getMessage());
        $_SESSION['w_flash'] = ['err', 'Could not do that. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['w_flash'] ?? null; unset($_SESSION['w_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$day = bcrf_weather_day($pdo, $date);
$told = [];
if ($day) foreach ($day['items'] as $it) if ($it['sent_at'] || $it['email'] === '') $told[$it['subject_type'] . ':' . $it['subject_id']] = true;   // no email: listed above to call
$subjects = bcrf_weather_subjects($pdo, $date);
$untold = array_values(array_filter($subjects, fn($s) => empty($told[$s['type'] . ':' . $s['id']])));
$st = $pdo->prepare("SELECT 1 FROM booking_blackouts WHERE d_date = ?");
$st->execute([$date]);
$isClosed = (bool)$st->fetchColumn();
$past = $date < $today;

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$link = fn(string $type, int $id, string $date) => $type === 'booking' ? '/admin/admin.php?view=bookings&day=' . rawurlencode($date) : '/admin/admin.php?view=event_inquiries&status=all';

bcrf_shell_open('Weather day', ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Weather day</h1>
  <p class="text-sm text-stone-500 mb-4">Call off a day: email every group booking and party on it at once, and close it to new bookings. Then note what you did for each one.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <form method="GET" action="weather.php" class="flex flex-wrap gap-2 items-end mb-4">
    <div><label for="w_date" class="<?= $label ?>">Day</label><input id="w_date" type="date" name="date" value="<?= $h($date) ?>" class="<?= $input ?>"></div>
    <button class="<?= $btnSec ?>">Show</button>
    <a href="?date=<?= $h($today) ?>" class="<?= $btnSec ?>">Today</a>
    <a href="?date=<?= $h(date('Y-m-d', strtotime('+1 day'))) ?>" class="<?= $btnSec ?>">Tomorrow</a>
  </form>

  <h2 class="text-lg font-semibold text-stone-800 mb-2"><?= $h(date('l, F j, Y', strtotime($date))) ?></h2>
  <?php if ($isClosed) echo bcrf_shell_notice('warning', 'This day is closed to new bookings (Bookings › blackout dates).'); ?>

  <?php if ($day): ?>
  <div class="<?= $card ?>">
    <h3 class="text-base font-semibold text-stone-800 mb-1">Called off</h3>
    <p class="text-sm text-stone-600 mb-3">Sent <?= $h((new DateTimeImmutable($day['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, g:i A')) ?>. Mark what you did for each one; refunds are done in Quotes &amp; payments, new dates on the booking or party.</p>
    <div class="divide-y divide-stone-100">
    <?php foreach ($day['items'] as $it): ?>
      <div class="py-3">
        <div class="flex flex-wrap justify-between gap-2 text-sm">
          <span><strong class="text-stone-800"><?= $h($it['name']) ?></strong> <span class="text-stone-500">· <?= $h($it['what']) ?> · <?= $h($it['status']) ?><?= $it['now_date'] && $it['now_date'] !== $date ? ' · now ' . $h(date('M j', strtotime($it['now_date']))) : '' ?></span></span>
          <span class="text-xs <?= $it['sent_at'] ? 'text-emerald-700' : 'text-red-700' ?>"><?= $it['sent_at'] ? 'Emailed ' . $h($it['email']) : $h($it['send_error'] ?: 'Not emailed') ?></span>
        </div>
        <form method="POST" action="weather.php" class="flex flex-wrap gap-2 items-end mt-2">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="date" value="<?= $h($date) ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
          <div><label class="<?= $label ?>" for="wo<?= (int)$it['id'] ?>">What happened</label>
            <select id="wo<?= (int)$it['id'] ?>" name="outcome" class="<?= $input ?>" <?= $mgr ? '' : 'disabled' ?>>
              <?php foreach (BCRF_WEATHER_OUTCOMES as $k => $v): ?><option value="<?= $h($k) ?>" <?= $it['outcome'] === $k ? 'selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?>
            </select></div>
          <div class="grow"><label class="<?= $label ?>" for="wn<?= (int)$it['id'] ?>">Note</label><input id="wn<?= (int)$it['id'] ?>" name="note" maxlength="255" value="<?= $h($it['outcome_note']) ?>" class="<?= $input ?>" placeholder="e.g. moved to Dec 12" <?= $mgr ? '' : 'disabled' ?>></div>
          <?php if ($mgr): ?><button name="act" value="outcome" class="<?= $btnSec ?>">Save</button><?php endif; ?>
          <a class="text-xs underline text-stone-500" href="<?= $h($link($it['subject_type'], (int)$it['subject_id'], $date)) ?>">Open</a>
          <a class="text-xs underline text-stone-500" href="/admin/quotes.php?for=<?= $h($it['subject_type'] . ':' . (int)$it['subject_id']) ?>">Quote &amp; payments</a>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$day['items']): ?><p class="text-sm text-stone-500">Nobody was booked that day.</p><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="<?= $card ?>">
    <h3 class="text-base font-semibold text-stone-800 mb-1"><?= $day ? 'Not told yet' : 'Booked that day' ?></h3>
    <?php if (!$untold): ?>
      <p class="text-sm text-stone-500"><?= $day ? 'Everyone booked that day has been told.' : 'No group bookings or parties that day.' ?> Online day tickets are good any open day, so they need nothing.</p>
    <?php else: ?>
      <ul class="text-sm divide-y divide-stone-100 mb-3">
        <?php foreach ($untold as $s): ?>
        <li class="py-2 flex flex-wrap justify-between gap-2"><span><strong class="text-stone-800"><?= $h($s['name']) ?></strong> <span class="text-stone-500">· <?= $h($s['what']) ?> · <?= $h($s['when']) ?> · <?= (int)$s['guests'] ?> guests · <?= $h($s['status']) ?></span></span>
          <span class="text-xs <?= $s['email'] ? 'text-stone-500' : 'text-red-700' ?>"><?= $s['email'] ? $h($s['email']) : 'No email: call them' ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($past): ?>
        <p class="text-sm text-stone-500">That day has passed.</p>
      <?php elseif (!$mgr): ?>
        <p class="text-sm text-stone-500">Only an Owner or Manager can call off a day.</p>
      <?php else: ?>
      <form method="POST" action="weather.php" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="date" value="<?= $h($date) ?>">
        <div><label for="w_msg" class="<?= $label ?>">Message <span class="font-normal text-stone-400">each email also says which booking it's about, with the date and time</span></label>
          <textarea id="w_msg" name="message" rows="6" maxlength="3000" required class="<?= $input ?>"><?= $h($day['message'] ?? bcrf_weather_default_message($pdo)) ?></textarea></div>
        <label class="flex items-center gap-2 text-sm text-stone-700"><input type="checkbox" name="close_day" value="1" <?= $isClosed ? 'checked disabled' : 'checked' ?>> Close this day to new bookings</label>
        <label class="flex items-center gap-2 text-sm text-stone-700"><input type="checkbox" name="save_default" value="1"> Save this wording for next time</label>
        <p class="text-xs text-stone-500">Nothing is cancelled or refunded automatically. Online day tickets are good any open day, so they aren't emailed.</p>
        <?php $withEmail = count(array_filter($untold, fn($s) => $s['email'] !== '')); ?>
        <button name="act" value="call" class="<?= $btnMain ?>" data-confirm="Email <?= $withEmail ?> customer<?= $withEmail === 1 ? '' : 's' ?> that <?= $h(date('l, M j', strtotime($date))) ?> is called off for weather?">Email <?= $withEmail ?> customer<?= $withEmail === 1 ? '' : 's' ?></button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php $recent = bcrf_weather_recent($pdo); if ($recent): ?>
  <div class="<?= $card ?>">
    <h3 class="text-base font-semibold text-stone-800 mb-2">Days called off</h3>
    <ul class="text-sm divide-y divide-stone-100">
      <?php foreach ($recent as $r): ?>
      <li class="py-2"><a class="underline" href="?date=<?= $h($r['business_date']) ?>"><?= $h(date('D, M j, Y', strtotime($r['business_date']))) ?></a>
        <span class="text-stone-500"> · <?= (int)$r['told'] ?> told<?= (int)$r['open_items'] ? ' · ' . (int)$r['open_items'] . ' not sorted yet' : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php
bcrf_shell_close();
