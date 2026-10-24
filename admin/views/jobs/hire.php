<?php
// admin/views/jobs/hire.php
// Hire confirm screen (Jobs pack P6): ?view=jobs_hire&app=ID
// Pre-filled from the application + job; nothing is saved until "Hire" is tapped.
// Administrators only. Saves go to admin/handlers/jobs_hire.php (jobs_hire_action=hire).
require_once __DIR__ . '/../../../includes/jobs/hire.php';

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$appId = (int)($_GET['app'] ?? 0);
$a    = $appId > 0 ? jobs_app_find($pdo, $appId) : null;
$back = '/admin/admin.php?view=jobs&app=' . $appId;

if (!$a) { echo "<p class='text-sm text-stone-500'>That application wasn't found. <a class='text-amber-700 underline' href='/admin/admin.php?view=jobs'>Back to Applicants</a></p>"; return; }
if ($a['status'] === 'hired') { echo "<p class='text-sm text-stone-600'>" . $h($a['first_name']) . " is already hired. <a class='text-amber-700 underline' href='/admin/admin.php?view=jobs_staff&user=" . (int)$a['user_id'] . "'>Open their staff page</a></p>"; return; }
if ((int)$a['is_spam']) { echo "<p class='text-sm text-stone-600'>This application is marked as spam. <a class='text-amber-700 underline' href='" . $h($back) . "'>Back to it</a></p>"; return; }

$def   = jobs_hire_defaults($pdo, $a);
$old   = $_SESSION['jobs_form_old']['hire'] ?? null; unset($_SESSION['jobs_form_old']['hire']);
$v     = function (string $k) use ($old, $def) { return $old !== null ? (string)($old[$k] ?? '') : (string)$def[$k]; };
$chk   = function (string $k) use ($old, $def) { return $old !== null ? !empty($old[$k]) : !empty($def[$k]); };
$user  = $def['_user'];
$minor = $a['age_bracket'] !== '18_plus';
$name  = trim($a['first_name'] . ' ' . $a['last_name']);
$open  = $user ? jobs_open_stints($pdo, (int)$user['id']) : [];
$ROLES = jobs_staff_roles();
$ENG   = jobs_engagement_labels();
$UNITS = jobs_pay_unit_labels();
$in    = 'mt-1 w-full border border-stone-300 rounded px-2 py-2 text-sm';
$lbl   = 'block text-xs text-stone-600';
?>
<div class="max-w-2xl pb-16">
  <a href="<?= $h($back) ?>" class="inline-block text-sm text-amber-700 hover:underline mb-3">&larr; Back to <?= $h($name) ?></a>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hire <?= $h($name) ?></h1>
  <p class="text-sm text-stone-500 mb-4"><?= $h($a['job_title']) ?> · <?= $h($a['category_name']) ?>. Check the details, then tap Hire. Nothing is saved until you do.</p>

  <!-- Who: new or existing account -->
  <div class="rounded-lg p-3 mb-4 text-sm border <?= $user ? 'bg-sky-50 border-sky-200 text-sky-900' : 'bg-stone-50 border-stone-200 text-stone-700' ?>">
    <?php if ($user): ?>
      <strong>They already have an account</strong> (<?= $h($user['email']) ?>, <?= $h($user['role']) ?><?= $user['account_status'] === 'disabled' ? ', login off' : '' ?>).
      Hiring updates that account instead of making a new one<?= $user['account_status'] === 'disabled' ? ' and turns the login back on' : '' ?>.
      <?php if ($user['role'] === 'administrator'): ?> They stay an administrator.<?php endif; ?>
      <?php if ($open): ?><br><span class="text-amber-800">Already working here as <?= $h($open[0]['staff_position']) ?> since <?= $h(date('M j, Y', strtotime($open[0]['start_date']))) ?>. This adds a second job; the first one stays.</span><?php endif; ?>
    <?php else: ?>
      <strong>A new staff account will be made</strong> for <?= $h($a['email']) ?>. They set their own password from the welcome email.
    <?php endif; ?>
  </div>

  <form method="POST" action="/admin/admin.php?view=jobs_hire&app=<?= $appId ?>" class="space-y-4" id="jh-form">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_hire_action" value="hire">
    <input type="hidden" name="app_id" value="<?= $appId ?>">

    <section class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-3">
      <h2 class="font-semibold text-stone-800">Person</h2>
      <div class="grid grid-cols-2 gap-2">
        <label class="<?= $lbl ?>">First name<input type="text" name="first_name" maxlength="100" required value="<?= $h($v('first_name')) ?>" class="<?= $in ?>"></label>
        <label class="<?= $lbl ?>">Last name<input type="text" name="last_name" maxlength="100" value="<?= $h($v('last_name')) ?>" class="<?= $in ?>"></label>
      </div>
      <label class="<?= $lbl ?>">Email <span class="text-stone-400">(their login)</span><input type="email" name="email" maxlength="190" required value="<?= $h($v('email')) ?>" class="<?= $in ?>" autocomplete="off"></label>
      <label class="<?= $lbl ?>">Phone<input type="tel" name="phone" maxlength="30" value="<?= $h($v('phone')) ?>" class="<?= $in ?>"></label>
    </section>

    <section class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-3">
      <h2 class="font-semibold text-stone-800">Job</h2>
      <label class="<?= $lbl ?>">Position<input type="text" name="staff_position" maxlength="150" required value="<?= $h($v('staff_position')) ?>" class="<?= $in ?>"></label>
      <div class="grid grid-cols-2 gap-2">
        <label class="<?= $lbl ?>">Type
          <select name="engagement_type" id="jh-eng" class="<?= $in ?> bg-white">
            <?php foreach ($ENG as $k => $l): ?><option value="<?= $k ?>" <?= $v('engagement_type') === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
          </select></label>
        <label class="<?= $lbl ?>">Start date<input type="date" name="start_date" required value="<?= $h($v('start_date')) ?>" class="<?= $in ?>"></label>
      </div>
      <div class="grid grid-cols-2 gap-2" data-eng="paid internship">
        <label class="<?= $lbl ?>">Pay ($)<input type="text" name="pay_rate" inputmode="decimal" value="<?= $h($v('pay_rate')) ?>" class="<?= $in ?>" placeholder="12.00"></label>
        <label class="<?= $lbl ?>">How often
          <select name="pay_unit" class="<?= $in ?> bg-white">
            <?php foreach ($UNITS as $k => $l): ?><option value="<?= $k ?>" <?= $v('pay_unit') === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
          </select></label>
      </div>
      <p class="text-xs text-stone-400" data-eng="paid">Hourly pay is also saved on their account as their pay rate.</p>
      <label class="<?= $lbl ?>" data-eng="work_trade">Work-trade terms <span class="text-stone-400">(what they get for their hours)</span>
        <textarea name="work_trade_terms" rows="3" maxlength="4000" class="<?= $in ?>"><?= $h($v('work_trade_terms')) ?></textarea></label>
      <label class="<?= $lbl ?>">What they can open in the admin
        <select name="role" class="<?= $in ?> bg-white" <?= $user && $user['role'] === 'administrator' ? 'disabled' : '' ?>>
          <?php foreach ($ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $v('role') === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
        </select></label>
      <?php if ($user && $user['role'] === 'administrator'): ?><input type="hidden" name="role" value="administrator"><?php endif; ?>
    </section>

    <?php if ($minor): ?>
    <section class="bg-sky-50 border border-sky-200 rounded-lg p-4 space-y-3">
      <h2 class="font-semibold text-sky-900">Under 18 checklist</h2>
      <p class="text-sm text-sky-900">NC requires a <strong>youth employment certificate</strong> before they work. You can hire now; they stay flagged on the Staff screen until it's marked received.
        Guardian: <?= $h($a['guardian_name'] ?: '—') ?><?= $a['guardian_email'] ? ' (' . $h($a['guardian_email']) . ', CC\'d on the welcome email)' : '' ?>.</p>
      <div class="grid grid-cols-2 gap-2">
        <label class="<?= $lbl ?>">Date of birth <span class="text-stone-400">(optional)</span><input type="date" name="dob" value="<?= $h($v('dob')) ?>" class="<?= $in ?> bg-white"></label>
        <label class="<?= $lbl ?>">Certificate # <span class="text-stone-400">(optional)</span><input type="text" name="youth_cert_number" maxlength="50" value="<?= $h($v('youth_cert_number')) ?>" class="<?= $in ?> bg-white"></label>
      </div>
      <label class="flex items-start gap-2 text-sm text-sky-900"><input type="checkbox" name="youth_cert_received" value="1" class="mt-1" <?= $chk('youth_cert_received') ? 'checked' : '' ?>><span>I have their youth employment certificate</span></label>
    </section>
    <?php endif; ?>

    <section class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-2">
      <label class="flex items-start gap-2 text-sm text-stone-700"><input type="checkbox" name="send_email" value="1" class="mt-1" <?= $chk('send_email') ? 'checked' : '' ?>>
        <span>Send the welcome email with a set-password link (works <?= JOBS_INVITE_DAYS ?> days)</span></label>
      <p class="text-xs text-stone-400">Uses the "Hired" template from Hiring &gt; Emails. You can send a new link later from their staff page.</p>
    </section>

    <div class="flex gap-2">
      <button type="submit" class="flex-1 bg-emerald-700 hover:bg-emerald-800 text-white font-semibold py-3 rounded">Hire <?= $h($a['first_name']) ?></button>
      <a href="<?= $h($back) ?>" class="px-5 py-3 rounded border border-stone-300 text-stone-700 text-center">Cancel</a>
    </div>
  </form>
</div>
<script>
(function () {
  var sel = document.getElementById('jh-eng');
  if (!sel) return;
  function sync() {
    document.querySelectorAll('#jh-form [data-eng]').forEach(function (el) {
      el.classList.toggle('hidden', el.getAttribute('data-eng').split(' ').indexOf(sel.value) === -1);
    });
  }
  sel.addEventListener('change', sync); sync();
})();
</script>
