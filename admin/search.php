<?php
// admin/search.php — Farm OS global search (02 › Search; Nov 13, 2026). Logic: includes/search.php.
//   GET search.php?q=…            the search page (works without JavaScript)
//   GET search.php?api=1&q=…      JSON for the live results (admin/assets/search.js)
// Searches only what the signed-in person can open. Codes (ticket, receipt, refund, gift card, pass link, lot) jump straight
// to the record. Opened from the sidebar, the More sheet (or a pinned shortcut) on phones, and "/" on a keyboard.
// No search history on the server; the page keeps this session's recent searches in the browser only.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_once __DIR__ . '/../includes/search.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

$q   = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
$can = bcrf_search_can_for((string)($_SESSION['user_role'] ?? ''));
$res = bcrf_search($pdo, $q, $can);

if (($_GET['api'] ?? '') !== '') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true] + $res);
    exit;
}
// A scanned or typed code opens its record (Enter on the form, or a link with ?q=CODE&go=1)
if ($res['jump'] && isset($_GET['go'])) { header('Location: ' . $res['jump']['url']); exit; }

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
bcrf_shell_open('Search', ['width' => 'max-w-3xl', 'css' => ['search.css'], 'js' => ['search.js']]);
?>
<div class="srch">
  <form class="srch-form" method="get" action="/admin/search.php" role="search">
    <input type="hidden" name="go" value="1">
    <label for="srchBox" class="srch-label">Search everything</label>
    <div class="srch-row">
      <input id="srchBox" class="srch-box" type="search" name="q" value="<?= $h($q) ?>" autocomplete="off" autofocus
             enterkeyhint="search" placeholder="Name, email, phone, ticket code, receipt…" aria-describedby="srchHint">
      <button type="submit" class="srch-go">Search</button>
    </div>
    <p id="srchHint" class="srch-hint">Scan or type a ticket code, receipt or refund number, gift card, pass link or lot code to open it.</p>
  </form>

  <div id="srchRecent" class="srch-recent" hidden></div>
  <div id="srchOut" aria-live="polite">
<?php if ($q !== '' && mb_strlen($q) < 2): ?>
    <p class="srch-empty">Type at least 2 letters.</p>
<?php elseif ($q !== ''): ?>
<?php if ($res['jump']): ?>
    <a class="srch-jump" href="<?= $h($res['jump']['url']) ?>">Open <?= $h($res['jump']['label']) ?> →</a>
<?php endif; ?>
<?php if (!$res['groups'] && !$res['jump']): ?>
    <p class="srch-empty">Nothing found for “<?= $h($q) ?>” in the records you can open.</p>
<?php endif; ?>
<?php foreach ($res['groups'] as $g): ?>
    <section class="srch-group">
      <h2 class="srch-gh"><?= $g['icon'] ?> <?= $h($g['label']) ?></h2>
      <ul class="srch-list">
<?php foreach ($g['rows'] as $r): ?>
        <li><a class="srch-item" href="<?= $h($r['url']) ?>"><span class="srch-t"><?= $h($r['title']) ?></span><?php if ($r['sub'] !== ''): ?><span class="srch-s"><?= $h($r['sub']) ?></span><?php endif; ?></a></li>
<?php endforeach; ?>
      </ul>
<?php if ($g['more']): ?>
      <a class="srch-more" href="<?= $h($g['more']) ?>">See all <?= $h(mb_strtolower($g['label'])) ?> →</a>
<?php endif; ?>
    </section>
<?php endforeach; ?>
<?php endif; ?>
  </div>
</div>
<?php
bcrf_shell_close();
