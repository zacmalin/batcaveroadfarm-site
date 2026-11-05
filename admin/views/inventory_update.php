<?php
// admin/views/inventory_update.php — Storefront › Products & Inventory › Counts
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
// Phone: each product is a card with −/+ steppers; desktop keeps the table. Counts save on blur (or on each −/+ tap).
// Behaviour lives in /admin/assets/inventory-counts.js (no inline handlers — 02 Navigation & UX, Nov 5 2026).
require_once __DIR__ . '/layout/ui.php';

$grouped = [];
foreach (($inventory_items ?? []) as $item) {
    $grouped[$item['category']][] = $item;
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<div id="invCounts" data-csrf="<?= $h($_SESSION['csrf_token'] ?? '') ?>">

<div class="flex flex-wrap justify-between items-center gap-2 mb-4">
    <div>
        <h1 class="text-xl font-bold font-serif text-stone-800">Inventory Counts</h1>
        <p class="text-xs text-stone-500">Type the count you see on the shelf. It saves when you leave the box; on a phone, − and + save right away.</p>
    </div>
    <span class="text-[10px] font-mono text-stone-500 bg-stone-100 border px-2 py-1 rounded">
        <?= array_sum(array_map('count', $grouped)) ?> active items &bull; <?= count($grouped) ?> categories
    </span>
</div>

<?php if (empty($inventory_items)): ?>
    <?= ui_empty('📦', 'No active inventory items', 'Products marked active in the catalog show up here for quick counts.', ['Open catalog', '?view=schema&table=inventory']) ?>
<?php else: ?>

<div class="bg-white border rounded-xl shadow-xs overflow-hidden">
    <table class="ui-cards w-full text-xs text-left" data-admin-list data-list-name-col="1">
        <thead class="bg-stone-900 text-stone-100 uppercase tracking-widest text-[9px] font-bold sticky top-0 z-10">
            <tr>
                <th class="p-3 w-12"></th>
                <th class="p-3">Name</th>
                <th class="p-3">Size</th>
                <th class="p-3 text-right">Price</th>
                <th class="p-3 text-center">On hand</th>
                <th class="p-3 text-center">Update count</th>
                <th class="p-3 text-center">Min</th>
                <th class="p-3 w-16"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        <?php foreach ($grouped as $category => $items): ?>

            <tr class="bg-stone-100 border-t-2 border-stone-300" data-list-section>
                <td colspan="8" class="px-3 py-2 font-black uppercase tracking-widest text-[10px] text-stone-500">
                    <?= $h($category) ?> <span class="font-normal text-stone-400 ml-1">(<?= count($items) ?>)</span>
                </td>
            </tr>

            <?php foreach ($items as $item):
                $id  = (int)$item['id'];
                $qty = (float)$item['stock_quantity'];
                $min = (float)$item['min_stock_level'];
                $low = $min > 0 && $qty <= $min;
                $size_label = ($item['size_value'] && $item['size_unit_id'])
                    ? (float)$item['size_value'] . ' ' . ($inventory_units[$item['size_unit_id']] ?? '')
                    : '—';
            ?>
            <tr id="inv-row-<?= $id ?>" class="hover:bg-stone-50 transition <?= $low ? 'bg-red-50/60' : '' ?>" data-min="<?= $min ?>">

                <td class="p-2" data-cell="media">
                    <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= $h($item['image_url']) ?>" alt="" data-fallback loading="lazy"
                             class="w-10 h-10 rounded object-cover border bg-stone-100">
                        <div class="w-10 h-10 rounded border bg-stone-100 hidden items-center justify-center text-stone-300 text-base">📦</div>
                    <?php else: ?>
                        <div class="w-10 h-10 rounded border bg-stone-100 flex items-center justify-center text-stone-300 text-base">📦</div>
                    <?php endif; ?>
                </td>

                <td class="p-3 font-bold text-stone-800" data-cell="title">
                    <?= $h($item['name']) ?>
                    <span id="inv-badge-<?= $id ?>" class="ml-1 <?= $low ? '' : 'hidden' ?>"><?= ui_chip('low') ?></span>
                </td>

                <td class="p-3 font-mono text-stone-500 text-[11px]"><?= $h($size_label) ?></td>

                <td class="p-3 font-mono text-stone-600 text-right"><?= ui_money($item['price']) ?></td>

                <td class="p-3 text-center font-mono font-bold <?= $low ? 'text-red-600' : 'text-stone-700' ?>" id="qty-display-<?= $id ?>"><?= $qty ?></td>

                <td class="p-3 text-center" data-cell="wide">
                    <div class="flex items-center gap-1 md:justify-center">
                        <button type="button" data-step="-1" data-for="<?= $id ?>" aria-label="One less <?= $h($item['name']) ?>"
                                class="md:hidden w-11 h-11 shrink-0 rounded-lg border bg-white text-lg font-bold text-stone-700 active:bg-stone-200">−</button>
                        <input type="number" step="0.01" min="0" inputmode="decimal"
                               id="inv-input-<?= $id ?>"
                               aria-label="Count for <?= $h($item['name']) ?>"
                               class="inv-input w-full md:w-20 h-11 md:h-auto p-1 border rounded-lg md:rounded font-mono text-center bg-stone-50 outline-none"
                               data-original="<?= $qty ?>" value="<?= $qty ?>"
                               data-field="stock_quantity" data-id="<?= $id ?>">
                        <button type="button" data-step="1" data-for="<?= $id ?>" aria-label="One more <?= $h($item['name']) ?>"
                                class="md:hidden w-11 h-11 shrink-0 rounded-lg border bg-white text-lg font-bold text-stone-700 active:bg-stone-200">+</button>
                    </div>
                </td>

                <td class="p-3 font-mono text-stone-500 text-center"><?= $min ?></td>

                <td class="p-3 text-center" data-cell="actions">
                    <button type="button" data-label-item="<?= $id ?>" data-label-name="<?= $h($item['name']) ?>"
                        class="<?= ui_btn('secondary') ?> whitespace-nowrap">🏷️ Label</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>
</div>

<!-- ── LABEL PRINT MODAL ── -->
<div id="labelModal" class="fixed inset-0 bg-stone-900/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-2 md:p-4" role="dialog" aria-modal="true" aria-labelledby="labelModalTitle">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
        <div class="flex justify-between items-center px-4 md:px-6 py-4 border-b">
            <div>
                <h3 id="labelModalTitle" class="font-bold text-stone-800 font-serif text-lg">Print Product Label</h3>
                <p id="labelItemName" class="text-xs text-stone-500 font-mono mt-0.5"></p>
            </div>
            <button type="button" data-label-close aria-label="Close"
                class="w-10 h-10 rounded-full text-stone-400 hover:text-stone-700 hover:bg-stone-100 text-2xl font-bold leading-none">&times;</button>
        </div>

        <div class="p-4 md:p-6 grid grid-cols-1 sm:grid-cols-2 gap-6">

            <!-- Form fields -->
            <div class="space-y-4 text-xs font-sans">
                <div>
                    <label for="labelCopies" class="block font-bold text-stone-600 mb-1">Number of Copies</label>
                    <input type="number" id="labelCopies" min="1" max="20" value="1" inputmode="numeric"
                        class="w-full p-2 border rounded bg-stone-50 font-mono outline-none">
                </div>
                <div>
                    <label for="labelStartPos" class="block font-bold text-stone-600 mb-1">
                        Start Position <span class="font-normal text-stone-400 ml-1">(for partially used sheets)</span>
                    </label>
                    <input type="number" id="labelStartPos" min="1" max="20" value="1" inputmode="numeric"
                        class="w-full p-2 border rounded bg-stone-50 font-mono outline-none">
                </div>
                <div>
                    <label for="labelBestBy" class="block font-bold text-stone-600 mb-1">
                        Best By Date <span class="font-normal text-stone-400 ml-1">(optional)</span>
                    </label>
                    <input type="date" id="labelBestBy" class="w-full p-2 border rounded bg-stone-50 font-mono outline-none">
                </div>
            </div>

            <!-- Live label preview -->
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-stone-400 mb-2">Preview</p>
                <div style="width:calc(3in * 0.72); height:calc(3in * 0.72); overflow:hidden;">
                <div style="transform:scale(0.72); transform-origin:top left; width:3in; height:3in; position:relative;"
                    class="border border-stone-200 shadow-sm overflow-hidden bg-white font-sans">

                    <div style="position:absolute;left:0;top:0;width:2in;height:2in;overflow:hidden;background:#f5f5f5;">
                        <img src="<?= bcrf_farm_url() ?>/img/logos/print_square_logo.png" alt=""
                            style="width:100%;height:100%;object-fit:contain;background:#fff;">
                    </div>

                    <div style="position:absolute;left:0;top:2in;width:2in;height:1in;background:#322016;color:#e6cca4;display:flex;align-items:center;justify-content:center;text-align:center;font-family:Georgia,serif;font-weight:bold;font-size:10pt;line-height:1.2;padding:6px;">
                        <span id="labelPreviewName"></span>
                    </div>

                    <div style="position:absolute;right:0;top:0;width:1in;height:3in;border-left:0.5pt solid #bbb;">
                        <div style="position:absolute;top:0;left:0;width:1in;height:2in;overflow:hidden;">
                            <div style="position:absolute;left:1in;top:0;width:2in;transform:rotate(90deg);transform-origin:top left;padding:5pt 4pt;font-family:Arial,sans-serif;font-size:5.5pt;line-height:1.4;color:#111;">
                                <div style="font-size:6.5pt;font-weight:bold;color:#322016;margin-bottom:2pt;"><?= bcrf_farm_domain() ?></div>
                                <div style="font-size:5pt;"><strong style="font-size:5.5pt;display:block;margin-bottom:1pt;">Ingredients:</strong><span id="labelPreviewIngredients">—</span></div>
                                <div id="labelPreviewContains" style="font-size:5pt;font-weight:bold;margin-top:1.5pt;line-height:1.3;"></div>
                                <div id="labelPreviewMadeBy" style="font-size:4.5pt;line-height:1.3;margin-top:2pt;color:#222;"></div>
                                <div id="labelPreviewBestBy" style="font-size:5.5pt;font-weight:bold;margin-top:3pt;"></div>
                            </div>
                        </div>
                        <div style="position:absolute;left:0;top:2in;width:1in;border-top:0.5pt solid #bbb;"></div>
                        <div style="position:absolute;bottom:0;left:0;width:1in;height:1in;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;font-family:Arial,sans-serif;font-size:6.5pt;font-weight:bold;color:#222;padding:4px;line-height:1.25;">
                            <span id="labelPreviewSize">—</span>
                        </div>
                    </div>
                </div>
                </div>
            </div>

        </div>

        <!-- Ingredients, heaviest first -->
        <div class="px-4 md:px-6 pb-4 text-xs font-sans">
            <label class="block font-bold text-stone-600 mb-0.5">Ingredients — heaviest first</label>
            <p class="text-[11px] text-stone-500 mb-2">Food labels must list ingredients by weight, the ingredient there is most of first. Use ▲ ▼ to set the order, then Save order. Tap Details on an ingredient to record its allergens and sub-ingredients (shared by every product that uses it).</p>
            <p id="labelIngWarn" class="hidden mb-2 p-2 rounded border border-amber-300 bg-amber-50 text-amber-900 text-[11px] font-semibold">This order hasn't been set yet (it's alphabetical). Put the heaviest ingredient first and tap Save.</p>
            <p id="labelAllergenWarn" class="hidden mb-2 p-2 rounded border border-red-300 bg-red-50 text-red-800 text-[11px] font-semibold"></p>
            <ol id="labelIngList" class="border rounded bg-stone-50 px-3 max-h-96 overflow-y-auto"></ol>
            <p id="labelIngEmpty" class="hidden text-[11px] text-stone-500 py-2">No ingredients linked. The label will show the product name as its only ingredient.</p>
            <div class="flex gap-2 mt-2">
                <select id="labelIngAdd" aria-label="Add an ingredient" class="flex-1 min-w-0 p-2 border rounded bg-white"></select>
                <button type="button" id="labelIngAddBtn" class="<?= ui_btn('secondary') ?>">Add</button>
            </div>
            <div class="flex items-center gap-3 mt-2">
                <button type="button" id="labelIngSave" disabled class="<?= ui_btn('success') ?>">Save order</button>
                <span id="labelIngStatus" class="text-[11px] text-stone-600" role="status" aria-live="polite"></span>
            </div>
        </div>

        <div class="px-4 md:px-6 pb-5 flex justify-between items-center border-t pt-4">
            <button type="button" data-label-close class="<?= ui_btn('secondary') ?>">Cancel</button>
            <button type="button" id="labelPrintBtn" class="<?= ui_btn('primary') ?>">🖨 Print Label</button>
        </div>
    </div>
</div>

<script src="/admin/assets/inventory-counts.js?v=20261105a"></script>
