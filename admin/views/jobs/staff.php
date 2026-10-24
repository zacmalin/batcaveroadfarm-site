<?php
// admin/views/jobs/staff.php
// Hiring > Staff (Jobs pack P6): people hired through Jobs.
//   list        ?view=jobs_staff[&show=current|past|all][&q=]
//   one person  ?view=jobs_staff&user=ID — jobs (stints), set-password link,
//               youth certificate, end a job, login on/off
// Administrators only. Saves go to admin/handlers/jobs_hire.php (jobs_hire_action).
require_once __DIR__ . '/../../../includes/jobs/hire.php';

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$ENG  = jobs_engagement_labels();
$UNITS = jobs_pay_unit_labels();
$ROLES = jobs_staff_roles();
$d    = fn($x, $f = 'M j, Y') => $x ? date($f, strtotime($x)) : '';
$tel  = fn($p) => preg_replace('/[^0-9+]/', '', (string)$p);
$chip = fn(string $t, string $c) => '<span class="inline-block whitespace-nowrap text-[11px] px-2 py-0.5 rounded ' . $c . '">' . htmlspecialchars($t) . '</span>';
$loginChip = function (array $u) use ($chip): string {
    if ($u['account_status'] === 'disabled') return $chip('Login off', 'bg-stone-200 text-stone-600');
    if ($u['account_status'] === 'invited' || empty($u['has_password'] ?? $u['password'] ?? null)) return $chip('Hasn\'t set password', 'bg-amber-100 text-amber-800');
    return $chip('Login on', 'bg-emerald-50 text-emerald-800');
};

try { $pdo->query('SELECT 1 FROM user_employment LIMIT 1'); }
catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>The Jobs tables aren't set up yet. Run /admin/jobs_install.php first.</div>";
    return;
}

$userId = (int)($_GET['user'] ?? 0);
$btn  = 'text-sm font-semibold px-4 py-2.5 rounded border';
$in   = 'mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm';

// ════════════════════════════════════════════════════════════════════════════
// ONE PERSON
// ════════════════════════════════════════════════════════════════════════════
if ($userId > 0):
    $u = jobs_user_find($pdo, $userId);
    if (!$u) { echo "<p class='text-sm text-stone-500'>That person wasn't found. <a class='text-amber-700 underline' href='/admin/admin.php?view=jobs_staff'>Back to Staff</a></p>"; return; }
    $stints = jobs_stints($pdo, $userId);
    $openN  = count(array_filter($stints, fn($s) => !$s['end_date']));
    $inv    = jobs_invite_last($pdo, $userId);
    $name   = trim($u['first_name'] . ' ' . $u['last_name']);
    $isMe   = $userId === (int)($_SESSION['user_id'] ?? 0);
    $hidden = '<input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="user_id" value="' . $userId . '">';
    $act    = '/admin/admin.php?view=jobs_staff&user=' . $userId;
    $invTxt = '';
    if ($inv) {
        if ($inv['used_at'] && empty($u['password'])) $invTxt = 'Last link was replaced or cancelled ' . $d($inv['used_at']) . '.';
        elseif ($inv['used_at'])                     $invTxt = 'Last link sent ' . $d($inv['created_at']) . '.';
        elseif (!(int)$inv['is_live'])                 $invTxt = 'Link sent ' . $d($inv['created_at']) . ' ran out ' . $d($inv['expires_at']) . '.';
        else                                         $invTxt = 'Link sent ' . $d($inv['created_at']) . ', works until ' . $d($inv['expires_at']) . '.';
    }
?>
<div class="max-w-3xl pb-16">
  <a href="/admin/admin.php?view=jobs_staff" class="inline-block text-sm text-amber-700 hover:underline mb-3">&larr; All staff</a>

  <div class="bg-white border border-stone-200 rounded-lg p-4 mb-4 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-stone-800 break-words"><?= $h($name) ?></h1>
        <div class="text-sm text-stone-500"><?= $h($u['staff_position'] ?: '—') ?> · <?= $h($ROLES[$u['role']] ?? $u['role']) ?></div>
      </div>
      <div class="flex flex-wrap gap-1"><?= $loginChip($u) ?><?= $openN ? '' : $chip('Not working here now', 'bg-stone-100 text-stone-600') ?></div>
    </div>
    <div class="grid grid-cols-3 gap-2 mt-4">
      <?php if ($u['phone_number']): ?>
      <a href="tel:<?= $h($tel($u['phone_number'])) ?>" class="text-center <?= $btn ?> border-stone-300 text-stone-700 hover:bg-stone-50">Call</a>
      <a href="sms:<?= $h($tel($u['phone_number'])) ?>" class="text-center <?= $btn ?> border-stone-300 text-stone-700 hover:bg-stone-50">Text</a>
      <?php endif; ?>
      <a href="mailto:<?= $h($u['email']) ?>" class="text-center <?= $btn ?> border-stone-300 text-stone-700 hover:bg-stone-50 <?= $u['phone_number'] ? '' : 'col-span-3' ?>">Email</a>
    </div>
    <div class="text-sm text-stone-600 mt-3 break-all"><?= $h($u['email']) ?><?= $u['phone_number'] ? ' · ' . $h($u['phone_number']) : '' ?></div>
  </div>

  <!-- ── Login ── -->
  <section class="bg-white border border-stone-200 rounded-lg p-4 mb-4 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-1">Login</h2>
    <?php if ($u['account_status'] === 'disabled'): ?>
      <p class="text-sm text-stone-600 mb-3">Their login is off. They can't sign in.</p>
      <form method="POST" action="<?= $h($act) ?>"><?= $hidden ?><input type="hidden" name="jobs_hire_action" value="login_on">
        <button class="<?= $btn ?> border-stone-300 text-stone-700 hover:bg-stone-50">Turn login on</button></form>
    <?php else: ?>
      <p class="text-sm text-stone-600 mb-1"><?= empty($u['password']) ? 'They haven\'t set a password yet.' : 'They can sign in at the staff login.' ?></p>
      <?php if ($invTxt !== ''): ?><p class="text-xs text-stone-400 mb-3"><?= $h($invTxt) ?></p><?php endif; ?>
      <div class="flex flex-wrap gap-2 mt-2">
        <?php if ($openN): ?>
        <form method="POST" action="<?= $h($act) ?>" onsubmit="return confirm('Email <?= $h(addslashes($u['first_name'])) ?> a new set-password link? Older links stop working.')"><?= $hidden ?><input type="hidden" name="jobs_hire_action" value="send_link">
          <button class="<?= $btn ?> border-amber-600 text-amber-800 hover:bg-amber-50">Send a new set-password link</button></form>
        <?php endif; ?>
        <?php if (!$isMe): ?>
        <form method="POST" action="<?= $h($act) ?>" onsubmit="return confirm('Turn off <?= $h(addslashes($u['first_name'])) ?>\'s login?')"><?= $hidden ?><input type="hidden" name="jobs_hire_action" value="login_off">
          <button class="<?= $btn ?> border-stone-300 text-stone-700 hover:bg-stone-50">Turn login off</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ── Jobs (stints) ── -->
  <h2 class="font-semibold text-stone-800 mb-2">Jobs here</h2>
  <?php foreach ($stints as $s):
      $cur = !$s['end_date'];
      $pay = $s['pay_rate'] !== null ? '$' . number_format((float)$s['pay_rate'], 2) . ' ' . ($UNITS[$s['pay_unit']] ?? $s['pay_unit']) : '';
  ?>
  <section class="bg-white border <?= $cur ? 'border-emerald-300' : 'border-stone-200' ?> rounded-lg p-4 mb-3 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="min-w-0">
        <div class="font-semibold text-stone-800"><?= $h($s['staff_position']) ?></div>
        <div class="text-sm text-stone-500"><?= $h($ENG[$s['engagement_type']] ?? $s['engagement_type']) ?><?= $pay !== '' ? ' · ' . $h($pay) : '' ?></div>
        <div class="text-xs text-stone-400 mt-0.5"><?= $h($d($s['start_date'])) ?> – <?= $cur ? 'now' : $h($d($s['end_date'])) ?><?= $s['application_id'] ? ' · <a class="text-amber-700 underline" href="/admin/admin.php?view=jobs&app=' . (int)$s['application_id'] . '">application</a>' : '' ?></div>
      </div>
      <?= $cur ? $chip('Current', 'bg-emerald-50 text-emerald-800') : $chip('Ended', 'bg-stone-100 text-stone-600') ?>
    </div>
    <?php if ($s['work_trade_terms']): ?><p class="text-sm text-stone-600 mt-2 whitespace-pre-line"><?= $h($s['work_trade_terms']) ?></p><?php endif; ?>
    <?php if (!$cur && $s['termination_reason']): ?><p class="text-sm text-stone-600 mt-2">Reason: <?= $h($s['termination_reason']) ?></p><?php endif; ?>

    <?php if ((int)$s['is_minor']): ?>
    <form method="POST" action="<?= $h($act) ?>" class="mt-3 border-t border-stone-100 pt-3 space-y-2">
      <?= $hidden ?><input type="hidden" name="jobs_hire_action" value="cert"><input type="hidden" name="stint_id" value="<?= (int)$s['id'] ?>">
      <div class="text-sm <?= $s['youth_cert_received_at'] ? 'text-emerald-800' : 'text-sky-900 font-semibold' ?>">
        Under 18<?= $s['dob'] ? ' (born ' . $h($d($s['dob'])) . ')' : '' ?> · Youth certificate: <?= $s['youth_cert_received_at'] ? 'received ' . $h($d($s['youth_cert_received_at'])) : 'still needed' ?></div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-stone-600 flex-1 min-w-[10rem]">Certificate #<input type="text" name="youth_cert_number" maxlength="50" value="<?= $h($s['youth_cert_number']) ?>" class="<?= $in ?>"></label>
        <label class="flex items-center gap-2 text-sm text-stone-700 py-2"><input type="checkbox" name="youth_cert_received" value="1" <?= $s['youth_cert_received_at'] ? 'checked' : '' ?>> Received</label>
        <button class="<?= $btn ?> border-stone-300 text-stone-700 hover:bg-stone-50">Save</button>
      </div>
    </form>
    <?php endif; ?>

    <?php if ($cur): ?>
    <details class="mt-3 border-t border-stone-100 pt-3">
      <summary class="text-sm text-red-700 cursor-pointer select-none">End this job</summary>
      <form method="POST" action="<?= $h($act) ?>" class="mt-3 space-y-2" onsubmit="return confirm('End <?= $h(addslashes($u['first_name'])) ?>\'s job as <?= $h(addslashes($s['staff_position'])) ?>?')">
        <?= $hidden ?><input type="hidden" name="jobs_hire_action" value="end"><input type="hidden" name="stint_id" value="<?= (int)$s['id'] ?>">
        <div class="grid grid-cols-2 gap-2">
          <label class="text-xs text-stone-600">Last day<input type="date" name="end_date" value="<?= $h(jobs_today()) ?>" min="<?= $h($s['start_date']) ?>" required class="<?= $in ?>"></label>
          <label class="text-xs text-stone-600">Reason<input type="text" name="reason" maxlength="500" required list="jh-reasons" class="<?= $in ?>" placeholder="Season ended"></label>
        </div>
        <?php if ($openN <= 1 && !$isMe): ?>
        <label class="flex items-start gap-2 text-sm text-stone-700"><input type="checkbox" name="login_off" value="1" class="mt-1" checked><span>Turn off their login too</span></label>
        <?php endif; ?>
        <button class="<?= $btn ?> border-red-300 text-red-700 hover:bg-red-50">End job</button>
      </form>
    </details>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
  <datalist id="jh-reasons"><option value="Season ended"><option value="Quit"><option value="Moved away"><option value="Let go"><option value="Internship finished"></datalist>
  <?php if (!$stints): ?><p class="text-sm text-stone-500">No jobs recorded for this person.</p><?php endif; ?>
  <p class="text-xs text-stone-400 mt-4">Rehiring someone? Hire them from a new application; their old jobs stay listed here.</p>
</div>
<?php
    return;
endif;

// ════════════════════════════════════════════════════════════════════════════
// LIST
// ════════════════════════════════════════════════════════════════════════════
$show = in_array($_GET['show'] ?? '', ['current', 'past', 'all'], true) ? $_GET['show'] : 'current';
$q    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$rows = jobs_staff_list($pdo, $show, $q);
$need = jobs_certs_needed_count($pdo);
?>
<div class="max-w-4xl pb-16">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hiring</h1>
  <p class="text-sm text-stone-500 mb-4">People you've hired: their logins, jobs and paperwork.</p>
  <?php require __DIR__ . '/_tabs.php'; ?>

  <?php if ($need): ?>
  <div class="bg-sky-50 border border-sky-200 text-sky-900 rounded-lg p-3 mb-4 text-sm"><?= $need ?> under-18 hire<?= $need === 1 ? '' : 's' ?> still need<?= $need === 1 ? 's' : '' ?> a youth employment certificate.</div>
  <?php endif; ?>

  <div class="flex flex-wrap items-center gap-1.5 mb-3">
    <?php foreach (['current' => 'Working now', 'past' => 'Past', 'all' => 'All'] as $k => $l): $on = $show === $k; ?>
      <a href="/admin/admin.php?view=jobs_staff&show=<?= $k ?>" class="text-sm px-3 py-1.5 rounded-full border whitespace-nowrap <?= $on ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-700 border-stone-300 hover:bg-stone-50' ?>" <?= $on ? 'aria-current="page"' : '' ?>><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <form method="GET" action="/admin/admin.php" class="flex gap-2 mb-4">
    <input type="hidden" name="view" value="jobs_staff"><input type="hidden" name="show" value="<?= $h($show) ?>">
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="Search name or email" class="flex-1 min-w-0 border border-stone-300 rounded px-3 py-2 text-sm">
    <button class="bg-stone-800 text-white text-sm font-semibold px-4 rounded">Find</button>
  </form>

  <?php if (!$rows): ?>
    <p class="text-sm text-stone-500 bg-white border border-stone-200 rounded-lg p-4">
      <?= $q !== '' ? 'Nobody matches that search.' : ($show === 'current' ? 'Nobody hired through Jobs yet. Open an application and tap Hired.' : 'Nobody here yet.') ?></p>
  <?php else: ?>
  <div class="bg-white border border-stone-200 rounded-lg shadow-sm divide-y divide-stone-100">
    <?php foreach ($rows as $r): ?>
    <a href="/admin/admin.php?view=jobs_staff&user=<?= (int)$r['id'] ?>" class="block p-4 hover:bg-stone-50">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <div class="font-semibold text-stone-800 break-words"><?= $h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></div>
          <div class="text-sm text-stone-500"><?= $h($r['staff_position']) ?> · <?= $h($ENG[$r['engagement_type']] ?? $r['engagement_type']) ?></div>
          <div class="text-xs text-stone-400"><?= $r['end_date'] ? 'Ended ' . $h($d($r['end_date'])) : 'Since ' . $h($d($r['start_date'])) ?><?= (int)$r['open_stints'] > 1 ? ' · ' . (int)$r['open_stints'] . ' jobs' : '' ?></div>
        </div>
        <div class="flex flex-col items-end gap-1 shrink-0">
          <?= $loginChip($r) ?>
          <?= (int)$r['certs_needed'] ? $chip('Youth cert needed', 'bg-sky-100 text-sky-800') : '' ?>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
