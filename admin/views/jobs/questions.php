<?php
// admin/views/jobs/questions.php
// Hiring > Question bank: reusable application questions and their choices.
// Archived questions can't be added to jobs but stay on past applications.
// Administrators only.
require_once __DIR__ . '/../../../includes/jobs/repo.php';

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$QT   = jobs_question_type_labels();
$input = 'w-full p-2 border border-stone-300 rounded bg-stone-50 text-sm';
$lbl   = 'block text-xs font-semibold text-stone-600 mb-1';

try { $all = jobs_questions($pdo); }
catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>The Jobs tables aren't set up yet. Run /admin/jobs_install.php first.</div>";
    return;
}

$editId    = (int)($_GET['edit'] ?? 0);
$isNew     = !empty($_GET['new']);
$returnJob = (int)($_GET['return_job'] ?? 0);
$q = $editId ? jobs_question($pdo, $editId) : null;

if ($isNew || $q):
    $old = $_SESSION['jobs_form_old']['q'] ?? null;
    unset($_SESSION['jobs_form_old']['q']);
    if ($old) { $returnJob = (int)($old['return_job'] ?? $returnJob); }
    $f = $old ?: ($q ?: ['type' => 'yes_no', 'options' => []]);
    $opts = $q['options'] ?? [];
    $usedOn = [];
    if ($q) {
        $st = $pdo->prepare("SELECT j.id, j.title FROM job_questions jq JOIN jobs j ON j.id = jq.job_id WHERE jq.question_id = ? ORDER BY j.title");
        $st->execute([$q['id']]);
        $usedOn = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $backHref = $returnJob ? '/admin/admin.php?view=jobs_posts&edit=' . $returnJob . '#questions' : '/admin/admin.php?view=jobs_questions';
?>
<div class="max-w-2xl">
  <a href="<?= $h($backHref) ?>" class="text-sm text-amber-700 hover:underline">&larr; <?= $returnJob ? 'Back to the job' : 'Question bank' ?></a>
  <h1 class="text-2xl font-bold text-stone-800 mt-1 mb-4"><?= $q ? 'Edit question' : 'New question' ?></h1>

  <?php if ($usedOn): ?>
  <p class="text-sm text-stone-600 bg-stone-50 border border-stone-200 rounded p-3 mb-4">
    Used on: <?= implode(', ', array_map(fn($j) => '<a class="text-amber-700 underline" href="/admin/admin.php?view=jobs_posts&edit=' . (int)$j['id'] . '">' . $h($j['title']) . '</a>', $usedOn)) ?>.
    Changes apply to those jobs from now on. Past applications keep the wording they were asked.
  </p>
  <?php endif; ?>

  <form method="POST" action="/admin/admin.php?view=jobs_questions" class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_setup_action" value="q_save">
    <input type="hidden" name="id" value="<?= (int)($q['id'] ?? 0) ?>">
    <input type="hidden" name="return_job" value="<?= $returnJob ?>">

    <div class="space-y-4">
      <div>
        <label class="<?= $lbl ?>" for="qf-label">Question *</label>
        <textarea id="qf-label" name="label" rows="2" required maxlength="300" class="<?= $input ?>"><?= $h($f['label'] ?? '') ?></textarea>
      </div>
      <div>
        <label class="<?= $lbl ?>" for="qf-help">Hint under the question (optional)</label>
        <input id="qf-help" type="text" name="help_text" maxlength="500" value="<?= $h($f['help_text'] ?? '') ?>" class="<?= $input ?>">
      </div>
      <div>
        <label class="<?= $lbl ?>" for="qf-type">Answer type</label>
        <select id="qf-type" name="type" class="<?= $input ?>">
          <?php foreach ($QT as $k => $l): ?><option value="<?= $k ?>" <?= ($f['type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
        <p id="qf-yn-note" class="text-xs text-stone-400 mt-1">For Yes / No questions, you choose the knockout answer on each job.</p>
      </div>

      <div id="qf-opts">
        <label class="<?= $lbl ?>">Choices</label>
        <p class="text-xs text-stone-400 mb-2">Tick <strong>Knockout</strong> on a choice that should automatically mark the application Not Selected. Clear a box or tick Remove to delete a choice.</p>
        <div class="space-y-2">
          <?php foreach ($opts as $o): $oid = (int)$o['id']; ?>
          <div class="flex flex-wrap gap-2 items-center">
            <input type="text" name="opt_label[<?= $oid ?>]" maxlength="200" value="<?= $h($o['label']) ?>" class="<?= $input ?> flex-1 min-w-[10rem]">
            <label class="text-xs text-stone-600 flex items-center gap-1"><input type="checkbox" name="opt_ko[<?= $oid ?>]" value="1" <?= (int)$o['is_knockout'] ? 'checked' : '' ?>> Knockout</label>
            <label class="text-xs text-red-600 flex items-center gap-1"><input type="checkbox" name="opt_del[<?= $oid ?>]" value="1"> Remove</label>
          </div>
          <?php endforeach; ?>
          <?php
            $newRows = max(2, 5 - count($opts));
            $oldNew  = (array)($old['new_opt_label'] ?? []);
            for ($i = 0; $i < $newRows; $i++): ?>
          <div class="flex flex-wrap gap-2 items-center">
            <input type="text" name="new_opt_label[<?= $i ?>]" maxlength="200" value="<?= $h($oldNew[$i] ?? '') ?>" class="<?= $input ?> flex-1 min-w-[10rem]" placeholder="<?= $opts || $i ? 'Another choice' : 'First choice' ?>">
            <label class="text-xs text-stone-600 flex items-center gap-1"><input type="checkbox" name="new_opt_ko[<?= $i ?>]" value="1" <?= !empty($old['new_opt_ko'][$i]) ? 'checked' : '' ?>> Knockout</label>
          </div>
          <?php endfor; ?>
        </div>
        <p class="text-xs text-stone-400 mt-2">Need more? Save, and more blank rows appear.</p>
      </div>
    </div>

    <div class="mt-5 flex flex-wrap gap-3">
      <button type="submit" class="bg-amber-700 hover:bg-amber-800 text-white font-semibold px-5 py-2 rounded text-sm"><?= $q ? 'Save question' : ($returnJob ? 'Save and add to job' : 'Save question') ?></button>
      <a href="<?= $h($backHref) ?>" class="px-5 py-2 rounded text-sm border border-stone-300 text-stone-600 hover:bg-stone-100">Cancel</a>
    </div>
  </form>

  <?php if ($q): ?>
  <form method="POST" action="/admin/admin.php?view=jobs_questions" class="mt-4">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_setup_action" value="<?= (int)$q['is_archived'] ? 'q_unarchive' : 'q_archive' ?>">
    <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
    <button class="px-4 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100"><?= (int)$q['is_archived'] ? 'Restore question' : 'Archive question' ?></button>
  </form>
  <?php endif; ?>
</div>
<script>
(function () {
  var t = document.getElementById('qf-type'), box = document.getElementById('qf-opts'), yn = document.getElementById('qf-yn-note');
  function sync() { box.style.display = (t.value === 'single_choice' || t.value === 'multi_choice') ? '' : 'none'; yn.style.display = t.value === 'yes_no' ? '' : 'none'; }
  t.addEventListener('change', sync); sync();
})();
</script>
<?php
    return;
endif;

// ── LIST ────────────────────────────────────────────────────────────────────
$active   = array_filter($all, fn($r) => !(int)$r['is_archived']);
$archived = array_filter($all, fn($r) => (int)$r['is_archived']);
?>
<div class="max-w-4xl">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hiring</h1>
  <p class="text-sm text-stone-500 mb-4">Write a question once, then attach it to any job. Don't ask about age beyond the age range, health conditions, religion, family plans or other protected traits.</p>
  <?php require __DIR__ . '/_tabs.php'; ?>

  <div class="flex items-center justify-between mb-4">
    <h2 class="font-semibold text-stone-700">Questions (<?= count($active) ?>)</h2>
    <a href="/admin/admin.php?view=jobs_questions&new=1" class="bg-amber-700 hover:bg-amber-800 text-white font-semibold px-4 py-2 rounded text-sm">+ New question</a>
  </div>

  <?php if (!$all): ?>
  <div class="bg-white border border-dashed border-stone-300 rounded-lg p-6 text-center text-sm text-stone-500">
    <p class="mb-3">The question bank is empty. Start with 8 common farm questions (lifting, transportation, weather, animals, schedule, experience, hours, how they heard), then edit them to suit.</p>
    <form method="POST" action="/admin/admin.php?view=jobs_questions">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="jobs_setup_action" value="q_starters">
      <button class="px-4 py-2 rounded text-sm bg-green-700 hover:bg-green-800 text-white font-semibold">Add starter questions</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="space-y-2">
    <?php foreach ($active as $r): ?>
    <a href="/admin/admin.php?view=jobs_questions&edit=<?= (int)$r['id'] ?>" class="block bg-white border border-stone-200 rounded-lg p-3 hover:border-amber-400">
      <div class="text-sm text-stone-800"><?= $h($r['label']) ?></div>
      <div class="text-xs text-stone-400 mt-0.5"><?= $h($QT[$r['type']] ?? $r['type']) ?><?= (int)$r['option_count'] ? ' · ' . (int)$r['option_count'] . ' choices' : '' ?> · on <?= (int)$r['used_on'] ?> job<?= (int)$r['used_on'] === 1 ? '' : 's' ?></div>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($archived): ?>
  <details class="mt-6">
    <summary class="cursor-pointer text-sm text-stone-500">Archived (<?= count($archived) ?>)</summary>
    <div class="space-y-2 mt-2">
      <?php foreach ($archived as $r): ?>
      <a href="/admin/admin.php?view=jobs_questions&edit=<?= (int)$r['id'] ?>" class="block bg-stone-50 border border-stone-200 rounded-lg p-3 text-stone-500">
        <div class="text-sm"><?= $h($r['label']) ?></div>
        <div class="text-xs mt-0.5"><?= $h($QT[$r['type']] ?? $r['type']) ?><?= (int)$r['used_on'] ? ' · still on ' . (int)$r['used_on'] . ' job' . ((int)$r['used_on'] === 1 ? '' : 's') : '' ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>
</div>
