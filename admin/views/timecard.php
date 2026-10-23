<?php
// Fix 2+3: helper — formats a seconds count into "Xh Ym"
function fmt_duration(int $secs): string {
    if ($secs <= 0) return '—';
    $h = floor($secs / 3600);
    $m = floor(($secs % 3600) / 60);
    return ($h > 0 ? "{$h}h " : '') . "{$m}m";
}

// Fix 3: elapsed seconds on the active shift (calculated at page load)
$elapsed_seconds = $active_shift ? (time() - strtotime($active_shift['clock_in'])) : 0;
?>

<h1 class="text-2xl font-serif font-black mb-6">Shift Time Clock Interface</h1>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <!-- ── CLOCK IN/OUT PANEL ── -->
    <div class="bg-white border rounded p-6 shadow-xs self-start space-y-4">
        <h3 class="font-serif font-black text-sm uppercase tracking-wide border-b pb-2 text-stone-800">Terminal Connection</h3>

        <form method="POST" class="space-y-4 font-sans text-xs">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <?php if (!$active_shift): ?>
                <div class="p-3 bg-red-50 border border-red-200 text-red-800 font-bold rounded font-mono text-[11px] tracking-wide">
                    ⏹ STATUS: CLOCKED OUT
                </div>
                <div>
                    <!-- Fix 8: maxlength cap on notes -->
                    <label class="block font-bold text-stone-600 mb-1">Shift Start Notes</label>
                    <input type="text" name="clock_notes" maxlength="120"
                        class="w-full p-2 border bg-stone-50 rounded text-xs"
                        placeholder="e.g., Running farm stand register...">
                </div>
                <button type="submit" name="time_clock_action" value="clock_in"
                    class="w-full bg-stone-900 text-white font-mono font-bold uppercase tracking-wider py-3 rounded hover:bg-stone-800 transition shadow-xs">
                    Clock IN →
                </button>

            <?php else: ?>
                <div class="p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 font-bold rounded font-mono text-[11px] tracking-wide animate-pulse">
                    ▶ STATUS: SHIFT ACTIVE
                </div>

                <!-- Fix 3: elapsed time display, updates live via JS -->
                <div class="bg-stone-50 border rounded p-3 text-center">
                    <span class="block text-[10px] uppercase tracking-widest font-bold text-stone-400 mb-1">Elapsed Time</span>
                    <span id="elapsedDisplay" class="text-2xl font-black font-mono text-stone-900">
                        <?= fmt_duration($elapsed_seconds) ?>
                    </span>
                    <span class="block text-xxs text-stone-400 mt-1 font-mono">
                        Since <?= date('g:i A', strtotime($active_shift['clock_in'])) ?>
                    </span>
                </div>

                <!-- Fix 9: confirm dialog on clock-out -->
                <button type="submit" name="time_clock_action" value="clock_out"
                    onclick="return sysClockOutConfirm(this);"
                    class="w-full bg-red-700 text-white font-mono font-bold uppercase tracking-wider py-3 rounded hover:bg-red-900 transition shadow-xs">
                    Clock OUT ✕
                </button>
            <?php endif; ?>
        </form>

        <!-- Fix 6: weekly hours summary card -->
        <div class="border-t pt-4 space-y-1 font-mono text-xs">
            <span class="block text-[10px] uppercase tracking-widest font-bold text-stone-400">This Week</span>
            <div class="flex justify-between items-baseline">
                <span class="text-stone-600 font-bold">Completed Hours</span>
                <span class="text-stone-900 font-black text-sm"><?= fmt_duration($weekly_seconds) ?></span>
            </div>
            <?php if ($active_shift): ?>
            <div class="flex justify-between items-baseline text-emerald-700">
                <span class="font-bold">+ Active Shift</span>
                <span class="font-black" id="weeklyElapsedExtra"><?= fmt_duration($elapsed_seconds) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── WEEKLY LOG TABLE ── -->
    <div class="lg:col-span-2 bg-white border rounded p-6 shadow-xs">
        <h3 class="font-serif font-black text-sm uppercase tracking-wide border-b pb-2 mb-4 text-stone-800">Weekly Shift Log</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse font-sans text-xs" data-admin-list data-list-name-col="0" data-list-date-col="0">
                <thead>
                    <tr class="bg-stone-50 border-b text-stone-400 uppercase tracking-wider text-[10px] font-bold">
                        <th class="p-3">Clock In</th>
                        <th class="p-3">Clock Out</th>
                        <!-- Fix 2: duration column -->
                        <th class="p-3">Duration</th>
                        <th class="p-3">Station IP</th>
                        <th class="p-3">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    <?php if (empty($my_shift_rows)): ?>
                        <tr>
                            <td colspan="5" class="p-4 text-center italic text-stone-400">No shift logs this week yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($my_shift_rows as $mc):
                        // Fix 2: calculate duration per row
                        $out_ts   = $mc['clock_out'] ? strtotime($mc['clock_out']) : null;
                        $in_ts    = strtotime($mc['clock_in']);
                        $dur_secs = $out_ts ? ($out_ts - $in_ts) : null;
                    ?>
                    <tr class="hover:bg-stone-50/50">
                        <td class="p-3 font-mono text-stone-900 whitespace-nowrap">
                            <?= date('M d, g:i A', $in_ts) ?>
                        </td>
                        <td class="p-3 font-mono text-stone-600 whitespace-nowrap">
                            <?php if ($mc['clock_out']): ?>
                                <?= date('M d, g:i A', $out_ts) ?>
                            <?php else: ?>
                                <span class="text-xxs font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 font-mono font-bold <?= $dur_secs ? 'text-stone-700' : 'text-emerald-600' ?>">
                            <?= $dur_secs ? fmt_duration($dur_secs) : '<span id="rowElapsed">—</span>' ?>
                        </td>
                        <td class="p-3 font-mono text-stone-400 text-xxs">
                            <?= htmlspecialchars($mc['ip_address'] ?? '', ENT_QUOTES) ?>
                        </td>
                        <td class="p-3 text-stone-500 font-medium max-w-xs truncate">
                            <?= htmlspecialchars($mc['notes'] ?? '', ENT_QUOTES) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <!-- Fix 2: weekly total footer row -->
                <?php if (!empty($my_shift_rows)): ?>
                <tfoot>
                    <tr class="border-t-2 border-stone-300 bg-stone-50 font-mono font-bold text-xs">
                        <td class="p-3 text-stone-500 uppercase tracking-wider text-[10px]" colspan="2">Weekly Total</td>
                        <td class="p-3 text-stone-900 font-black"><?= fmt_duration($weekly_seconds) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

<?php if ($active_shift): ?>
<!-- Fix 3: live elapsed timer — ticks every 60s, no page reload needed -->
<script>
function sysClockOutConfirm(el) {
    sysConfirm(
        'Clock out and end your current shift?',
        'Clock Out', '🕒', 'Clock Out'
    ).then(function(confirmed) {
        if (!confirmed) return;
        var form = el.closest('form');
        // form.submit() skips button name/value — inject it as hidden input
        if (el.name) {
            var h = document.createElement('input');
            h.type  = 'hidden';
            h.name  = el.name;
            h.value = el.value;
            form.appendChild(h);
        }
        form.submit();
    });
    return false;
}

    let elapsedSecs = <?= $elapsed_seconds ?>;
    const weeklySecs = <?= $weekly_seconds ?>;

    function fmtDuration(s) {
        if (s <= 0) return '0m';
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        return (h > 0 ? h + 'h ' : '') + m + 'm';
    }

    function tick() {
        elapsedSecs += 60;
        const el = document.getElementById('elapsedDisplay');
        const ex = document.getElementById('weeklyElapsedExtra');
        const re = document.getElementById('rowElapsed');
        if (el) el.textContent = fmtDuration(elapsedSecs);
        if (ex) ex.textContent = fmtDuration(elapsedSecs);
        if (re) re.textContent = fmtDuration(elapsedSecs);
    }

    setInterval(tick, 60000);
</script>
<?php endif; ?>
