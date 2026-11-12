<?php
// admin/views/event_inquiries.php — private event inquiry management.
require_once __DIR__ . '/layout/ui.php';   // shared chips, buttons, empty states (02 Navigation & UX)
$csrf = $_SESSION['csrf_token'] ?? '';      // was never set here, so status/note saves posted an empty token and were refused
$statusFilter = trim((string)($_GET['status'] ?? 'new'));
$allowedStatuses = ['new','contacted','quoted','confirmed','declined','archived','all'];
try { require_once __DIR__ . '/../../includes/event_tasks.php'; } catch (Throwable $e) { /* checklist link just won't show */ }
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

$statusBadge = fn(string $s): string => ui_chip($s);

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
    <div class="ui-strip flex flex-wrap gap-2 mb-6">
        <?php
        $tabs = ['new' => 'New', 'contacted' => 'Contacted', 'quoted' => 'Quoted',
                 'confirmed' => 'Confirmed', 'declined' => 'Declined', 'archived' => 'Archived', 'all' => 'All'];
        foreach ($tabs as $val => $label):
            $cnt = $val !== 'all' ? ($counts[$val] ?? 0) : array_sum($counts);
            $active = $statusFilter === $val;
        ?>
        <a href="?view=event_inquiries&status=<?= $val ?>"
           class="inline-flex items-center min-h-10 md:min-h-8 px-3 rounded-lg text-xs font-semibold border whitespace-nowrap <?= $active ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-600 border-stone-200 hover:bg-stone-50' ?>">
            <?= $label ?> <?php if ($cnt > 0): ?><span class="ml-1 opacity-70">(<?= $cnt ?>)</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$inquiries): ?>
    <?= $statusFilter === 'new'
        ? ui_empty('🎉', 'No new inquiries', 'Birthday parties, field trips and other private event requests from the website land here. You\'re caught up.')
        : ui_empty('📂', 'Nothing here', 'No ' . ($statusFilter !== 'all' ? $statusFilter . ' ' : '') . 'inquiries.', ['See new inquiries', '?view=event_inquiries&status=new']) ?>
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
            <div class="text-xs text-stone-500 flex flex-wrap gap-x-4 gap-y-1">
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
                <div class="flex flex-wrap gap-2 mt-3">
                    <?php if ($inq['phone']): ?><a href="tel:<?= htmlspecialchars($inq['phone'], ENT_QUOTES) ?>" class="<?= ui_btn('secondary') ?>">📞 Call</a><?php endif; ?>
                    <a href="mailto:<?= htmlspecialchars($inq['email'], ENT_QUOTES) ?>" class="<?= ui_btn('secondary') ?>">✉️ Email</a>
                </div>
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
                <label class="sr-only" for="ev_date_<?= (int)$inq['id'] ?>">Party date</label>
                <input type="date" id="ev_date_<?= (int)$inq['id'] ?>" name="event_date" value="<?= htmlspecialchars((string)($inq['preferred_date'] ?? ''), ENT_QUOTES) ?>" title="Party date (checklist tasks are dated from it)"
                       class="min-h-10 md:min-h-8 px-2 text-xs border border-stone-300 rounded-lg bg-stone-50">
                <select name="new_status" class="min-h-10 md:min-h-8 px-2 text-xs border border-stone-300 rounded-lg bg-stone-50">
                    <?php foreach (['new','contacted','quoted','confirmed','declined','archived'] as $s): ?>
                    <option value="<?= $s ?>" <?= $inq['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="<?= ui_btn('primary') ?>">Update status</button>
            </form>
            <?php if ($inq['status'] === 'confirmed' && function_exists('bcrf_event_task_counts')): $ck = bcrf_event_task_counts($pdo, 'event_inquiry', (int)$inq['id']); if ($ck['total']): ?>
            <a href="/admin/tasks.php?tab=all&amp;status=all&amp;related=event_inquiry:<?= (int)$inq['id'] ?>" class="<?= ui_btn('secondary') ?>">✅ Checklist <?= $ck['done'] ?>/<?= $ck['total'] ?></a>
            <?php endif; endif; ?>

            <!-- Add / edit note -->
            <form method="POST" action="/admin/admin.php?view=event_inquiries&status=<?= htmlspecialchars($statusFilter) ?>" class="flex gap-2 items-center flex-1 min-w-[220px]">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <input type="hidden" name="inquiry_action" value="note">
                <input type="hidden" name="id" value="<?= (int)$inq['id'] ?>">
                <input type="text" name="admin_notes" placeholder="Add a note…"
                       value="<?= htmlspecialchars($inq['admin_notes'] ?? '', ENT_QUOTES) ?>"
                       class="flex-1 min-w-0 min-h-10 md:min-h-8 px-2 text-xs border border-stone-300 rounded-lg bg-stone-50">
                <button type="submit" class="<?= ui_btn('secondary') ?>">Save note</button>
            </form>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>
