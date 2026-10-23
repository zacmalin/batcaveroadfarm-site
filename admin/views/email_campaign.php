<?php
// Fetch subscribers from your existing mailing_list table
$subscribers = $pdo->query("SELECT * FROM mailing_list ORDER BY id DESC")->fetchAll();
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white p-6 border rounded shadow-xs">
        <h3 class="font-bold mb-4 text-stone-800 uppercase text-xs">CRM Pool (<?= count($subscribers) ?>)</h3>
        <div class="max-h-[50vh] overflow-y-auto space-y-2">
            <?php foreach ($subscribers as $sub): ?>
                <div class="border-b py-2 text-[10px]">
                    <div class="font-bold truncate"><?= htmlspecialchars($sub['email']) ?></div>
                    <div class="flex gap-1 mt-1">
                        <span class="<?= $sub['is_subscribed'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?> px-1 rounded">Sub</span>
                        <span class="<?= $sub['track_nursery_updates'] ? 'bg-blue-100 text-blue-700' : 'bg-stone-100' ?> px-1 rounded">Nursery</span>
                        <span class="<?= $sub['track_inventory_alerts'] ? 'bg-amber-100 text-amber-700' : 'bg-stone-100' ?> px-1 rounded">Inv</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="lg:col-span-2 bg-white p-6 border rounded shadow-xs">
        <h3 class="font-bold mb-4 text-stone-800 uppercase text-xs">Draft Campaign</h3>
        <form method="POST" action="handlers/campaign_action.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="text" name="subject" placeholder="Campaign Subject" class="w-full p-2 border rounded mb-4" required>
            <select name="segment" class="w-full p-2 border rounded mb-4 text-xs">
                <option value="all">Send to All Active</option>
                <option value="nursery">Nursery Updates Only</option>
                <option value="inventory">Inventory Alerts Only</option>
            </select>
            <textarea name="body" class="w-full h-40 p-2 border rounded mb-4" placeholder="Newsletter content..." required></textarea>
            <button type="submit" class="w-full bg-stone-900 text-white py-2 rounded font-bold uppercase text-xs">Send Blast</button>
        </form>
    </div>
</div>
