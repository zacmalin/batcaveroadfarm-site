<?php
// admin/notifications.php — the admin bell (Nov 13, 2026; migration 0063; logic in includes/notifications.php).
//   GET  notifications.php            your notifications, newest first (unread in bold)
//   GET  notifications.php?open=ID    marks it read and opens what it's about
//   POST notifications.php            action=read_all: mark everything read
// Everyone signed in to the admin has a bell; what's on it depends on who you are (see includes/notifications.php).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

$uid  = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (isset($_GET['open'])) {
    $n = bcrf_notification_get($pdo, $uid, $role, (int)$_GET['open']);
    if ($n) bcrf_notifications_mark_read($pdo, $uid, $role, (int)$n['id']);
    header('Location: ' . ($n && $n['link'] ? $n['link'] : '/admin/notifications.php'));
    exit;
}
$toast = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $toast = ['err', 'The page was open too long. Try again.'];
    } elseif (($_POST['action'] ?? '') === 'read_all') {
        $c = bcrf_notifications_mark_read($pdo, $uid, $role);
        header('Location: /admin/notifications.php?done=' . $c);
        exit;
    }
}
if (isset($_GET['done'])) $toast = ['success', (int)$_GET['done'] === 0 ? 'Nothing new.' : 'All marked read.'];

$ready = bcrf_notifications_ready($pdo);
$rows = $ready ? bcrf_notifications_list($pdo, $uid, $role) : [];
$unread = count(array_filter($rows, fn($r) => !$r['is_read']));
$icons = ['party_request' => '🎈', 'booking_request' => '🚌', 'online_payment' => '💳', 'deposit_overdue' => '⏰',
          'reminder_sent' => '✉️', 'task_late' => '✅'];
$when = function (string $utc): string {
    $t = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $today = new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));
    if ($t >= $today) return $t->format('g:i A');
    if ($t >= $today->modify('-1 day')) return 'Yesterday ' . $t->format('g:i A');
    return $t->format('M j, g:i A');
};

bcrf_shell_open('Notifications', ['width' => 'max-w-3xl', 'toast' => $toast]);
?>
<div class="flex items-center justify-between gap-3 mb-4">
  <h1 class="text-xl font-black text-stone-800">🔔 Notifications</h1>
  <?php if ($unread > 0): ?>
  <form method="post" action="/admin/notifications.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="action" value="read_all">
    <button type="submit" class="px-3 py-2 rounded-lg bg-stone-800 text-white text-xs font-bold hover:bg-stone-700">Mark all read</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$ready): ?>
  <?= bcrf_shell_notice('warning', 'Notifications are waiting for migration 0063 (Settings › Database).') ?>
<?php elseif (!$rows): ?>
  <div class="bg-white border border-stone-200 rounded-xl p-6 text-center text-stone-500 text-sm">Nothing here yet. New party and booking requests, online payments, overdue deposits and your late tasks show up here.</div>
<?php else: ?>
  <ul class="bg-white border border-stone-200 rounded-xl divide-y divide-stone-100 overflow-hidden">
    <?php foreach ($rows as $r): ?>
    <li>
      <a href="/admin/notifications.php?open=<?= (int)$r['id'] ?>" class="flex items-start gap-3 px-4 py-3 hover:bg-amber-50 <?= $r['is_read'] ? 'text-stone-500' : 'text-stone-900' ?>">
        <span class="text-lg leading-none mt-0.5" aria-hidden="true"><?= $icons[$r['kind']] ?? '🔔' ?></span>
        <span class="flex-1 min-w-0">
          <span class="block text-sm <?= $r['is_read'] ? 'font-normal' : 'font-bold' ?>"><?= $h($r['title']) ?></span>
          <?php if ($r['body']): ?><span class="block text-xs text-stone-500 truncate"><?= $h($r['body']) ?></span><?php endif; ?>
        </span>
        <span class="shrink-0 text-[11px] text-stone-400 whitespace-nowrap"><?= $h($when($r['created_at'])) ?></span>
        <?php if (!$r['is_read']): ?><span class="shrink-0 w-2 h-2 mt-1.5 rounded-full bg-amber-500" title="Unread"></span><?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <p class="mt-3 text-[11px] text-stone-400">Kept for 60 days. Emails about these still go out as before.</p>
<?php endif; ?>
<?php bcrf_shell_close();
