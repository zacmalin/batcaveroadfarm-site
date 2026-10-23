<?php
$csrf = $_SESSION['csrf_token'] ?? '';   // used by the hidden csrf_token fields below
// admin/views/passes.php — annual pass management.
$statusFilter = trim((string)($_GET['pstatus'] ?? 'active'));
$search       = trim((string)($_GET['search']  ?? ''));
$allowed      = ['pending_payment','active','expired','cancelled','suspended','all'];
if (!in_array($statusFilter, $allowed, true)) $statusFilter = 'active';

$passes = []; $counts = []; $passTypes = []; $revenue = 0;
try {
    foreach (['pending_payment','active','expired','cancelled','suspended'] as $s) {
        $counts[$s] = (int)$pdo->query("SELECT COUNT(*) FROM passes WHERE status='$s'")->fetchColumn();
    }
    $revenue = (float)$pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM passes WHERE payment_status='paid'")->fetchColumn();
    $passTypes = $pdo->query("SELECT * FROM pass_types ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $typeMap   = array_column($passTypes, null, 'id');

    $where = $statusFilter !== 'all' ? "p.status = " . $pdo->quote($statusFilter) : '1=1';
    if ($search !== '') {
        $like = $pdo->quote('%' . $search . '%');
        $where .= " AND (p.holder_name LIKE $like OR p.email LIKE $like)";
    }
    $passes = $pdo->query("
        SELECT p.*, t.label, t.max_guests,
               (SELECT COUNT(*) FROM pass_visits pv WHERE pv.pass_id = p.id) AS visit_count,
               (SELECT MAX(pv2.visited_at) FROM pass_visits pv2 WHERE pv2.pass_id = p.id) AS last_visit
        FROM passes p JOIN pass_types t ON t.id = p.pass_type_id
        WHERE $where ORDER BY p.created_at DESC LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-300 text-amber-800 rounded-lg text-sm'>Run <code>passes_setup.sql</code> in phpMyAdmin first.</div>";
    return;
}

$sBadge = fn(string $s) => match($s) {
    'active'          => "<span class='px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-800'>✓ Active</span>",
    'pending_payment' => "<span class='px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800'>⏳ Pending payment</span>",
    'expired'         => "<span class='px-2 py-0.5 rounded text-[10px] font-bold bg-stone-100 text-stone-500'>Expired</span>",
    'suspended'       => "<span class='px-2 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-700'>Suspended</span>",
    'cancelled'       => "<span class='px-2 py-0.5 rounded text-[10px] font-bold bg-stone-100 text-stone-400 line-through'>Cancelled</span>",
    default           => "<span class='px-2 py-0.5 rounded text-[10px] bg-stone-100 text-stone-500'>$s</span>",
};
?>
<div class="max-w-5xl">
<h1 class="text-2xl font-bold text-stone-800 mb-1">🎟 Annual Passes</h1>

<!-- Summary cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="bg-white border rounded-lg p-3 shadow-xs">
        <div class="text-[9px] font-bold uppercase tracking-widest text-stone-400 font-mono">Active passes</div>
        <div class="text-2xl font-black text-green-700 font-mono mt-1"><?= $counts['active'] ?></div>
    </div>
    <div class="bg-white border rounded-lg p-3 shadow-xs">
        <div class="text-[9px] font-bold uppercase tracking-widest text-stone-400 font-mono">Pass revenue</div>
        <div class="text-2xl font-black text-emerald-700 font-mono mt-1">$<?= number_format($revenue, 0) ?></div>
    </div>
    <div class="bg-white border rounded-lg p-3 shadow-xs">
        <div class="text-[9px] font-bold uppercase tracking-widest text-stone-400 font-mono">Expiring this month</div>
        <?php
            $expiring = 0;
            try { $expiring = (int)$pdo->query("SELECT COUNT(*) FROM passes WHERE status='active' AND valid_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn(); } catch (Throwable $e) {}
        ?>
        <div class="text-2xl font-black <?= $expiring > 0 ? 'text-amber-600' : 'text-stone-400' ?> font-mono mt-1"><?= $expiring ?></div>
    </div>
    <div class="bg-white border rounded-lg p-3 shadow-xs">
        <div class="text-[9px] font-bold uppercase tracking-widest text-stone-400 font-mono">Pending payment</div>
        <div class="text-2xl font-black <?= $counts['pending_payment'] > 0 ? 'text-amber-600' : 'text-stone-400' ?> font-mono mt-1"><?= $counts['pending_payment'] ?></div>
    </div>
</div>

<!-- Issue pass manually -->
<details class="bg-white border border-stone-200 rounded-lg mb-6 shadow-xs">
    <summary class="px-5 py-3 cursor-pointer font-semibold text-stone-700 text-sm select-none">+ Issue pass manually (in-person / cash sale)</summary>
    <form method="POST" action="/admin/admin.php?view=passes" class="p-5 grid grid-cols-2 md:grid-cols-3 gap-4">
        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="pass_action"  value="issue">
        <div>
            <label class="block text-xs font-semibold text-stone-500 mb-1">Pass type *</label>
            <select name="pt_id" required class="w-full p-2 border rounded bg-stone-50 text-sm">
                <option value="">— Select —</option>
                <?php foreach ($passTypes as $pt): ?>
                <option value="<?= (int)$pt['id'] ?>"><?= htmlspecialchars($pt['label']) ?> — $<?= number_format((float)$pt['price'],0) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-500 mb-1">Holder name *</label>
            <input type="text" name="holder_name" required class="w-full p-2 border rounded bg-stone-50 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-500 mb-1">Email *</label>
            <input type="email" name="email" required class="w-full p-2 border rounded bg-stone-50 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-500 mb-1">Phone</label>
            <input type="tel" name="phone" class="w-full p-2 border rounded bg-stone-50 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-500 mb-1">Payment method</label>
            <select name="payment_provider" class="w-full p-2 border rounded bg-stone-50 text-sm">
                <option value="cash">Cash</option>
                <option value="comp">Complimentary (free)</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-500 mb-1">Amount collected ($)</label>
            <input type="number" name="amount_paid" min="0" step="0.01" class="w-full p-2 border rounded bg-stone-50 text-sm" placeholder="0.00">
        </div>
        <div class="col-span-2 md:col-span-3">
            <button type="submit" class="px-5 py-2 bg-stone-800 text-white rounded text-sm font-semibold hover:bg-stone-700">Issue Pass & Send Email</button>
        </div>
    </form>
</details>

<!-- Filter + search -->
<div class="flex flex-wrap gap-2 items-center mb-5">
    <?php foreach (['active'=>'Active','pending_payment'=>'Pending','expired'=>'Expired','suspended'=>'Suspended','cancelled'=>'Cancelled','all'=>'All'] as $v=>$lbl):
        $active = $statusFilter === $v;
        $cnt = $v !== 'all' ? ($counts[$v] ?? 0) : array_sum($counts);
    ?>
    <a href="?view=passes&pstatus=<?= $v ?>"
       class="px-3 py-1.5 rounded text-xs font-semibold border <?= $active ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-600 border-stone-200 hover:bg-stone-50' ?>">
        <?= $lbl ?> <?php if ($cnt > 0): ?><span class="opacity-70">(<?= $cnt ?>)</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
    <form method="GET" class="ml-auto flex gap-2">
        <input type="hidden" name="view" value="passes">
        <input type="hidden" name="pstatus" value="<?= htmlspecialchars($statusFilter) ?>">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
               placeholder="Search name or email…"
               class="p-2 text-xs border border-stone-300 rounded bg-stone-50 w-48">
        <button class="px-3 py-2 bg-stone-100 text-stone-700 rounded text-xs hover:bg-stone-200">Search</button>
    </form>
</div>

<!-- Pass list -->
<?php if (!$passes): ?>
<div class="bg-white border rounded-lg p-8 text-center text-stone-400 text-sm">No passes found.</div>
<?php else: foreach ($passes as $p):
    $verifyUrl = 'https://batcaveroadfarm.com/passes/verify.php?token=' . rawurlencode($p['token']);
    $isExpiring = $p['status'] === 'active' && $p['valid_until'] && $p['valid_until'] <= date('Y-m-d', strtotime('+30 days'));
?>
<div class="bg-white border <?= $isExpiring ? 'border-amber-300' : 'border-stone-200' ?> rounded-lg mb-3 shadow-xs">
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 bg-stone-50 border-b border-stone-100">
        <div>
            <span class="font-bold text-stone-800"><?= htmlspecialchars($p['holder_name']) ?></span>
            <?= $sBadge($p['status']) ?>
            <?php if ($isExpiring): ?><span class="ml-2 text-[10px] font-bold text-amber-700">⚠ Expiring soon</span><?php endif; ?>
        </div>
        <div class="text-xs text-stone-500 flex flex-wrap gap-3">
            <span><?= htmlspecialchars($p['label']) ?></span>
            <?php if ($p['valid_until']): ?><span>Expires <?= date('M j, Y', strtotime($p['valid_until'])) ?></span><?php endif; ?>
            <span>👤 <?= (int)$p['visit_count'] ?> visit<?= $p['visit_count'] != 1 ? 's' : '' ?></span>
            <?php if ($p['last_visit']): ?><span>Last: <?= date('M j', strtotime($p['last_visit'])) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="px-5 py-3 flex flex-wrap gap-2 items-center text-xs text-stone-500">
        <a href="mailto:<?= htmlspecialchars($p['email']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($p['email']) ?></a>
        <?php if ($p['phone']): ?><span>·</span><a href="tel:<?= htmlspecialchars($p['phone']) ?>"><?= htmlspecialchars($p['phone']) ?></a><?php endif; ?>
        <?php if ($p['amount_paid']): ?><span>· Paid: $<?= number_format((float)$p['amount_paid'],2) ?></span><?php endif; ?>
        <a href="<?= htmlspecialchars($verifyUrl) ?>" target="_blank" class="ml-auto text-stone-400 hover:text-stone-600">🔍 View pass</a>
    </div>
    <!-- Status actions -->
    <div class="px-5 py-2 border-t border-stone-100 flex flex-wrap gap-2">
        <form method="POST" action="/admin/admin.php?view=passes&pstatus=<?= $statusFilter ?>" class="flex gap-2 items-center">
            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="pass_action" value="status">
            <input type="hidden" name="pass_id"     value="<?= (int)$p['id'] ?>">
            <select name="new_status" class="p-1.5 text-xs border border-stone-300 rounded bg-stone-50">
                <?php foreach (['active','suspended','cancelled','expired'] as $s): ?>
                <option value="<?= $s ?>" <?= $p['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-3 py-1.5 text-xs rounded font-semibold bg-stone-800 text-white hover:bg-stone-700">Update</button>
        </form>
        <?php if ($p['status'] === 'active'): ?>
        <form method="POST" action="/admin/admin.php?view=passes&pstatus=<?= $statusFilter ?>">
            <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="pass_action" value="extend">
            <input type="hidden" name="pass_id"     value="<?= (int)$p['id'] ?>">
            <button type="submit" data-confirm="Extend this pass by 1 year?" data-confirm-label="Extend"
                    class="px-3 py-1.5 text-xs rounded font-semibold bg-green-50 text-green-700 hover:bg-green-100 border border-green-200">+1 Year</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>
</div>

<?php
// ── PASS TYPES MANAGEMENT ────────────────────────────────────────────────────
$editId   = (int)($_GET['edit_type_id'] ?? 0);
$creating = !empty($_GET['create_type']);
$editType = null;
if ($editId > 0) {
    try { $st=$pdo->prepare("SELECT * FROM pass_types WHERE id=?"); $st->execute([$editId]); $editType=$st->fetch(PDO::FETCH_ASSOC)?:null; } catch(Throwable $e){}
}
$typesSoldMap = [];
try {
    $rows = $pdo->query("SELECT pass_type_id, COUNT(*) AS cnt FROM passes WHERE status != 'cancelled' GROUP BY pass_type_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $typesSoldMap[$r['pass_type_id']] = $r['cnt'];
} catch(Throwable $e){}
?>

<div id="pass-types" class="max-w-5xl mt-10">
<h2 class="text-xl font-bold text-stone-800 mb-4">🎟 Pass Types</h2>

<!-- Existing types list -->
<div class="space-y-3 mb-6">
<?php foreach ($passTypes as $pt):
    $sold = (int)($typesSoldMap[$pt['id']] ?? 0);
    $perks = json_decode($pt['perks'] ?? '[]', true) ?: [];
    $isEditing = ($editId === (int)$pt['id']);
?>
<div class="bg-white border <?= $isEditing ? 'border-amber-400 ring-2 ring-amber-200' : 'border-stone-200' ?> rounded-lg shadow-xs overflow-hidden">
    <div class="flex items-center justify-between gap-3 px-5 py-3 bg-stone-50 border-b border-stone-100">
        <div class="flex items-center gap-3">
            <span class="font-bold text-stone-800"><?= htmlspecialchars($pt['label']) ?></span>
            <?php if (!$pt['is_active']): ?><span class="text-[10px] bg-stone-200 text-stone-500 px-2 py-0.5 rounded font-semibold">Inactive</span><?php endif; ?>
            <?php if ($pt['is_founding']): ?><span class="text-[10px] bg-amber-100 text-amber-800 px-2 py-0.5 rounded font-semibold">⭐ Featured</span><?php endif; ?>
            <?php if ($pt['founding_qty_limit']): ?><span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-semibold">Limited: <?= (int)$pt['founding_qty_limit'] ?></span><?php endif; ?>
        </div>
        <div class="flex items-center gap-4 text-xs text-stone-500">
            <span class="font-mono font-bold text-stone-700">$<?= number_format((float)$pt['price'],2) ?></span>
            <span><?= (int)($pt['duration_months'] ?? 12) ?> mo</span>
            <span>👥 <?= (int)$pt['max_guests'] ?> guests</span>
            <span class="text-stone-400">Sold: <?= $sold ?></span>
            <a href="?view=passes&edit_type_id=<?= (int)$pt['id'] ?>#pass-types"
               class="px-3 py-1 rounded text-xs font-semibold <?= $isEditing ? 'bg-amber-500 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' ?>">
               <?= $isEditing ? 'Editing…' : 'Edit' ?></a>
        </div>
    </div>
    <?php if (!empty($perks)): ?>
    <div class="px-5 py-2 text-xs text-stone-500">
        <?= implode(' · ', array_map('htmlspecialchars', $perks)) ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- Create / Edit form -->
<?php if ($editType || $creating):
    $f = $editType ?? ['label'=>'','tagline'=>'','price'=>'','duration_months'=>12,'max_guests'=>1,'perks'=>'[]','is_founding'=>0,'founding_qty_limit'=>null,'sort_order'=>10,'is_active'=>1,'type_key'=>''];
    $fPerks = json_decode($f['perks'] ?? '[]', true) ?: [''];
    $formTitle = $editType ? 'Edit: ' . htmlspecialchars($f['label']) : 'New pass type';
?>
<div class="bg-white border-2 border-amber-400 rounded-xl shadow-sm p-6">
    <h3 class="font-bold text-stone-800 text-base mb-5"><?= $formTitle ?></h3>
    <form method="POST" action="/admin/admin.php?view=passes#pass-types">
        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="pass_action"  value="save_type">
        <input type="hidden" name="pt_id"        value="<?= (int)($f['id'] ?? 0) ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
            <!-- Title -->
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-stone-500 mb-1">Pass title *</label>
                <input type="text" name="pt[label]" required
                       value="<?= htmlspecialchars($f['label'], ENT_QUOTES) ?>"
                       placeholder="e.g. Family Pass"
                       class="w-full p-2.5 border border-stone-300 rounded-lg bg-stone-50 text-sm">
            </div>
            <!-- Tagline -->
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-stone-500 mb-1">Tagline <span class="font-normal opacity-60">(shown under title on pass cards)</span></label>
                <input type="text" name="pt[tagline]"
                       value="<?= htmlspecialchars($f['tagline'] ?? '', ENT_QUOTES) ?>"
                       placeholder="e.g. Bring the whole family — unlimited visits all year."
                       class="w-full p-2.5 border border-stone-300 rounded-lg bg-stone-50 text-sm">
            </div>
            <!-- Price -->
            <div>
                <label class="block text-xs font-semibold text-stone-500 mb-1">Price ($) *</label>
                <input type="number" name="pt[price]" required min="0" step="0.01"
                       value="<?= number_format((float)$f['price'], 2) ?>"
                       class="w-full p-2.5 border border-stone-300 rounded-lg bg-stone-50 text-sm font-mono">
            </div>
            <!-- Duration -->
            <div>
                <label class="block text-xs font-semibold text-stone-500 mb-1">Duration (months) <span class="font-normal opacity-60">12 = 1 year</span></label>
                <input type="number" name="pt[duration_months]" required min="1" max="60"
                       value="<?= (int)($f['duration_months'] ?? 12) ?>"
                       class="w-full p-2.5 border border-stone-300 rounded-lg bg-stone-50 text-sm font-mono">
            </div>
            <!-- Max guests -->
            <div>
                <label class="block text-xs font-semibold text-stone-500 mb-1">Max guests per visit *</label>
                <input type="number" name="pt[max_guests]" required min="1" max="20"
                       value="<?= (int)$f['max_guests'] ?>"
                       class="w-full p-2.5 border border-stone-300 rounded-lg bg-stone-50 text-sm font-mono">
            </div>
            <!-- Sort order -->
            <div>
                <label class="block text-xs font-semibold text-stone-500 mb-1">Sort order <span class="font-normal opacity-60">lower = shown first</span></label>
                <input type="number" name="pt[sort_order]" min="0" max="99"
                       value="<?= (int)$f['sort_order'] ?>"
                       class="w-full p-2.5 border border-stone-300 rounded-lg bg-stone-50 text-sm font-mono">
            </div>
        </div>

        <!-- Benefits (dynamic) -->
        <div class="mb-5">
            <label class="block text-xs font-semibold text-stone-500 mb-2">Benefits <span class="font-normal opacity-60">(shown as bullet points on the pass card)</span></label>
            <div id="perks-list" class="space-y-2 mb-3">
                <?php foreach ($fPerks as $perk): ?>
                <div class="flex gap-2 items-center perk-row">
                    <input type="text" name="pt[perks][]"
                           value="<?= htmlspecialchars($perk, ENT_QUOTES) ?>"
                           placeholder="e.g. Unlimited visits for 12 months"
                           class="flex-1 p-2 border border-stone-300 rounded bg-stone-50 text-sm">
                    <button type="button" onclick="this.closest('.perk-row').remove()"
                            class="text-stone-400 hover:text-red-500 text-lg leading-none px-1" title="Remove">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="addPerk()"
                    class="px-3 py-1.5 text-xs rounded border border-stone-300 text-stone-600 hover:bg-stone-50 font-semibold">
                + Add benefit
            </button>
        </div>

        <!-- Checkboxes -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <!-- Featured -->
            <label class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-3 cursor-pointer">
                <input type="checkbox" name="pt[is_founding]" value="1"
                       <?= !empty($f['is_founding']) ? 'checked' : '' ?>
                       class="mt-0.5 w-4 h-4 accent-amber-600">
                <div>
                    <div class="font-semibold text-sm text-stone-700">⭐ Featured</div>
                    <div class="text-xs text-stone-500 mt-0.5">Dark card styling + "Limited Edition" badge</div>
                </div>
            </label>
            <!-- Limited qty -->
            <label class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-3 cursor-pointer">
                <input type="checkbox" name="pt[has_limit]" value="1"
                       id="has-limit-cb"
                       <?= !empty($f['founding_qty_limit']) ? 'checked' : '' ?>
                       onchange="document.getElementById('limit-qty').style.display=this.checked?'':'none'"
                       class="mt-0.5 w-4 h-4 accent-blue-600">
                <div class="flex-1">
                    <div class="font-semibold text-sm text-stone-700">Limited quantity</div>
                    <div id="limit-qty" class="mt-1.5" style="<?= empty($f['founding_qty_limit']) ? 'display:none' : '' ?>">
                        <input type="number" name="pt[founding_qty_limit]" min="1"
                               value="<?= (int)($f['founding_qty_limit'] ?? 50) ?>"
                               placeholder="e.g. 50"
                               class="w-full p-1.5 border border-blue-300 rounded bg-white text-sm font-mono"
                               onclick="event.stopPropagation()">
                        <div class="text-xs text-stone-400 mt-1">Total available</div>
                    </div>
                </div>
            </label>
            <!-- Active -->
            <label class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-3 cursor-pointer">
                <input type="checkbox" name="pt[is_active]" value="1"
                       <?= !empty($f['is_active']) ? 'checked' : '' ?>
                       class="mt-0.5 w-4 h-4 accent-green-600">
                <div>
                    <div class="font-semibold text-sm text-stone-700">Active</div>
                    <div class="text-xs text-stone-500 mt-0.5">Show on the public passes page</div>
                </div>
            </label>
        </div>

        <?php if ($editType): ?>
        <div class="mb-4 text-xs text-stone-400 font-mono">
            URL key: <strong><?= htmlspecialchars($f['type_key']) ?></strong> (auto-generated on create, fixed after)
        </div>
        <?php endif; ?>

        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2.5 bg-stone-800 text-white rounded-lg text-sm font-semibold hover:bg-stone-700">
                <?= $editType ? 'Save changes' : 'Create pass type' ?>
            </button>
            <a href="?view=passes#pass-types" class="px-5 py-2.5 bg-stone-100 text-stone-600 rounded-lg text-sm font-semibold hover:bg-stone-200">Cancel</a>
        </div>
    </form>
</div>

<script>
function addPerk() {
    var list = document.getElementById('perks-list');
    var row  = document.createElement('div');
    row.className = 'flex gap-2 items-center perk-row';
    row.innerHTML = '<input type="text" name="pt[perks][]" placeholder="e.g. 10% off farmstand purchases" class="flex-1 p-2 border border-stone-300 rounded bg-stone-50 text-sm">'
                  + '<button type="button" onclick="this.closest(\'.perk-row\').remove()" class="text-stone-400 hover:text-red-500 text-lg leading-none px-1" title="Remove">×</button>';
    list.appendChild(row);
    row.querySelector('input').focus();
}
</script>

<?php else: ?>
<a href="?view=passes&create_type=1#pass-types"
   class="inline-block px-5 py-2.5 bg-stone-800 text-white rounded-lg text-sm font-semibold hover:bg-stone-700">
   + Create new pass type
</a>
<?php endif; ?>
</div>
