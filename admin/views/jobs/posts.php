<?php
// admin/views/jobs/posts.php
// Hiring > Jobs: list of reusable job postings, and the job editor
// (details, hiring round open/close, questions attached to the job).
// Administrators only (admin_only in the view registry).
require_once __DIR__ . '/../../../includes/jobs/repo.php';

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$editRaw = (string)($_GET['edit'] ?? '');
$isNew   = $editRaw === 'new';
$editId  = $isNew ? 0 : (int)$editRaw;
$money   = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$fmtDate = fn($d) => $d ? date('M j, Y', strtotime($d)) : '';

try { $cats = jobs_categories($pdo); }
catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>The Jobs tables aren't set up yet. Run /admin/jobs_install.php first.</div>";
    return;
}

$ENG = jobs_engagement_labels();
$SCH = jobs_schedule_labels();

// ════════════════════════════════════════════════════════════════════════════
// LIST
// ════════════════════════════════════════════════════════════════════════════
if (!$isNew && $editId === 0):
    $jobs = jobs_list_with_cycles($pdo);
?>
<div class="max-w-4xl">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hiring</h1>
  <p class="text-sm text-stone-500 mb-4">Reusable job postings. Open hiring on a job to post it; close it when you're done. Each time you open it again starts a new hiring round.</p>
  <?php require __DIR__ . '/_tabs.php'; ?>

  <div class="flex items-center justify-between mb-4">
    <h2 class="font-semibold text-stone-700">Jobs (<?= count($jobs) ?>)</h2>
    <a href="/admin/admin.php?view=jobs_posts&edit=new" class="bg-amber-700 hover:bg-amber-800 text-white font-semibold px-4 py-2 rounded text-sm">+ Add a job</a>
  </div>

  <?php if (!$jobs): ?>
    <div class="bg-white border border-dashed border-stone-300 rounded-lg p-6 text-center text-sm text-stone-500">
      No jobs yet. Tap <strong>Add a job</strong> to write your first posting. Tip: set up the <a class="text-amber-700 underline" href="/admin/admin.php?view=jobs_questions">question bank</a> first so you can attach questions.
    </div>
  <?php endif; ?>

  <div class="space-y-3">
  <?php foreach ($jobs as $j):
      $open = !empty($j['open_cycle_id']);
      $expired = $open && !empty($j['closes_at']) && $j['closes_at'] < jobs_today();
  ?>
    <div class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
          <a href="/admin/admin.php?view=jobs_posts&edit=<?= (int)$j['id'] ?>" class="text-base font-semibold text-stone-800 hover:text-amber-800"><?= $h($j['title']) ?></a>
          <div class="text-xs text-stone-500 mt-0.5">
            <?= $h($j['category_name']) ?> · <?= $h($ENG[$j['engagement_type']] ?? $j['engagement_type']) ?> · <?= $h($SCH[$j['schedule_type']] ?? '') ?>
            <?= (int)$j['min_age'] < 18 ? ' · ' . (int)$j['min_age'] . '+' : '' ?>
          </div>
        </div>
        <?php if ($expired): ?>
          <span class="text-xs font-semibold px-2 py-1 rounded bg-amber-100 text-amber-800">Closing date passed</span>
        <?php elseif ($open): ?>
          <span class="text-xs font-semibold px-2 py-1 rounded bg-green-100 text-green-800">Hiring open</span>
        <?php else: ?>
          <span class="text-xs font-semibold px-2 py-1 rounded bg-stone-100 text-stone-500">Closed</span>
        <?php endif; ?>
      </div>
      <?php if ($open): ?>
      <div class="text-sm text-stone-600 mt-2">
        <?= (int)$j['hires_count'] ?> of <?= (int)$j['openings'] ?> filled
        · <?= (int)$j['cycle_apps'] ?> application<?= (int)$j['cycle_apps'] === 1 ? '' : 's' ?><?= (int)$j['cycle_new'] ? ' (' . (int)$j['cycle_new'] . ' new)' : '' ?>
        <?= $j['closes_at'] ? ' · closes ' . $h($fmtDate($j['closes_at'])) : ' · no closing date' ?>
      </div>
      <?php elseif ((int)$j['total_apps']): ?>
      <div class="text-xs text-stone-400 mt-2"><?= (int)$j['total_apps'] ?> past application<?= (int)$j['total_apps'] === 1 ? '' : 's' ?></div>
      <?php endif; ?>
      <div class="flex gap-2 mt-3">
        <a href="/admin/admin.php?view=jobs_posts&edit=<?= (int)$j['id'] ?>" class="px-3 py-1.5 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100">Edit</a>
        <?php if ($open): ?>
        <form method="POST" action="/admin/admin.php?view=jobs_posts" onsubmit="return confirm('Close hiring for this job? It comes off the public Jobs page.');">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="jobs_setup_action" value="job_close">
          <input type="hidden" name="from" value="list">
          <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
          <button class="px-3 py-1.5 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100">Close hiring</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php
    return;
endif;

// ════════════════════════════════════════════════════════════════════════════
// EDITOR
// ════════════════════════════════════════════════════════════════════════════
$job = $editId ? jobs_find($pdo, $editId) : null;
if ($editId && !$job) {
    echo "<p class='text-stone-500 p-6'>Job not found. <a class='text-amber-700 underline' href='/admin/admin.php?view=jobs_posts'>Back to jobs</a></p>";
    return;
}
if (!$cats) {
    echo "<div class='max-w-3xl p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>Add a category first: <a class='underline' href='/admin/admin.php?view=jobs_categories'>Categories</a>.</div>";
    return;
}

// Re-fill from a failed save, otherwise from the saved job.
$old = $_SESSION['jobs_form_old']['job'] ?? null;
unset($_SESSION['jobs_form_old']['job']);
$f = $old ?: ($job ?: ['engagement_type' => 'paid', 'schedule_type' => 'part_time', 'pay_unit' => 'hour', 'min_age' => 18, 'default_openings' => 1]);
$fv = fn($k, $d = '') => $h($f[$k] ?? $d);
$fm = fn($k) => $h(isset($f[$k]) && $old ? $f[$k] : $money($f[$k] ?? null));

$cycle   = $job ? jobs_open_cycle_row($pdo, $editId) : null;
$cycles  = $job ? jobs_cycles($pdo, $editId) : [];
$jqs     = $job ? jobs_job_questions($pdo, $editId) : [];
$bank    = $job ? jobs_questions($pdo, false) : [];
$attachedIds = array_map(fn($r) => (int)$r['question_id'], $jqs);
$catMinors = [];
foreach ($cats as $c) $catMinors[(int)$c['id']] = (int)$c['allows_minors'];
$QT = jobs_question_type_labels();
$input = 'w-full p-2 border border-stone-300 rounded bg-stone-50 text-sm';
$lbl   = 'block text-xs font-semibold text-stone-600 mb-1';
?>
<div class="max-w-3xl">
  <a href="/admin/admin.php?view=jobs_posts" class="text-sm text-amber-700 hover:underline">&larr; All jobs</a>
  <h1 class="text-2xl font-bold text-stone-800 mt-1 mb-4"><?= $job ? $h($job['title']) : 'New job' ?></h1>

  <?php if ($job): ?>
  <!-- ── Hiring round ─────────────────────────────────────────────────── -->
  <section class="bg-white border <?= $cycle ? 'border-green-300' : 'border-stone-200' ?> rounded-lg p-4 mb-6 shadow-sm">
    <?php if ($cycle): ?>
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h2 class="font-semibold text-green-800">Hiring is open</h2>
        <span class="text-xs text-stone-500">Since <?= $h(date('M j, Y g:ia', strtotime($cycle['opened_at']))) ?> · <?= (int)$cycle['hires_count'] ?> of <?= (int)$cycle['openings'] ?> filled</span>
      </div>
      <?php if ($cycle['closes_at'] && $cycle['closes_at'] < jobs_today()): ?>
        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded p-2 mb-3">The closing date has passed, so the public page no longer takes applications. Set a new date or close hiring.</p>
      <?php endif; ?>
      <form method="POST" action="/admin/admin.php?view=jobs_posts" class="grid grid-cols-2 gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="jobs_setup_action" value="cycle_update">
        <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
        <div><label class="<?= $lbl ?>">Openings</label><input type="number" name="openings" min="<?= max(1, (int)$cycle['hires_count']) ?>" max="99" value="<?= (int)$cycle['openings'] ?>" class="<?= $input ?>"></div>
        <div><label class="<?= $lbl ?>">Last day to apply</label><input type="date" name="closes_at" value="<?= $h($cycle['closes_at']) ?>" class="<?= $input ?>"></div>
        <label class="col-span-2 flex items-center gap-2 text-sm text-stone-600"><input type="checkbox" name="auto_close" value="1" <?= (int)$cycle['auto_close_when_filled'] ? 'checked' : '' ?>> Close hiring automatically when all openings are filled</label>
        <div class="col-span-2"><button class="px-4 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100">Update round</button></div>
      </form>
      <form method="POST" action="/admin/admin.php?view=jobs_posts" class="mt-3 pt-3 border-t border-stone-100" onsubmit="return confirm('Close hiring? The job comes off the public Jobs page. Applications stay in the pipeline.');">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="jobs_setup_action" value="job_close">
        <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
        <button class="px-4 py-2 rounded text-sm bg-stone-700 hover:bg-stone-800 text-white font-semibold">Close hiring</button>
      </form>
    <?php else: ?>
      <h2 class="font-semibold text-stone-700 mb-1">Hiring is closed</h2>
      <p class="text-sm text-stone-500 mb-3">Opening hiring posts this job and starts a new round<?= $cycles ? ' (round ' . (count($cycles) + 1) . ')' : '' ?>.</p>
      <form method="POST" action="/admin/admin.php?view=jobs_posts" class="grid grid-cols-2 gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="jobs_setup_action" value="job_open">
        <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
        <div><label class="<?= $lbl ?>">Openings</label><input type="number" name="openings" min="1" max="99" value="<?= (int)$job['default_openings'] ?>" class="<?= $input ?>"></div>
        <div><label class="<?= $lbl ?>">Last day to apply (optional)</label><input type="date" name="closes_at" min="<?= jobs_today() ?>" class="<?= $input ?>"></div>
        <label class="col-span-2 flex items-center gap-2 text-sm text-stone-600"><input type="checkbox" name="auto_close" value="1" checked> Close hiring automatically when all openings are filled</label>
        <div class="col-span-2"><button class="px-4 py-2 rounded text-sm bg-green-700 hover:bg-green-800 text-white font-semibold">Open hiring</button></div>
      </form>
      <?php if (!$jqs): ?><p class="text-xs text-stone-400 mt-2">No questions attached yet. That's fine, but you may want to add some below first.</p><?php endif; ?>
    <?php endif; ?>

    <?php if ($cycles): ?>
    <details class="mt-4 text-sm">
      <summary class="cursor-pointer text-stone-500">Past and current rounds (<?= count($cycles) ?>)</summary>
      <ul class="mt-2 space-y-1 text-stone-600">
        <?php foreach ($cycles as $n => $cy): ?>
        <li>Round <?= count($cycles) - $n ?>: <?= $h(date('M j, Y', strtotime($cy['opened_at']))) ?> to <?= $cy['closed_at'] ? $h(date('M j, Y', strtotime($cy['closed_at']))) : 'now' ?>
          · <?= (int)$cy['hires_count'] ?>/<?= (int)$cy['openings'] ?> filled · <?= (int)$cy['apps'] ?> application<?= (int)$cy['apps'] === 1 ? '' : 's' ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- ── Job details ──────────────────────────────────────────────────── -->
  <form method="POST" action="/admin/admin.php?view=jobs_posts" class="bg-white border border-stone-200 rounded-lg p-4 mb-6 shadow-sm" id="job-form">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_setup_action" value="job_save">
    <input type="hidden" name="id" value="<?= (int)($job['id'] ?? 0) ?>">
    <h2 class="font-semibold text-stone-700 mb-3">Job details</h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="sm:col-span-2">
        <label class="<?= $lbl ?>" for="jf-title">Job title *</label>
        <input id="jf-title" type="text" name="title" required maxlength="150" value="<?= $fv('title') ?>" class="<?= $input ?>" placeholder="Farmstand Associate">
      </div>
      <div class="sm:col-span-2">
        <label class="<?= $lbl ?>" for="jf-slug">Web address</label>
        <div class="flex items-center text-sm"><span class="text-stone-400 mr-1">/jobs/</span>
          <input id="jf-slug" type="text" name="slug" maxlength="80" value="<?= $fv('slug') ?>" class="<?= $input ?>" placeholder="made from the title">
        </div>
        <?php if ($job && $cycles): ?><p class="text-xs text-stone-400 mt-1">This job has been posted before. Changing the address breaks old links.</p><?php endif; ?>
      </div>
      <div>
        <label class="<?= $lbl ?>" for="jf-cat">Category *</label>
        <select id="jf-cat" name="category_id" class="<?= $input ?>">
          <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)($f['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= $h($c['name']) ?><?= (int)$c['is_active'] ? '' : ' (inactive)' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="<?= $lbl ?>" for="jf-age">Minimum age</label>
        <select id="jf-age" name="min_age" class="<?= $input ?>">
          <?php foreach (jobs_min_age_labels() as $a => $al): ?>
          <option value="<?= $a ?>" <?= (int)($f['min_age'] ?? 18) === $a ? 'selected' : '' ?>><?= $al ?></option>
          <?php endforeach; ?>
        </select>
        <p id="jf-age-note" class="text-xs text-stone-400 mt-1">Under 18 is only allowed in Thrift Shop and Farmstand. Minors need an NC youth employment certificate before their first shift, and under-16s have stricter hour limits.</p>
      </div>
      <div>
        <label class="<?= $lbl ?>" for="jf-eng">Type of work</label>
        <select id="jf-eng" name="engagement_type" class="<?= $input ?>">
          <?php foreach ($ENG as $k => $l): ?><option value="<?= $k ?>" <?= ($f['engagement_type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="<?= $lbl ?>" for="jf-sch">Schedule</label>
        <select id="jf-sch" name="schedule_type" class="<?= $input ?>">
          <?php foreach ($SCH as $k => $l): ?><option value="<?= $k ?>" <?= ($f['schedule_type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
      </div>

      <!-- Pay (paid + internship) -->
      <div class="sm:col-span-2 grid grid-cols-3 gap-3" id="jf-pay">
        <div><label class="<?= $lbl ?>">Pay from *</label><input type="text" inputmode="decimal" name="pay_min" value="<?= $fm('pay_min') ?>" class="<?= $input ?>" placeholder="14"></div>
        <div><label class="<?= $lbl ?>">Pay up to</label><input type="text" inputmode="decimal" name="pay_max" value="<?= $fm('pay_max') ?>" class="<?= $input ?>" placeholder="optional"></div>
        <div><label class="<?= $lbl ?>">Per</label>
          <select name="pay_unit" class="<?= $input ?>">
            <?php foreach (jobs_pay_unit_labels() as $k => $l): ?><option value="<?= $k ?>" <?= ($f['pay_unit'] ?? 'hour') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
        </div>
        <p class="col-span-3 text-xs text-stone-400">Hourly pay must be at least $<?= number_format(JOBS_MIN_WAGE, 2) ?>. Leave "up to" blank for a single rate.</p>
      </div>

      <!-- Work-trade -->
      <div class="sm:col-span-2 grid grid-cols-1 gap-3" id="jf-wt">
        <div><label class="<?= $lbl ?>">What the work-trade includes *</label>
          <textarea name="work_trade_terms" rows="3" class="<?= $input ?>" placeholder="Weekly produce share and farm store credit in exchange for 6 hours a week."><?= $fv('work_trade_terms') ?></textarea></div>
        <div class="max-w-xs"><label class="<?= $lbl ?>">Value per hour worked *</label><input type="text" inputmode="decimal" name="work_trade_hourly_value" value="<?= $fm('work_trade_hourly_value') ?>" class="<?= $input ?>" placeholder="<?= number_format(JOBS_MIN_WAGE, 2) ?>"></div>
        <p class="text-xs text-stone-400">Because the farm is a business, work-trade must be worth at least minimum wage ($<?= number_format(JOBS_MIN_WAGE, 2) ?>) per hour. This value isn't shown publicly.</p>
      </div>

      <div class="sm:col-span-2">
        <label class="<?= $lbl ?>">Schedule details</label>
        <input type="text" name="schedule_notes" maxlength="300" value="<?= $fv('schedule_notes') ?>" class="<?= $input ?>" placeholder="Saturdays and Sundays, 9am to 3pm, April through October">
      </div>
      <div class="sm:col-span-2">
        <label class="<?= $lbl ?>">Short summary (shown on the Jobs list)</label>
        <input type="text" name="summary" maxlength="300" value="<?= $fv('summary') ?>" class="<?= $input ?>">
      </div>
      <?php foreach (['description' => 'About the job', 'responsibilities' => 'What you\'ll do (one per line)', 'requirements' => 'What you need (one per line)', 'physical_requirements' => 'Physical requirements (one per line)'] as $k => $l): ?>
      <div class="sm:col-span-2">
        <label class="<?= $lbl ?>"><?= $l ?></label>
        <textarea name="<?= $k ?>" rows="<?= $k === 'description' ? 4 : 3 ?>" class="<?= $input ?>"><?= $fv($k) ?></textarea>
      </div>
      <?php endforeach; ?>
      <div>
        <label class="<?= $lbl ?>">Usual number of openings</label>
        <input type="number" name="default_openings" min="1" max="99" value="<?= $fv('default_openings', 1) ?>" class="<?= $input ?>">
      </div>
    </div>

    <div class="mt-5 flex flex-wrap gap-3">
      <button type="submit" class="bg-amber-700 hover:bg-amber-800 text-white font-semibold px-5 py-2 rounded text-sm"><?= $job ? 'Save job' : 'Save and continue' ?></button>
      <a href="/admin/admin.php?view=jobs_posts" class="px-5 py-2 rounded text-sm border border-stone-300 text-stone-600 hover:bg-stone-100">Cancel</a>
    </div>
  </form>

  <?php if ($job): ?>
  <!-- ── Questions on this job ───────────────────────────────────────── -->
  <section id="questions" class="bg-white border border-stone-200 rounded-lg p-4 mb-6 shadow-sm">
    <h2 class="font-semibold text-stone-700 mb-1">Application questions</h2>
    <p class="text-xs text-stone-500 mb-3">Asked in this order after the contact details. A knockout answer on a yes/no question automatically marks the application Not Selected. For pick-one or pick-any questions, knockout choices are set in the question bank.</p>

    <?php if ($jqs): ?>
    <form method="POST" action="/admin/admin.php?view=jobs_posts" id="jq-form">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="jobs_setup_action" value="jq_save">
      <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
    </form>
    <ol class="space-y-2 mb-3">
      <?php foreach ($jqs as $i => $q): $jqid = (int)$q['id']; ?>
      <li class="border border-stone-200 rounded p-3 <?= (int)$q['is_archived'] ? 'bg-stone-50' : '' ?>">
        <div class="flex gap-2 items-start">
          <span class="text-stone-400 text-sm w-5 shrink-0"><?= $i + 1 ?>.</span>
          <div class="flex-1 min-w-0">
            <div class="text-sm text-stone-800"><?= $h($q['label']) ?></div>
            <div class="text-xs text-stone-400"><?= $h($QT[$q['type']] ?? $q['type']) ?><?php
              if ($q['options']) { $ko = array_filter($q['options'], fn($o) => (int)$o['is_knockout']); echo ' · ' . count($q['options']) . ' choices' . ($ko ? ' · knockout: ' . $h(implode(', ', array_column($ko, 'label'))) : ''); }
              if ((int)$q['is_archived']) echo ' · <span class="text-amber-700">archived in bank</span>';
            ?></div>
            <div class="flex flex-wrap gap-4 mt-2 text-sm">
              <label class="flex items-center gap-1 text-stone-600"><input type="checkbox" form="jq-form" name="jq_required[<?= $jqid ?>]" value="1" <?= (int)$q['is_required'] ? 'checked' : '' ?>> Required</label>
              <?php if ($q['type'] === 'yes_no'): ?>
              <label class="flex items-center gap-2 text-stone-600"><span class="whitespace-nowrap">Knockout:</span>
                <select form="jq-form" name="jq_knockout[<?= $jqid ?>]" class="p-1 border border-stone-300 rounded bg-stone-50 text-sm">
                  <option value="none" <?= $q['knockout_answer'] === 'none' ? 'selected' : '' ?>>never</option>
                  <option value="no" <?= $q['knockout_answer'] === 'no' ? 'selected' : '' ?>>they answer No</option>
                  <option value="yes" <?= $q['knockout_answer'] === 'yes' ? 'selected' : '' ?>>they answer Yes</option>
                </select>
              </label>
              <?php endif; ?>
            </div>
          </div>
          <div class="flex flex-col gap-1 shrink-0">
            <?php foreach (['up' => '&uarr;', 'down' => '&darr;'] as $dir => $arrow):
                if (($dir === 'up' && $i === 0) || ($dir === 'down' && $i === count($jqs) - 1)) { echo '<span class="w-8 h-7"></span>'; continue; } ?>
            <form method="POST" action="/admin/admin.php?view=jobs_posts">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="jobs_setup_action" value="jq_move">
              <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
              <input type="hidden" name="jq_id" value="<?= $jqid ?>">
              <input type="hidden" name="dir" value="<?= $dir ?>">
              <button class="w-8 h-7 border border-stone-300 rounded text-stone-600 hover:bg-stone-100" aria-label="Move <?= $dir ?>"><?= $arrow ?></button>
            </form>
            <?php endforeach; ?>
            <form method="POST" action="/admin/admin.php?view=jobs_posts" onsubmit="return confirm('Remove this question from the job? It stays in the question bank.');">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="jobs_setup_action" value="jq_remove">
              <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
              <input type="hidden" name="jq_id" value="<?= $jqid ?>">
              <button class="w-8 h-7 border border-red-200 rounded text-red-600 hover:bg-red-50" aria-label="Remove">&times;</button>
            </form>
          </div>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
    <button type="submit" form="jq-form" class="px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold mb-4">Save required and knockout settings</button>
    <?php else: ?>
    <p class="text-sm text-stone-400 mb-3">No questions yet.</p>
    <?php endif; ?>

    <?php $avail = array_filter($bank, fn($q) => !in_array((int)$q['id'], $attachedIds, true)); ?>
    <div class="border-t border-stone-100 pt-3">
      <?php if ($avail): ?>
      <form method="POST" action="/admin/admin.php?view=jobs_posts" class="flex flex-wrap gap-2 items-end">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="jobs_setup_action" value="jq_add">
        <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
        <div class="flex-1 min-w-[12rem]">
          <label class="<?= $lbl ?>">Add from the question bank</label>
          <select name="question_id" class="<?= $input ?>">
            <?php foreach ($avail as $q): ?><option value="<?= (int)$q['id'] ?>"><?= $h(mb_strimwidth($q['label'], 0, 80, '…')) ?> (<?= $h($QT[$q['type']] ?? '') ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <button class="px-4 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100">Add</button>
      </form>
      <?php endif; ?>
      <a href="/admin/admin.php?view=jobs_questions&new=1&return_job=<?= (int)$job['id'] ?>" class="inline-block mt-3 text-sm text-amber-700 hover:underline">+ Write a new question for this job</a>
    </div>
  </section>

  <!-- ── Applications (temporary list until the pipeline screen ships) ── -->
  <?php
    require_once __DIR__ . '/../../../includes/jobs/status.php';
    $appCycle = $cycle ?: ($cycles[0] ?? null);
    $apps = [];
    if ($appCycle) {
        $st = $pdo->prepare("SELECT a.id, a.first_name, a.last_name, a.email, a.phone, a.age_bracket, a.guardian_name, a.status, a.is_spam,
                                    a.knockout_flag, a.is_returning, a.prior_match, a.submitted_at,
                                    (SELECT f.id FROM application_files f WHERE f.application_id = a.id ORDER BY f.id LIMIT 1) AS file_id
                               FROM applications a WHERE a.job_cycle_id = ? ORDER BY a.submitted_at DESC, a.id DESC LIMIT 200");
        $st->execute([(int)$appCycle['id']]);
        $apps = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $SL = jobs_status_labels();
  ?>
  <section id="applications" class="bg-white border border-stone-200 rounded-lg p-4 mb-6 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-1">Applications <?= $cycle ? 'this round' : 'in the last round' ?> <span class="text-stone-400 font-normal">(<?= count($apps) ?>)</span></h2>
    <p class="text-xs text-stone-500 mb-3">A simple list for now. Moving people through the steps (review, interview, offer, hire) comes in the next update.</p>
    <?php if (!$apps): ?>
      <p class="text-sm text-stone-400">No applications yet.<?php if ($cycle): ?> Public page: <a class="text-amber-700 hover:underline" href="/jobs/<?= $h(rawurlencode($job['slug'])) ?>/" target="_blank" rel="noopener">/jobs/<?= $h($job['slug']) ?>/</a><?php endif; ?></p>
    <?php else: ?>
      <ul class="divide-y divide-stone-100">
      <?php foreach ($apps as $a):
          $flags = [];
          if ((int)$a['knockout_flag']) $flags[] = ['Knockout', 'bg-red-100 text-red-800'];
          if ($a['age_bracket'] !== '18_plus') $flags[] = ['Under 18 (' . str_replace('_', '–', $a['age_bracket']) . ')', 'bg-sky-100 text-sky-800'];
          if ((int)$a['is_returning']) $flags[] = ['Returning', 'bg-stone-100 text-stone-700'];
          if ((int)$a['prior_match']) $flags[] = ['Seen before', 'bg-stone-100 text-stone-700'];
          if ((int)$a['is_spam']) $flags[] = ['Spam', 'bg-stone-200 text-stone-600'];
      ?>
        <li class="py-2 flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="text-sm font-semibold text-stone-800"><?= $h($a['first_name'] . ' ' . $a['last_name']) ?></div>
            <div class="text-xs text-stone-500 break-all"><a class="hover:underline" href="mailto:<?= $h($a['email']) ?>"><?= $h($a['email']) ?></a><?= $a['phone'] ? ' · <a class="hover:underline" href="tel:' . $h(preg_replace('/[^0-9+]/', '', $a['phone'])) . '">' . $h($a['phone']) . '</a>' : '' ?></div>
            <div class="text-xs text-stone-400"><?= $h(date('M j, g:ia', strtotime($a['submitted_at']))) ?><?= $a['guardian_name'] ? ' · Guardian: ' . $h($a['guardian_name']) : '' ?></div>
            <?php if ($flags): ?><div class="flex flex-wrap gap-1 mt-1"><?php foreach ($flags as [$t, $c]): ?><span class="text-[11px] px-2 py-0.5 rounded <?= $c ?>"><?= $h($t) ?></span><?php endforeach; ?></div><?php endif; ?>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <span class="text-xs px-2 py-1 rounded bg-amber-50 text-amber-800 border border-amber-200"><?= $h($SL[$a['status']] ?? $a['status']) ?></span>
            <?php if ($a['file_id']): ?><a class="text-xs px-2 py-1 rounded border border-stone-300 text-stone-700 hover:bg-stone-100" href="/admin/jobs_file.php?id=<?= (int)$a['file_id'] ?>" target="_blank" rel="noopener">Résumé</a><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- ── More ─────────────────────────────────────────────────────────── -->
  <section class="flex flex-wrap gap-3 mb-10">
    <form method="POST" action="/admin/admin.php?view=jobs_posts">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="jobs_setup_action" value="job_duplicate">
      <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
      <button class="px-4 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100">Make a copy</button>
    </form>
    <form method="POST" action="/admin/admin.php?view=jobs_posts" onsubmit="return confirm('Delete this job for good? Only possible if nobody has applied.');">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="jobs_setup_action" value="job_delete">
      <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
      <button class="px-4 py-2 rounded text-sm border border-red-200 text-red-700 hover:bg-red-50">Delete job</button>
    </form>
  </section>
  <?php endif; ?>
</div>

<script>
// Job editor: show pay or work-trade fields, and only allow under-18 in categories that permit it.
(function () {
  var eng = document.getElementById('jf-eng'), pay = document.getElementById('jf-pay'), wt = document.getElementById('jf-wt');
  var cat = document.getElementById('jf-cat'), age = document.getElementById('jf-age');
  var minors = <?= json_encode($catMinors) ?>;
  function sync() {
    var isWT = eng.value === 'work_trade';
    pay.style.display = isWT ? 'none' : '';
    wt.style.display  = isWT ? '' : 'none';
    var ok = !!minors[cat.value];
    Array.prototype.forEach.call(age.options, function (o) { if (o.value !== '18') o.disabled = !ok; });
    if (!ok && age.value !== '18') age.value = '18';
  }
  eng.addEventListener('change', sync); cat.addEventListener('change', sync); sync();
})();
</script>
