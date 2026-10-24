<?php
// admin/views/jobs/applicants.php
// Hiring > Applicants (Jobs pack P4): the pipeline list (filters, tap-to-move,
// bulk actions) and one application's page (?app=ID): contact, guardian,
// answers, résumé, move panel, notes, status history and emails.
// Administrators only (admin_only in the view registry). Saves go to
// admin/handlers/jobs_pipeline.php via POST key jobs_pipeline_action.
require_once __DIR__ . '/../../../includes/jobs/pipeline.php';

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$SL   = jobs_status_labels();
$SC   = jobs_status_classes();
$ENG  = jobs_engagement_labels();
$fmt  = fn($d, $f = 'M j, g:ia') => $d ? date($f, strtotime($d)) : '';
$pill = fn(string $s) => '<span class="inline-block shrink-0 whitespace-nowrap text-[11px] font-semibold px-2 py-0.5 rounded border ' . ($SC[$s] ?? 'bg-stone-100 text-stone-600 border-stone-300') . '">' . htmlspecialchars($SL[$s] ?? $s) . '</span>';
$tel  = fn($p) => preg_replace('/[^0-9+]/', '', (string)$p);
$back = 'view=jobs' . (isset($_GET['b']) && is_string($_GET['b']) && preg_match('#^[A-Za-z0-9_%=&.\-+]*$#', $_GET['b']) ? '&' . $_GET['b'] : '');

$flagsFor = function (array $a): array {
    $f = [];
    if ((int)$a['knockout_flag'])            $f[] = ['Knockout', 'bg-red-100 text-red-800'];
    if ($a['age_bracket'] !== '18_plus')     $f[] = ['Under 18 (' . str_replace('_', '–', $a['age_bracket']) . ')', 'bg-sky-100 text-sky-800'];
    if ((int)$a['is_returning'])             $f[] = ['Returning', 'bg-stone-100 text-stone-700'];
    if ((int)$a['prior_match'])              $f[] = ['Seen before', 'bg-stone-100 text-stone-700'];
    if ((int)$a['is_spam'])                  $f[] = ['Spam', 'bg-stone-300 text-stone-700'];
    if (!empty($a['archived_at']))           $f[] = ['Archived', 'bg-stone-200 text-stone-600'];
    return $f;
};
$chips = function (array $flags) use ($h): string {
    if (!$flags) return '';
    $o = '<div class="flex flex-wrap gap-1 mt-1">';
    foreach ($flags as [$t, $c]) $o .= '<span class="text-[11px] px-2 py-0.5 rounded ' . $c . '">' . $h($t) . '</span>';
    return $o . '</div>';
};

try { $pdo->query('SELECT 1 FROM applications LIMIT 1'); }
catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>The Jobs tables aren't set up yet. Run /admin/jobs_install.php first.</div>";
    return;
}

$appId = (int)($_GET['app'] ?? 0);

// ════════════════════════════════════════════════════════════════════════════
// ONE APPLICATION
// ════════════════════════════════════════════════════════════════════════════
if ($appId > 0):
    $a = jobs_app_find($pdo, $appId);
    if (!$a) { echo "<p class='text-sm text-stone-500'>That application wasn't found. <a class='text-amber-700 underline' href='/admin/admin.php?view=jobs'>Back to Applicants</a></p>"; return; }
    $q = fn(string $sql) => (function () use ($pdo, $sql, $appId) { $st = $pdo->prepare($sql); $st->execute([$appId]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; })();
    $answers = $q('SELECT * FROM application_answers WHERE application_id = ? ORDER BY id');
    $files   = $q('SELECT id, original_name, size_bytes FROM application_files WHERE application_id = ? ORDER BY id');
    $notes   = $q('SELECT * FROM application_notes WHERE application_id = ? ORDER BY id DESC');
    $hist    = $q('SELECT * FROM application_status_history WHERE application_id = ? ORDER BY changed_at DESC, id DESC');
    $mails   = $q('SELECT * FROM email_log WHERE application_id = ? ORDER BY sent_at DESC, id DESC LIMIT 50');
    $queued  = $q("SELECT * FROM email_queue WHERE application_id = ? AND status IN ('pending','failed') ORDER BY id DESC");
    $others  = (function () use ($pdo, $a) {
        $st = $pdo->prepare('SELECT a.id, a.status, a.submitted_at, j.title FROM applications a JOIN jobs j ON j.id = a.job_id WHERE a.email = ? AND a.id <> ? ORDER BY a.submitted_at DESC LIMIT 10');
        $st->execute([$a['email'], (int)$a['id']]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    })();
    $avail   = array_intersect_key(jobs_availability_labels(), array_flip((array)(json_decode((string)$a['availability'], true) ?: [])));
    $name    = trim($a['first_name'] . ' ' . $a['last_name']);
    $minor   = $a['age_bracket'] !== '18_plus';
    $old     = $_SESSION['jobs_form_old']['move'] ?? []; unset($_SESSION['jobs_form_old']['move']);
    $wantTo  = (string)($old['to'] ?? ($_GET['to'] ?? ''));
    if (!isset($SL[$wantTo]) || $wantTo === 'hired') $wantTo = '';
    $self    = 'view=jobs&app=' . (int)$a['id'] . ($back !== 'view=jobs' ? '&b=' . rawurlencode(substr($back, 10)) : '');
    $ivDate  = $old['interview_date'] ?? ($a['interview_at'] ? substr($a['interview_at'], 0, 10) : '');
    $ivTime  = $old['interview_time'] ?? ($a['interview_at'] ? substr($a['interview_at'], 11, 5) : '');
    $ivLoc   = $old['interview_location'] ?? ($a['interview_location'] ?: (site_address() ?: '4132 Bat Cave Road, Old Fort, NC 28762'));
?>
<div class="max-w-3xl pb-16">
  <a href="/admin/admin.php?<?= $h($back) ?>" class="inline-block text-sm text-amber-700 hover:underline mb-3">&larr; All applicants</a>

  <!-- ── Header ── -->
  <div class="bg-white border border-stone-200 rounded-lg p-4 mb-4 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-stone-800 break-words"><?= $h($name) ?></h1>
        <div class="text-sm text-stone-500"><?= $h($a['job_title']) ?> · <?= $h($a['category_name']) ?></div>
        <div class="text-xs text-stone-400 mt-0.5">Applied <?= $h($fmt($a['submitted_at'], 'M j, Y g:ia')) ?><?= $a['cycle_closed_at'] ? ' · round closed' : '' ?></div>
        <?= $chips($flagsFor($a)) ?>
      </div>
      <div class="text-base"><?= $pill($a['status']) ?></div>
    </div>
    <?php if ($a['status'] === 'interview' && $a['interview_at']): ?>
      <div class="mt-3 text-sm bg-violet-50 border border-violet-200 text-violet-900 rounded p-2">Interview: <strong><?= $h(jobs_fmt_interview($a['interview_at'])) ?></strong><?= $a['interview_location'] ? ' · ' . $h($a['interview_location']) : '' ?></div>
    <?php endif; ?>
    <div class="grid grid-cols-3 gap-2 mt-4">
      <?php if ($a['phone']): ?>
      <a href="tel:<?= $h($tel($a['phone'])) ?>" class="text-center text-sm font-semibold py-2.5 rounded border border-stone-300 text-stone-700 hover:bg-stone-50">Call</a>
      <a href="sms:<?= $h($tel($a['phone'])) ?>" class="text-center text-sm font-semibold py-2.5 rounded border border-stone-300 text-stone-700 hover:bg-stone-50">Text</a>
      <?php endif; ?>
      <a href="mailto:<?= $h($a['email']) ?>?subject=<?= rawurlencode('Your application for ' . $a['job_title']) ?>" class="text-center text-sm font-semibold py-2.5 rounded border border-stone-300 text-stone-700 hover:bg-stone-50 <?= $a['phone'] ? '' : 'col-span-3' ?>">Email</a>
    </div>
    <div class="text-sm text-stone-600 mt-3 break-all"><?= $h($a['email']) ?><?= $a['phone'] ? ' · ' . $h($a['phone']) : '' ?></div>
  </div>

  <!-- ── Move ── -->
  <?php if ((int)$a['is_spam']): ?>
  <div class="bg-stone-50 border border-stone-300 rounded-lg p-4 mb-4 text-sm text-stone-600">Marked as spam, so it's hidden from the list and can't be moved. Use <strong>Not spam</strong> at the bottom to bring it back.</div>
  <?php elseif ($a['status'] === 'hired'): ?>
  <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4 mb-4 text-sm text-emerald-900">Hired. Staff changes go through the hire screen.</div>
  <?php else: ?>
  <section id="move" class="bg-white border border-amber-300 rounded-lg p-4 mb-4 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-2">Move to</h2>
    <form method="POST" action="/admin/admin.php?view=jobs" id="jp-move">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="jobs_pipeline_action" value="move">
      <input type="hidden" name="from_detail" value="1">
      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <input type="hidden" name="back" value="<?= $h($self) ?>">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3" role="radiogroup" aria-label="New status">
        <?php foreach ($SL as $k => $l):
            if ($k === 'hired') continue;
            $isCur = $k === $a['status'] && $k !== 'interview';
        ?>
        <label class="jp-to flex items-center justify-center text-center text-sm py-2.5 px-1 rounded border cursor-pointer select-none
                      <?= $isCur ? 'opacity-40 cursor-not-allowed border-stone-200' : 'border-stone-300 hover:bg-stone-50' ?>">
          <input type="radio" name="to" value="<?= $k ?>" class="sr-only" <?= $isCur ? 'disabled' : '' ?> <?= $wantTo === $k ? 'checked' : '' ?>>
          <?= $h($k === 'interview' && $a['status'] === 'interview' ? 'New interview time' : $l) ?>
        </label>
        <?php endforeach; ?>
        <span class="flex items-center justify-center text-center text-[11px] py-2 px-1 rounded border border-dashed border-stone-300 text-stone-400" title="Coming in the next update">Hired (next update)</span>
      </div>

      <div data-for="interview" class="jp-extra hidden border-t border-stone-100 pt-3 mb-3 space-y-2">
        <div class="grid grid-cols-2 gap-2">
          <label class="text-xs text-stone-600">Date<input type="date" name="interview_date" value="<?= $h($ivDate) ?>" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm"></label>
          <label class="text-xs text-stone-600">Time<input type="time" name="interview_time" value="<?= $h($ivTime) ?>" step="900" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm"></label>
        </div>
        <label class="block text-xs text-stone-600">Where<input type="text" name="interview_location" maxlength="255" value="<?= $h($ivLoc) ?>" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm"></label>
        <label class="flex items-start gap-2 text-sm text-stone-700"><input type="checkbox" name="send_email" value="1" class="mt-1" <?= !$old || !empty($old['send_email']) ? 'checked' : '' ?>>
          <span>Email the interview time with a calendar invite<?= $minor ? ' (guardian CC\'d)' : '' ?></span></label>
      </div>

      <div data-for="offer" class="jp-extra hidden border-t border-stone-100 pt-3 mb-3 space-y-2">
        <label class="block text-xs text-stone-600">Offer details <span class="text-stone-400">(pay, start date, schedule — goes in the email)</span>
          <textarea name="offer_details" rows="4" maxlength="2000" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm" placeholder="$12/hr, Saturdays and Sundays 10–4, starting Nov 7."><?= $h($old['offer_details'] ?? '') ?></textarea></label>
        <label class="flex items-start gap-2 text-sm text-stone-700"><input type="checkbox" name="send_email" value="1" class="mt-1" <?= !$old || !empty($old['send_email']) ? 'checked' : '' ?>>
          <span>Send the offer email now<?= $minor ? ' (guardian CC\'d)' : '' ?></span></label>
      </div>

      <div data-for="not_selected" class="jp-extra hidden border-t border-stone-100 pt-3 mb-3">
        <label class="flex items-start gap-2 text-sm text-stone-700"><input type="checkbox" name="queue_email" value="1" class="mt-1" checked>
          <span>Put the "not selected" email in the queue. Nothing is sent until you approve it.</span></label>
      </div>

      <label class="block text-xs text-stone-600 mb-3">Note for the history <span class="text-stone-400">(optional, only you two see it)</span>
        <input type="text" name="note" maxlength="300" value="<?= $h($old['note'] ?? '') ?>" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm"></label>
      <button class="w-full sm:w-auto bg-amber-700 hover:bg-amber-800 text-white font-semibold px-6 py-2.5 rounded text-sm">Move</button>
    </form>
  </section>
  <?php endif; ?>

  <!-- ── About them ── -->
  <section class="bg-white border border-stone-200 rounded-lg p-4 mb-4 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-2">About them</h2>
    <dl class="text-sm grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1.5">
      <dt class="text-stone-500">Age</dt><dd><?= $h(['14_15' => '14 or 15', '16_17' => '16 or 17', '18_plus' => '18 or older'][$a['age_bracket']] ?? $a['age_bracket']) ?></dd>
      <dt class="text-stone-500">Can work</dt><dd><?= $avail ? $h(implode(', ', $avail)) : '<span class="text-stone-400">—</span>' ?></dd>
      <dt class="text-stone-500">Earliest start</dt><dd><?= $a['earliest_start'] ? $h($fmt($a['earliest_start'], 'M j, Y')) : '<span class="text-stone-400">—</span>' ?></dd>
      <dt class="text-stone-500">Worked or applied before</dt><dd><?= (int)$a['is_returning'] ? 'They say yes' : 'No' ?><?= (int)$a['prior_match'] ? ' · email matches an earlier application or account' : '' ?></dd>
    </dl>
    <?php if ($minor): ?>
    <div class="mt-3 bg-sky-50 border border-sky-200 rounded p-3 text-sm">
      <div class="font-semibold text-sky-900 mb-1">Parent or guardian</div>
      <div><?= $h($a['guardian_name']) ?></div>
      <div class="break-all"><?php if ($a['guardian_phone']): ?><a class="text-sky-800 underline" href="tel:<?= $h($tel($a['guardian_phone'])) ?>"><?= $h($a['guardian_phone']) ?></a> · <?php endif; ?><a class="text-sky-800 underline" href="mailto:<?= $h($a['guardian_email']) ?>"><?= $h($a['guardian_email']) ?></a></div>
      <div class="text-xs text-sky-800 mt-1">Permission given <?= $h($fmt($a['guardian_consent_at'], 'M j, Y g:ia')) ?>. They're CC'd on every email to the applicant. An NC youth employment certificate is needed before the first shift.</div>
    </div>
    <?php endif; ?>
    <?php if (trim((string)$a['cover_note']) !== ''): ?>
    <div class="mt-3"><div class="text-xs font-semibold text-stone-500 mb-1">Anything else they told us</div>
      <div class="text-sm text-stone-800 whitespace-pre-line break-words bg-stone-50 rounded p-3"><?= $h($a['cover_note']) ?></div></div>
    <?php endif; ?>
    <?php if ($files): ?>
    <div class="mt-3 flex flex-wrap gap-2">
      <?php foreach ($files as $f): ?>
        <a href="/admin/jobs_file.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener" class="text-sm px-3 py-2 rounded border border-stone-300 text-stone-700 hover:bg-stone-50">Résumé (PDF, <?= max(1, (int)round($f['size_bytes'] / 1024)) ?> KB)</a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- ── Answers ── -->
  <section class="bg-white border border-stone-200 rounded-lg p-4 mb-4 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-2">Answers</h2>
    <?php if (!$answers): ?><p class="text-sm text-stone-400">No questions on this job.</p><?php endif; ?>
    <ul class="space-y-2">
      <?php foreach ($answers as $an): $hit = (int)$an['is_knockout_hit']; ?>
      <li class="rounded p-2 <?= $hit ? 'bg-red-50 border border-red-200' : 'bg-stone-50' ?>">
        <div class="text-xs text-stone-500"><?= $h($an['question_label_snapshot']) ?></div>
        <div class="text-sm <?= $hit ? 'text-red-800 font-semibold' : 'text-stone-800' ?> whitespace-pre-line break-words"><?= trim((string)$an['answer_text']) !== '' ? $h($an['answer_text']) : '<span class="text-stone-400 font-normal">(no answer)</span>' ?><?= $hit ? ' <span class="text-[11px] font-semibold px-1.5 py-0.5 rounded bg-red-100">Knockout</span>' : '' ?></div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ── Notes ── -->
  <section id="notes" class="bg-white border border-stone-200 rounded-lg p-4 mb-4 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-2">Notes <span class="text-stone-400 font-normal text-sm">(private — the applicant never sees these)</span></h2>
    <form method="POST" action="/admin/admin.php?view=jobs" class="mb-3">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="jobs_pipeline_action" value="note_add">
      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <input type="hidden" name="back" value="<?= $h($self) ?>#notes">
      <textarea name="body" rows="2" maxlength="4000" required class="w-full border border-stone-300 rounded px-2 py-2 text-sm" placeholder="Called, left a message…"></textarea>
      <button class="mt-2 px-4 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100">Add note</button>
    </form>
    <ul class="space-y-2">
      <?php foreach ($notes as $n): ?>
      <li class="bg-yellow-50 border border-yellow-200 rounded p-2">
        <div class="text-sm text-stone-800 whitespace-pre-line break-words"><?= $h($n['body']) ?></div>
        <div class="flex items-center justify-between mt-1">
          <span class="text-[11px] text-stone-500"><?= $h($n['user_name'] ?: 'Admin') ?> · <?= $h($fmt($n['created_at'])) ?></span>
          <form method="POST" action="/admin/admin.php?view=jobs" onsubmit="return confirm('Delete this note?');">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="jobs_pipeline_action" value="note_delete">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
            <input type="hidden" name="back" value="<?= $h($self) ?>#notes">
            <button class="text-[11px] text-red-700 hover:underline">Delete</button>
          </form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ── Emails ── -->
  <section id="emails" class="bg-white border border-stone-200 rounded-lg p-4 mb-4 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-2">Emails</h2>
    <?php foreach ($queued as $qe): ?>
      <div class="text-sm rounded p-2 mb-2 <?= $qe['status'] === 'failed' ? 'bg-red-50 border border-red-200 text-red-800' : 'bg-amber-50 border border-amber-200 text-amber-900' ?>">
        <strong><?= $qe['status'] === 'failed' ? 'Failed in queue' : 'Waiting for approval' ?>:</strong> <?= $h($qe['rendered_subject']) ?>
        <div class="text-[11px] opacity-80">Queued by <?= $h($qe['queued_by'] ?: 'Website') ?> · <?= $h($fmt($qe['created_at'])) ?> · sending from the queue comes in the next email update</div>
      </div>
    <?php endforeach; ?>
    <?php if (!$mails && !$queued): ?><p class="text-sm text-stone-400">No emails yet.</p><?php endif; ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($mails as $m): $bad = $m['status'] !== 'sent'; ?>
      <li class="py-1.5 text-sm">
        <div class="flex items-start justify-between gap-2">
          <span class="min-w-0 break-words <?= $bad ? 'text-red-800' : 'text-stone-800' ?>"><?= $h($m['subject']) ?></span>
          <span class="shrink-0 text-[11px] font-semibold px-1.5 py-0.5 rounded <?= $bad ? 'bg-red-100 text-red-800' : 'bg-emerald-50 text-emerald-800' ?>"><?= $bad ? 'Failed' : 'Sent' ?></span>
        </div>
        <div class="text-[11px] text-stone-500 break-all">To <?= $h($m['to_email']) ?><?= $m['cc_email'] ? ' · CC ' . $h($m['cc_email']) : '' ?> · <?= $h($fmt($m['sent_at'])) ?></div>
        <?php if ($bad && $m['error']): ?><div class="text-[11px] text-red-700 break-words"><?= $h($m['error']) ?></div><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ── History ── -->
  <section class="bg-white border border-stone-200 rounded-lg p-4 mb-4 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-2">History</h2>
    <ol class="space-y-1.5">
      <?php foreach ($hist as $hr): ?>
      <li class="text-sm">
        <span class="text-stone-800"><?= $hr['from_status'] === null ? 'Applied' : ($hr['from_status'] === $hr['to_status'] ? $h($SL[$hr['to_status']] ?? $hr['to_status']) : $h($SL[$hr['from_status']] ?? $hr['from_status']) . ' → ' . $h($SL[$hr['to_status']] ?? $hr['to_status'])) ?></span>
        <?= $hr['note'] ? '<span class="text-stone-600"> — ' . $h($hr['note']) . '</span>' : '' ?>
        <div class="text-[11px] text-stone-400"><?= $h($hr['changed_by_name'] ?: 'Website') ?> · <?= $h($fmt($hr['changed_at'])) ?></div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php if ($others): ?>
    <div class="mt-3 pt-3 border-t border-stone-100">
      <div class="text-xs font-semibold text-stone-500 mb-1">Other applications from this email</div>
      <ul class="text-sm space-y-1">
        <?php foreach ($others as $o): ?>
        <li><a class="text-amber-700 hover:underline" href="/admin/admin.php?view=jobs&app=<?= (int)$o['id'] ?>"><?= $h($o['title']) ?></a> · <?= $h($fmt($o['submitted_at'], 'M j, Y')) ?> · <?= $h($SL[$o['status']] ?? $o['status']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </section>

  <!-- ── More ── -->
  <section class="flex flex-wrap gap-2">
    <?php foreach ([[(int)$a['is_spam'] ? 'not_spam' : 'spam', (int)$a['is_spam'] ? 'Not spam' : 'Mark as spam', 'Hide this as spam? Any held emails to them are cancelled.'],
                    [$a['archived_at'] ? 'unarchive' : 'archive', $a['archived_at'] ? 'Bring back from archive' : 'Archive', '']] as [$act, $lab, $conf]):
        if ($act === 'archive' && (int)$a['is_spam']) continue; ?>
    <form method="POST" action="/admin/admin.php?view=jobs" <?= $conf ? 'onsubmit="return confirm(' . $h(json_encode($conf)) . ');"' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="jobs_pipeline_action" value="<?= $act ?>">
      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <input type="hidden" name="back" value="<?= $h($act === 'spam' || $act === 'archive' ? $back : $self) ?>">
      <button class="px-4 py-2 rounded text-sm border <?= $act === 'spam' ? 'border-red-200 text-red-700 hover:bg-red-50' : 'border-stone-300 text-stone-700 hover:bg-stone-100' ?>"><?= $h($lab) ?></button>
    </form>
    <?php endforeach; ?>
  </section>
</div>

<style>
  #jp-move .jp-to:has(input:checked){background:#b45309;border-color:#92400e;color:#fff;font-weight:600}
  #jp-move .jp-to:has(input:focus-visible){outline:2px solid #b45309;outline-offset:2px}
</style>
<script>
// Move panel: show the extra fields for the picked status.
(function () {
  var f = document.getElementById('jp-move'); if (!f) return;
  function sync() {
    var c = f.querySelector('input[name="to"]:checked'), v = c ? c.value : '';
    Array.prototype.forEach.call(f.querySelectorAll('.jp-extra'), function (d) {
      var on = d.getAttribute('data-for') === v;
      d.classList.toggle('hidden', !on);
      Array.prototype.forEach.call(d.querySelectorAll('input,textarea'), function (i) { i.disabled = !on; });
    });
  }
  f.addEventListener('change', sync); sync();
  f.addEventListener('submit', function (e) {
    if (!f.querySelector('input[name="to"]:checked')) { e.preventDefault(); alert('Tap a status first.'); }
  });
})();
</script>
<?php
    return;
endif;

// ════════════════════════════════════════════════════════════════════════════
// LIST (pipeline)
// ════════════════════════════════════════════════════════════════════════════
$F      = jobs_pipeline_filters($_GET);
$counts = jobs_pipeline_counts($pdo, $F);
$apps   = jobs_pipeline_list($pdo, $F);
$jobsAll = $pdo->query('SELECT j.id, j.title, c.name AS cat FROM jobs j JOIN job_categories c ON c.id = j.category_id ORDER BY j.is_active DESC, c.sort_order, j.title')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$catsAll = jobs_categories($pdo);
$rounds  = [];
if ($F['job']) { foreach (jobs_cycles($pdo, $F['job']) as $cy) $rounds[] = $cy; }
$queuePending = jobs_queue_pending_count($pdo);

// Query string for the current filters, with overrides.
$qs = function (array $over = []) use ($F): string {
    $p = array_merge($F, $over);
    $def = ['job' => 0, 'cat' => 0, 'round' => 'current', 'status' => 'active', 'flag' => '', 'show' => '', 'q' => '', 'sort' => 'new'];
    $out = [];
    foreach ($p as $k => $v) if ((string)$v !== (string)$def[$k]) $out[$k] = $v;
    return $out ? http_build_query($out) : '';
};
$here      = 'view=jobs' . ($qs() !== '' ? '&' . $qs() : '');
$activeN   = array_sum(array_intersect_key($counts, array_flip(jobs_active_statuses())));
$allN      = array_sum($counts);
$filtersOn = $F['job'] || $F['cat'] || $F['round'] !== 'current' || $F['flag'] !== '' || $F['show'] !== '' || $F['q'] !== '' || $F['sort'] !== 'new';
?>
<div class="max-w-4xl pb-28">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hiring</h1>
  <p class="text-sm text-stone-500 mb-4">Everyone who applied. Tap a name to see their answers, add notes and move them along.</p>
  <?php require __DIR__ . '/_tabs.php'; ?>

  <?php if ($queuePending): ?>
  <div class="mb-3 text-sm bg-amber-50 border border-amber-200 text-amber-900 rounded p-2"><?= $queuePending ?> email<?= $queuePending === 1 ? ' is' : 's are' ?> waiting for approval in the queue. Sending from the queue comes in the next update.</div>
  <?php endif; ?>

  <!-- Status chips -->
  <nav class="flex gap-1.5 overflow-x-auto pb-2 mb-2 -mx-1 px-1" aria-label="Status">
    <?php
      $chipList = [['active', 'In progress', $activeN]];
      foreach ($SL as $k => $l) $chipList[] = [$k, $l, $counts[$k] ?? 0];
      $chipList[] = ['all', 'All', $allN];
      foreach ($chipList as [$k, $l, $n]):
        $on = $F['status'] === $k;
        $q2 = $qs(['status' => $k]);
    ?>
      <a href="/admin/admin.php?view=jobs<?= $q2 !== '' ? '&' . $h($q2) : '' ?>"
         class="shrink-0 text-sm px-3 py-1.5 rounded-full border whitespace-nowrap <?= $on ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-700 border-stone-300 hover:bg-stone-50' ?>"
         <?= $on ? 'aria-current="page"' : '' ?>><?= $h($l) ?> <span class="<?= $on ? 'text-stone-300' : 'text-stone-400' ?>"><?= (int)$n ?></span></a>
    <?php endforeach; ?>
  </nav>

  <!-- Filters -->
  <details class="bg-white border border-stone-200 rounded-lg mb-3 shadow-sm" <?= $filtersOn ? 'open' : '' ?>>
    <summary class="px-4 py-2.5 text-sm font-semibold text-stone-700 cursor-pointer">Filter and search<?= $filtersOn ? ' <span class="text-amber-700 font-normal">(on)</span>' : '' ?></summary>
    <form method="GET" action="/admin/admin.php" class="px-4 pb-4 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
      <input type="hidden" name="view" value="jobs">
      <input type="hidden" name="status" value="<?= $h($F['status']) ?>">
      <label class="text-xs text-stone-600 sm:col-span-2">Search name, email or phone
        <input type="search" name="q" value="<?= $h($F['q']) ?>" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm"></label>
      <label class="text-xs text-stone-600">Job
        <select name="job" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm">
          <option value="0">All jobs</option>
          <?php foreach ($jobsAll as $j): ?><option value="<?= (int)$j['id'] ?>" <?= $F['job'] === (int)$j['id'] ? 'selected' : '' ?>><?= $h($j['title'] . ' (' . $j['cat'] . ')') ?></option><?php endforeach; ?>
        </select></label>
      <label class="text-xs text-stone-600">Area
        <select name="cat" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm">
          <option value="0">All areas</option>
          <?php foreach ($catsAll as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $F['cat'] === (int)$c['id'] ? 'selected' : '' ?>><?= $h($c['name']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="text-xs text-stone-600">Hiring round
        <select name="round" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm">
          <option value="current" <?= $F['round'] === 'current' ? 'selected' : '' ?>>Current (open rounds + anyone in progress)</option>
          <option value="all" <?= $F['round'] === 'all' ? 'selected' : '' ?>>All rounds</option>
          <?php foreach ($rounds as $cy): ?><option value="<?= (int)$cy['id'] ?>" <?= $F['round'] === (string)$cy['id'] ? 'selected' : '' ?>>Round from <?= $h($fmt($cy['opened_at'], 'M j, Y')) ?><?= $cy['closed_at'] ? '' : ' (open)' ?></option><?php endforeach; ?>
        </select>
        <?php if (!$F['job']): ?><span class="text-[11px] text-stone-400">Pick a job to choose a single round.</span><?php endif; ?></label>
      <label class="text-xs text-stone-600">Only show
        <select name="flag" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm">
          <?php foreach (['' => 'Everyone', 'returning' => 'Returning / seen before', 'knockout' => 'Knockout answers', 'minor' => 'Under 18', 'resume' => 'Has a résumé'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $F['flag'] === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
        </select></label>
      <label class="text-xs text-stone-600">Show
        <select name="show" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm">
          <?php foreach (['' => 'Regular', 'archived' => 'Archived', 'spam' => 'Spam'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $F['show'] === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
        </select></label>
      <label class="text-xs text-stone-600">Sort
        <select name="sort" class="mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm">
          <?php foreach (['new' => 'Newest first', 'old' => 'Oldest first', 'name' => 'Last name'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $F['sort'] === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
        </select></label>
      <div class="sm:col-span-2 flex gap-2 mt-1">
        <button class="bg-stone-800 text-white font-semibold px-4 py-2 rounded text-sm">Apply</button>
        <?php if ($filtersOn): ?><a href="/admin/admin.php?view=jobs<?= $F['status'] !== 'active' ? '&status=' . $h($F['status']) : '' ?>" class="px-4 py-2 rounded text-sm border border-stone-300 text-stone-700">Clear</a><?php endif; ?>
      </div>
    </form>
  </details>

  <?php if (!$apps): ?>
    <div class="bg-white border border-dashed border-stone-300 rounded-lg p-6 text-center text-sm text-stone-500">
      <?= $allN ? 'Nobody in this group.' : 'No applications here yet.' ?>
      <?php if ($F['status'] !== 'all' && $allN): ?> <a class="text-amber-700 underline" href="/admin/admin.php?view=jobs<?= $qs(['status' => 'all']) !== '' ? '&' . $h($qs(['status' => 'all'])) : '' ?>">Show all <?= $allN ?></a><?php endif; ?>
    </div>
  <?php else: ?>

  <form method="POST" action="/admin/admin.php?view=jobs" id="jp-bulk">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_pipeline_action" value="bulk">
    <input type="hidden" name="back" value="<?= $h($here) ?>">

    <div class="flex items-center justify-between mb-2 text-sm">
      <label class="flex items-center gap-2 text-stone-600"><input type="checkbox" id="jp-all" class="w-4 h-4"> Select all <?= count($apps) ?></label>
      <span class="text-stone-400 text-xs"><?= count($apps) ?> shown<?= count($apps) >= 300 ? ' (first 300)' : '' ?></span>
    </div>

    <ul class="space-y-2">
    <?php foreach ($apps as $a):
        $nm  = trim($a['first_name'] . ' ' . $a['last_name']);
        $url = '/admin/admin.php?view=jobs&app=' . (int)$a['id'] . ($qs() !== '' ? '&b=' . rawurlencode($qs()) : '');
    ?>
      <li class="bg-white border border-stone-200 rounded-lg shadow-sm flex">
        <label class="flex items-start pl-3 pr-1 pt-4 cursor-pointer" aria-label="Select <?= $h($nm) ?>">
          <input type="checkbox" name="ids[]" value="<?= (int)$a['id'] ?>" class="jp-id w-5 h-5">
        </label>
        <div class="flex-1 min-w-0 p-3 pl-2">
          <div class="flex items-start justify-between gap-2">
            <a href="<?= $h($url) ?>" class="min-w-0">
              <span class="block text-base font-semibold text-stone-800 hover:text-amber-800 break-words"><?= $h($nm) ?></span>
              <span class="block text-xs text-stone-500"><?= $h($a['job_title']) ?> · <?= $h($fmt($a['submitted_at'])) ?></span>
            </a>
            <?= $pill($a['status']) ?>
          </div>
          <?php if ($a['status'] === 'interview' && $a['interview_at']): ?>
            <div class="text-xs text-violet-800 mt-1">Interview <?= $h($fmt($a['interview_at'], 'D M j, g:ia')) ?></div>
          <?php endif; ?>
          <?= $chips($flagsFor($a)) ?>
          <div class="flex flex-wrap items-center gap-2 mt-2">
            <?php if ($a['status'] !== 'hired' && !(int)$a['is_spam']): ?>
            <select name="to" form="qm<?= (int)$a['id'] ?>" class="jp-qm text-sm border border-stone-300 rounded px-2 py-1.5 bg-white" aria-label="Move <?= $h($nm) ?> to">
              <option value="">Move to…</option>
              <?php foreach ($SL as $k => $l): if ($k === 'hired' || $k === $a['status']) continue; ?>
              <option value="<?= $k ?>"><?= $h($l) ?><?= in_array($k, ['interview', 'offer'], true) ? ' (add details)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($a['phone']): ?><a href="tel:<?= $h($tel($a['phone'])) ?>" class="text-sm px-2.5 py-1.5 rounded border border-stone-300 text-stone-700">Call</a><?php endif; ?>
            <?php if ($a['file_id']): ?><a href="/admin/jobs_file.php?id=<?= (int)$a['file_id'] ?>" target="_blank" rel="noopener" class="text-sm px-2.5 py-1.5 rounded border border-stone-300 text-stone-700">Résumé</a><?php endif; ?>
            <?php if ((int)$a['note_count']): ?><span class="text-xs text-stone-500"><?= (int)$a['note_count'] ?> note<?= (int)$a['note_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
          </div>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>

    <!-- Bulk bar (sticks to the bottom while something is ticked) -->
    <div id="jp-bar" class="fixed bottom-0 left-0 right-0 z-40 bg-stone-900 text-white p-3 shadow-2xl hidden">
      <div class="max-w-4xl mx-auto flex flex-wrap items-center gap-2 text-sm">
        <span id="jp-n" class="font-semibold mr-1">0 picked</span>
        <select name="bulk_do" id="jp-do" class="bg-white text-stone-900 border border-stone-300 rounded px-2 py-2 text-sm flex-1 min-w-[10rem]">
          <option value="">Do what?</option>
          <optgroup label="Move to">
            <?php foreach (jobs_bulk_statuses() as $k): ?><option value="status:<?= $k ?>"><?= $h($SL[$k]) ?></option><?php endforeach; ?>
          </optgroup>
          <option value="note">Add a note</option>
          <?php if ($F['show'] === 'archived'): ?><option value="unarchive">Bring back from archive</option><?php elseif ($F['show'] !== 'spam'): ?><option value="archive">Archive</option><?php endif; ?>
          <?php if ($F['show'] === 'spam'): ?><option value="not_spam">Not spam</option><?php else: ?><option value="spam">Mark as spam</option><?php endif; ?>
        </select>
        <input type="text" name="bulk_note" id="jp-note" maxlength="300" placeholder="Note (optional)" class="bg-white text-stone-900 border border-stone-300 rounded px-2 py-2 text-sm flex-1 min-w-[10rem]">
        <label id="jp-qwrap" class="hidden items-center gap-1.5 text-xs"><input type="checkbox" name="queue_email" value="1" checked> Queue "not selected" emails</label>
        <button class="bg-amber-600 hover:bg-amber-500 font-semibold px-4 py-2 rounded">Apply</button>
      </div>
    </div>
  </form>

  <!-- One small form per row for "Move to…" (selects point here with form="qmID") -->
  <?php foreach ($apps as $a): if ($a['status'] === 'hired' || (int)$a['is_spam']) continue; ?>
  <form method="POST" action="/admin/admin.php?view=jobs" id="qm<?= (int)$a['id'] ?>" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_pipeline_action" value="move">
    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
    <input type="hidden" name="queue_email" value="1">
    <input type="hidden" name="back" value="<?= $h($here) ?>">
  </form>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
// Pipeline list: quick move, select all, and the bulk bar.
(function () {
  var SL = <?= json_encode($SL) ?>;
  Array.prototype.forEach.call(document.querySelectorAll('.jp-qm'), function (s) {
    s.addEventListener('change', function () {
      if (!s.value) return;
      var label = SL[s.value] || s.value, msg = 'Move to ' + label + '?';
      if (s.value === 'not_selected') msg += '\nThe "not selected" email goes in the queue (nothing is sent yet).';
      if (s.value === 'interview' || s.value === 'offer') { s.form.submit(); return; }
      if (confirm(msg)) s.form.submit(); else s.value = '';
    });
  });
  var bulk = document.getElementById('jp-bulk'); if (!bulk) return;
  var all = document.getElementById('jp-all'), bar = document.getElementById('jp-bar'), n = document.getElementById('jp-n');
  var sel = document.getElementById('jp-do'), qw = document.getElementById('jp-qwrap'), note = document.getElementById('jp-note');
  var boxes = function () { return bulk.querySelectorAll('.jp-id'); };
  function sync() {
    var c = bulk.querySelectorAll('.jp-id:checked').length;
    bar.classList.toggle('hidden', c === 0);
    n.textContent = c + ' picked';
    all.checked = c > 0 && c === boxes().length;
    var ns = sel.value === 'status:not_selected';
    qw.classList.toggle('hidden', !ns); qw.classList.toggle('flex', ns);
    note.placeholder = sel.value === 'note' ? 'Type the note' : 'Note (optional)';
    note.required = sel.value === 'note';
  }
  all.addEventListener('change', function () { Array.prototype.forEach.call(boxes(), function (b) { b.checked = all.checked; }); sync(); });
  bulk.addEventListener('change', sync);
  bulk.addEventListener('submit', function (e) {
    var c = bulk.querySelectorAll('.jp-id:checked').length;
    if (!sel.value) { e.preventDefault(); alert('Pick what to do.'); return; }
    var t = sel.options[sel.selectedIndex].text;
    if (!confirm(t + ' for ' + c + ' application' + (c === 1 ? '' : 's') + '?')) e.preventDefault();
  });
  sync();
})();
</script>
