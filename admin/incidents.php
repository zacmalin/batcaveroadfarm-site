<?php
// admin/incidents.php — Animals › Incidents (Farm OS Phase 5 slice 8, Nov 12, 2026; includes/incidents.php, migration 0047).
//   GET  incidents.php[?status=open|closed|all&type=]   every incident, newest first (Owners and Managers)
//   GET  incidents.php?new=1                            report one (any staff member)
//   GET  incidents.php?id=N[&edit=1]                    one report: everything, photos, links, change history; change, add, close
//   POST act=add | edit | person | photo | status
// Staff can only open tasks.php (config.php), so tasks.php?incident=new | N loads this page too; its links then stay on tasks.php.
// Permission: any staff member can report and see their own reports. View 'incidents' (Owners always; Managers by
// default) to see all and to change, add to or close one. Never deleted; every change is kept on the report and audited.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once __DIR__ . '/../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/admin_shell.php';
if (is_file(__DIR__ . '/../includes/animals.php')) require_once __DIR__ . '/../includes/animals.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = bcrf_incident_uid();
$role = (string)($_SESSION['user_role'] ?? '');
$all = bcrf_incidents_can();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$via = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'tasks.php';
// Links: report form, one report, the list (the list is only on incidents.php).
$url = function ($what, array $more = []) use ($via): string {
    if ($what === 'list') return 'incidents.php' . ($more ? '?' . http_build_query($more) : '');
    if ($via) return 'tasks.php?' . http_build_query(['incident' => $what] + $more);
    return 'incidents.php?' . http_build_query(($what === 'new' ? ['new' => 1] : ['id' => (int)$what]) + $more);
};
$canAnimals = $role === 'administrator' || (function_exists('hasTableAccess') && hasTableAccess($role, 'animals', 'view'));

if (!bcrf_incidents_ready($pdo)) {
    bcrf_shell_open('Incidents');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Incidents</h1>', bcrf_shell_notice('warning', 'Run migration 0047 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = $id ? $url($id) : $url('new');
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {   // over the server's upload limit: PHP drops the whole form
        $_SESSION['inc_flash'] = ['err', 'Those photos were too big to send together. Save the report with one photo, then add the rest.'];
        header('Location: ' . $url('new')); exit;
    }
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfIncidentError('Security token expired; try again.');
        if ($act === 'add') {
            $r = bcrf_incident_add($pdo, $_POST, $_FILES['photos'] ?? [], $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'incidents', $r['id'], 'Incident reported');
            $msg = 'Report #' . $r['id'] . ' saved.' . ($r['notified'] ? ' The Owners have been emailed.' : '') . ($r['task_id'] ? ' A follow-up task is on their list.' : '');
            $_SESSION['inc_flash'] = $r['warnings'] ? ['err', $msg . ' ' . implode(' ', $r['warnings'])] : ['ok', $msg];
            $back = $url($r['id']);
        } elseif ($act === 'edit') {
            $changed = bcrf_incident_update($pdo, $id, $_POST, $uid);
            if ($changed && function_exists('erp_audit')) erp_audit($pdo, 'update', 'incidents', $id, 'Incident changed: ' . implode(', ', $changed));
            $_SESSION['inc_flash'] = ['ok', $changed ? 'Saved. The change is kept on the report.' : 'Nothing changed.'];
        } elseif ($act === 'person') {
            bcrf_incident_add_person($pdo, $id, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'incidents', $id, 'Incident: visitor added');
            $_SESSION['inc_flash'] = ['ok', 'Visitor added.'];
        } elseif ($act === 'photo') {
            $i = bcrf_incident_get($pdo, $id);
            if (!$all && !bcrf_incident_can_see($i)) throw new BcrfIncidentError('That report is not yours.');
            $n = 0;
            foreach (bcrf_incident_files($_FILES['photos'] ?? []) as $f) { bcrf_incident_photo_add($pdo, $id, $f, $uid); $n++; }
            if (!$n) throw new BcrfIncidentError('Pick a photo first.');
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'incidents', $id, 'Incident: ' . $n . ' photo' . ($n > 1 ? 's' : '') . ' added');
            $_SESSION['inc_flash'] = ['ok', $n . ' photo' . ($n > 1 ? 's' : '') . ' added.'];
        } elseif ($act === 'status') {
            $to = (string)($_POST['to'] ?? '');
            bcrf_incident_set_status($pdo, $id, $to, (string)($_POST['note'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'incidents', $id, $to === 'closed' ? 'Incident closed' : 'Incident reopened');
            $_SESSION['inc_flash'] = ['ok', $to === 'closed' ? 'Closed.' : 'Reopened.'];
        } else {
            throw new BcrfIncidentError('Unknown action.');
        }
    } catch (BcrfIncidentError $e) {
        $_SESSION['inc_flash'] = ['err', $e->getMessage()];
        if ($act === 'add') $_SESSION['inc_draft'] = array_diff_key($_POST, ['csrf_token' => 1]);
    } catch (Throwable $e) {
        error_log('[incidents] ' . $act . ': ' . $e->getMessage());
        $_SESSION['inc_flash'] = ['err', 'Could not save. Try again.'];
        if ($act === 'add') $_SESSION['inc_draft'] = array_diff_key($_POST, ['csrf_token' => 1]);
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['inc_flash'] ?? null; unset($_SESSION['inc_flash']);
$draft = $_SESSION['inc_draft'] ?? []; unset($_SESSION['inc_draft']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$hidden  = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
$sevChip = ['minor' => 'bg-stone-100 text-stone-700', 'moderate' => 'bg-amber-100 text-amber-900', 'serious' => 'bg-red-100 text-red-800'];
$local   = fn(?string $utc, string $f = 'D M j, Y g:i A') => $utc ? bcrf_incident_local($utc)->format($f) : '';
$animals = function_exists('bcrf_animals_list') && function_exists('bcrf_animals_ready') && bcrf_animals_ready($pdo) ? bcrf_animals_list($pdo, 'all') : [];
$places  = function_exists('bcrf_enclosures') && function_exists('bcrf_animals_ready') && bcrf_animals_ready($pdo) ? array_column(bcrf_enclosures($pdo), 'name') : [];

// The fields shared by the report form and the change form. $d: values to show.
$fields = function (array $d, bool $isNew) use ($h, $input, $label, $animals, $places): void {
    $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
    $chk = fn($a, $b) => (string)$a === (string)$b ? ' checked' : '';
    $picked = array_map('intval', (array)($d['animals'] ?? [])); ?>
    <fieldset class="mb-4"><legend class="<?= $label ?>">What kind of incident</legend>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <?php foreach (BCRF_INCIDENT_TYPES as $k => $v): ?>
        <label class="flex items-center gap-2 p-3 border border-stone-200 rounded min-h-11"><input type="radio" name="type" value="<?= $k ?>" required<?= $chk($d['type'] ?? '', $k) ?>> <?= $h($v) ?></label>
        <?php endforeach; ?>
      </div></fieldset>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
      <div><label class="<?= $label ?>" for="i_inj">If a visitor was hurt</label><select id="i_inj" name="injury" class="<?= $input ?>"><option value="">—</option>
        <?php foreach (BCRF_INCIDENT_INJURIES as $k => $v): ?><option value="<?= $k ?>"<?= $sel($d['injury'] ?? '', $k) ?>><?= $h($v) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= $label ?>" for="i_date">Day</label><input id="i_date" name="date" type="date" required value="<?= $h($d['date'] ?? '') ?>" max="<?= $h(bcrf_incident_now_local()->format('Y-m-d')) ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="i_time">Time (a guess is fine)</label><input id="i_time" name="time" type="time" required value="<?= $h($d['time'] ?? '') ?>" class="<?= $input ?>"></div>
    </div>
    <fieldset class="mb-4"><legend class="<?= $label ?>">How serious</legend>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
        <?php foreach (BCRF_INCIDENT_SEVERITY as $k => $v): ?>
        <label class="flex items-start gap-2 p-3 border border-stone-200 rounded min-h-11"><input class="mt-1" type="radio" name="severity" value="<?= $k ?>" required<?= $chk($d['severity'] ?? '', $k) ?>>
          <span><?= $h($v) ?><span class="block text-xs text-stone-500"><?= $h(BCRF_INCIDENT_SEVERITY_HELP[$k]) ?></span></span></label>
        <?php endforeach; ?>
      </div></fieldset>
    <div class="mb-3"><label class="<?= $label ?>" for="i_loc">Where</label><input id="i_loc" name="location" maxlength="120" required list="i_places" value="<?= $h($d['location'] ?? '') ?>" class="<?= $input ?>" placeholder="e.g. Goat Barn, parking lot, gift shop">
      <datalist id="i_places"><?php foreach ($places as $p): ?><option value="<?= $h($p) ?>"><?php endforeach; ?></datalist></div>
    <div class="mb-3"><label class="<?= $label ?>" for="i_desc">What happened</label><textarea id="i_desc" name="description" rows="4" maxlength="5000" required class="<?= $input ?>" placeholder="Just the facts: who, what, in what order."><?= $h($d['description'] ?? '') ?></textarea></div>
    <div class="mb-3"><label class="<?= $label ?>" for="i_act">What was done (first aid, who was called)</label><textarea id="i_act" name="action_taken" rows="3" maxlength="5000" class="<?= $input ?>"><?= $h($d['action_taken'] ?? '') ?></textarea></div>
    <?php if ($animals): ?>
    <div class="mb-3"><label class="<?= $label ?>" for="i_an">Animals involved (hold to pick more than one)</label>
      <select id="i_an" name="animals[]" multiple size="6" class="<?= $input ?>">
        <?php foreach ($animals as $a): ?><option value="<?= (int)$a['id'] ?>"<?= in_array((int)$a['id'], $picked, true) ? ' selected' : '' ?>><?= $h($a['name']) ?><?= function_exists('bcrf_animal_is_leaving') && bcrf_animal_is_leaving((string)$a['status']) ? ' (left)' : '' ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <?php if ($isNew): ?>
    <fieldset class="mb-3"><legend class="<?= $label ?>">Visitors involved (name, and phone or email if you have them)</legend>
      <?php for ($n = 0; $n < 3; $n++): ?>
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 mb-2<?= $n ? ' pt-2 border-t border-stone-100' : '' ?>">
        <input name="person_name[]" maxlength="120" aria-label="Visitor <?= $n + 1 ?> name" placeholder="Name" value="<?= $h(($d['person_name'] ?? [])[$n] ?? '') ?>" class="<?= $input ?>">
        <input name="person_phone[]" maxlength="30" inputmode="tel" aria-label="Visitor <?= $n + 1 ?> phone" placeholder="Phone" value="<?= $h(($d['person_phone'] ?? [])[$n] ?? '') ?>" class="<?= $input ?>">
        <input name="person_email[]" maxlength="190" type="email" aria-label="Visitor <?= $n + 1 ?> email" placeholder="Email" value="<?= $h(($d['person_email'] ?? [])[$n] ?? '') ?>" class="<?= $input ?>">
        <input name="person_note[]" maxlength="255" aria-label="Visitor <?= $n + 1 ?> note" placeholder="e.g. hurt, age 6; parent" value="<?= $h(($d['person_note'] ?? [])[$n] ?? '') ?>" class="<?= $input ?>">
      </div>
      <?php endfor; ?>
      <p class="text-xs text-stone-500">More visitors can be added after saving.</p></fieldset>
    <?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="i_staff">Staff there</label><input id="i_staff" name="staff_involved" maxlength="255" value="<?= $h($d['staff_involved'] ?? '') ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="i_wit">Witnesses (names, contact)</label><textarea id="i_wit" name="witnesses" rows="2" maxlength="2000" class="<?= $input ?>"><?= $h($d['witnesses'] ?? '') ?></textarea></div>
    </div>
    <div class="mb-3"><label class="<?= $label ?>" for="i_fu">Follow-up needed</label><textarea id="i_fu" name="follow_up" rows="2" maxlength="2000" class="<?= $input ?>" placeholder="e.g. call the family tomorrow, fix the gate latch"><?= $h($d['follow_up'] ?? '') ?></textarea></div>
<?php
};

// ── Report form ──
$id = $via ? (ctype_digit((string)($_GET['incident'] ?? '')) ? (int)$_GET['incident'] : 0) : (int)($_GET['id'] ?? 0);
$isNew = $via ? !$id : !empty($_GET['new']);
if (!$isNew && !$id && !$all) $isNew = true;   // people without the list land on the form

if ($isNew) {
    $now = bcrf_incident_now_local();
    $d = $draft + ['date' => $now->format('Y-m-d'), 'time' => $now->format('H:i')];
    $mine = $uid ? bcrf_incident_list($pdo, ['mine' => $uid], 10) : [];
    bcrf_shell_open('Report an incident', ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Report an incident</h1>',
         '<p class="text-sm text-stone-500 mb-3">Write down what happened and what was done, as plainly as you can. Saving emails the Owners right away.</p>';
    if ($error) echo bcrf_shell_notice('err', $error); ?>
  <form method="POST" action="<?= $h($url('new')) ?>" enctype="multipart/form-data" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="add">
    <?php $fields($d, true); ?>
    <div class="mb-4"><label class="<?= $label ?>" for="i_ph">Photos (optional, up to <?= BCRF_INCIDENT_PHOTO_MAX ?>)</label><input id="i_ph" name="photos[]" type="file" accept="image/*" multiple class="text-sm"></div>
    <button class="<?= $btnMain ?> w-full sm:w-auto">Save report</button>
  </form>
  <?php if ($mine): ?>
  <div class="<?= $card ?>"><h2 class="text-base font-semibold text-stone-800 mb-2">Your reports</h2>
    <ul class="divide-y divide-stone-100"><?php foreach ($mine as $i): ?>
      <li class="py-2 text-sm"><a class="underline text-amber-800" href="<?= $h($url((int)$i['id'])) ?>">#<?= (int)$i['id'] ?> · <?= $h($local($i['happened_at'], 'M j, g:i A')) ?></a> · <?= $h(bcrf_incident_line($i)) ?><?= $i['status'] === 'closed' ? ' · closed' : '' ?></li>
    <?php endforeach; ?></ul></div>
  <?php endif;
    bcrf_shell_close(); exit;
}

// ── One report ──
if ($id) {
    try { $i = bcrf_incident_get($pdo, $id); } catch (BcrfIncidentError $e) { $i = null; }
    if (!$i || !bcrf_incident_can_see($i)) {
        bcrf_shell_open('Incident');
        echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Incident</h1>', bcrf_shell_notice('err', 'That report was not found, or it is not yours to see.');
        bcrf_shell_close(); exit;
    }
    $lt = bcrf_incident_local($i['happened_at']);
    bcrf_shell_open('Incident #' . $id, ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    if ($all && !$via) echo '<a href="incidents.php" class="inline-flex items-center min-h-10 text-sm text-stone-500 hover:text-stone-800 mb-1">← All incidents</a>'; ?>
  <div class="flex flex-wrap items-center gap-2 mb-1">
    <h1 class="text-2xl font-bold text-stone-800">#<?= $id ?> <?= $h(BCRF_INCIDENT_TYPES[$i['type']]) ?><?= $i['injury'] ? ' (' . $h(strtolower(BCRF_INCIDENT_INJURIES[$i['injury']])) . ')' : '' ?></h1>
    <span class="text-xs font-semibold px-2 py-1 rounded <?= $sevChip[$i['severity']] ?>"><?= $h(BCRF_INCIDENT_SEVERITY[$i['severity']]) ?></span>
    <span class="text-xs font-semibold px-2 py-1 rounded <?= $i['status'] === 'open' ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800' ?>"><?= $i['status'] === 'open' ? 'Open' : 'Closed' ?></span>
  </div>
  <p class="text-sm text-stone-500 mb-3"><?= $h($lt->format('D M j, Y g:i A')) ?> · <?= $h($i['location']) ?> · reported by <?= $h($i['created_by_name'] ?: 'someone') ?> <?= $h($local($i['created_at'], 'M j g:i A')) ?>
    <?= $i['owner_notified_at'] ? ' · Owners emailed' : '' ?><?= $i['task_id'] && $all ? ' · <a class="underline text-amber-800" href="tasks.php?id=' . (int)$i['task_id'] . '">follow-up task</a>' : '' ?></p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if ($i['status'] === 'closed'): ?><div class="<?= $card ?> text-sm"><strong>Closed</strong> <?= $h($local($i['closed_at'])) ?><?= $i['closed_by_name'] ? ' by ' . $h($i['closed_by_name']) : '' ?>: <?= $h($i['close_note']) ?></div><?php endif; ?>

  <?php if ($all && !empty($_GET['edit'])):
    $d = $i; $d['date'] = $lt->format('Y-m-d'); $d['time'] = $lt->format('H:i'); $d['animals'] = array_column($i['animals'], 'id'); ?>
  <form method="POST" action="<?= $h($url($id)) ?>" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="edit"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-3">Change the report</h2>
    <?php $fields($d, false); ?>
    <p class="text-xs text-stone-500 mb-3">The old wording stays in the change history below.</p>
    <button class="<?= $btnMain ?>">Save changes</button> <a class="<?= $btnSec ?> inline-block" href="<?= $h($url($id)) ?>">Cancel</a>
  </form>
  <?php else: ?>
  <div class="<?= $card ?> text-sm space-y-3">
    <div><h2 class="text-xs font-semibold text-stone-500 uppercase">What happened</h2><p class="whitespace-pre-line"><?= $h($i['description']) ?></p></div>
    <?php foreach (['action_taken' => 'What was done', 'staff_involved' => 'Staff there', 'witnesses' => 'Witnesses', 'follow_up' => 'Follow-up needed'] as $k => $lbl): if ((string)$i[$k] === '') continue; ?>
    <div><h2 class="text-xs font-semibold text-stone-500 uppercase"><?= $h($lbl) ?></h2><p class="whitespace-pre-line"><?= $h($i[$k]) ?></p></div>
    <?php endforeach; ?>
    <?php if ($i['animals']): ?><div><h2 class="text-xs font-semibold text-stone-500 uppercase">Animals</h2><p>
      <?php foreach ($i['animals'] as $n => $a): ?><?= $n ? ', ' : '' ?><?= $canAnimals ? '<a class="underline text-amber-800" href="animals.php?id=' . (int)$a['id'] . '">' . $h($a['name']) . '</a>' : $h($a['name']) ?><?php endforeach; ?></p></div><?php endif; ?>
    <?php if ($all): ?><a class="<?= $btnSec ?> inline-block" href="<?= $h($url($id, ['edit' => 1])) ?>">Change the report</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Visitors</h2>
    <?php if (!$i['people']): ?><p class="text-sm text-stone-500">None on the report.</p><?php else: ?>
    <ul class="divide-y divide-stone-100"><?php foreach ($i['people'] as $p): ?>
      <li class="py-2 text-sm"><strong><?= $h($p['name']) ?></strong><?= $p['note'] ? ' · ' . $h($p['note']) : '' ?>
        <span class="block"><?= $p['phone'] ? '<a class="underline text-amber-800" href="tel:' . $h(preg_replace('/[^0-9+]/', '', $p['phone'])) . '">' . $h($p['phone']) . '</a>' : '' ?><?= $p['phone'] && $p['email'] ? ' · ' : '' ?><?= $p['email'] ? '<a class="underline text-amber-800" href="mailto:' . $h($p['email']) . '">' . $h($p['email']) . '</a>' : '' ?></span>
        <?php if ($all): $links = [];
          if ($p['customer_id']) $links[] = '<a class="underline text-amber-800" href="customers.php?id=' . (int)$p['customer_id'] . '">customer</a>';
          if ($p['waiver_id']) $links[] = '<a class="underline text-amber-800" href="waivers.php?id=' . (int)$p['waiver_id'] . '">waiver signed</a>';
          if ($p['check_in_id']) $links[] = 'checked in that day (#' . (int)$p['check_in_id'] . ')';
          if ($p['booking_id']) $links[] = '<a class="underline text-amber-800" href="/admin/admin.php?view=bookings">booking #' . (int)$p['booking_id'] . '</a>';
          if ($p['event_inquiry_id']) $links[] = '<a class="underline text-amber-800" href="/admin/admin.php?view=event_inquiries">party #' . (int)$p['event_inquiry_id'] . '</a>'; ?>
        <span class="block text-xs <?= $p['waiver_id'] || $i['type'] !== 'visitor_injury' ? 'text-stone-500' : 'text-red-700' ?>"><?= $links ? implode(' · ', $links) : ($i['type'] === 'visitor_injury' ? 'No waiver found for this name or email.' : 'No waiver or account found.') ?></span>
        <?php endif; ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($all && count($i['people']) < BCRF_INCIDENT_PEOPLE_MAX): ?>
    <details class="mt-2"><summary class="cursor-pointer text-sm text-amber-800 underline">Add a visitor</summary>
      <form method="POST" action="<?= $h($url($id)) ?>" class="grid grid-cols-1 sm:grid-cols-4 gap-2 mt-2"><?= $hidden ?><input type="hidden" name="act" value="person"><input type="hidden" name="id" value="<?= $id ?>">
        <input name="name" maxlength="120" required aria-label="Name" placeholder="Name" class="<?= $input ?>">
        <input name="phone" maxlength="30" inputmode="tel" aria-label="Phone" placeholder="Phone" class="<?= $input ?>">
        <input name="email" maxlength="190" type="email" aria-label="Email" placeholder="Email" class="<?= $input ?>">
        <input name="note" maxlength="255" aria-label="Note" placeholder="e.g. parent" class="<?= $input ?>">
        <div class="sm:col-span-4"><button class="<?= $btnSec ?>">Add visitor</button></div>
      </form></details>
    <?php endif; ?>
  </div>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Photos</h2>
    <?php if (!$i['photos']): ?><p class="text-sm text-stone-500">None yet.</p><?php elseif ($all): ?>
    <div class="grid grid-cols-3 sm:grid-cols-4 gap-2"><?php foreach ($i['photos'] as $p): ?>
      <a href="incident_photo.php?id=<?= (int)$p['id'] ?>"><img src="incident_photo.php?id=<?= (int)$p['id'] ?>&amp;thumb=1" alt="Incident photo <?= (int)$p['id'] ?>" class="w-full h-24 object-cover rounded border border-stone-200"></a>
    <?php endforeach; ?></div>
    <?php else: ?><p class="text-sm text-stone-500"><?= count($i['photos']) ?> photo<?= count($i['photos']) > 1 ? 's' : '' ?> saved. Owners and Managers can see them.</p><?php endif; ?>
    <?php if (count($i['photos']) < BCRF_INCIDENT_PHOTO_MAX): ?>
    <form method="POST" action="<?= $h($url($id)) ?>" enctype="multipart/form-data" class="flex flex-wrap gap-2 items-end mt-3"><?= $hidden ?><input type="hidden" name="act" value="photo"><input type="hidden" name="id" value="<?= $id ?>">
      <input name="photos[]" type="file" accept="image/*" multiple required aria-label="Photos" class="text-sm"><button class="<?= $btnSec ?>">Add photos</button></form>
    <?php endif; ?>
  </div>

  <?php if ($all): ?>
  <div class="<?= $card ?>">
    <?php if ($i['status'] === 'open'): ?>
    <h2 class="text-base font-semibold text-stone-800 mb-2">Close it</h2>
    <form method="POST" action="<?= $h($url($id)) ?>" class="flex flex-wrap gap-2 items-end"><?= $hidden ?><input type="hidden" name="act" value="status"><input type="hidden" name="to" value="closed"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="i_close">How it was settled</label><input id="i_close" name="note" maxlength="500" required class="<?= $input ?>" placeholder="e.g. called the family Monday, all fine; latch fixed"></div>
      <button class="<?= $btnMain ?>">Close</button></form>
    <?php else: ?>
    <form method="POST" action="<?= $h($url($id)) ?>" class="flex flex-wrap gap-2 items-end"><?= $hidden ?><input type="hidden" name="act" value="status"><input type="hidden" name="to" value="open"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="i_re">Why reopen (optional)</label><input id="i_re" name="note" maxlength="500" class="<?= $input ?>"></div>
      <button class="<?= $btnSec ?>" data-confirm="Reopen this incident?">Reopen</button></form>
    <?php endif; ?>
  </div>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Change history</h2>
    <?php if (!$i['edits']): ?><p class="text-sm text-stone-500">No changes since it was reported.</p><?php else: ?>
    <ul class="divide-y divide-stone-100"><?php foreach ($i['edits'] as $e): $f = BCRF_INCIDENT_FIELDS[$e['field']] ?? ucfirst($e['field']);
      $show = function ($v) use ($e) {
          if ($v === null || $v === '') return '(blank)';
          if ($e['field'] === 'happened_at') return bcrf_incident_local($v)->format('M j, Y g:i A');
          if ($e['field'] === 'type') return BCRF_INCIDENT_TYPES[$v] ?? $v;
          if ($e['field'] === 'severity') return BCRF_INCIDENT_SEVERITY[$v] ?? $v;
          if ($e['field'] === 'injury') return BCRF_INCIDENT_INJURIES[$v] ?? $v;
          return $v;
      }; ?>
      <li class="py-2 text-sm"><strong><?= $h($f) ?></strong> · <?= $h($local($e['edited_at'], 'M j, Y g:i A')) ?><?= $e['by_name'] ? ' · ' . $h($e['by_name']) : '' ?>
        <?php if ($e['old_value'] !== null): ?><span class="block text-xs text-stone-500 whitespace-pre-line">Was: <?= $h($show($e['old_value'])) ?></span><?php endif; ?>
        <span class="block text-xs text-stone-700 whitespace-pre-line"><?= $e['old_value'] !== null ? 'Now: ' : '' ?><?= $h($show($e['new_value'])) ?></span></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
  <?php endif;
    bcrf_shell_close(); exit;
}

// ── The list (Owners and Managers) ──
$status = (string)($_GET['status'] ?? 'open');
if (!in_array($status, ['open', 'closed', 'all'], true)) $status = 'open';
$type = (string)($_GET['type'] ?? '');
$rows = bcrf_incident_list($pdo, ['status' => $status === 'all' ? '' : $status, 'type' => $type]);
bcrf_shell_open('Incidents', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<div class="flex flex-wrap items-center justify-between gap-2 mb-3"><h1 class="text-2xl font-bold text-stone-800">Incidents</h1>',
     '<a class="', $btnMain, '" href="incidents.php?new=1">Report an incident</a></div>';
if ($error) echo bcrf_shell_notice('err', $error); ?>
  <form method="GET" class="<?= $card ?> flex flex-wrap gap-3 items-end">
    <div><label class="<?= $label ?>" for="f_st">Showing</label><select id="f_st" name="status" class="<?= $input ?>">
      <?php foreach (['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div><label class="<?= $label ?>" for="f_ty">Kind</label><select id="f_ty" name="type" class="<?= $input ?>"><option value="">Every kind</option>
      <?php foreach (BCRF_INCIDENT_TYPES as $k => $v): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select></div>
    <button class="<?= $btnSec ?>">Show</button>
  </form>
  <div class="<?= $card ?>">
    <?php if (!$rows): ?><p class="text-sm text-stone-500"><?= $status === 'open' ? 'No open incidents.' : 'Nothing here.' ?></p><?php else: ?>
    <ul class="divide-y divide-stone-100"><?php foreach ($rows as $i): ?>
      <li class="py-2 text-sm"><a class="underline text-amber-800 font-semibold" href="incidents.php?id=<?= (int)$i['id'] ?>">#<?= (int)$i['id'] ?> · <?= $h($local($i['happened_at'], 'D M j, g:i A')) ?></a>
        <span class="text-xs font-semibold px-2 py-0.5 rounded <?= $sevChip[$i['severity']] ?>"><?= $h(BCRF_INCIDENT_SEVERITY[$i['severity']]) ?></span><?= $i['status'] === 'closed' ? ' <span class="text-xs text-stone-500">closed</span>' : '' ?>
        <span class="block text-stone-700"><?= $h(bcrf_incident_line($i)) ?></span>
        <span class="block text-xs text-stone-500"><?= $i['animal_names'] ? $h($i['animal_names']) . ' · ' : '' ?><?= (int)$i['people_count'] ? (int)$i['people_count'] . ' visitor' . ((int)$i['people_count'] > 1 ? 's' : '') . ' · ' : '' ?>reported by <?= $h($i['created_by_name'] ?: 'someone') ?></span></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
  <p class="text-sm text-stone-500">Any staff member can report an incident from the menu (Animals › Incidents › Report). Reports are never deleted; changes are kept on each one.</p>
<?php
bcrf_shell_close();
