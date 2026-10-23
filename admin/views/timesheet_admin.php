<?php
// admin/views/timesheet_admin.php
// Full view for viewing aggregated weekly timesheets.

if ($current_role !== 'administrator') {
    echo "<p class='p-6 text-red-700 font-mono text-xs'>Access denied.</p>";
    return;
}
?>

<div class="flex justify-between items-center mb-6 print:hidden">
    <h1 class="text-2xl font-serif font-black">Administrative Timesheet Report</h1>
    <div class="space-x-2">
        <button onclick="window.print()" class="bg-stone-100 border border-stone-300 px-4 py-2 rounded text-xs font-bold uppercase text-stone-700 hover:bg-stone-200">🖨️ Print Report</button>
        <a href="/admin/handlers/export_timesheets.php" class="bg-emerald-700 text-white px-4 py-2 rounded text-xs font-bold uppercase tracking-wider hover:bg-emerald-800">📊 Export to CSV</a>
    </div>
</div>

<!-- Search Toolbar -->
<div class="bg-white border rounded p-5 mb-6 shadow-xs flex justify-between items-center print:hidden">
    <form method="GET" class="flex gap-2">
        <input type="hidden" name="view" value="timesheet_admin">
        <input type="text" name="search_q" value="<?= htmlspecialchars($_GET['search_q'] ?? '') ?>" 
               placeholder="Search staff name..." class="p-2 border rounded text-xs outline-none focus:border-stone-500">
        <button type="submit" class="bg-stone-900 text-white px-4 py-2 rounded text-xs font-bold uppercase tracking-wider">Search Logs</button>
        <?php if (!empty($_GET['search_q'])): ?>
            <a href="admin.php?view=timesheet_admin" class="bg-stone-200 px-4 py-2 rounded text-xs font-bold uppercase">Clear</a>
        <?php endif; ?>
    </form>
    <!-- ... Export/Print buttons remain the same ... -->
</div>


<?php if (empty($all_time_cards)): ?>
    <div class="bg-white p-10 border rounded text-center text-stone-500 italic">No timesheet data available for the current period.</div>
<?php else: ?>
    <?php foreach ($all_time_cards as $week => $users): ?>
        <div class="bg-white border rounded shadow-xs mb-8 overflow-hidden print:border-stone-300 print:shadow-none">
            <div class="bg-stone-900 text-white p-3 font-bold text-xs uppercase tracking-wider">
                Week Ending: <?= htmlspecialchars($week) ?>
            </div>
            <table class="w-full text-xs text-center border-collapse">
                <thead>
                    <tr class="bg-stone-50 border-b border-stone-200">
                        <th class="p-3 text-left">Employee Name (ID)</th>
                        <th class="p-3">Sun</th>
                        <th class="p-3">Mon</th>
                        <th class="p-3">Tue</th>
                        <th class="p-3">Wed</th>
                        <th class="p-3">Thu</th>
                        <th class="p-3">Fri</th>
                        <th class="p-3">Sat</th>
                        <th class="p-3 font-bold text-stone-900 bg-stone-100">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    <?php foreach ($users as $id => $u): ?>
                        <tr class="hover:bg-stone-50">
                            <td class="p-3 text-left font-bold text-stone-800"><?= htmlspecialchars($u['name']) ?> <span class="text-stone-400 font-normal">(#<?= $id ?>)</span></td>
                            <?php foreach ($u['days'] as $h): ?>
                                <td class="p-3 font-mono text-stone-600"><?= $h > 0 ? number_format($h, 1) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="p-3 font-bold bg-stone-50 text-stone-900"><?= number_format($u['total'], 1) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<style>
    @media print {
        body { background: white; }
        .print\:hidden { display: none !important; }
        .shadow-xs { box-shadow: none !important; }
        table { border: 1px solid #e7e5e4; }
    }
</style>
