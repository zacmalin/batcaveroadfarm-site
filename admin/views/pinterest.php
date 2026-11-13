<?php
// admin/views/pinterest.php — Pinterest drafts: bulk generator + review (Part A, Nov 5, 2026).
// Opened as the Pinterest tab of Social Queue (?view=social&tab=pinterest; views/social.php includes this
// file), so it shares the "social" permission. Its forms post back to the same address; the global CSRF
// check in admin.php has already run, and admin/handlers/pinterest_action.php does the work and redirects.
// Images come through admin/pinterest_image.php. Nothing on this screen posts to Pinterest; approved Pins
// are sent once the Pinterest connection (Part B) exists.

if ($current_role !== 'administrator' && !hasViewAccess($current_role, 'social')) {
    echo "<p class='text-stone-500 p-6'>Your role does not have access to Social Queue.</p>";
    return;
}
require_once dirname(__DIR__, 2) . '/includes/pinterest.php';
require_once dirname(__DIR__, 2) . '/includes/adapter_pinterest.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pinterest_action'])) {
    try { require dirname(__DIR__) . '/handlers/pinterest_action.php'; }   // redirects when done
    catch (Throwable $ex) {
        error_log('[ERP] Pinterest handler failed: ' . $ex->getMessage());
        $_SESSION['toast_msg'] = 'Pinterest action failed: ' . mb_substr($ex->getMessage(), 0, 160);
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=social&tab=pinterest'); exit;
    }
}
$csrf   = (string)($_SESSION['csrf_token'] ?? '');
$e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$canPub = in_array($current_role, ['administrator', 'moderator'], true);
$ready  = bcrf_pinterest_ready($pdo);

$filters = [
    'drafts'   => ['Drafts',   "t.status IN ('draft','failed')"],
    'approved' => ['Approved', "t.status IN ('approved','scheduled','sending')"],
    'posted'   => ['Posted',   "t.status = 'sent'"],
    'changed'  => ['Changed',  "t.source_changed = 1"],
    'skipped'  => ['Skipped',  "t.status = 'skipped'"],
    'all'      => ['All',      "1 = 1"],
];
$f    = isset($filters[$_GET['f'] ?? '']) ? $_GET['f'] : 'drafts';
$tsel = in_array($_GET['t'] ?? '', ['recipe', 'animal'], true) ? $_GET['t'] : '';
$page = max(1, (int)($_GET['p'] ?? 1));
$per  = 30;

$rows = []; $counts = []; $total = 0; $batch = null; $last = null; $elig = ['recipe' => 0, 'animal' => 0];
if ($ready) {
    foreach ($filters as $k => [, $w]) {
        $counts[$k] = (int)$pdo->query("SELECT COUNT(*) FROM social_queue_targets t WHERE t.platform = 'pinterest' AND $w" . ($tsel ? " AND t.content_type = " . $pdo->quote($tsel) : ''))->fetchColumn();
    }
    $total = $counts[$f];
    $st = $pdo->prepare("SELECT t.* FROM social_queue_targets t WHERE t.platform = 'pinterest' AND {$filters[$f][1]}" . ($tsel ? ' AND t.content_type = ?' : '')
        . " ORDER BY t.updated_at DESC, t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($tsel ? [$tsel] : []);
    $rows  = $st->fetchAll(PDO::FETCH_ASSOC);
    $batch = bcrf_pin_batch_active($pdo);
    $last  = $batch ?: ($pdo->query("SELECT * FROM pinterest_batches ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null);
    foreach (['recipe', 'animal'] as $ty) { try { $elig[$ty] = bcrf_pin_count_sources($pdo, $ty); } catch (Throwable $ex) {} }
}
$boards = $ready ? bcrf_pin_board_names($pdo) : [];
$appOk  = bcrf_pinapi_configured();
$pAuth  = bcrf_pinapi_auth($pdo);
$pOn    = bcrf_pinapi_ready($pdo);
$pBoards = $pOn ? bcrf_pinapi_boards($pdo) : [];
$defBoard = ['recipes' => bcrf_pin_setting($pdo, 'pinterest_board_id_recipes'), 'animals' => bcrf_pin_setting($pdo, 'pinterest_board_id_animals')];
$isAdmin = $current_role === 'administrator';
$url    = fn(array $q) => '/admin/admin.php?' . http_build_query(['view' => 'social', 'tab' => 'pinterest'] + $q);
$hidden = function () use ($csrf, $f, $tsel, $e): string {
    return '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '"><input type="hidden" name="back" value="' . $e($f) . '"><input type="hidden" name="back_type" value="' . $e($tsel) . '">';
};
$badge = function (string $s): string {
    $m = ['draft' => 'bg-amber-100 text-amber-800 border-amber-200', 'approved' => 'bg-sky-100 text-sky-800 border-sky-200',
          'scheduled' => 'bg-sky-100 text-sky-800 border-sky-200', 'sending' => 'bg-sky-100 text-sky-800 border-sky-200',
          'sent' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'failed' => 'bg-red-100 text-red-800 border-red-200',
          'skipped' => 'bg-stone-100 text-stone-600 border-stone-200'];
    $label = $s === 'sent' ? 'posted' : $s;
    return '<span class="text-[9px] font-black uppercase tracking-wider border px-1.5 py-0.5 rounded ' . ($m[$s] ?? $m['skipped']) . '">' . htmlspecialchars($label) . '</span>';
};
$btn  = 'px-3 py-2 rounded border text-[11px] font-bold';
?>
<div class="max-w-6xl">
  <h1 class="text-lg font-serif font-black text-stone-800 mb-1">📌 Pinterest</h1>
  <p class="text-[11px] text-stone-500 mb-4">Drafts made from published recipes and public animals. Bulk drafts never post on their own: approving a draft queues it, and it posts once Pinterest is connected. You can also download an image, pin it by hand and record the link.</p>

  <?php if (!$ready): ?>
    <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-900">
      Not set up yet. In <a class="underline font-bold" href="/admin/migrations.php">Settings › Database</a>, run migration <code>0007_pinterest_drafts</code>, then come back.
    </div>
  </div>
  <?php return; endif; ?>

  <?php $pEnv = (string)($pAuth['env'] ?? ''); $pNeeds = !empty($pAuth['refresh_token_enc']) && (int)$pAuth['needs_reconnect']; ?>
  <div class="border rounded p-3 mb-4 text-xs <?= $pOn ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : ($pNeeds || !$appOk ? 'bg-red-50 border-red-200 text-red-900' : 'bg-stone-50 border-stone-200 text-stone-700') ?>">
    <span class="font-black"><?= $pOn ? '✅ Pinterest connected' : ($pNeeds ? '⛔ Pinterest needs reconnecting' : ($appOk ? '⚪ Pinterest not connected' : '⚠️ Pinterest app keys missing')) ?></span>
    <span class="ml-1"><?php if ($pOn): ?>as <?= $e($pAuth['username'] ?? '?') ?> · <?= $pEnv === 'production' ? 'Production (public Pins)' : 'Sandbox (Pins visible only to you)' ?>. Approved Pins post automatically.<?php
      elseif ($pNeeds): ?><?= $e(mb_substr((string)$pAuth['last_error'], 0, 160)) ?><?php
      elseif ($appOk): ?>Approved Pins wait here until it is connected.<?php
      else: ?>Add PINTEREST_APP_ID and PINTEREST_APP_SECRET to bcrf-secrets.php.<?php endif; ?></span>
    <?php if ($isAdmin && $appOk): ?><a class="underline font-bold ml-2" href="/admin/pinterest_connect.php"><?= $pOn ? 'Settings' : 'Connect' ?></a><?php endif; ?>
    <?php if ($pOn && $canPub && ($counts['approved'] ?? 0) > 0): ?>
      <form method="post" class="inline ml-2"><?= $hidden() ?><button name="pinterest_action" value="send_all" class="underline font-bold">Send all approved</button></form>
    <?php endif; ?>
  </div>

  <!-- Bulk generator -->
  <div class="bg-white border border-stone-200 rounded p-3 mb-4">
    <div class="text-[10px] font-black uppercase tracking-wider text-stone-500 mb-2">Bulk generate drafts</div>
    <?php if ($batch): $pct = $batch['total'] ? min(100, (int)round(100 * $batch['processed'] / max(1, (int)$batch['total']))) : 0; ?>
      <div class="text-xs text-stone-700 mb-2">Batch #<?= (int)$batch['id'] ?> (<?= $e(str_replace(',', ' + ', $batch['content_types'])) ?>) is <strong><?= $e($batch['status']) ?></strong>:
        <?= (int)$batch['processed'] ?> of <?= (int)$batch['total'] ?> ·
        <?= (int)$batch['created_count'] ?> new · <?= (int)$batch['updated_count'] ?> refreshed · <?= (int)$batch['skipped_count'] ?> unchanged/skipped · <span class="<?= $batch['failed_count'] ? 'text-red-700 font-bold' : '' ?>"><?= (int)$batch['failed_count'] ?> failed</span></div>
      <div class="h-2 bg-stone-100 rounded mb-3 overflow-hidden"><div class="h-2 bg-emerald-600" style="width:<?= $pct ?>%"></div></div>
      <div class="flex gap-2 flex-wrap">
        <form method="post"><?= $hidden() ?><input type="hidden" name="pinterest_action" value="run_step"><button class="<?= $btn ?> bg-emerald-700 text-white border-emerald-700">Process next <?= BCRF_PIN_BATCH_CHUNK ?> now</button></form>
        <form method="post"><?= $hidden() ?><input type="hidden" name="pinterest_action" value="cancel_batch"><button class="<?= $btn ?> bg-white text-stone-700 border-stone-300">Cancel batch</button></form>
      </div>
      <p class="text-[10px] text-stone-400 mt-2">The job runner continues it every few minutes on its own. Refresh to see progress.</p>
    <?php else: ?>
      <form method="post" class="flex flex-wrap items-center gap-3">
        <?= $hidden() ?><input type="hidden" name="pinterest_action" value="start_batch">
        <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" name="types[]" value="recipe" checked> Recipes (<?= $elig['recipe'] ?>)</label>
        <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" name="types[]" value="animal" checked> Animals (<?= $elig['animal'] ?>)</label>
        <button class="<?= $btn ?> bg-emerald-700 text-white border-emerald-700">Generate drafts</button>
      </form>
      <p class="text-[10px] text-stone-400 mt-2">Safe to run again: unchanged records are skipped, changed ones are refreshed (your hand edits are kept), and approved or posted Pins are never touched. Bulk runs only make drafts.</p>
    <?php endif; ?>
    <?php if ($last): $log = json_decode((string)$last['log_json'], true) ?: []; ?>
      <details class="mt-2 text-[11px] text-stone-600">
        <summary class="cursor-pointer">Last batch #<?= (int)$last['id'] ?>: <?= $e($last['status']) ?>, <?= (int)$last['processed'] ?>/<?= (int)$last['total'] ?><?= $last['failed_count'] ? ', ' . (int)$last['failed_count'] . ' failed' : '' ?></summary>
        <ul class="mt-1 space-y-0.5 font-mono text-[10px]">
          <?php foreach (array_reverse($log) as $l): ?>
            <li class="<?= $l['r'] === 'failed' ? 'text-red-700' : '' ?>"><?= $e($l['t']) ?> #<?= (int)$l['id'] ?>: <?= $e($l['r']) ?> — <?= $e($l['m']) ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  </div>

  <!-- Filters -->
  <div class="flex flex-wrap gap-1.5 mb-2">
    <?php foreach ($filters as $k => [$lbl]): ?>
      <a href="<?= $e($url(['f' => $k] + ($tsel ? ['t' => $tsel] : []))) ?>" class="px-2.5 py-1.5 rounded-full border text-[11px] font-bold <?= $k === $f ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-600 border-stone-300' ?>"><?= $e($lbl) ?> <span class="opacity-60"><?= $counts[$k] ?? 0 ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="flex gap-3 mb-4 text-[11px]">
    <?php foreach (['' => 'Everything', 'recipe' => '🍴 Recipes', 'animal' => '🐐 Animals'] as $k => $lbl): ?>
      <a href="<?= $e($url(['f' => $f] + ($k ? ['t' => $k] : []))) ?>" class="<?= $k === $tsel ? 'font-black text-stone-900 underline' : 'text-stone-500' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="bg-white border border-stone-200 rounded p-6 text-center text-sm text-stone-500">Nothing here<?= $f === 'drafts' && !$counts['all'] ? ' yet. Tap “Generate drafts” above.' : '.' ?></div>
  <?php endif; ?>

  <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
  <?php foreach ($rows as $r): $rid = (int)$r['id']; $editable = in_array($r['status'], ['draft', 'failed'], true);
        $page_url = preg_replace('/\?.*$/', '', (string)$r['link_url']); ?>
    <div id="pin-<?= $rid ?>" class="bg-white border border-stone-200 rounded overflow-hidden flex flex-col">
      <div class="flex">
        <a href="/admin/pinterest_image.php?id=<?= $rid ?>&v=<?= $e(strtotime((string)$r['updated_at'])) ?>" target="_blank" rel="noopener" class="shrink-0 w-28 sm:w-32 bg-stone-100">
          <?php if ($r['image_path']): ?>
            <img src="/admin/pinterest_image.php?id=<?= $rid ?>&v=<?= $e(strtotime((string)$r['updated_at'])) ?>" alt="<?= $e($r['alt_text']) ?>" loading="lazy" class="w-full aspect-[2/3] object-cover">
          <?php else: ?><div class="w-full aspect-[2/3] flex items-center justify-center text-[10px] text-stone-400">No image</div><?php endif; ?>
        </a>
        <div class="p-2.5 min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-1 mb-1">
            <span class="text-[10px] font-black uppercase tracking-wider text-stone-500"><?= $r['content_type'] === 'recipe' ? '🍴 Recipe' : '🐐 Animal' ?> #<?= (int)$r['content_id'] ?></span>
            <?= $badge($r['status']) ?>
            <?php if ($r['edited_at']): ?><span class="text-[9px] font-bold text-violet-700">✎ edited</span><?php endif; ?>
            <?php if ((int)$r['source_changed']): ?><span class="text-[9px] font-bold text-orange-700">● record changed</span><?php endif; ?>
          </div>
          <div class="text-[12px] font-bold text-stone-800 leading-snug"><?= $e($r['title']) ?></div>
          <div class="text-[11px] text-stone-600 mt-1 line-clamp-4"><?= $e($r['description']) ?></div>
          <?php $bId = $r['board_id'] ?: ($defBoard[$r['board_key']] ?? ''); $bName = $bId !== '' && isset($pBoards[$bId]) ? $pBoards[$bId] : ($boards[$r['board_key']] ?? $r['board_key']); ?>
          <div class="text-[10px] text-stone-400 mt-1">Board: <?= $e($bName) ?><?= $r['board_id'] ? ' (this Pin only)' : '' ?> · <a class="underline" href="<?= $e($page_url) ?>" target="_blank" rel="noopener">page</a></div>
          <?php if ($r['last_error']): ?><div class="text-[10px] text-red-700 mt-1">⚠ <?= $e(mb_substr((string)$r['last_error'], 0, 200)) ?></div><?php endif; ?>
          <?php if ($r['status'] === 'sent' && $r['external_url']): ?><div class="text-[10px] mt-1"><a class="underline text-emerald-700 font-bold" href="<?= $e($r['external_url']) ?>" target="_blank" rel="noopener">View on Pinterest</a></div><?php endif; ?>
        </div>
      </div>

      <div class="border-t border-stone-100 p-2 flex flex-wrap gap-1.5 mt-auto">
        <?php if ($r['status'] === 'draft' && $canPub): ?>
          <form method="post"><?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><button name="pinterest_action" value="approve" class="<?= $btn ?> bg-emerald-700 text-white border-emerald-700">Approve</button></form>
        <?php endif; ?>
        <?php if ($r['status'] === 'approved' && $canPub && $pOn): ?>
          <form method="post"><?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><button name="pinterest_action" value="send" class="<?= $btn ?> bg-emerald-700 text-white border-emerald-700">Send now</button></form>
        <?php endif; ?>
        <?php if (in_array($r['status'], ['approved', 'scheduled'], true) && $canPub): ?>
          <form method="post"><?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><button name="pinterest_action" value="unapprove" class="<?= $btn ?> bg-white text-stone-700 border-stone-300">Back to draft</button></form>
        <?php endif; ?>
        <?php $stuck = $r['status'] === 'sending' && strtotime($r['updated_at'] . ' UTC') < time() - 900;
              if ($canPub && $pOn && ($r['status'] === 'failed' || $stuck)): $careful = $stuck || $r['last_error_code'] === 'ambiguous'; ?>
          <form method="post" class="flex items-center gap-1.5 flex-wrap"><?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>">
            <?php if ($careful): ?><label class="text-[10px] text-stone-600 flex items-center gap-1"><input type="checkbox" name="checked" value="1" required> Checked Pinterest: it's not there</label><?php endif; ?>
            <button name="pinterest_action" value="retry" class="<?= $btn ?> bg-white text-stone-700 border-stone-300">Retry</button></form>
        <?php endif; ?>
        <?php if ($editable): ?>
          <form method="post"><?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><button name="pinterest_action" value="regenerate" class="<?= $btn ?> bg-white text-stone-700 border-stone-300">Regenerate</button></form>
          <form method="post"><?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><button name="pinterest_action" value="skip" class="<?= $btn ?> bg-white text-stone-500 border-stone-200">Skip</button></form>
        <?php elseif ($r['status'] === 'skipped'): ?>
          <form method="post"><?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><button name="pinterest_action" value="unskip" class="<?= $btn ?> bg-white text-stone-700 border-stone-300">Restore</button></form>
        <?php endif; ?>
        <?php if ($r['image_path']): ?>
          <a href="/admin/pinterest_image.php?id=<?= $rid ?>&dl=1" class="<?= $btn ?> bg-white text-stone-700 border-stone-300">⬇ Image</a>
        <?php endif; ?>
      </div>

      <?php if ($editable): ?>
        <details class="border-t border-stone-100 px-2.5 py-2">
          <summary class="text-[11px] font-bold text-stone-600 cursor-pointer">Edit text</summary>
          <form method="post" class="mt-2 space-y-2">
            <?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="pinterest_action" value="save">
            <label class="block text-[10px] font-bold text-stone-500">Title (max 100)
              <input name="title" maxlength="100" required value="<?= $e($r['title']) ?>" class="mt-0.5 w-full border border-stone-300 rounded px-2 py-1.5 text-[12px] font-normal text-stone-800 select-text"></label>
            <label class="block text-[10px] font-bold text-stone-500">Description (max 500)
              <textarea name="description" maxlength="500" rows="5" class="mt-0.5 w-full border border-stone-300 rounded px-2 py-1.5 text-[12px] font-normal text-stone-800 select-text"><?= $e($r['description']) ?></textarea></label>
            <label class="block text-[10px] font-bold text-stone-500">Alt text (describes the photo)
              <input name="alt_text" maxlength="500" value="<?= $e($r['alt_text']) ?>" class="mt-0.5 w-full border border-stone-300 rounded px-2 py-1.5 text-[12px] font-normal text-stone-800 select-text"></label>
            <div class="flex gap-1.5 flex-wrap">
              <button class="<?= $btn ?> bg-stone-800 text-white border-stone-800">Save</button>
              <?php if ($r['edited_at']): ?><button name="pinterest_action" value="reset_text" formnovalidate class="<?= $btn ?> bg-white text-stone-700 border-stone-300">Reset to generated text</button><?php endif; ?>
            </div>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($canPub && $pBoards && in_array($r['status'], ['draft', 'approved', 'failed'], true)): ?>
        <details class="border-t border-stone-100 px-2.5 py-2">
          <summary class="text-[11px] font-bold text-stone-600 cursor-pointer">Board for this Pin</summary>
          <form method="post" class="mt-2 flex gap-1.5">
            <?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="pinterest_action" value="set_board">
            <select name="board_id" class="flex-1 min-w-0 border border-stone-300 rounded px-2 py-1.5 text-[12px] bg-white">
              <option value="">Default for <?= $e($r['board_key']) ?><?= ($defBoard[$r['board_key']] ?? '') !== '' && isset($pBoards[$defBoard[$r['board_key']]]) ? ' (' . $e($pBoards[$defBoard[$r['board_key']]]) . ')' : '' ?></option>
              <?php foreach ($pBoards as $bid => $bn): ?><option value="<?= $e($bid) ?>"<?= (string)$r['board_id'] === (string)$bid ? ' selected' : '' ?>><?= $e($bn) ?></option><?php endforeach; ?>
            </select>
            <button class="<?= $btn ?> bg-stone-800 text-white border-stone-800">Save</button>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($canPub && in_array($r['status'], ['draft', 'approved', 'failed'], true)): ?>
        <details class="border-t border-stone-100 px-2.5 py-2">
          <summary class="text-[11px] font-bold text-stone-600 cursor-pointer">Posted it by hand? Record the Pin</summary>
          <form method="post" class="mt-2 flex gap-1.5">
            <?= $hidden() ?><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="pinterest_action" value="mark_pinned">
            <input name="pin_url" type="url" required placeholder="https://www.pinterest.com/pin/…" class="flex-1 min-w-0 border border-stone-300 rounded px-2 py-1.5 text-[12px] select-text">
            <button class="<?= $btn ?> bg-stone-800 text-white border-stone-800">Save</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>

  <?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
    <div class="flex gap-2 mt-4 text-[11px]">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="<?= $e($url(['f' => $f, 'p' => $i] + ($tsel ? ['t' => $tsel] : []))) ?>" class="px-2.5 py-1.5 rounded border <?= $i === $page ? 'bg-stone-800 text-white border-stone-800' : 'bg-white border-stone-300' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
