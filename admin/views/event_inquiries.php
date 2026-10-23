<?php
// admin/views/event_inquiries.php — private event inquiry management.
$statusFilter = trim((string)($_GET['status'] ?? 'new'));
$allowedStatuses = ['new','contacted','quoted','confirmed','declined','archived','all'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'new';

$inquiries = [];
$counts    = [];
try {
    foreach (['new','contacted','quoted','confirmed','declined','archived'] as $s) {
        $counts[$s] = (int)$pdo->query("SELECT COUNT(*) FROM event_inquiries WHERE status='$s'")->fetchColumn();
    }
    $where = $statusFilter !== 'all' ? "WHERE status = " . $pdo->quote($statusFilter) : '';
    $inquiries = $pdo->query("SELECT * FROM event_inquiries $where ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-300 text-amber-800 rounded-lg text-sm'>Run <code>event_inquiry.sql</code> in phpMyAdmin first.</div>";
    return;
}

$statusBadge = function(string $s): string {
    $map = [
        'new'       => ['bg-blue-100 text-blue-800',    '🆕 New'],
        'contacted' => ['bg-amber-100 text-amber-800',  '📞 Contacted'],
        'quoted'    => ['bg-purple-100 text-purple-800','💰 Quoted'],
        'confirmed' => ['bg-green-100 text-green-800',  '✅ Confirmed'],
        'declined'  => ['bg-red-100 text-red-700',      '❌ Declined'],
        'archived'  => ['bg-stone-100 text-stone-400',  '📁 Archived'],
    ];
    [$cls, $label] = $map[$s] ?? ['bg-stone-100 text-stone-500', $s];
    return "<span class='px-2 py-0.5 rounded text-[10px] font-bold $cls'>$label</span>";
};

$eventLabels = [
    'birthday'   => '🎂 Birthday Party',
    'field_trip' => '🎒 Field Trip',
    'buyout'     => '🌾 Farm Buyout',
    'corporate'  => '🤝 Corporate',
    'other'      => '✨ Other',
];
?>
<div class="max-w-5xl">
    <h1 class="text-2xl font-bold text-stone-800 mb-1">🎉 Private Event Inquiries</h1>
    <p class="text-sm text-stone-500 mb-6">Manage birthday parties, field trips, and private event leads.</p>

    <!-- Status filter tabs -->
    <div class="flex flex-wrap gap-2 mb-6">
        <?php
        $tabs = ['new' => 'New', 'contacted' => 'Contacted', 'quoted' => 'Quoted',
                 'confirmed' => 'Confirmed', 'declined' => 'Declined', 'archived' => 'Archived', 'all' => 'All'];
        foreach ($tabs as $val => $label):
            $cnt = $val !== 'all' ? ($counts[$val] ?? 0) : array_sum($counts);
            $active = $statusFilter === $val;
        ?>
        <a href="?view=event_inquiries&status=<?= $val ?>"
           class="px-3 py-1.5 rounded text-xs font-semibold border <?= $active ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-600 border-stone-200 hover:bg-stone-50' ?>">
            <?= $label ?> <?php if ($cnt > 0): ?><span class="ml-1 opacity-70">(<?= $cnt ?>)</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$inquiries): ?>
    <div class="bg-white border border-stone-200 rounded-lg p-8 text-center text-stone-400 text-sm">
        No <?= $statusFilter !== 'all' ? $statusFilter : '' ?> inquiries yet.
    </div>
    <?php else: foreach ($inquiries as $inq):
        $addons = $inq['addons'] ? json_decode($inq['addons'], true) : [];
        $typeLabel = $eventLabels[$inq['event_type']] ?? $inq['event_type'];
        $guests = (int)$inq['adult_count'] + (int)$inq['child_count'];
    ?>
    <div class="bg-white border border-stone-200 rounded-lg mb-4 overflow-hidden shadow-xs">
        <!-- Header row -->
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 bg-stone-50 border-b border-stone-100">
            <div>
                <span class="font-bold text-stone-800"><?= htmlspecialchars($inq['customer_name']) ?></span>
                <span class="ml-2 text-stone-400 text-xs font-mono"><?= date('M j, Y', strtotime($inq['created_at'])) ?></span>
                <span class="ml-2"><?= $statusBadge($inq['status']) ?></span>
            </div>
            <div class="text-xs text-stone-500 flex gap-4">
                <span><?= htmlspecialchars($typeLabel) ?></span>
                <span>👥 <?= $guests ?> guests</span>
                <?php if ($inq['preferred_date']): ?>
                <span>📅 <?= date('M j, Y', strtotime($inq['preferred_date'])) ?><?= $inq['date_flexible'] ? ' ±' : '' ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Details (expandable) -->
        <div class="px-5 py-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <div class="text-stone-500"><a href="mailto:<?= htmlspecialchars($inq['email']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($inq['email']) ?></a></div>
                <?php if ($inq['phone']): ?><div class="text-stone-500"><a href="tel:<?= htmlspecialchars($inq['phone']) ?>"><?= htmlspecialchars($inq['phone']) ?></a></div><?php endif; ?>
                <?php if ($inq['birthday_age']): ?><div class="text-stone-600 mt-1">🎂 Birthday child age: <?= (int)$inq['birthday_age'] ?></div><?php endif; ?>
                <?php if (!empty($addons)): ?><div class="text-stone-600 mt-1">✨ <?= htmlspecialchars(implode(', ', $addons)) ?></div><?php endif; ?>
                <?php if ($inq['heard_from']): ?><div class="text-stone-400 text-xs mt-1">Heard from: <?= htmlspecialchars($inq['heard_from']) ?></div><?php endif; ?>
            </div>
            <div>
                <?php if ($inq['special_requests']): ?>
                <div class="bg-stone-50 border border-stone-200 rounded p-3 text-stone-600 text-xs italic leading-relaxed"><?= nl2br(htmlspecialchars($inq['special_requests'])) ?></div>
                <?php endif; ?>
                <?php if ($inq['admin_notes']): ?>
                <div class="mt-2 text-xs text-stone-500"><strong>Admin note:</strong> <?= nl2br(htmlspecialchars($inq['admin_notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <div class="px-5 py-3 border-t border-stone-100 flex flex-wrap gap-2 items-center">
            <!-- Status update -->
            <form method="POST" action="/admin/admin.php?view=event_inquiries&status=<?= htmlspecialchars($statusFilter) ?>" class="flex gap-2 items-center">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <input type="hidden" name="inquiry_action" value="status">
                <input type="hidden" name="id" value="<?= (int)$inq['id'] ?>">
                <select name="new_status" class="p-1.5 text-xs border border-stone-300 rounded bg-stone-50">
                    <?php foreach (['new','contacted','quoted','confirmed','declined','archived'] as $s): ?>
                    <option value="<?= $s ?>" <?= $inq['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-3 py-1.5 rounded text-xs font-semibold bg-stone-800 text-white hover:bg-stone-700">Update status</button>
            </form>

            <!-- Add / edit note -->
            <form method="POST" action="/admin/admin.php?view=event_inquiries&status=<?= htmlspecialchars($statusFilter) ?>" class="flex gap-2 items-center flex-1 min-w-[220px]">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <input type="hidden" name="inquiry_action" value="note">
                <input type="hidden" name="id" value="<?= (int)$inq['id'] ?>">
                <input type="text" name="admin_notes" placeholder="Add a note…"
                       value="<?= htmlspecialchars($inq['admin_notes'] ?? '', ENT_QUOTES) ?>"
                       class="flex-1 p-1.5 text-xs border border-stone-300 rounded bg-stone-50">
                <button type="submit" class="px-3 py-1.5 rounded text-xs font-semibold bg-stone-100 text-stone-600 hover:bg-stone-200">Save note</button>
            </form>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>
