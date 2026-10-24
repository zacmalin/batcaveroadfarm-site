<?php
// admin/views/jobs/emails.php
// Hiring > Emails (Jobs pack P5). Three tabs:
//   queue      held emails (Not Selected) — Send, Edit, Cancel, one at a time or in bulk
//   templates  edit subject + body with live preview, merge-tag list, test send, reset
//   log        every hiring email sent or failed
// Administrators only. Saves go to admin/handlers/jobs_emails.php (POST jobs_emails_action).
require_once __DIR__ . '/../../../includes/jobs/emails.php';

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$fmt  = fn($d, $f = 'M j, g:ia') => $d ? date($f, strtotime($d)) : '';
$tab  = in_array($_GET['tab'] ?? '', ['queue', 'templates', 'log'], true) ? $_GET['tab'] : 'queue';
$CAT  = jobs_template_catalog();
$HELP = jobs_tag_help();

try { $pdo->query('SELECT 1 FROM email_queue LIMIT 1'); }
catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>The Jobs tables aren't set up yet. Run /admin/jobs_install.php first.</div>";
    return;
}
$tplNames = $pdo->query('SELECT tpl_key, name FROM email_templates')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$tplName  = fn($k) => $tplNames[$k] ?? (str_starts_with((string)$k, 'test_') ? 'Test: ' . ($tplNames[substr($k, 5)] ?? substr($k, 5)) : $k);
$pending  = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE status IN ('pending','failed')")->fetchColumn();
$failed7  = (int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND sent_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$preview  = fn(string $inner) => '<iframe sandbox="" referrerpolicy="no-referrer" class="w-full h-[28rem] border border-stone-200 rounded bg-stone-900" title="Email preview" srcdoc="' . $h(jobs_email_preview_html($inner)) . '"></iframe>';
?>
<div class="max-w-4xl pb-28">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hiring</h1>
  <p class="text-sm text-stone-500 mb-4">Emails to applicants: approve held ones, change the wording, and see what went out.</p>
  <?php require __DIR__ . '/_tabs.php'; ?>

  <nav class="flex flex-wrap gap-1.5 mb-4" aria-label="Email sections">
    <?php foreach (['queue' => 'Waiting' . ($pending ? ' (' . $pending . ')' : ''), 'templates' => 'Templates', 'log' => 'Sent log' . ($failed7 ? ' (' . $failed7 . ' failed)' : '')] as $k => $l): $on = $tab === $k; ?>
      <a href="/admin/admin.php?view=jobs_emails&tab=<?= $k ?>" class="text-sm px-3 py-1.5 rounded-full border whitespace-nowrap <?= $on ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-700 border-stone-300 hover:bg-stone-50' ?>" <?= $on ? 'aria-current="page"' : '' ?>><?= $h($l) ?></a>
    <?php endforeach; ?>
  </nav>

<?php
// ════════════════════════════════════════════════════════════════════════════
// QUEUE
// ════════════════════════════════════════════════════════════════════════════
if ($tab === 'queue'):
    $qs     = in_array($_GET['qs'] ?? '', ['waiting', 'sent', 'cancelled'], true) ? $_GET['qs'] : 'waiting';
    $editId = (int)($_GET['edit'] ?? 0);
    $where  = ['waiting' => "q.status IN ('pending','failed')", 'sent' => "q.status = 'sent'", 'cancelled' => "q.status = 'cancelled'"][$qs];
    $order  = $qs === 'waiting' ? 'q.created_at ASC, q.id ASC' : 'COALESCE(q.sent_at, q.updated_at) DESC, q.id DESC';
    $rows   = $pdo->query("SELECT q.*, a.first_name, a.last_name, a.status AS app_status, a.is_spam, j.title AS job_title
                             FROM email_queue q LEFT JOIN applications a ON a.id = q.application_id LEFT JOIN jobs j ON j.id = a.job_id
                            WHERE $where ORDER BY $order LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $SL = jobs_status_labels();
?>
  <div class="flex gap-3 text-sm mb-3">
    <?php foreach (['waiting' => 'Waiting', 'sent' => 'Sent', 'cancelled' => 'Cancelled'] as $k => $l): ?>
      <a href="/admin/admin.php?view=jobs_emails&tab=queue&qs=<?= $k ?>" class="<?= $qs === $k ? 'font-semibold text-amber-800 underline' : 'text-stone-500 hover:text-stone-800' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($qs === 'waiting'): ?>
  <p class="text-sm text-stone-600 mb-3">"Not selected" emails wait here so nobody gets one by accident. Read each one, then tap <strong>Send</strong>. Cancelling never emails anyone.</p>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="bg-white border border-dashed border-stone-300 rounded-lg p-6 text-center text-sm text-stone-500"><?= $qs === 'waiting' ? 'Nothing waiting. All caught up.' : 'Nothing here yet.' ?></div>
  <?php else: ?>
  <form method="POST" action="/admin/admin.php?view=jobs_emails" id="je-bulk">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_emails_action" value="q_bulk">
    <input type="hidden" name="back" value="view=jobs_emails&tab=queue">
    <?php if ($qs === 'waiting'): ?>
    <label class="flex items-center gap-2 text-sm text-stone-600 mb-2"><input type="checkbox" id="je-all" class="w-4 h-4"> Select all <?= count($rows) ?></label>
    <?php endif; ?>
  </form>

  <ul class="space-y-2">
  <?php foreach ($rows as $q):
      $who   = trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? '')) ?: $q['to_email'];
      $exp   = jobs_queue_expected_status($q['template_key']);
      $stale = $q['application_id'] !== null && ($q['app_status'] === null || (int)$q['is_spam'] || ($exp && $q['app_status'] !== $exp));
      $open  = in_array($q['status'], ['pending', 'failed'], true);
  ?>
    <li id="q<?= (int)$q['id'] ?>" class="bg-white border <?= $q['status'] === 'failed' ? 'border-red-300' : 'border-stone-200' ?> rounded-lg shadow-sm flex">
      <?php if ($open): ?>
      <label class="flex items-start pl-3 pr-1 pt-4 cursor-pointer" aria-label="Select email to <?= $h($who) ?>">
        <input type="checkbox" name="qids[]" value="<?= (int)$q['id'] ?>" form="je-bulk" class="je-id w-5 h-5">
      </label>
      <?php endif; ?>
      <div class="flex-1 min-w-0 p-3 <?= $open ? 'pl-2' : '' ?>">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <?php if ($q['application_id'] !== null && $q['app_status'] !== null): ?>
              <a href="/admin/admin.php?view=jobs&app=<?= (int)$q['application_id'] ?>" class="block text-base font-semibold text-stone-800 hover:text-amber-800 break-words"><?= $h($who) ?></a>
            <?php else: ?>
              <span class="block text-base font-semibold text-stone-800 break-words"><?= $h($who) ?></span>
            <?php endif; ?>
            <span class="block text-xs text-stone-500"><?= $h($tplName($q['template_key'])) ?><?= $q['job_title'] ? ' · ' . $h($q['job_title']) : '' ?></span>
          </div>
          <span class="shrink-0 whitespace-nowrap text-[11px] font-semibold px-2 py-0.5 rounded border <?= ['pending' => 'bg-amber-50 text-amber-900 border-amber-200', 'failed' => 'bg-red-50 text-red-800 border-red-200', 'sent' => 'bg-emerald-50 text-emerald-800 border-emerald-200', 'cancelled' => 'bg-stone-100 text-stone-600 border-stone-300'][$q['status']] ?? '' ?>"><?= $h(['pending' => 'Waiting', 'failed' => 'Failed', 'sent' => 'Sent', 'cancelled' => 'Cancelled'][$q['status']] ?? $q['status']) ?></span>
        </div>
        <div class="text-sm text-stone-800 mt-1 break-words"><?= $h($q['rendered_subject']) ?></div>
        <div class="text-[11px] text-stone-500 break-all">To <?= $h($q['to_email']) ?><?= $q['cc_email'] ? ' · CC ' . $h($q['cc_email']) : '' ?></div>
        <div class="text-[11px] text-stone-400">Queued by <?= $h($q['queued_by'] ?: 'Website') ?> · <?= $h($fmt($q['created_at'])) ?><?= $q['approved_by'] ? ' · ' . ($q['status'] === 'sent' ? 'sent' : ($q['status'] === 'cancelled' ? 'cancelled' : 'tried')) . ' by ' . $h($q['approved_by']) . ($q['sent_at'] ? ' ' . $h($fmt($q['sent_at'])) : '') : '' ?></div>
        <?php if ($q['status'] === 'failed' && $q['error']): ?><div class="text-[11px] text-red-700 mt-1 break-words">Last try failed: <?= $h($q['error']) ?></div><?php endif; ?>
        <?php if ($open && $stale): ?><div class="text-xs mt-1 bg-amber-50 border border-amber-200 text-amber-900 rounded p-1.5">
          <?= $q['app_status'] === null ? 'The application was deleted.' : ((int)$q['is_spam'] ? 'Marked as spam.' : 'They\'re now ' . $h($SL[$q['app_status']] ?? $q['app_status']) . '.') ?> Sending is blocked — cancel this one.</div><?php endif; ?>

        <?php if ($editId === (int)$q['id'] && $open): ?>
        <form method="POST" action="/admin/admin.php?view=jobs_emails" class="mt-3 space-y-2">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="jobs_emails_action" value="q_edit">
          <input type="hidden" name="qid" value="<?= (int)$q['id'] ?>">
          <label class="block text-xs text-stone-600">Subject<input type="text" name="subject" maxlength="200" required value="<?= $h($q['rendered_subject']) ?>" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm"></label>
          <label class="block text-xs text-stone-600">Message (HTML)
            <textarea name="body_html" rows="10" required class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-xs font-mono"><?= $h($q['rendered_body']) ?></textarea></label>
          <div class="flex gap-2">
            <button class="bg-stone-800 text-white font-semibold px-4 py-2 rounded text-sm">Save changes</button>
            <a href="/admin/admin.php?view=jobs_emails&tab=queue#q<?= (int)$q['id'] ?>" class="px-4 py-2 rounded text-sm border border-stone-300 text-stone-700">Cancel edit</a>
          </div>
        </form>
        <?php else: ?>
        <details class="mt-2">
          <summary class="text-sm text-amber-700 cursor-pointer">Read it</summary>
          <div class="mt-2"><?= $preview($q['rendered_body']) ?></div>
        </details>
        <?php endif; ?>

        <?php if ($open && $editId !== (int)$q['id']): ?>
        <div class="flex flex-wrap gap-2 mt-3">
          <?php if (!$stale): ?>
          <form method="POST" action="/admin/admin.php?view=jobs_emails" onsubmit="return confirm(<?= $h(json_encode('Send this email to ' . $who . ' now?')) ?>);">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="jobs_emails_action" value="q_send"><input type="hidden" name="qid" value="<?= (int)$q['id'] ?>">
            <button class="bg-amber-700 hover:bg-amber-800 text-white font-semibold px-4 py-2 rounded text-sm"><?= $q['status'] === 'failed' ? 'Try again' : 'Send' ?></button>
          </form>
          <a href="/admin/admin.php?view=jobs_emails&tab=queue&edit=<?= (int)$q['id'] ?>#q<?= (int)$q['id'] ?>" class="px-4 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-50">Edit</a>
          <?php endif; ?>
          <form method="POST" action="/admin/admin.php?view=jobs_emails" onsubmit="return confirm('Cancel this email? Nothing will be sent.');">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="jobs_emails_action" value="q_cancel"><input type="hidden" name="qid" value="<?= (int)$q['id'] ?>">
            <button class="px-4 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-50">Cancel email</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>

  <?php if ($qs === 'waiting'): ?>
  <div id="je-bar" class="fixed bottom-0 left-0 right-0 z-40 bg-stone-900 text-white p-3 shadow-2xl hidden">
    <div class="max-w-4xl mx-auto flex flex-wrap items-center gap-2 text-sm">
      <span id="je-n" class="font-semibold mr-1">0 picked</span>
      <button type="submit" form="je-bulk" name="bulk_do" value="send" class="bg-amber-600 hover:bg-amber-500 font-semibold px-4 py-2 rounded">Send</button>
      <button type="submit" form="je-bulk" name="bulk_do" value="cancel" class="bg-white text-stone-900 font-semibold px-4 py-2 rounded">Cancel emails</button>
    </div>
  </div>
  <script>
  (function () {
    var f = document.getElementById('je-bulk'), all = document.getElementById('je-all'), bar = document.getElementById('je-bar'), n = document.getElementById('je-n');
    var boxes = function () { return document.querySelectorAll('.je-id'); };
    function sync() { var c = document.querySelectorAll('.je-id:checked').length; bar.classList.toggle('hidden', !c); n.textContent = c + ' picked'; if (all) all.checked = c > 0 && c === boxes().length; }
    if (all) all.addEventListener('change', function () { Array.prototype.forEach.call(boxes(), function (b) { b.checked = all.checked; }); sync(); });
    document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('je-id')) sync(); });
    f.addEventListener('submit', function (e) {
      var c = document.querySelectorAll('.je-id:checked').length, how = e.submitter ? e.submitter.value : 'send';
      if (!confirm((how === 'send' ? 'Send ' : 'Cancel ') + c + ' email' + (c === 1 ? '' : 's') + '?' + (how === 'cancel' ? ' Nothing will be sent.' : ''))) e.preventDefault();
    });
    sync();
  })();
  </script>
  <?php endif; ?>
  <?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════════════════════
// TEMPLATES
// ════════════════════════════════════════════════════════════════════════════
elseif ($tab === 'templates'):
    $all = jobs_templates_all($pdo);
    $key = (string)($_GET['tpl'] ?? '');
    if (!isset($all[$key])) $key = '';
    $defaults = jobs_default_templates();

    if ($key === ''):
?>
  <p class="text-sm text-stone-600 mb-3">Tap an email to change its wording. The farm header and footer are added automatically.</p>
  <ul class="space-y-2">
    <?php foreach ($all as $k => $t): $edited = isset($defaults[$k]) && ($defaults[$k]['subject'] !== $t['subject'] || $defaults[$k]['body'] !== $t['body_html']); ?>
    <li><a href="/admin/admin.php?view=jobs_emails&tab=templates&tpl=<?= $h(rawurlencode($k)) ?>" class="block bg-white border border-stone-200 rounded-lg p-3 shadow-sm hover:border-amber-400">
      <div class="flex items-start justify-between gap-2">
        <span class="font-semibold text-stone-800"><?= $h($t['name']) ?></span>
        <span class="shrink-0 flex gap-1">
          <?php if ($edited): ?><span class="text-[11px] px-2 py-0.5 rounded bg-sky-50 text-sky-800 border border-sky-200">Edited</span><?php endif; ?>
          <span class="text-[11px] px-2 py-0.5 rounded border <?= (int)$t['is_active'] ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-stone-100 text-stone-500 border-stone-300' ?>"><?= (int)$t['is_active'] ? 'On' : 'Off' ?></span>
        </span>
      </div>
      <div class="text-xs text-stone-500 mt-0.5"><?= $h($CAT[$k]['when'] ?? '') ?></div>
      <div class="text-sm text-stone-700 mt-1 break-words">“<?= $h($t['subject']) ?>”</div>
    </a></li>
    <?php endforeach; ?>
  </ul>
<?php
    else:
        $t    = $all[$key];
        $old  = $_SESSION['jobs_form_old']['tpl_' . $key] ?? null; unset($_SESSION['jobs_form_old']['tpl_' . $key]);
        $subj = $old['subject'] ?? $t['subject'];
        $body = $old['body_html'] ?? $t['body_html'];
        $act  = $old ? !empty($old['is_active']) : (bool)$t['is_active'];
        $tags = $CAT[$key]['tags'] ?? array_keys($HELP);
        $sample = jobs_sample_vars();
        $htmlTags = jobs_mail_html_tags();
        $hasDefault = isset($defaults[$key]);
?>
  <a href="/admin/admin.php?view=jobs_emails&tab=templates" class="inline-block text-sm text-amber-700 hover:underline mb-3">&larr; All templates</a>
  <form method="POST" action="/admin/admin.php?view=jobs_emails" id="je-tpl" class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-3">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_emails_action" value="tpl_save">
    <input type="hidden" name="tpl_key" value="<?= $h($key) ?>">
    <div>
      <h2 class="font-semibold text-stone-800"><?= $h($t['name']) ?></h2>
      <p class="text-xs text-stone-500"><?= $h($CAT[$key]['when'] ?? '') ?></p>
    </div>
    <label class="block text-xs text-stone-600">Subject
      <input type="text" name="subject" id="je-subj" maxlength="200" required value="<?= $h($subj) ?>" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm"></label>

    <div>
      <div class="text-xs text-stone-600 mb-1">Tap a tag to drop it in where your cursor is:</div>
      <div class="flex flex-wrap gap-1.5">
        <?php foreach ($tags as $tg): ?>
          <button type="button" class="je-tag text-xs px-2 py-1 rounded border border-amber-300 bg-amber-50 text-amber-900 font-mono" data-tag="{<?= $h($tg) ?>}" title="<?= $h($HELP[$tg] ?? '') ?>">{<?= $h($tg) ?>}</button>
        <?php endforeach; ?>
      </div>
      <details class="mt-1"><summary class="text-xs text-stone-500 cursor-pointer">What each tag means</summary>
        <dl class="text-xs grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 mt-1">
          <?php foreach ($tags as $tg): ?><dt class="font-mono text-stone-700">{<?= $h($tg) ?>}</dt><dd class="text-stone-500"><?= $h($HELP[$tg] ?? '') ?></dd><?php endforeach; ?>
        </dl></details>
    </div>

    <label class="block text-xs text-stone-600">Message <span class="text-stone-400">(HTML — keep the &lt;p&gt; tags around each paragraph)</span>
      <textarea name="body_html" id="je-body" rows="12" required class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-xs font-mono leading-relaxed"><?= $h($body) ?></textarea></label>

    <label class="flex items-start gap-2 text-sm text-stone-700"><input type="checkbox" name="is_active" value="1" class="mt-1" <?= $act ? 'checked' : '' ?>>
      <span>On <span class="text-stone-500 text-xs">— when off, this email is skipped and nothing is sent.</span></span></label>

    <div class="flex flex-wrap gap-2">
      <button class="bg-amber-700 hover:bg-amber-800 text-white font-semibold px-5 py-2.5 rounded text-sm">Save</button>
      <button name="then_test" value="1" class="px-4 py-2.5 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-50">Save + send me a test</button>
    </div>

    <div>
      <div class="text-xs font-semibold text-stone-500 mb-1">Preview <span class="font-normal text-stone-400">(with sample details — updates as you type)</span></div>
      <div class="text-sm text-stone-800 mb-1 break-words" id="je-psubj"></div>
      <iframe id="je-prev" sandbox="" referrerpolicy="no-referrer" class="w-full h-[30rem] border border-stone-200 rounded bg-stone-900" title="Email preview"></iframe>
    </div>
  </form>

  <?php if ($hasDefault): ?>
  <form method="POST" action="/admin/admin.php?view=jobs_emails" class="mt-3" onsubmit="return confirm('Put back the original wording? Your changes to this email will be lost.');">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_emails_action" value="tpl_reset">
    <input type="hidden" name="tpl_key" value="<?= $h($key) ?>">
    <button class="text-sm text-red-700 hover:underline">Reset to the original wording</button>
  </form>
  <?php endif; ?>

  <script>
  (function () {
    var S = <?= json_encode($sample, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var RAW = <?= json_encode($htmlTags) ?>;
    var HEAD = <?= json_encode(bcf_email_header('Jobs'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var FOOT = <?= json_encode(bcf_email_footer(false), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var subj = document.getElementById('je-subj'), body = document.getElementById('je-body');
    var frame = document.getElementById('je-prev'), ps = document.getElementById('je-psubj');
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    function fill(t, html) {
      return t.replace(/\{([a-z_]+)\}/g, function (m, k) {
        if (!(k in S)) return html ? '<mark style="background:#fca5a5;color:#000">' + esc(m) + ' ?</mark>' : m + ' ?';
        return html && RAW.indexOf(k) < 0 ? esc(S[k]) : S[k];
      });
    }
    var tmr;
    function draw() { ps.textContent = 'Subject: ' + fill(subj.value, false); frame.srcdoc = HEAD + fill(body.value, true) + FOOT; }
    [subj, body].forEach(function (el) { el.addEventListener('input', function () { clearTimeout(tmr); tmr = setTimeout(draw, 250); }); });
    draw();
    // Tap-to-insert tags into whichever field was used last.
    var last = body;
    [subj, body].forEach(function (el) { el.addEventListener('focus', function () { last = el; }); });
    Array.prototype.forEach.call(document.querySelectorAll('.je-tag'), function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the cursor where it was
      b.addEventListener('click', function () {
        var t = b.getAttribute('data-tag'), s = last.selectionStart ?? last.value.length, e = last.selectionEnd ?? s;
        last.value = last.value.slice(0, s) + t + last.value.slice(e);
        last.focus(); last.selectionStart = last.selectionEnd = s + t.length;
        draw();
      });
    });
  })();
  </script>
<?php
    endif;

// ════════════════════════════════════════════════════════════════════════════
// SENT LOG
// ════════════════════════════════════════════════════════════════════════════
else:
    $only = ($_GET['only'] ?? '') === 'failed' ? 'failed' : '';
    $q    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per  = 50;
    $w = []; $p = [];
    if ($only) $w[] = "l.status = 'failed'";
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $w[] = '(l.to_email LIKE ? OR l.cc_email LIKE ? OR l.subject LIKE ?)'; array_push($p, $like, $like, $like);
    }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $st = $pdo->prepare("SELECT COUNT(*) FROM email_log l $where"); $st->execute($p); $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT l.*, a.first_name, a.last_name FROM email_log l LEFT JOIN applications a ON a.id = l.application_id
                          $where ORDER BY l.sent_at DESC, l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($p);
    $logs  = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pages = max(1, (int)ceil($total / $per));
    $lq    = fn(array $o) => http_build_query(array_filter(array_merge(['view' => 'jobs_emails', 'tab' => 'log', 'only' => $only, 'q' => $q], $o), fn($v) => $v !== '' && $v !== 1 && $v !== null));
?>
  <form method="GET" action="/admin/admin.php" class="flex flex-wrap gap-2 mb-3 text-sm">
    <input type="hidden" name="view" value="jobs_emails"><input type="hidden" name="tab" value="log">
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="Search email or subject" class="flex-1 min-w-[12rem] border border-stone-300 rounded px-2 py-2">
    <select name="only" class="border border-stone-300 rounded px-2 py-2 bg-white">
      <option value="">All</option><option value="failed" <?= $only ? 'selected' : '' ?>>Failed only</option>
    </select>
    <button class="bg-stone-800 text-white font-semibold px-4 py-2 rounded">Show</button>
  </form>
  <p class="text-xs text-stone-400 mb-2"><?= $total ?> email<?= $total === 1 ? '' : 's' ?></p>
  <?php if (!$logs): ?>
    <div class="bg-white border border-dashed border-stone-300 rounded-lg p-6 text-center text-sm text-stone-500">No emails match.</div>
  <?php else: ?>
  <ul class="bg-white border border-stone-200 rounded-lg shadow-sm divide-y divide-stone-100">
    <?php foreach ($logs as $l): $bad = $l['status'] !== 'sent'; $who = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')); ?>
    <li class="p-3 text-sm">
      <div class="flex items-start justify-between gap-2">
        <span class="min-w-0 break-words <?= $bad ? 'text-red-800' : 'text-stone-800' ?>"><?= $h($l['subject']) ?></span>
        <span class="shrink-0 text-[11px] font-semibold px-1.5 py-0.5 rounded <?= $bad ? 'bg-red-100 text-red-800' : 'bg-emerald-50 text-emerald-800' ?>"><?= $bad ? 'Failed' : 'Sent' ?></span>
      </div>
      <div class="text-[11px] text-stone-500 break-all"><?= $h($tplName($l['template_key'])) ?> · to <?= $h($l['to_email']) ?><?= $l['cc_email'] ? ' · CC ' . $h($l['cc_email']) : '' ?> · <?= $h($fmt($l['sent_at'])) ?></div>
      <?php if ($who !== '' && $l['application_id']): ?><a class="text-[11px] text-amber-700 hover:underline" href="/admin/admin.php?view=jobs&app=<?= (int)$l['application_id'] ?>"><?= $h($who) ?>'s application</a><?php endif; ?>
      <?php if ($bad && $l['error']): ?><div class="text-[11px] text-red-700 break-words"><?= $h($l['error']) ?></div><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($pages > 1): ?>
  <div class="flex items-center justify-between mt-3 text-sm">
    <?php if ($page > 1): ?><a class="text-amber-700 hover:underline" href="/admin/admin.php?<?= $h($lq(['p' => $page - 1])) ?>">&larr; Newer</a><?php else: ?><span></span><?php endif; ?>
    <span class="text-stone-400">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="text-amber-700 hover:underline" href="/admin/admin.php?<?= $h($lq(['p' => $page + 1])) ?>">Older &rarr;</a><?php else: ?><span></span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</div>
