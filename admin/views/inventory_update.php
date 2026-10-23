<?php
// Group items by category for header rows (Fix 8)
$grouped = [];
foreach ($inventory_items as $item) {
    $grouped[$item['category']][] = $item;
}
?>

<div class="flex justify-between items-center mb-6">
    <h2 class="text-xl font-bold font-serif text-stone-800">Quick Inventory Update</h2>
    <span class="text-xxs font-mono text-stone-400 bg-stone-100 border px-2 py-1 rounded">
        <?= array_sum(array_map('count', $grouped)) ?> active items
        &bull; <?= count($grouped) ?> categories
    </span>
</div>

<?php if (empty($inventory_items)): ?>
    <div class="bg-white border rounded p-8 text-center text-stone-400 italic text-sm">
        No active inventory items found.
    </div>
<?php else: ?>

<div class="bg-white border rounded shadow-xs overflow-hidden">
    <table class="w-full text-xs text-left" data-admin-list data-list-name-col="1">
        <thead class="bg-stone-900 text-stone-100 uppercase tracking-widest text-[9px] font-bold sticky top-0 z-10">
            <tr>
                <th class="p-3 w-12"></th>
                <th class="p-3">Name</th>
                <th class="p-3">Size</th>
                <th class="p-3 text-right">Price</th>
                <th class="p-3 text-center">Current Qty</th>
                <th class="p-3 text-center">Update Qty</th>
                <th class="p-3 text-center">Min Threshold</th>
                    <th class="p-3 w-16"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        <?php foreach ($grouped as $category => $items): ?>

            <!-- Fix 8: category header row -->
            <tr class="bg-stone-100 border-t-2 border-stone-300" data-list-section>
                <td colspan="8" class="px-3 py-2 font-black uppercase tracking-widest text-[10px] text-stone-500">
                    <?= htmlspecialchars($category) ?>
                    <span class="font-normal text-stone-400 ml-1">(<?= count($items) ?>)</span>
                </td>
            </tr>

            <?php foreach ($items as $item):
                $low = isset($item['stock_quantity'], $item['min_stock_level'])
                    && (float)$item['stock_quantity'] <= (float)$item['min_stock_level']
                    && (float)$item['min_stock_level'] > 0;
                $size_label = ($item['size_value'] && $item['size_unit_id'])
                    ? (float)$item['size_value'] . ' ' . ($inventory_units[$item['size_unit_id']] ?? '')
                    : '—';
            ?>
            <tr id="inv-row-<?= (int)$item['id'] ?>"
                class="hover:bg-stone-50 transition <?= $low ? 'bg-red-50/60' : '' ?>"
                data-min="<?= (float)$item['min_stock_level'] ?>">

                <!-- Image with fallback (Fix 6) -->
                <td class="p-2">
                    <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES) ?>"
                            class="w-9 h-9 rounded object-cover border bg-stone-100"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"
                            loading="lazy">
                        <div class="w-9 h-9 rounded border bg-stone-100 hidden items-center justify-center text-stone-300 text-base">📦</div>
                    <?php else: ?>
                        <div class="w-9 h-9 rounded border bg-stone-100 flex items-center justify-center text-stone-300 text-base">📦</div>
                    <?php endif; ?>
                </td>

                <td class="p-3 font-bold text-stone-800">
                    <?= htmlspecialchars($item['name']) ?>
                    <span id="inv-badge-<?= (int)$item['id'] ?>"
                        class="ml-1 text-[9px] font-black uppercase tracking-wider text-red-600 bg-red-50 border border-red-200 px-1 py-0.5 rounded <?= $low ? '' : 'hidden' ?>">Low</span>
                </td>

                <!-- Fix 10: size is now displayed -->
                <td class="p-3 font-mono text-stone-500 text-xxs"><?= htmlspecialchars($size_label) ?></td>

                <td class="p-3 font-mono text-stone-600 text-right">
                    $<?= number_format((float)$item['price'], 2) ?>
                </td>

                <!-- Current qty display — updates live on save -->
                <td class="p-3 text-center font-mono font-bold <?= $low ? 'text-red-600' : 'text-stone-700' ?>"
                    id="qty-display-<?= (int)$item['id'] ?>">
                    <?= (float)$item['stock_quantity'] ?>
                </td>

                <!-- Fix 5: tracks original value, only POSTs if changed -->
                <td class="p-3 text-center">
                    <input type="number" step="0.01" min="0"
                        class="inv-input w-20 p-1 border rounded font-mono text-center bg-stone-50 outline-none"
                        data-original="<?= (float)$item['stock_quantity'] ?>"
                        value="<?= (float)$item['stock_quantity'] ?>"
                        data-field="stock_quantity"
                        data-id="<?= (int)$item['id'] ?>">
                </td>

                <td class="p-3 font-mono text-stone-500 text-center">
                    <?= (float)$item['min_stock_level'] ?>
                </td>
                <td class="p-3 text-center">
                    <button type="button"
                        onclick="openLabelModal(<?= (int)$item['id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES) ?>)"
                        class="text-[9px] font-bold uppercase tracking-wider font-mono border px-2 py-1 rounded bg-stone-50 hover:bg-stone-900 hover:text-white transition whitespace-nowrap">
                        🏷️ Label
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

// Fix 9: track save state per input to show loading indicator
async function saveField(input) {
    const field    = input.dataset.field;
    const id       = input.dataset.id;
    const original = input.dataset.original;
    const value    = input.value.trim();

    // Fix 5: skip if value hasn't changed
    if (parseFloat(value) === parseFloat(original)) return;

    // Fix 9: loading state
    input.disabled = true;
    input.style.borderColor = '#a8a29e'; // stone-400

    try {
        const res = await fetch('/admin/handlers/update_inventory.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: CSRF_TOKEN, id, field, value }),
        });

        const data = await res.json();

        if (data.ok) {
            // Success — flash green, update stored original and live display
            input.dataset.original   = value;
            input.style.borderColor  = '#22c55e';

            if (field === 'stock_quantity') {
                const newQty  = parseFloat(value);
                const row     = document.getElementById('inv-row-'   + id);
                const display = document.getElementById('qty-display-' + id);
                const badge   = document.getElementById('inv-badge-'  + id);
                const minVal  = row ? parseFloat(row.dataset.min) : 0;
                const isLow   = minVal > 0 && newQty <= minVal;

                if (display) {
                    display.textContent = newQty;
                    display.className   = display.className
                        .replace(/text-red-600|text-stone-700/g, '')
                        .trim() + (isLow ? ' text-red-600' : ' text-stone-700');
                }
                if (badge)  badge.classList.toggle('hidden', !isLow);
                if (row)    row.classList.toggle('bg-red-50/60', isLow);
            }
        } else {
            // Fix 4: surface server error to user
            input.style.borderColor = '#ef4444';
            showInvToast('❌ ' + (data.message || 'Save failed'), true);
        }
    } catch (err) {
        // Fix 4: network / parse error
        input.style.borderColor = '#ef4444';
        showInvToast('❌ Network error — change not saved.', true);
    } finally {
        input.disabled = false;
        setTimeout(() => input.style.borderColor = '', 2500);
    }
}

// Attach blur handlers to all inventory inputs
document.querySelectorAll('.inv-input').forEach(input => {
    input.addEventListener('focus', () => {
        // Store value at focus-time for comparison on blur
        input.dataset.original = input.value;
    });
    input.addEventListener('blur', () => saveField(input));

    // Also allow Enter key to trigger save and move focus
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
    });
});

// Lightweight inline toast — doesn't require the full page toast system
// ── LABEL PRINT MODAL ────────────────────────────────────────────────────────
const LABEL_CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
let labelItemId   = null;
let labelItemData = null;

async function openLabelModal(itemId, itemName) {
    labelItemId = itemId;
    document.getElementById('labelItemName').textContent = itemName;
    document.getElementById('labelPreviewName').textContent = itemName;
    document.getElementById('labelModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    // Reset fields
    document.getElementById('labelCopies').value  = '1';
    document.getElementById('labelStartPos').value = '1';
    document.getElementById('labelBestBy').value  = '';
    document.getElementById('labelPreviewBestBy').textContent = '';
    document.getElementById('labelPreviewIngredients').textContent = 'Loading...';

    // Fetch item data
    try {
        const res  = await fetch('/admin/handlers/get_label_data.php?item_id=' + itemId);
        const data = await res.json();
        if (data.ok) {
            labelItemData = data;
            document.getElementById('labelPreviewSize').textContent = data.size_label || '—';
            const ings = data.ingredients.length > 0 ? data.ingredients.join(', ') : data.name;
            document.getElementById('labelPreviewIngredients').textContent = ings;
        } else {
            document.getElementById('labelPreviewIngredients').textContent = itemName;
        }
    } catch (err) {
        document.getElementById('labelPreviewIngredients').textContent = itemName;
    }
}

function closeLabelModal() {
    document.getElementById('labelModal').classList.add('hidden');
    document.body.style.overflow = '';
    labelItemId = null;
    labelItemData = null;
}

function updateLabelPreview() {
    const bestBy = document.getElementById('labelBestBy').value;
    if (bestBy) {
        const d = new Date(bestBy + 'T12:00:00');
        document.getElementById('labelPreviewBestBy').textContent =
            'Best By: ' + d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
    } else {
        document.getElementById('labelPreviewBestBy').textContent = '';
    }
}

function printLabel() {
    if (!labelItemId) return;
    const copies   = document.getElementById('labelCopies').value;
    const startPos = document.getElementById('labelStartPos').value;
    const bestBy   = document.getElementById('labelBestBy').value;
    const params   = new URLSearchParams({ item_id: labelItemId, copies, start_pos: startPos });
    if (bestBy) params.set('best_by', bestBy);
    window.open('/admin/print_label.php?' + params.toString(), '_blank');
    closeLabelModal();
}

document.getElementById('labelModal').addEventListener('click', function(e) {
    if (e.target === this) closeLabelModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLabelModal();
});

function showInvToast(msg, isError = false) {
    let toast = document.getElementById('invToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id        = 'invToast';
        toast.className = 'fixed bottom-5 right-5 z-50 px-4 py-2 rounded shadow-lg font-mono text-xs font-bold transition-opacity duration-300';
        document.body.appendChild(toast);
    }
    toast.textContent  = msg;
    toast.style.background   = isError ? '#fef2f2' : '#f0fdf4';
    toast.style.color         = isError ? '#991b1b' : '#166534';
    toast.style.border        = isError ? '1px solid #fecaca' : '1px solid #bbf7d0';
    toast.style.opacity       = '1';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => toast.style.opacity = '0', 3000);
}
</script>

<!-- ── LABEL PRINT MODAL ── -->
<div id="labelModal" class="fixed inset-0 bg-stone-900/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg">
        <div class="flex justify-between items-center px-6 py-4 border-b">
            <div>
                <h3 class="font-bold text-stone-800 font-serif text-lg">Print Product Label</h3>
                <p id="labelItemName" class="text-xs text-stone-500 font-mono mt-0.5"></p>
            </div>
            <button type="button" onclick="closeLabelModal()"
                class="text-stone-400 hover:text-stone-700 text-xl font-bold leading-none">&times;</button>
        </div>

        <div class="p-6 grid grid-cols-2 gap-6">

            <!-- LEFT: Form fields -->
            <div class="space-y-4 text-xs font-sans">
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Number of Copies</label>
                    <input type="number" id="labelCopies" min="1" max="20" value="1"
                        class="w-full p-2 border rounded bg-stone-50 font-mono outline-none">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">
                        Start Position
                        <span class="font-normal text-stone-400 ml-1">(for partially used sheets)</span>
                    </label>
                    <input type="number" id="labelStartPos" min="1" max="20" value="1"
                        class="w-full p-2 border rounded bg-stone-50 font-mono outline-none">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">
                        Best By Date
                        <span class="font-normal text-stone-400 ml-1">(optional)</span>
                    </label>
                    <input type="date" id="labelBestBy"
                        onchange="updateLabelPreview()"
                        class="w-full p-2 border rounded bg-stone-50 font-mono outline-none">
                </div>
            </div>

            <!-- RIGHT: Live label preview -->
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-stone-400 mb-2">Preview</p>
                <!-- Scaled preview: 3x3in label at ~50% scale (216px × 216px) -->
                <div style="transform:scale(0.72); transform-origin:top left; width:3in; height:3in; position:relative;"
                    class="border border-stone-200 shadow-sm overflow-hidden bg-white font-sans">

                    <!-- Logo 2"×2" -->
                    <div style="position:absolute;left:0;top:0;width:2in;height:2in;overflow:hidden;">
                        <img src="https://batcaveroadfarm.com/img/logos/print_square_logo.png"
                            style="width:100%;height:100%;object-fit:contain;"
                            onerror="this.style.background='#f5f5f5'">
                    </div>

                    <!-- Product name 2"×1" -->
                    <div style="position:absolute;left:0;top:2in;width:2in;height:1in;background:#322016;color:#e6cca4;display:flex;align-items:center;justify-content:center;text-align:center;font-family:Georgia,serif;font-weight:bold;font-size:10pt;line-height:1.2;padding:6px;">
                        <span id="labelPreviewName"></span>
                    </div>

                    <!-- Right column border -->
                    <div style="position:absolute;right:0;top:0;width:1in;height:3in;border-left:0.5pt solid #bbb;">

                        <!-- Rotated info area 1"×2" -->
                        <div style="position:absolute;top:0;left:0;width:1in;height:2in;overflow:hidden;">
                            <div style="position:absolute;left:1in;top:0;width:2in;transform:rotate(90deg);transform-origin:top left;padding:5pt 4pt;font-family:Arial,sans-serif;font-size:5.5pt;line-height:1.4;color:#111;">
                                <div style="font-size:6.5pt;font-weight:bold;color:#322016;margin-bottom:2pt;">batcaveroadfarm.com</div>
                                <div style="font-size:5pt;"><strong style="font-size:5.5pt;display:block;margin-bottom:1pt;">Ingredients:</strong><span id="labelPreviewIngredients">—</span></div>
                                <div id="labelPreviewBestBy" style="font-size:5.5pt;font-weight:bold;margin-top:3pt;"></div>
                            </div>
                        </div>

                        <!-- Divider -->
                        <div style="position:absolute;left:0;top:2in;width:1in;border-top:0.5pt solid #bbb;"></div>

                        <!-- Size 1"×1" -->
                        <div style="position:absolute;bottom:0;left:0;width:1in;height:1in;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;font-family:Arial,sans-serif;font-size:8pt;font-weight:bold;color:#222;padding:4px;line-height:1.3;">
                            <span id="labelPreviewSize">—</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="px-6 pb-5 flex justify-between items-center border-t pt-4">
            <button type="button" onclick="closeLabelModal()"
                class="border font-mono font-bold uppercase text-[10px] tracking-wider px-4 py-2 rounded text-stone-600 hover:bg-stone-100 transition">
                Cancel
            </button>
            <button type="button" onclick="printLabel()"
                class="bg-stone-900 text-white font-mono font-bold uppercase tracking-wider px-6 py-2 rounded hover:bg-stone-700 transition shadow-xs text-xs">
                🖨 Print Label
            </button>
        </div>
    </div>
</div>
