<?php
// admin/views/theme_scheduler.php
require_once __DIR__ . '/../../includes/themes.php';
$catalog = bcrf_theme_catalog();

$winMap = [];
foreach ($catalog as $slug => $meta) {
    $parts = explode('..', (string)$meta['window']);
    $winMap[$slug] = ['s' => $parts[0] ?? '', 'e' => $parts[1] ?? ''];
}

$tablesMissing = false;
$schedules = [];
$settings  = ['override' => '', 'default' => ''];
$activeNow = '';
try {
    $schedules = $pdo->query("SELECT * FROM theme_schedules ORDER BY priority DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pdo->query("SELECT skey, sval FROM theme_settings")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        $settings[$k] = (string)$v;
    }
    $activeNow = bcrf_active_theme($pdo);
} catch (Throwable $e) {
    $tablesMissing = true;
}
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES);
$nameOf = fn(string $s): string => isset($catalog[$s]) ? $catalog[$s]['name'] : $s;
?>

<div class="mb-6">
    <h1 class="text-2xl font-serif font-black text-stone-800">🎨 Seasonal Themes</h1>
    <p class="text-xs text-stone-400 font-mono mt-0.5">Schedule when each seasonal look runs across the site.</p>
</div>

<?php if ($tablesMissing): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded p-4 text-xs">
        ⚠️ The theme tables don't exist yet. Run <code class="font-mono">sql/theme_tables.sql</code> in phpMyAdmin, then reload this page.
    </div>
<?php else: ?>

<!-- Current status -->
<div class="bg-white border rounded shadow-xs p-5 mb-5">
    <div class="text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">Live right now</div>
    <div class="text-lg font-bold text-stone-800">
        <?= $activeNow !== '' ? htmlspecialchars($nameOf($activeNow)) : 'No theme (default look)' ?>
    </div>
    <div class="text-xs text-stone-500 mt-1">
        Override: <strong><?= $settings['override'] !== '' ? htmlspecialchars($nameOf($settings['override'])) : 'off' ?></strong>
        &nbsp;&bull;&nbsp;
        Default: <strong><?= $settings['default'] !== '' ? htmlspecialchars($nameOf($settings['default'])) : 'none' ?></strong>
    </div>
    <div class="mt-3 flex flex-wrap gap-1.5">
        <?php foreach ($catalog as $slug => $meta): ?>
            <a href="https://batcaveroadfarm.com/?theme=<?= urlencode($slug) ?>" target="_blank" rel="noopener"
               class="text-[11px] font-mono border border-stone-300 rounded px-2 py-1 hover:bg-stone-100">
                👁 <?= htmlspecialchars($meta['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

    <!-- Settings: override + default -->
    <div class="bg-white border rounded shadow-xs p-5">
        <h2 class="text-lg font-serif font-black text-stone-800 mb-3">Override &amp; Default</h2>
        <form method="POST" action="/admin/admin.php?view=theme_scheduler">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="theme_action" value="settings">

            <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">Manual override (forces a theme now)</label>
            <select name="override" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs mb-3">
                <option value="">— off —</option>
                <?php foreach ($catalog as $slug => $meta): ?>
                    <option value="<?= htmlspecialchars($slug) ?>" <?= $settings['override'] === $slug ? 'selected' : '' ?>><?= htmlspecialchars($meta['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">Default (when nothing is scheduled)</label>
            <select name="default" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs mb-4">
                <option value="">— none —</option>
                <?php foreach ($catalog as $slug => $meta): ?>
                    <option value="<?= htmlspecialchars($slug) ?>" <?= $settings['default'] === $slug ? 'selected' : '' ?>><?= htmlspecialchars($meta['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="bg-stone-900 hover:bg-stone-700 text-white font-mono font-bold uppercase tracking-wider px-4 py-2 rounded text-xs">Save Settings</button>
        </form>
    </div>

    <!-- Add a schedule -->
    <div class="bg-white border rounded shadow-xs p-5">
        <h2 class="text-lg font-serif font-black text-stone-800 mb-3">Add a Schedule</h2>
        <form method="POST" action="/admin/admin.php?view=theme_scheduler">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="theme_action" value="add">

            <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">Theme</label>
            <select name="theme_slug" id="ts-theme" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs mb-3">
                <?php foreach ($catalog as $slug => $meta): ?>
                    <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($meta['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">Mode</label>
            <select name="mode" id="ts-mode" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs mb-3">
                <option value="annual">Recurring every year</option>
                <option value="range">One-off date range</option>
            </select>

            <div id="ts-annual" class="grid grid-cols-2 gap-2 mb-3">
                <div>
                    <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">Start (MM-DD)</label>
                    <input type="text" name="start_md" id="ts-start-md" placeholder="12-10" pattern="\d{2}-\d{2}" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">End (MM-DD)</label>
                    <input type="text" name="end_md" id="ts-end-md" placeholder="12-26" pattern="\d{2}-\d{2}" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs">
                </div>
            </div>

            <div id="ts-range" class="grid grid-cols-2 gap-2 mb-3" style="display:none">
                <div>
                    <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">Start date</label>
                    <input type="date" name="start_date" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">End date</label>
                    <input type="date" name="end_date" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs">
                </div>
            </div>

            <label class="block text-[11px] font-mono uppercase tracking-wider text-stone-500 mb-1">Priority (higher wins on overlap — give holidays a higher number than seasons)</label>
            <input type="number" name="priority" value="0" class="w-full border border-stone-300 rounded px-2 py-1.5 text-xs mb-4">

            <button type="submit" class="bg-stone-900 hover:bg-stone-700 text-white font-mono font-bold uppercase tracking-wider px-4 py-2 rounded text-xs">Add Schedule</button>
        </form>
    </div>
</div>

<!-- Existing schedules -->
<div class="bg-white border rounded shadow-xs p-5 mt-5">
    <h2 class="text-lg font-serif font-black text-stone-800 mb-3">Scheduled Themes</h2>
    <?php if (empty($schedules)): ?>
        <p class="text-stone-400 italic text-xs">No schedules yet. Add one above, or set a manual override.</p>
    <?php else: ?>
    <table class="w-full text-xs" data-admin-list data-list-name-col="0">
        <thead>
            <tr class="text-left text-[10px] font-mono uppercase tracking-wider text-stone-400 border-b">
                <th class="py-2">Theme</th><th>When</th><th>Priority</th><th>Status</th><th class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($schedules as $row):
                $when = $row['mode'] === 'annual'
                    ? htmlspecialchars($row['start_md'] . ' → ' . $row['end_md']) . ' <span class="text-stone-400">(yearly)</span>'
                    : htmlspecialchars($row['start_date'] . ' → ' . $row['end_date']);
                $on = (int)$row['is_active'] === 1;
            ?>
            <tr class="border-b border-stone-100">
                <td class="py-2 font-bold text-stone-800"><?= htmlspecialchars($nameOf($row['theme_slug'])) ?></td>
                <td class="font-mono text-stone-600"><?= $when ?></td>
                <td class="font-mono"><?= (int)$row['priority'] ?></td>
                <td>
                    <span class="text-[10px] font-black uppercase px-1.5 py-0.5 rounded <?= $on ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-200 text-stone-500' ?>">
                        <?= $on ? 'Active' : 'Paused' ?>
                    </span>
                </td>
                <td class="text-right whitespace-nowrap">
                    <form method="POST" action="/admin/admin.php?view=theme_scheduler" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="theme_action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="border px-2 py-1 rounded hover:bg-stone-100 font-mono"><?= $on ? 'Pause' : 'Resume' ?></button>
                    </form>
                    <form method="POST" action="/admin/admin.php?view=theme_scheduler" class="inline" >
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="theme_action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="border border-red-200 text-red-700 px-2 py-1 rounded hover:bg-red-50 font-mono">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
(function () {
    var win = <?= json_encode($winMap) ?>;
    var sel = document.getElementById('ts-theme');
    var sm = document.getElementById('ts-start-md'), em = document.getElementById('ts-end-md');
    function prefill() { var w = win[sel.value]; if (w) { sm.value = w.s; em.value = w.e; } }
    if (sel) sel.addEventListener('change', prefill);
    var modeSel = document.getElementById('ts-mode');
    function modeToggle() {
        var annual = modeSel.value === 'annual';
        document.getElementById('ts-annual').style.display = annual ? '' : 'none';
        document.getElementById('ts-range').style.display = annual ? 'none' : '';
    }
    if (modeSel) { modeSel.addEventListener('change', modeToggle); modeToggle(); }
    prefill();
})();
</script>

<?php endif; ?>
