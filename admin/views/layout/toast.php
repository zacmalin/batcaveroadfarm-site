<?php // admin/views/layout/toast.php — toast after a save (success / warning / error) ?>
<?php if (!empty($toastMessage)): ?>
    <div id="toastNotification" class="fixed bottom-5 right-5 z-50 animate-toast flex items-center space-x-2 px-4 py-3 rounded shadow-lg text-xs font-semibold bg-stone-900 text-stone-100 border border-stone-800">
        <span><?= $toastType === 'error' ? '❌' : ($toastType === 'warning' ? '⚠️' : '✅') ?></span>
        <span><?= htmlspecialchars($toastMessage) ?></span>
    </div>
<?php endif; ?>
