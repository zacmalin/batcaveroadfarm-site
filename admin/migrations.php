<?php // admin/migrations.php
/*
 * Database (Farm OS Phase 0, Nov 5, 2026). Administrators only.
 *   - Lists every migration in admin/migrations/ and whether it has run here
 *   - Capture schema: saves the current table structure (no data) to admin/migrations/schema_snapshot.txt
 *     so it can be committed as a migration
 *   - Start tracking: records 0001_baseline as already present on this (existing) database
 *   - Run pending: applies new migrations in order, after checking there's a backup from the last 24 hours
 * Library: includes/migrations.php
 */
require_once '../config.php';
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/../includes/migrations.php';
require_once __DIR__ . '/includes/audit.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf  = $_SESSION['csrf_token'];
$uid   = (int)($_SESSION['user_id'] ?? 0) ?: null;
$flash = $_SESSION['migrations_flash'] ?? null; unset($_SESSION['migrations_flash']);
$go = function (string $type, $msg) { $_SESSION['migrations_flash'] = [$type, (array)$msg]; header('Location: /admin/migrations.php'); exit; };
$audit = function (string $label) use ($pdo) { if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'schema_migrations', null, $label); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) $go('err', 'Security token expired — reload and try again.');
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'capture') {
            $c = bcrf_schema_snapshot_save($pdo);
            $audit('Captured database structure snapshot');
            $note = [sprintf('Saved %s tables and %s views (%s KB) to %s.', $c['tables'], $c['views'], number_format($c['bytes'] / 1024, 1), $c['file'])];
            if ($c['triggers'] || $c['routines']) $note[] = "Note: the database also has {$c['triggers']} trigger(s) and {$c['routines']} stored routine(s), which the snapshot doesn't include.";
            $go('ok', $note);
        }
        if ($action === 'make_baseline') {
            $sha = bcrf_baseline_from_snapshot();
            $audit('Saved structure snapshot as migration 0001_baseline');
            $go('ok', ['Saved as migration 0001_baseline (fingerprint ' . substr($sha, 0, 12) . ').', 'Next: tap Start tracking.']);
        }
        if ($action === 'adopt') {
            [$ok, $msg] = bcrf_migrations_adopt_baseline($pdo, $uid);
            if ($ok) $audit('Started migration tracking (0001 baseline recorded)');
            $go($ok ? 'ok' : 'err', $msg);
        }
        if ($action === 'run') {
            $last = bcrf_migrations_last_backup();
            if ($last < time() - 86400 && empty($_POST['no_backup_ok'])) {
                $go('err', 'No database backup from the last 24 hours was found. Check Backups first, or tick "Run without a recent backup".');
            }
            [$ok, $log] = bcrf_migrations_run_pending($pdo, $uid);
            $audit('Ran migrations: ' . implode(' | ', $log));
            $go($ok ? 'ok' : 'err', $log);
        }
    } catch (Throwable $e) {
        error_log('[migrations] ' . $e->getMessage());
        $go('err', 'Something went wrong: ' . $e->getMessage());
    }
    $go('err', 'Unknown action.');
}

$status   = bcrf_migrations_status($pdo);
$tracking = (bool)array_filter($status, fn($r) => $r['applied']);
$pending  = array_filter($status, fn($r) => $r['state'] === 'pending');
$changed  = array_filter($status, fn($r) => $r['state'] === 'changed');
$hasBase  = isset($status['0001']);
$snapAt   = is_file(bcrf_migrations_dir() . '/schema_snapshot.txt') ? filemtime(bcrf_migrations_dir() . '/schema_snapshot.txt') : 0;
$lastBk   = bcrf_migrations_last_backup();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$when = fn($utc) => $utc ? date('M j, Y g:i A', strtotime($utc . ' UTC')) : '';
$label = [
    'applied'      => ['✅', 'Ran'],
    'baseline'     => ['📌', 'Starting point'],
    'pending'      => ['⏳', 'Waiting to run'],
    'changed'      => ['⚠️', 'File edited after it ran'],
    'missing_file' => ['❓', 'Ran here, file missing'],
];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Database — BCRF Admin</title><meta name="robots" content="noindex">
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f5f5f4;color:#1c1917;margin:0;padding:16px;font-size:15px;line-height:1.5}
main{max-width:720px;margin:0 auto}h1{font-size:1.4rem;margin:0 0 4px}h2{font-size:1.05rem;margin:0 0 8px}a{color:#4a6741}
.card{background:#fff;border:1px solid #e7e5e4;border-radius:10px;padding:16px;margin:14px 0}
.row{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f0efee;align-items:flex-start}.row:last-child{border-bottom:0}
.dot{flex:0 0 auto;width:22px}.lbl{font-weight:600;flex:1;min-width:0;word-break:break-word}.val{color:#57534e;font-size:.88em;text-align:right}
.flash{padding:12px 14px;border-radius:8px;margin:12px 0}.ok{background:#ecfdf5;border:1px solid #a7f3d0}.err{background:#fef2f2;border:1px solid #fecaca}
.flash p{margin:2px 0}
button{font:inherit;padding:10px 16px;border-radius:8px;border:0;cursor:pointer;margin:4px 6px 4px 0;min-height:44px}
.primary{background:#3b2010;color:#fff}.plain{background:#e7e5e4}
.muted{color:#78716c;font-size:.9em}code{background:#f5f5f4;padding:1px 5px;border-radius:4px;font-size:.88em;word-break:break-all}
label.chk{display:flex;gap:8px;align-items:center;margin:8px 0;font-size:.92em}
</style></head><body><main>
<p><a href="/admin/admin.php">← Back to console</a></p>
<h1>🗄️ Database</h1>
<p class="muted" style="margin:0">Every change to the database structure is a numbered migration, so this farm and any future farm get the same structure, step by step.</p>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?php foreach ($flash[1] as $line): ?><p><?= $h($line) ?></p><?php endforeach; ?></div><?php endif; ?>

<div class="card">
  <div class="row"><span class="dot"><?= $tracking ? '✅' : '⚠️' ?></span><span class="lbl">Migration tracking</span><span class="val"><?= $tracking ? 'On' : 'Not started' ?></span></div>
  <div class="row"><span class="dot"><?= $pending ? '⏳' : '✅' ?></span><span class="lbl">Waiting to run</span><span class="val"><?= count($pending) ?></span></div>
  <div class="row"><span class="dot"><?= $lastBk > time() - 86400 ? '✅' : '⚠️' ?></span><span class="lbl">Newest database backup</span><span class="val"><?= $lastBk ? $h(date('M j, g:i A', $lastBk)) : 'none found' ?></span></div>
  <div class="row"><span class="dot">•</span><span class="lbl">Structure snapshot</span><span class="val"><?= $snapAt ? $h(date('M j, g:i A', $snapAt)) : 'not captured' ?></span></div>
</div>

<?php if ($changed): ?>
<div class="flash err"><p><b>A migration file was edited after it ran</b> (<?= $h(implode(', ', array_keys($changed))) ?>). Running is blocked: put the new change in a new numbered file and restore the old one from GitHub.</p></div>
<?php endif; ?>

<div class="card">
  <h2>Migrations</h2>
  <?php if (!$status): ?>
    <p class="muted">No migration files yet. Capture the structure below; it becomes <code>0001_baseline</code>.</p>
  <?php else: foreach ($status as $r): [$ic, $tx] = $label[$r['state']]; ?>
    <div class="row">
      <span class="dot"><?= $ic ?></span>
      <span class="lbl"><?= $h($r['version'] . ' ' . str_replace('_', ' ', $r['name'])) ?><br><span class="muted"><?= $h($tx) ?></span></span>
      <span class="val"><?= $r['applied'] ? $h($when($r['applied']['applied_at'])) : '' ?></span>
    </div>
  <?php endforeach; endif; ?>
</div>

<div class="card">
  <h2>Actions</h2>
  <?php if (!$hasBase && $snapAt): ?>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <p>The structure snapshot is ready. <b>Save as migration 0001</b> turns it into <code>0001_baseline</code>, the starting point every future change builds on.</p>
      <button class="primary" name="action" value="make_baseline">Save as migration 0001</button>
    </form>
  <?php endif; ?>

  <?php if ($hasBase && !$tracking): ?>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <p>This database already has all its tables. <b>Start tracking</b> records <code>0001_baseline</code> as the starting point without running it.</p>
      <button class="primary" name="action" value="adopt">Start tracking</button>
    </form>
  <?php endif; ?>

  <?php if ($tracking && $pending && !$changed): ?>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <p><?= count($pending) ?> migration<?= count($pending) === 1 ? '' : 's' ?> waiting. They run in order and stop at the first problem.</p>
      <?php if ($lastBk < time() - 86400): ?>
        <label class="chk"><input type="checkbox" name="no_backup_ok" value="1"> Run without a recent backup</label>
      <?php endif; ?>
      <button class="primary" name="action" value="run">Run <?= count($pending) ?> migration<?= count($pending) === 1 ? '' : 's' ?></button>
    </form>
  <?php endif; ?>

  <form method="POST"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <p class="muted">Capture structure saves every table's definition (no data) to <code>admin/migrations/schema_snapshot.txt</code>, a folder the web can't reach. It changes nothing in the database.</p>
    <button class="plain" name="action" value="capture">Capture structure</button>
  </form>
</div>
</main></body></html>
