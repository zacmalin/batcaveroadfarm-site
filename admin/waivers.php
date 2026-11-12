<?php
// admin/waivers.php — Customers › Waivers (Farm OS Phase 3, Nov 12, 2026; includes/waivers.php, migration 0039).
//   GET  waivers.php[?q=…]          signed waivers, newest first; search by name, email, phone or a child's name
//   GET  waivers.php?id=N           one signature: who, children, version, how and when, the drawn signature, the text
//   GET  waivers.php?tab=wording    the published versions and the draft; administrators edit and publish
//   GET  waivers.php?tab=settings   gate mode (off / warn / block) and how long a signature lasts (administrators)
//   POST act=void | draft | publish | settings
// Permission: view 'customers'. Publishing and settings: administrators only. Signatures are never deleted.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'customers']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/waivers.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$isAdmin = ($_SESSION['user_role'] ?? '') === 'administrator';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$local = fn($utc, $fmt = 'M j, Y g:i A') => $utc ? date($fmt, strtotime($utc . ' UTC')) : '';

if (!bcrf_waivers_ready($pdo)) {
    bcrf_shell_open('Waivers');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Waivers</h1>', bcrf_shell_notice('warning', 'Run migration 0039 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $back = 'waivers.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfWaiverError('Security token expired; try again.');
        if ($act === 'void') {
            $id = (int)($_POST['id'] ?? 0);
            $back = 'waivers.php?id=' . $id;
            bcrf_waiver_void($pdo, $id, (string)($_POST['reason'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'waivers', $id, 'Waiver voided: ' . trim((string)$_POST['reason']));
            $_SESSION['wv_flash'] = ['ok', 'Voided. It no longer counts at the gate.'];
        } elseif (in_array($act, ['draft', 'publish', 'settings'], true)) {
            if (!$isAdmin) throw new BcrfWaiverError('Only an administrator can change the waiver.');
            $back = 'waivers.php?tab=' . ($act === 'settings' ? 'settings' : 'wording');
            if ($act === 'draft') {
                bcrf_waiver_draft_save($pdo, (string)($_POST['title'] ?? ''), (string)($_POST['body'] ?? ''), $uid);
                $_SESSION['wv_flash'] = ['ok', 'Draft saved. Nobody sees it until you publish it.'];
            } elseif ($act === 'publish') {
                $v = bcrf_waiver_publish($pdo, (string)($_POST['effective'] ?? ''), (string)($_POST['reviewed_note'] ?? ''), !empty($_POST['attorney']), $uid);
                if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'waiver_templates', $v, 'Waiver version ' . $v . ' published (reviewed: ' . trim((string)$_POST['reviewed_note']) . ')');
                $_SESSION['wv_flash'] = ['ok', 'Version ' . $v . ' published.'];
            } else {
                $mode = (string)($_POST['mode'] ?? 'off');
                $months = (int)($_POST['months'] ?? 12);
                if (!isset(BCRF_WAIVER_MODES[$mode])) throw new BcrfWaiverError('Pick what the gate does.');
                if ($months < 1 || $months > 60) throw new BcrfWaiverError('A signature can last 1 to 60 months.');
                bcrf_waiver_set_setting($pdo, 'waiver_mode', $mode);
                bcrf_waiver_set_setting($pdo, 'waiver_valid_months', (string)$months);
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'site_settings', 0, 'Waiver settings: gate ' . $mode . ', good for ' . $months . ' months');
                $_SESSION['wv_flash'] = ['ok', 'Saved.'];
            }
        } else {
            throw new BcrfWaiverError('Unknown action.');
        }
    } catch (BcrfWaiverError $e) {
        $_SESSION['wv_flash'] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[waivers] ' . $act . ': ' . $e->getMessage());
        $_SESSION['wv_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['wv_flash'] ?? null; unset($_SESSION['wv_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$hidden  = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
$tab = (string)($_GET['tab'] ?? '');
$tabs = function (string $on) use ($h) {
    $out = '<div class="flex flex-wrap gap-1 mb-4">';
    foreach ([['Signed', 'waivers.php', ''], ['Wording', 'waivers.php?tab=wording', 'wording'], ['Settings', 'waivers.php?tab=settings', 'settings']] as [$t, $u, $k]) {
        $out .= '<a href="' . $u . '" class="px-3 py-2 rounded text-sm border ' . ($k === $on ? 'border-stone-800 bg-stone-800 text-white' : 'border-stone-300 text-stone-700 bg-white hover:bg-stone-100') . '">' . $h($t) . '</a>';
    }
    return $out . '</div>';
};
$cur = bcrf_waiver_current($pdo);
$mode = bcrf_waiver_setting($pdo, 'waiver_mode', 'off');
$status = $cur ? 'Version ' . (int)$cur['version'] . ' is in effect. Gate: ' . BCRF_WAIVER_MODES[bcrf_waiver_mode($pdo)] . '.'
               : 'Nothing is published yet, so nobody can sign and the gate doesn\'t check. Paste the attorney-reviewed wording under Wording, then publish it.';

// ── One signature ──
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $w = bcrf_waiver_get($pdo, $id);
    if (!$w) { header('Location: waivers.php'); exit; }
    bcrf_shell_open('Waiver: ' . $w['signer_name'], ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    echo '<a href="waivers.php" class="text-sm text-amber-800 underline">‹ Waivers</a>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mt-2 mb-3">', $h($w['signer_name']), '</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if ($w['voided_at']) echo bcrf_shell_notice('warning', 'Voided ' . $local($w['voided_at']) . ': ' . $w['void_reason']);
    elseif (strtotime($w['expires_at'] . ' UTC') < time()) echo bcrf_shell_notice('warning', 'Ran out on ' . $local($w['expires_at'], 'M j, Y') . '.');
?>
  <div class="<?= $card ?> text-sm">
    <p><?= $h($w['signer_email']) ?><?= $w['signer_phone'] ? ' · ' . $h($w['signer_phone']) : '' ?><?= $w['customer_id'] ? ' · <a class="underline text-amber-800" href="customers.php?id=' . (int)$w['customer_id'] . '">Customer page</a>' : '' ?></p>
    <p class="mt-1">Children: <?= $w['minors'] ? $h(implode(', ', array_map(fn($m) => $m['name'] . ($m['age'] !== '' ? ' (' . $m['age'] . ')' : ''), $w['minors']))) : 'none listed' ?></p>
    <p class="mt-1">Signed <?= $h($local($w['signed_at'])) ?> · <?= $h(BCRF_WAIVER_VIA[$w['via']] ?? $w['via']) ?> · version <?= (int)$w['version'] ?> · good until <?= $h($local($w['expires_at'], 'M j, Y')) ?></p>
    <p class="mt-1">Typed signature: <strong><?= $h($w['typed_signature']) ?></strong></p>
    <?php if ($w['signature_png']): ?><img src="data:image/png;base64,<?= $h($w['signature_png']) ?>" alt="Drawn signature" class="mt-2 border border-stone-200 rounded max-w-full"><?php endif; ?>
    <p class="mt-2 text-xs text-stone-500">Text fingerprint <?= $h(substr($w['body_sha256'], 0, 16)) ?>… · from <?= $h($w['ip'] ?? '?') ?></p>
  </div>
  <details class="<?= $card ?>">
    <summary class="cursor-pointer text-sm font-semibold text-stone-800">What they signed (version <?= (int)$w['version'] ?>)</summary>
    <h2 class="text-base font-semibold text-stone-800 mt-3"><?= $h($w['title']) ?></h2>
    <?php foreach (bcrf_waiver_paragraphs($w['body']) as $p): ?><p class="text-sm mt-2"><?= nl2br($h($p)) ?></p><?php endforeach; ?>
  </details>
  <?php if (!$w['voided_at']): ?>
  <form method="POST" action="waivers.php" class="<?= $card ?> flex flex-wrap gap-2 items-end"><?= $hidden ?><input type="hidden" name="act" value="void"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="vr">Void it (it stays on file)</label><input id="vr" name="reason" maxlength="255" required placeholder="Why (e.g. signed under the wrong email)" class="<?= $input ?>"></div>
    <button class="<?= $btnSec ?>" data-confirm="Void this waiver? It stays on file but no longer counts at the gate.">Void</button>
  </form>
  <?php endif; ?>
<?php
    bcrf_shell_close(); exit;
}

// ── Wording ──
if ($tab === 'wording') {
    bcrf_shell_open('Waivers', ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Waivers</h1>', $tabs('wording');
    if ($error) echo bcrf_shell_notice('err', $error);
    echo bcrf_shell_notice($cur ? 'info' : 'warning', $status);
    $draft = bcrf_waiver_draft($pdo);
    $versions = bcrf_waiver_versions($pdo);
?>
  <form method="POST" action="waivers.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="draft">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Draft</h2>
    <p class="text-sm text-stone-500 mb-3">The wording is a legal document: paste exactly what your NC attorney approved. Leave a blank line between paragraphs.</p>
    <div class="mb-3"><label class="<?= $label ?>" for="wt">Title</label><input id="wt" name="title" maxlength="160" required value="<?= $h($draft['title']) ?>" class="<?= $input ?>" <?= $isAdmin ? '' : 'disabled' ?>></div>
    <div class="mb-3"><label class="<?= $label ?>" for="wb">Wording</label><textarea id="wb" name="body" rows="16" required class="<?= $input ?>" <?= $isAdmin ? '' : 'disabled' ?>><?= $h($draft['body']) ?></textarea></div>
    <?php if ($isAdmin): ?><button class="<?= $btnSec ?>">Save draft</button><?php endif; ?>
  </form>
  <?php if ($isAdmin): ?>
  <form method="POST" action="waivers.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="publish">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Publish the draft</h2>
    <p class="text-sm text-stone-500 mb-3">Publishing makes it the next version. It can't be edited afterward; to change it, publish a new version. Signatures already made keep pointing to the version they signed.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
      <div><label class="<?= $label ?>" for="we">Takes effect</label><input id="we" name="effective" type="date" required value="<?= $h(date('Y-m-d')) ?>" min="<?= $h(date('Y-m-d')) ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="wr">Reviewed by</label><input id="wr" name="reviewed_note" maxlength="255" required placeholder="Attorney name, date reviewed" class="<?= $input ?>"></div>
    </div>
    <label class="flex gap-2 items-start text-sm mb-3"><input type="checkbox" name="attorney" value="1" required class="mt-1"> An NC attorney reviewed this exact wording.</label>
    <button class="<?= $btnMain ?>" data-confirm="Publish this wording? Visitors will sign it from the date it takes effect.">Publish</button>
  </form>
  <?php endif; ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Published versions</h2>
    <?php if (!$versions): ?><p class="text-sm text-stone-500">None yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($versions as $v): ?>
      <li class="py-2 text-sm"><details><summary class="cursor-pointer">Version <?= (int)$v['version'] ?> · in effect from <?= $h($local($v['effective_at'], 'M j, Y')) ?> · <?= (int)$v['signed'] ?> signed · reviewed: <?= $h($v['reviewed_note']) ?></summary>
        <h3 class="font-semibold mt-2"><?= $h($v['title']) ?></h3><?php foreach (bcrf_waiver_paragraphs($v['body']) as $p): ?><p class="mt-2"><?= nl2br($h($p)) ?></p><?php endforeach; ?></details></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
    bcrf_shell_close(); exit;
}

// ── Settings ──
if ($tab === 'settings') {
    bcrf_shell_open('Waivers', ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Waivers</h1>', $tabs('settings');
    if ($error) echo bcrf_shell_notice('err', $error);
    echo bcrf_shell_notice($cur ? 'info' : 'warning', $status);
?>
  <form method="POST" action="waivers.php" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="settings">
    <fieldset class="mb-3" <?= $isAdmin ? '' : 'disabled' ?>>
      <legend class="<?= $label ?>">At the gate</legend>
      <?php foreach (BCRF_WAIVER_MODES as $k => $v): ?>
      <label class="flex gap-2 items-start text-sm py-1"><input type="radio" name="mode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?> class="mt-1">
        <span><?= $h($v) ?><span class="block text-xs text-stone-500"><?= $h(['off' => 'Nothing is shown or checked.', 'warn' => 'Each ticket, pass and group shows whether a waiver is on file, with a Sign now button.', 'block' => 'Nobody is checked in until the adult signs. Owners and managers can let a party in with a reason.'][$k]) ?></span></span></label>
      <?php endforeach; ?>
      <div class="mt-3 max-w-48"><label class="<?= $label ?>" for="wm">A signature lasts (months)</label><input id="wm" name="months" type="number" min="1" max="60" value="<?= bcrf_waiver_valid_months($pdo) ?>" class="<?= $input ?>"></div>
    </fieldset>
    <?php if ($isAdmin): ?><button class="<?= $btnMain ?>">Save</button><?php else: ?><p class="text-sm text-stone-500">Only an administrator can change these.</p><?php endif; ?>
    <p class="text-sm text-stone-500 mt-3">Visitors sign online at <a class="underline text-amber-800" href="<?= $h(bcrf_farm_url()) ?>/waiver.php"><?= $h(preg_replace('#^https?://#', '', bcrf_farm_url())) ?>/waiver.php</a>, or on the gate device. Signatures are kept even if someone asks to delete their data.</p>
  </form>
<?php
    bcrf_shell_close(); exit;
}

// ── Signed ──
$q = trim((string)($_GET['q'] ?? ''));
$rows = bcrf_waiver_search($pdo, $q);
bcrf_shell_open('Waivers', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Waivers</h1>', $tabs('');
if ($error) echo bcrf_shell_notice('err', $error);
if (!$cur) echo bcrf_shell_notice('warning', $status);
?>
  <form method="GET" action="waivers.php" class="flex gap-2 mb-4">
    <input name="q" value="<?= $h($q) ?>" placeholder="Name, email, phone or a child's name" aria-label="Search waivers" class="<?= $input ?>"><button class="<?= $btnSec ?> shrink-0">Search</button></form>
  <?php if (!$rows): ?><p class="text-sm text-stone-500"><?= $q !== '' ? 'Nothing matches that.' : 'No waivers signed yet.' ?></p><?php else: ?>
  <div class="<?= $card ?> p-0 overflow-hidden">
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rows as $r): $gone = $r['voided_at'] || strtotime($r['expires_at'] . ' UTC') < time(); ?>
      <li><a href="waivers.php?id=<?= (int)$r['id'] ?>" class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2 hover:bg-stone-50">
        <span><span class="text-sm font-semibold text-stone-800"><?= $h($r['signer_name']) ?></span>
          <span class="text-xs text-stone-500"><?= $r['minors'] ? '+ ' . $h(implode(', ', array_column($r['minors'], 'name'))) : '' ?></span>
          <span class="block text-xs text-stone-500 break-all"><?= $h($r['signer_email']) ?></span></span>
        <span class="text-xs <?= $gone ? 'text-red-700' : 'text-stone-500' ?>"><?= $r['voided_at'] ? 'Voided' : ($gone ? 'Ran out ' . $h($local($r['expires_at'], 'M j, Y')) : 'Signed ' . $h($local($r['signed_at'], 'M j, Y')) . ' · ' . $h(BCRF_WAIVER_VIA[$r['via']] ?? $r['via'])) ?> · v<?= (int)$r['version'] ?></span>
      </a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php
bcrf_shell_close();
