<?php
// admin/views/permissions_person.php — Permissions › By person (Nov 14, 2026; includes/staff_extras.php, migration 0064).
// Find a staff member, see what their role gives them (greyed), and tick extras on top. Saved by
// admin/handlers/permissions_matrix.php (same form key as the role matrix). Loaded from admin/views/permissions.php
// (Owners only).
require_once dirname(__DIR__, 2) . '/includes/staff_extras.php';
require_once dirname(__DIR__, 2) . '/includes/roles.php';

$__h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$__name = fn(array $u): string => trim((string)$u['first_name'] . ' ' . (string)$u['last_name']) ?: ('#' . (int)$u['id']);
$__base = '/admin/admin.php?view=permissions&tab=person';

if (!bcrf_extras_ready($pdo)) {
    echo '<div class="max-w-4xl mx-auto p-4 rounded border border-amber-200 bg-amber-50 text-amber-900 text-sm">Extra access for one person needs migration 0064. Run it in Settings › Database, then come back.</div>';
    return;
}

$__q = trim((string)($_GET['q'] ?? ''));
$__pid = (int)($_GET['person'] ?? 0);
$__person = $__pid ? bcrf_extras_person($pdo, $__pid) : null;
$__people = bcrf_extras_people($pdo);
$__found = bcrf_extras_search($pdo, $__q, 30);
?>
<div class="max-w-4xl mx-auto space-y-6 font-sans text-xs">

    <p class="text-sm text-stone-600">Give one person more than their role, like a Shopkeep who also writes the blog. They keep everything their role has. Owners already have everything, and Staff logins are time clock only, so neither is listed.</p>

    <!-- Who has extras -->
    <div class="bg-white border rounded p-5 shadow-xs">
        <h2 class="font-bold text-stone-700 text-sm mb-2">Has extras</h2>
        <?php if (!$__people): ?>
            <p class="text-stone-500">No one has extras yet.</p>
        <?php else: ?>
            <ul class="divide-y divide-stone-100">
            <?php foreach ($__people as $p): ?>
                <li class="py-2 flex items-center justify-between gap-3">
                    <a class="font-bold text-amber-900 underline" href="<?= $__h($__base . '&person=' . (int)$p['id']) ?>"><?= $__h($__name($p)) ?></a>
                    <span class="text-stone-500"><?= $__h(bcrf_role_label($p['role'])) ?> · <?= (int)$p['n'] ?> extra<?= (int)$p['n'] === 1 ? '' : 's' ?><?= ($p['account_status'] ?? '') === 'disabled' ? ' · login off' : '' ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Find a person -->
    <div class="bg-white border rounded p-5 shadow-xs">
        <form method="GET" action="/admin/admin.php" class="flex flex-wrap gap-2 items-end">
            <input type="hidden" name="view" value="permissions">
            <input type="hidden" name="tab" value="person">
            <label class="flex-1 min-w-[12rem]">
                <span class="block font-bold text-stone-700 text-sm mb-2">Find a staff member</span>
                <input type="search" name="q" value="<?= $__h($__q) ?>" placeholder="Name or email" autocomplete="off"
                    class="w-full p-2.5 border rounded bg-stone-50 outline-none text-sm text-stone-800">
            </label>
            <button type="submit" class="min-h-[44px] px-4 py-2 rounded-[10px] border border-[#3b2010] bg-[#3b2010] text-white text-sm font-semibold hover:bg-[#2a170b] transition">Search</button>
        </form>
        <?php if (!$__found): ?>
            <p class="mt-3 text-stone-500"><?= $__q !== '' ? 'No staff member matches “' . $__h($__q) . '”.' : 'No staff members yet.' ?></p>
        <?php else: ?>
            <ul class="mt-3 divide-y divide-stone-100">
            <?php foreach ($__found as $u): $sel = $__person && (int)$__person['id'] === (int)$u['id']; ?>
                <li class="py-2 flex items-center justify-between gap-3 <?= $sel ? 'bg-amber-50 -mx-2 px-2 rounded' : '' ?>">
                    <a class="font-bold <?= $sel ? 'text-stone-900' : 'text-amber-900 underline' ?>" href="<?= $__h($__base . '&person=' . (int)$u['id'] . ($__q !== '' ? '&q=' . rawurlencode($__q) : '')) ?>"><?= $__h($__name($u)) ?></a>
                    <span class="text-stone-500 truncate"><?= $__h(bcrf_role_label($u['role'])) ?><?= ($u['account_status'] ?? '') === 'disabled' ? ' · login off' : '' ?><?= $u['email'] ? ' · ' . $__h($u['email']) : '' ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($__pid && !$__person): ?>
        <div class="p-4 rounded border border-amber-200 bg-amber-50 text-amber-900 text-sm">That person can’t have extras (Owners already have everything; Staff logins are time clock only).</div>
    <?php endif; ?>

    <?php if ($__person):
        $__role = (string)$__person['role'];
        $__x = bcrf_extras_load($pdo, (int)$__person['id']);
    ?>
    <form method="POST" action="/admin/admin.php?view=permissions&amp;tab=person" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= $__h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="save_permissions_matrix" value="1">
        <input type="hidden" name="extras_user_id" value="<?= (int)$__person['id'] ?>">

        <div class="bg-white border rounded p-5 shadow-xs">
            <h2 class="font-bold text-stone-800 text-base"><?= $__h($__name($__person)) ?> <span class="text-stone-500 font-normal">· <?= $__h(bcrf_role_label($__role)) ?></span></h2>
            <p class="mt-1 text-stone-600">Greyed ticks come from the <?= $__h(bcrf_role_label($__role)) ?> role (change those on the By role tab). Tick anything extra for this one person.
            Blog posts, recipes and animal pages given here as extras need review: they can save drafts and send them to you, but only an Owner or Manager can publish.</p>
        </div>

        <?php foreach (bcrf_permission_sections() as $sec): ?>
        <div class="bg-white border rounded shadow-xs overflow-hidden">
            <div class="bg-stone-900 text-stone-100 px-5 py-3 flex items-center gap-2">
                <span class="text-base"><?= $sec['icon'] ?></span>
                <h2 class="font-bold uppercase tracking-widest text-[10px]"><?= $__h($sec['label']) ?></h2>
            </div>
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-stone-50 border-b text-stone-400 uppercase tracking-widest text-[9px] font-bold">
                        <th class="px-4 py-2">Module / Table</th>
                        <th class="px-4 py-2 text-center w-24">Access</th>
                        <th class="px-4 py-2 text-center w-24">Edit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                <?php foreach ($sec['items'] as [$itype, $ikey, $iicon, $ilabel]):
                    $k = $__h($ikey);
                    if ($itype === 'admin_only') {
                        $rv = $re = false;
                    } elseif ($itype === 'view') {
                        $rv = hasViewAccess($__role, $ikey, false);
                        $xv = !empty($__x['view'][$ikey]);
                    } else {
                        $rv = hasTableAccess($__role, $ikey, 'view', false);
                        $re = hasTableAccess($__role, $ikey, 'edit', false);
                        $xv = !empty($__x['table'][$ikey]['view']);
                        $xe = !empty($__x['table'][$ikey]['edit']);
                    }
                    $review = $itype === 'table' && in_array($ikey, bcrf_review_tables(), true) && !$re;
                ?>
                <tr class="<?= $itype === 'admin_only' ? 'opacity-50 bg-stone-50' : 'hover:bg-stone-50' ?>">
                    <td class="px-4 py-2.5 font-bold text-stone-700">
                        <span class="mr-1"><?= $iicon ?></span><?= $__h($ilabel) ?>
                        <?php if ($itype === 'admin_only'): ?><span class="ml-1 text-[9px] font-mono text-stone-400">owner only</span><?php endif; ?>
                        <?php if ($review): ?><span class="ml-1 text-[9px] font-mono text-amber-700">edit as extra = drafts need review</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 text-center">
                        <?php if ($itype === 'admin_only'): ?>
                            <span class="text-stone-300">🔒</span>
                        <?php elseif ($rv): ?>
                            <input type="checkbox" checked disabled class="w-4 h-4 opacity-40" title="From the role" aria-label="<?= $__h($ilabel) ?>: from the role">
                        <?php elseif ($itype === 'view'): ?>
                            <input type="checkbox" name="view_permissions_block[<?= $k ?>]" value="1" class="w-4 h-4" <?= $xv ? 'checked' : '' ?> aria-label="<?= $__h($ilabel) ?>: extra access">
                        <?php else: ?>
                            <input type="checkbox" name="perm_node[<?= $k ?>][view]" value="1" class="w-4 h-4" <?= $xv ? 'checked' : '' ?> aria-label="<?= $__h($ilabel) ?>: extra access">
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 text-center">
                        <?php if ($itype !== 'table'): ?>
                            <span class="text-stone-200">—</span>
                        <?php elseif ($re): ?>
                            <input type="checkbox" checked disabled class="w-4 h-4 opacity-40" title="From the role" aria-label="<?= $__h($ilabel) ?> edit: from the role">
                        <?php else: ?>
                            <input type="checkbox" name="perm_node[<?= $k ?>][edit]" value="1" class="w-4 h-4" <?= $xe ? 'checked' : '' ?> aria-label="<?= $__h($ilabel) ?> edit: extra">
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <div class="flex flex-wrap justify-end gap-2">
            <?php if (!empty($__x['view']) || !empty($__x['table'])): ?>
            <button type="submit" name="extras_clear" value="1"
                data-confirm="Take away every extra from <?= $__h($__name($__person)) ?>? They keep what their role gives them." data-confirm-label="Remove extras"
                class="border font-mono font-bold uppercase tracking-wider py-2.5 px-5 rounded text-red-700 border-red-200 hover:bg-red-50 transition">
                Remove all extras
            </button>
            <?php endif; ?>
            <button type="submit"
                class="bg-stone-950 text-white font-mono font-bold uppercase tracking-wider py-2.5 px-8 rounded hover:bg-stone-800 transition shadow-xs">
                Save extras →
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>
