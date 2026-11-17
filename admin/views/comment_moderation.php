<?php
$total_unmoderated = count($mod_comments);
?>

<div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-stone-800">Comment Moderation</h1>
        <p class="text-xs text-stone-400 mt-0.5">
            <?= $mod_pending_count ?> pending &bull;
            <?= $mod_flagged_count ?> flagged &bull;
            <?= $total_unmoderated ?> total to review
        </p>
    </div>
    <?php if ($total_unmoderated > 0): ?>
    <div class="flex flex-wrap gap-2 text-xs font-bold uppercase tracking-wider">
        <button type="button" data-mod-select-all  class="border border-stone-200 px-3 py-2 rounded bg-stone-50 hover:bg-stone-200" style="min-height:44px;">Select All</button>
        <button type="button" data-mod-select-none class="border border-stone-200 px-3 py-2 rounded bg-stone-50 hover:bg-stone-200" style="min-height:44px;">Select None</button>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($mod_comments)): ?>
    <div class="bg-white border border-stone-200 rounded p-12 text-center text-stone-400 italic shadow-sm">
        ✅ No comments awaiting review. All caught up!
    </div>
<?php else: ?>

<form method="POST" action="/admin/admin.php?view=comment_moderation" id="modForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
    <input type="hidden" name="moderate_comments" value="1">
    <input type="hidden" name="mod_action" id="modAction" value="">

    <!-- Action bar — sticky at top -->
    <div class="sticky top-0 z-10 bg-stone-100/95 backdrop-blur-sm border-b border-stone-200 px-3 py-3 mb-4 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2 print:hidden">
        <span id="selectedCount" role="status" aria-live="polite" class="text-xs text-stone-500 font-bold">0 selected</span>
        <div class="flex flex-wrap gap-2 w-full sm:w-auto">
            <button type="button" data-mod-action="approve"
                class="bg-emerald-700 hover:bg-emerald-900 text-white font-bold uppercase tracking-wider px-3 sm:px-5 py-2 rounded text-xs shadow-sm transition flex-1 sm:flex-none" style="min-height:44px;">
                ✅ Approve Selected
            </button>
            <button type="button" data-mod-action="reject"
                class="bg-red-700 hover:bg-red-900 text-white font-bold uppercase tracking-wider px-3 sm:px-5 py-2 rounded text-xs shadow-sm transition flex-1 sm:flex-none" style="min-height:44px;">
                ❌ Reject Selected
            </button>
        </div>
    </div>

    <!-- Two-column comment grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <?php foreach ($mod_comments as $comment):
            $commenter  = htmlspecialchars(trim($comment['first_name'] . ' ' . $comment['last_name']));
            $recipe     = htmlspecialchars($comment['recipe_title'] ?? 'Unknown Recipe');
            $age_hours  = round((time() - strtotime($comment['created_at'])) / 3600, 1);
            $is_flagged = $comment['status'] === 'flagged';
            $is_banned  = !empty($comment['restricted_until']) && strtotime($comment['restricted_until']) > time();
            $high_risk  = (int)$comment['recent_rejections'] >= 3;
        ?>
        <label class="block cursor-pointer">
            <div class="bg-white border rounded shadow-sm p-4 hover:border-stone-400 transition has-[:checked]:border-emerald-400 has-[:checked]:bg-emerald-50/30">
                <div class="flex justify-between items-start gap-3">

                    <!-- Checkbox -->
                    <input type="checkbox" name="comment_ids[]"
                        value="<?= (int)$comment['id'] ?>"
                        class="mod-cb mt-1 w-4 h-4 shrink-0 cursor-pointer"
                        >

                    <div class="flex-1 min-w-0">
                        <!-- Header row -->
                        <div class="flex flex-wrap justify-between items-center gap-2 mb-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-bold text-stone-800 text-sm"><?= $commenter ?></span>
                                <span class="text-[10px] text-stone-400 uppercase"><?= htmlspecialchars($comment['role']) ?></span>
                                <?php if ($is_flagged): ?>
                                    <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded bg-red-100 text-red-700 border border-red-200">🚩 Flagged</span>
                                <?php endif; ?>
                                <?php if ($is_banned): ?>
                                    <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded bg-stone-200 text-stone-600">⛔ Restricted until <?= date('M d', strtotime($comment['restricted_until'])) ?></span>
                                <?php endif; ?>
                                <?php if ($high_risk): ?>
                                    <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 border border-amber-200">⚠️ <?= (int)$comment['recent_rejections'] ?> recent rejections</span>
                                <?php endif; ?>
                            </div>
                            <span class="text-[10px] text-stone-400 shrink-0">
                                <?= $age_hours < 1 ? 'Just now' : $age_hours . 'h ago' ?>
                            </span>
                        </div>

                        <!-- Recipe link -->
                        <div class="text-[11px] font-bold text-stone-500 mb-2">
                            📖 <?= $recipe ?>
                        </div>

                        <!-- Comment text -->
                        <p class="text-xs text-stone-700 leading-relaxed bg-stone-50 rounded p-2.5 border border-stone-100">
                            <?= htmlspecialchars($comment['comment_text']) ?>
                        </p>

                        <!-- Footer -->
                        <div class="flex justify-between items-center mt-2 text-[10px] text-stone-400">
                            <span><?= date('M d, Y g:i A', strtotime($comment['created_at'])) ?></span>
                            <span class="<?= $is_flagged ? 'text-red-500 font-bold' : '' ?>">
                                <?= ucfirst($comment['status']) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </label>
        <?php endforeach; ?>
    </div>
</form>

<?php endif; ?>

<script src="/admin/assets/comment-moderation.js" defer></script>
