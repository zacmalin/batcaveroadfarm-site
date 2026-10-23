<style>
@media print {
    @page {
        size: letter portrait;
        margin: 0.5in;
    }

    /* Nuclear layout reset — kill everything except main content */
    body > *:not(main) { display: none !important; }
    body {
        display: block !important;
        background: #fff !important;
        color: #000 !important;
        font-size: 9pt !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    main {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 100% !important;
        flex: none !important;
        padding: 0 !important;
        margin: 0 !important;
        max-height: none !important;
        overflow: visible !important;
        background: #fff !important;
    }

    /* Kill all backgrounds except dark table headers */
    *:not(.bg-stone-900):not(.bg-stone-100) {
        background: transparent !important;
        box-shadow: none !important;
    }
    .bg-stone-100 { background: #f3f4f6 !important; }
    .bg-stone-900 {
        background: #1c1917 !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Hide non-content elements */
    button, form, .print\:hidden { display: none !important; }

    /* Title */
    h1 { font-size: 14pt !important; margin: 0 0 8pt !important; }
    h3 { font-size: 9pt !important; margin: 0 !important; }

    /* Summary cards — tight horizontal strip */
    .grid {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 6pt !important;
        margin-bottom: 10pt !important;
        width: 100% !important;
    }
    .grid > div {
        flex: 0 0 auto !important;
        min-width: 70pt !important;
        max-width: 120pt !important;
        padding: 4pt 6pt !important;
        border: 0.5pt solid #bbb !important;
        border-radius: 2pt !important;
        page-break-inside: avoid !important;
    }
    .text-2xl { font-size: 12pt !important; }
    .text-sm  { font-size: 8pt  !important; }
    .text-xs  { font-size: 7.5pt !important; }
    .text-xxs, .text-\[9px\], .text-\[10px\] { font-size: 6.5pt !important; }
    .animate-pulse { animation: none !important; }

    /* Table — full width, compact */
    .overflow-x-auto, .overflow-hidden { overflow: visible !important; }
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 7.5pt !important;
        table-layout: fixed !important;
    }
    th, td {
        padding: 2.5pt 4pt !important;
        border-bottom: 0.5pt solid #ddd !important;
        word-wrap: break-word !important;
        white-space: normal !important;
    }
    /* Column widths */
    th:nth-child(1), td:nth-child(1) { width: 18% !important; }
    th:nth-child(2), td:nth-child(2) { width: 20% !important; }
    th:nth-child(3), td:nth-child(3) { width: 20% !important; }
    th:nth-child(4), td:nth-child(4) { width: 12% !important; }
    th:nth-child(5), td:nth-child(5) { display: none !important; } /* IP — hidden */
    th:nth-child(6), td:nth-child(6) { width: 30% !important; }

    tr { page-break-inside: avoid !important; }
    .border-t-2 { border-top: 1pt solid #999 !important; }
    .rounded { border-radius: 1pt !important; }

    /* Borders on containers */
    .border { border: 0.5pt solid #ddd !important; }
    .mb-6 { margin-bottom: 8pt !important; }
    .px-5 { padding-left: 4pt !important; padding-right: 4pt !important; }
    .py-3 { padding-top: 3pt !important; padding-bottom: 3pt !important; }
}
</style>

<?php
// Guard all loader variables — view works even if loader wasn't updated yet
$ts_staff       = $ts_staff       ?? [];
$ts_shifts      = $ts_shifts      ?? [];
$ts_summary     = $ts_summary     ?? [];
$ts_filter_user = $ts_filter_user ?? 0;

// Safer date defaults calculated here rather than relying solely on loader
$preset = $_GET['preset'] ?? 'this_week';

// Reliable "start of current week" — Monday at 00:00
$monday_offset   = (date('N') == 1) ? 0 : -(date('N') - 1);
$this_monday     = date('Y-m-d', strtotime("{$monday_offset} days"));

$default_from = match($preset) {
    'last_week'  => date('Y-m-d', strtotime('-' . (date('N') + 6) . ' days')),
    'this_month' => date('Y-m-01'),
    'last_month' => date('Y-m-01', strtotime('first day of last month')),
    'custom'     => $_GET['date_from'] ?? $this_monday,
    default      => $this_monday,
};
$default_to = match($preset) {
    'last_week'  => date('Y-m-d', strtotime('-' . date('N') . ' days')),
    'last_month' => date('Y-m-t', strtotime('last day of last month')),
    'custom'     => $_GET['date_to'] ?? date('Y-m-d'),
    default      => date('Y-m-d'),
};

// Use loader value if valid, otherwise fall back to calculated value
$ts_date_from = (!empty($ts_date_from) && $ts_date_from !== '') ? $ts_date_from : $default_from;
$ts_date_to   = (!empty($ts_date_to)   && $ts_date_to   !== '') ? $ts_date_to   : $default_to;

// If loader didn't run (old loader.php deployed), run queries directly
if (empty($ts_staff) && empty($ts_shifts)) {
    try {
        $ts_staff = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE role != 'user' ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $uid_filter = (int)($_GET['user_id'] ?? 0);
        $params     = [$ts_date_from, $ts_date_to];
        $extra_where = '';
        if ($uid_filter > 0) {
            $extra_where = 'AND t.user_id = ?';
            $params[]    = $uid_filter;
            $ts_filter_user = $uid_filter;
        }

        $stmt = $pdo->prepare("
            SELECT t.id, t.user_id, t.clock_in, t.clock_out,
                   t.ip_address, COALESCE(t.notes,'') AS notes,
                   u.first_name, u.last_name, u.role
            FROM   time_cards t
            JOIN   users u ON t.user_id = u.id
            WHERE  DATE(t.clock_in) >= ? AND DATE(t.clock_in) <= ?
              AND  u.role != 'user'
            {$extra_where}
            ORDER  BY t.clock_in DESC
        ");
        $stmt->execute($params);
        $ts_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ts_shifts as $shift) {
            $uid = $shift['user_id'];
            if (!isset($ts_summary[$uid])) {
                $ts_summary[$uid] = [
                    'name'    => trim($shift['first_name'] . ' ' . $shift['last_name']),
                    'role'    => $shift['role'],
                    'seconds' => 0,
                    'shifts'  => 0,
                    'active'  => false,
                ];
            }
            $ts_summary[$uid]['shifts']++;
            if ($shift['clock_out']) {
                $ts_summary[$uid]['seconds'] += strtotime($shift['clock_out']) - strtotime($shift['clock_in']);
            } else {
                $ts_summary[$uid]['active'] = true;
            }
        }
        uasort($ts_summary, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
    } catch (Throwable $e) {
        error_log('[ERP] Timesheet fallback queries failed: ' . $e->getMessage());
    }
}

// ── PRINT MODE ───────────────────────────────────────────────────────────────
// When ?print=1 is set, output a clean standalone document and exit.
// This runs inside admin.php's scope so auth + data are already loaded.
if (!empty($_GET['print'])) {
    ob_end_clean(); // discard any buffered admin layout output
    header('Content-Type: text/html; charset=utf-8');

    if (!function_exists('pf_dur')) {
        function pf_dur(int $s): string {
            if ($s <= 0) return '—';
            $h = (int)floor($s / 3600);
            $m = (int)floor(($s % 3600) / 60);
            return ($h > 0 ? "{$h}h " : '') . "{$m}m";
        }
    }

    $print_from    = htmlspecialchars(date('M d, Y', strtotime($ts_date_from)));
    $print_to      = htmlspecialchars(date('M d, Y', strtotime($ts_date_to)));
    $total_secs_p  = array_sum(array_column($ts_summary, 'seconds'));
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Timesheet — <?= $print_from ?> to <?= $print_to ?></title>
    <style>
        @page { size: letter portrait; margin: 0.5in; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Georgia, 'Times New Roman', serif; font-size: 9pt; color: #000; background: #fff; }
        .hd { display:flex; justify-content:space-between; align-items:baseline; border-bottom:2pt solid #000; padding-bottom:5pt; margin-bottom:10pt; }
        .hd h1 { font-size:14pt; font-weight:bold; }
        .hd .meta { font-size:7.5pt; color:#555; text-align:right; line-height:1.6; }
        .cards { display:flex; flex-wrap:wrap; gap:5pt; margin-bottom:10pt; }
        .card { border:0.75pt solid #aaa; border-radius:2pt; padding:4pt 7pt; min-width:75pt; }
        .card .cn { font-size:8.5pt; font-weight:bold; }
        .card .cr { font-size:6pt; text-transform:uppercase; letter-spacing:.04em; color:#666; }
        .card .ch { font-size:13pt; font-weight:900; font-family:'Courier New',monospace; line-height:1.2; }
        .card .cs { font-size:7pt; color:#555; }
        .card.tot { background:#1c1917; color:#fff; border-color:#1c1917; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .card.tot .cr { color:#aaa; }
        .lbl { font-size:6.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:.08em; color:#555; border-bottom:0.5pt solid #ccc; padding-bottom:2pt; margin-bottom:4pt; }
        table { width:100%; border-collapse:collapse; font-size:7.5pt; table-layout:fixed; }
        col.c1{width:17%}col.c2{width:19%}col.c3{width:19%}col.c4{width:10%}col.c5{width:35%}
        thead tr { background:#1c1917; color:#fff; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        th { padding:3pt 4pt; text-align:left; font-size:6.5pt; text-transform:uppercase; letter-spacing:.06em; }
        td { padding:2.5pt 4pt; border-bottom:0.4pt solid #e5e5e5; vertical-align:top; word-break:break-word; }
        .sub td { background:#f3f4f6; font-weight:bold; font-size:7pt; border-top:0.75pt solid #999; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .tot-row td { background:#1c1917; color:#fff; font-weight:bold; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        tr { page-break-inside:avoid; }
        .mono { font-family:'Courier New',monospace; }
        .act { font-size:6pt; font-weight:bold; text-transform:uppercase; background:#d1fae5; color:#065f46; padding:1pt 3pt; border-radius:2pt; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    </style>
</head>
<body>
<div class="hd">
    <div><h1>Bat Cave Road Farm — Staff Timesheet</h1></div>
    <div class="meta">
        <?= $print_from ?> — <?= $print_to ?><br>
        <?= count($ts_shifts) ?> shift<?= count($ts_shifts) !== 1 ? 's' : '' ?> &bull; <?= pf_dur($total_secs_p) ?> total<br>
        Printed <?= date('M d, Y g:i A') ?>
    </div>
</div>

<?php if (!empty($ts_summary)): ?>
<div class="cards">
    <?php foreach ($ts_summary as $info): ?>
    <div class="card">
        <div class="cn"><?= htmlspecialchars($info['name']) ?></div>
        <div class="cr"><?= htmlspecialchars($info['role']) ?></div>
        <div class="ch"><?= pf_dur($info['seconds']) ?></div>
        <div class="cs"><?= $info['shifts'] ?> shift<?= $info['shifts'] !== 1 ? 's' : '' ?><?= $info['active'] ? ' · active' : '' ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (count($ts_summary) > 1): ?>
    <div class="card tot">
        <div class="cn">All Staff</div>
        <div class="cr">Combined</div>
        <div class="ch"><?= pf_dur($total_secs_p) ?></div>
        <div class="cs"><?= count($ts_shifts) ?> shifts total</div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="lbl">Shift Log Detail</div>

<?php if (empty($ts_shifts)): ?>
    <p style="font-style:italic;color:#666;padding:8pt 0;font-size:9pt;">No shift logs found for this period.</p>
<?php else: ?>
<table>
    <colgroup><col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"></colgroup>
    <thead><tr><th>Staff Member</th><th>Clock In</th><th>Clock Out</th><th>Duration</th><th>Notes</th></tr></thead>
    <tbody>
        <?php
        $prev_uid_p = null;
        foreach ($ts_shifts as $shift):
            $in_ts_p  = strtotime($shift['clock_in']);
            $out_ts_p = $shift['clock_out'] ? strtotime($shift['clock_out']) : null;
            $dur_p    = $out_ts_p ? ($out_ts_p - $in_ts_p) : null;
            $uid_p    = $shift['user_id'];
            if ($prev_uid_p !== null && $prev_uid_p !== $uid_p && isset($ts_summary[$prev_uid_p])): ?>
            <tr class="sub"><td colspan="3" style="text-align:right">SUBTOTAL</td><td><?= pf_dur($ts_summary[$prev_uid_p]['seconds']) ?></td><td></td></tr>
            <?php endif; $prev_uid_p = $uid_p; ?>
        <tr>
            <td><strong><?= htmlspecialchars(trim($shift['first_name'].' '.$shift['last_name'])) ?></strong></td>
            <td class="mono"><?= date('M d, g:i A', $in_ts_p) ?></td>
            <td class="mono"><?= $out_ts_p ? date('M d, g:i A', $out_ts_p) : '<span class="act">Active</span>' ?></td>
            <td class="mono" style="font-weight:bold"><?= $dur_p ? pf_dur($dur_p) : '—' ?></td>
            <td style="color:#444"><?= htmlspecialchars($shift['notes']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($prev_uid_p && isset($ts_summary[$prev_uid_p])): ?>
        <tr class="sub"><td colspan="3" style="text-align:right">SUBTOTAL</td><td><?= pf_dur($ts_summary[$prev_uid_p]['seconds']) ?></td><td></td></tr>
        <?php endif; ?>
        <?php if (count($ts_summary) > 1): ?>
        <tr class="tot-row"><td colspan="3" style="text-align:right">GRAND TOTAL</td><td><?= pf_dur($total_secs_p) ?></td><td></td></tr>
        <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>
<script>window.onload = function(){ window.print(); };</script>
</body></html>
    <?php
    exit;
}

if (!function_exists('fmt_ts_duration')) {
    function fmt_ts_duration(int $secs): string {
        if ($secs <= 0) return '—';
        $h = (int)floor($secs / 3600);
        $m = (int)floor(($secs % 3600) / 60);
        return ($h > 0 ? "{$h}h " : '') . "{$m}m";
    }
}

$total_seconds = array_sum(array_column($ts_summary, 'seconds'));
$preset_labels = [
    'this_week'  => 'This Week',
    'last_week'  => 'Last Week',
    'this_month' => 'This Month',
    'last_month' => 'Last Month',
    'custom'     => 'Custom Range',
];
?>

<div class="flex justify-between items-center mb-6 print:hidden">
    <h1 class="text-2xl font-serif font-black text-stone-800">Staff Timesheet</h1>
    <button onclick="openPrint()"
        class="border font-mono font-bold uppercase text-[10px] tracking-wider px-4 py-2 rounded text-stone-600 hover:bg-stone-900 hover:text-white transition">
        🖨 Print / Export
    </button>
</div>

<!-- ── FILTERS ── -->
<form method="GET" action="/admin/admin.php" class="bg-white border rounded p-4 shadow-xs mb-6 print:hidden">
    <input type="hidden" name="view" value="timesheet">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs font-sans items-end">

        <div>
            <label class="block font-bold text-stone-500 uppercase tracking-wider mb-1 text-[10px]">Period</label>
            <select name="preset" onchange="toggleCustomDates(this.value)"
                class="w-full p-2 border bg-stone-50 rounded font-medium outline-none">
                <?php foreach ($preset_labels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $preset === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="customFrom" <?= $preset !== 'custom' ? 'style="display:none"' : '' ?>>
            <label class="block font-bold text-stone-500 uppercase tracking-wider mb-1 text-[10px]">From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($ts_date_from, ENT_QUOTES) ?>"
                class="w-full p-2 border bg-stone-50 rounded font-mono outline-none">
        </div>
        <div id="customTo" <?= $preset !== 'custom' ? 'style="display:none"' : '' ?>>
            <label class="block font-bold text-stone-500 uppercase tracking-wider mb-1 text-[10px]">To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($ts_date_to, ENT_QUOTES) ?>"
                class="w-full p-2 border bg-stone-50 rounded font-mono outline-none">
        </div>

        <div>
            <label class="block font-bold text-stone-500 uppercase tracking-wider mb-1 text-[10px]">Staff</label>
            <select name="user_id" class="w-full p-2 border bg-stone-50 rounded font-medium outline-none">
                <option value="0">All Staff</option>
                <?php foreach ($ts_staff as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $ts_filter_user === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="flex-1 bg-stone-900 text-white font-mono font-bold uppercase tracking-wider py-2 rounded text-[10px]">Apply</button>
            <a href="/admin/admin.php?view=timesheet" class="flex-1 border py-2 bg-stone-100 rounded font-bold text-stone-600 text-center uppercase tracking-wider font-mono text-[10px]">Reset</a>
        </div>
    </div>

    <div class="mt-3 text-xxs font-mono text-stone-400">
        <?= htmlspecialchars(date('M d, Y', strtotime($ts_date_from))) ?>
        — <?= htmlspecialchars(date('M d, Y', strtotime($ts_date_to))) ?>
        &bull; <?= count($ts_shifts) ?> shifts
        &bull; <span class="text-stone-700 font-bold"><?= fmt_ts_duration($total_seconds) ?> total</span>
    </div>
</form>

<?php if (empty($ts_shifts)): ?>
    <div class="bg-white border rounded p-10 text-center text-stone-400 italic text-sm shadow-xs">
        No shift logs found for this period. Try a different date range.
    </div>
<?php else: ?>

<!-- ── SUMMARY CARDS ── -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-6">
    <?php foreach ($ts_summary as $uid => $info): ?>
    <div class="bg-white border rounded p-4 shadow-xs <?= $info['active'] ? 'border-emerald-300' : '' ?>">
        <div class="font-bold text-stone-800 text-sm truncate"><?= htmlspecialchars($info['name']) ?></div>
        <div class="text-xxs text-stone-400 font-mono uppercase tracking-wider mb-2"><?= htmlspecialchars($info['role']) ?></div>
        <div class="text-2xl font-black font-mono text-stone-900"><?= fmt_ts_duration($info['seconds']) ?></div>
        <div class="text-xxs text-stone-500 mt-0.5"><?= $info['shifts'] ?> shift<?= $info['shifts'] !== 1 ? 's' : '' ?></div>
        <?php if ($info['active']): ?>
            <div class="mt-2 text-[9px] font-black uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded inline-block animate-pulse">Active now</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (count($ts_summary) > 1): ?>
    <div class="bg-stone-900 text-stone-100 border border-stone-800 rounded p-4 shadow-xs">
        <div class="font-bold text-stone-300 text-sm">All Staff</div>
        <div class="text-xxs text-stone-500 font-mono uppercase tracking-wider mb-2">Combined</div>
        <div class="text-2xl font-black font-mono text-white"><?= fmt_ts_duration($total_seconds) ?></div>
        <div class="text-xxs text-stone-400 mt-0.5"><?= count($ts_shifts) ?> shifts total</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── SHIFT LOG ── -->
<div class="bg-white border rounded shadow-xs overflow-hidden">
    <div class="px-5 py-3 border-b bg-stone-50 flex justify-between items-center">
        <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider">Shift Log</h3>
        <span class="font-mono text-xxs text-stone-400">
            <?= htmlspecialchars(date('M d, Y', strtotime($ts_date_from))) ?> — <?= htmlspecialchars(date('M d, Y', strtotime($ts_date_to))) ?>
        </span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left">
            <thead class="bg-stone-900 text-stone-100 uppercase tracking-widest text-[9px] font-bold">
                <tr>
                    <th class="p-3">Staff Member</th>
                    <th class="p-3">Clock In</th>
                    <th class="p-3">Clock Out</th>
                    <th class="p-3">Duration</th>
                    <th class="p-3 print:hidden">IP</th>
                    <th class="p-3">Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100 font-medium">
                <?php
                $prev_uid = null;
                foreach ($ts_shifts as $shift):
                    $in_ts   = strtotime($shift['clock_in']);
                    $out_ts  = $shift['clock_out'] ? strtotime($shift['clock_out']) : null;
                    $dur_sec = $out_ts ? ($out_ts - $in_ts) : null;
                    $uid     = $shift['user_id'];
                    $name    = htmlspecialchars(trim($shift['first_name'] . ' ' . $shift['last_name']));

                    if ($prev_uid !== null && $prev_uid !== $uid && isset($ts_summary[$prev_uid])): ?>
                    <tr class="bg-stone-100 border-t-2 border-stone-300 font-bold text-[10px] font-mono">
                        <td class="p-2 text-stone-500 uppercase tracking-wider" colspan="3">Subtotal</td>
                        <td class="p-2 text-stone-800 font-black"><?= fmt_ts_duration($ts_summary[$prev_uid]['seconds']) ?></td>
                        <td colspan="2" class="print:hidden"></td>
                    </tr>
                    <?php endif;
                    $prev_uid = $uid; ?>
                <tr class="hover:bg-stone-50 <?= !$out_ts ? 'bg-emerald-50/40' : '' ?>">
                    <td class="p-3 font-bold text-stone-800"><?= $name ?></td>
                    <td class="p-3 font-mono text-stone-600 whitespace-nowrap"><?= date('M d, g:i A', $in_ts) ?></td>
                    <td class="p-3 font-mono text-stone-600 whitespace-nowrap">
                        <?= $out_ts ? date('M d, g:i A', $out_ts) : '<span class="text-[9px] font-black uppercase text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded">Active</span>' ?>
                    </td>
                    <td class="p-3 font-mono font-bold <?= !$out_ts ? 'text-emerald-700' : 'text-stone-700' ?>">
                        <?= $dur_sec ? fmt_ts_duration($dur_sec) : '—' ?>
                    </td>
                    <td class="p-3 font-mono text-stone-400 text-[10px] print:hidden"><?= htmlspecialchars($shift['ip_address'] ?? '') ?></td>
                    <td class="p-3 text-stone-500 max-w-xs truncate"><?= htmlspecialchars($shift['notes']) ?></td>
                </tr>
                <?php endforeach; ?>

                <?php if ($prev_uid && isset($ts_summary[$prev_uid])): ?>
                <tr class="bg-stone-100 border-t-2 border-stone-300 font-bold text-[10px] font-mono">
                    <td class="p-2 text-stone-500 uppercase tracking-wider" colspan="3">Subtotal</td>
                    <td class="p-2 text-stone-800 font-black"><?= fmt_ts_duration($ts_summary[$prev_uid]['seconds']) ?></td>
                    <td colspan="2" class="print:hidden"></td>
                </tr>
                <?php endif; ?>
                <?php if (count($ts_summary) > 1): ?>
                <tr class="bg-stone-900 text-stone-100 font-black text-xs font-mono">
                    <td class="p-3 uppercase tracking-wider" colspan="3">Grand Total</td>
                    <td class="p-3"><?= fmt_ts_duration($total_seconds) ?></td>
                    <td colspan="2" class="print:hidden"></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function openPrint() {
    // Open the same URL with &print=1 — timesheet view renders print layout when this is set
    const params = new URLSearchParams(window.location.search);
    params.set('view',  'timesheet');
    params.set('print', '1');
    window.open('/admin/admin.php?' + params.toString(), '_blank');
}

function toggleCustomDates(val) {
    const show = val === 'custom';
    document.getElementById('customFrom').style.display = show ? '' : 'none';
    document.getElementById('customTo').style.display   = show ? '' : 'none';
}
</script>
