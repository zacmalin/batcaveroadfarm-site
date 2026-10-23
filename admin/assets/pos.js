// admin/assets/pos.js
// Register UI logic extracted verbatim from admin/pos.php (behavior-preserving).
// PHP-injected data arrives via the inline window.POS bootstrap defined in pos.php:
//   window.POS = { catalogueMatrix, allCategories, groceryCategories, lastReceiptData }

// ── DATA FROM PHP ─────────────────────────────────────────────────────────────
const catalogueMatrix   = window.POS.catalogueMatrix;
const allCategories     = window.POS.allCategories;
const groceryCategories = window.POS.groceryCategories;

// ── STATE ─────────────────────────────────────────────────────────────────────
let currentCart          = [];
let currentTab           = allCategories[0] ?? '';
let cartDiscountPercent  = 0;
let globalCurrentMethod  = 'CASH';
let globalCurrentReceiptNo = '';
let cashCentsAccumulator = 0;
let taxExemptMode        = false;
let wasPrinted           = false;

// ── MODAL HELPERS ─────────────────────────────────────────────────────────────
function openMobilePaymentModal()  { if (!currentCart.length) return; document.getElementById('mobilePaymentModal').classList.remove('hidden'); }
function closeMobilePaymentModal() { document.getElementById('mobilePaymentModal').classList.add('hidden'); }
function openCustomItemModal()     { document.getElementById('customItemModal').classList.remove('hidden'); }
function closeCustomItemModal()    { document.getElementById('customItemModal').classList.add('hidden'); }
function closeCashCalculatorModal(){ document.getElementById('cashCalculatorModal').classList.add('hidden'); }

function openDiscountModal() {
    document.getElementById('discountModal').classList.remove('hidden');
    const display = document.getElementById('currentDiscountDisplay');
    if (cartDiscountPercent > 0) {
        display.textContent = 'Current: ' + cartDiscountPercent + '% — selecting a new rate will replace it';
        display.classList.remove('hidden');
    } else {
        display.classList.add('hidden');
    }
}
function closeDiscountModal() { document.getElementById('discountModal').classList.add('hidden'); }

// ── TAX EXEMPT TOGGLE ────────────────────────────────────────────────────────
function toggleTaxExempt() {
    taxExemptMode = !taxExemptMode;
    const btn = document.getElementById('taxExemptBtn');
    const lbl = document.getElementById('lblTaxLabel');
    if (taxExemptMode) {
        btn.className = btn.className.replace('border-stone-300 text-stone-600 hover:bg-stone-100', 'border-emerald-500 bg-emerald-50 text-emerald-800 font-black');
        if (lbl) lbl.textContent = 'TAX EXEMPT:';
    } else {
        btn.className = btn.className.replace('border-emerald-500 bg-emerald-50 text-emerald-800 font-black', 'border-stone-300 text-stone-600 hover:bg-stone-100');
        if (lbl) lbl.textContent = 'NC SALES TAX:';
    }
    renderTicketCalculationDisplay();
}

// ── CONFIRM CLEAR TICKET ─────────────────────────────────────────────────────
function confirmClearTicket() {
    if (!currentCart.length) return; // nothing to clear
    document.getElementById('clearTicketModal').classList.remove('hidden');
}

// ── INVENTORY STOCK REFRESH ───────────────────────────────────────────────────
function refreshInventoryStock() {
    const btn = document.querySelector('[onclick="refreshInventoryStock()"]');
    if (btn) { btn.textContent = '↻ Refreshing…'; btn.disabled = true; }
    fetch('/admin/pos.php?ajax_stock=1')
        .then(r => r.json())
        .then(stockMap => {
            // Update stock_quantity in catalogueMatrix
            let updated = 0;
            Object.values(catalogueMatrix).forEach(items => {
                items.forEach(item => {
                    if (stockMap.hasOwnProperty(item.id)) {
                        item.stock_quantity = stockMap[item.id];
                        updated++;
                    }
                });
            });
            renderPOSMatrixGrid(catalogueMatrix[currentTab] || []);
            if (btn) { btn.textContent = '✓ Updated'; btn.disabled = false; setTimeout(() => { btn.textContent = '↻ Stock'; }, 2000); }
        })
        .catch(() => { if (btn) { btn.textContent = '↻ Stock'; btn.disabled = false; } });
}

// ── CATEGORY TABS ─────────────────────────────────────────────────────────────
function changeActiveCategoryTab(catKey) {
    currentTab = catKey;
    document.querySelectorAll('.cat-btn').forEach(btn => {
        btn.className = 'cat-btn flex-1 min-w-[80px] py-4 bg-stone-200 text-stone-700 rounded border shadow-sm font-mono font-bold text-[10px] text-center uppercase tracking-wider';
    });
    const active = document.getElementById('tab-' + catKey);
    if (active) active.className = 'cat-btn flex-1 min-w-[80px] py-4 bg-stone-900 text-white rounded border border-stone-950 shadow-sm font-mono font-bold text-[10px] text-center uppercase tracking-wider';
    document.getElementById('filterInput').value = '';
    renderPOSMatrixGrid(catalogueMatrix[catKey] || []);
}

// ── ITEM GRID ─────────────────────────────────────────────────────────────────
function renderPOSMatrixGrid(items) {
    const dock = document.getElementById('productGridWrapper');
    if (!dock) return;
    dock.innerHTML = '';
    if (!items || !items.length) {
        dock.innerHTML = '<p class="col-span-4 text-center italic text-stone-400 py-12 border border-dashed rounded bg-white w-full">No active items in this category.</p>';
        return;
    }
    items.forEach(item => {
        let card = document.createElement('div');
        card.setAttribute('data-search', item.name.toLowerCase());
        const isOutOfStock = item.stock_quantity !== null && item.stock_quantity <= 0;
        if (isOutOfStock) {
            card.onclick = null;
            card.style.opacity = '0.45';
            card.style.cursor  = 'not-allowed';
            card.style.pointerEvents = 'none';
        } else {
            card.onclick = () => addItemToTicketQueue(item.id, item.name, parseFloat(item.price), item.tbl, item.category);
        }
        if (item.image_url) {
            card.className = 'relative rounded-lg h-24 overflow-hidden bg-cover bg-center cursor-pointer shadow-sm active:scale-95 transition-transform duration-75 border border-stone-300';
            card.style.backgroundImage = "url('" + item.image_url + "')";
            const lowBadge = (item.stock_quantity !== null && item.stock_quantity <= 3)
                ? '<span class="absolute top-1.5 right-1.5 bg-red-600 text-white text-[8px] font-black px-1 py-0.5 rounded uppercase">' + (item.stock_quantity <= 0 ? 'OUT' : 'LOW') + '</span>'
                : '';
            card.innerHTML = '<div class="absolute inset-0 bg-stone-950/50 flex flex-col justify-end p-2 text-left"><h4 class="font-bold text-white text-[11px] leading-tight truncate">' + item.name + '</h4><span class="font-mono text-[10px] font-black text-emerald-300 block mt-0.5">$' + parseFloat(item.price).toFixed(2) + '</span></div>' + lowBadge;
        } else {
            card.className = 'bg-white border rounded-lg h-24 p-2.5 flex flex-col justify-between items-center text-center cursor-pointer shadow-sm border-stone-200 active:scale-95 transition-transform duration-75';
            const lowLabel = (item.stock_quantity !== null && item.stock_quantity <= 3)
                ? '<span class="text-[8px] font-black uppercase px-1 py-0.5 rounded mt-0.5 inline-block ' + (item.stock_quantity <= 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') + '">' + (item.stock_quantity <= 0 ? 'Out' : 'Low: ' + item.stock_quantity) + '</span>'
                : '';
            card.innerHTML = '<div class="w-full h-8 flex items-center justify-center text-xl text-stone-300">📦</div><div class="w-full"><h4 class="font-bold text-stone-800 leading-tight truncate text-[11px]">' + item.name + '</h4><span class="font-mono text-[10px] font-black text-emerald-800 block mt-0.5">$' + parseFloat(item.price).toFixed(2) + '</span>' + lowLabel + '</div>';
        }
        dock.appendChild(card);
    });
}

function liveFilterGridItems(val) {
    const q = val.toLowerCase().trim();
    document.getElementById('clearSearchBtn')?.classList.toggle('hidden', q === '');
    if (q === '') { renderPOSMatrixGrid(catalogueMatrix[currentTab] || []); return; }
    const all = Object.values(catalogueMatrix).flat();
    renderPOSMatrixGrid(all.filter(i => i.name.toLowerCase().includes(q)));
}
function clearSearchInput() {
    document.getElementById('filterInput').value = '';
    liveFilterGridItems('');
    document.getElementById('filterInput').focus();
}

// ── CART ──────────────────────────────────────────────────────────────────────
function addItemToTicketQueue(id, name, price, table, category, qty = 1) {
    let match = currentCart.find(i => i.id === id && i.tbl === table);
    if (match) { match.qty += qty; }
    else { currentCart.push({ id, name, price, tbl: 'inventory', qty, category }); }
    renderTicketCalculationDisplay();
}
function adjustCartQty(id, table, delta) {
    let match = currentCart.find(i => i.id === id && i.tbl === table);
    if (!match) return;
    match.qty = Math.max(0.5, match.qty + delta);
    if (match.qty <= 0) currentCart = currentCart.filter(i => !(i.id === id && i.tbl === table));
    renderTicketCalculationDisplay();
}
function updateRowQuantity(id, table, newQty) {
    let match = currentCart.find(i => i.id === id && i.tbl === table);
    if (match) {
        match.qty = parseFloat(newQty) || 0;
        if (match.qty <= 0) currentCart = currentCart.filter(i => !(i.id === id && i.tbl === table));
    }
    renderTicketCalculationDisplay();
}
function deleteRowItem(id, table) {
    currentCart = currentCart.filter(i => !(i.id === id && i.tbl === table));
    renderTicketCalculationDisplay();
}
function submitCustomItemModal() {
    let label = document.getElementById('customNameInput').value.trim();
    let qty   = parseFloat(document.getElementById('customQtyInput').value);
    let price = parseFloat(document.getElementById('customPriceInput').value);
    if (!label || isNaN(qty) || qty <= 0 || isNaN(price) || price < 0) return;
    addItemToTicketQueue(Date.now(), '★ ' + label, price, 'VIRTUAL_CUSTOM_ITEM', 'Custom');
    closeCustomItemModal();
}
function submitDiscountValue(val) {
    let pct = parseInt(val);
    if (isNaN(pct) || pct < 0 || pct > 100) return;
    cartDiscountPercent = pct;
    renderTicketCalculationDisplay();
    closeDiscountModal();
}
function wipeActiveTicketForm() {
    currentCart = []; cartDiscountPercent = 0;
    globalCurrentReceiptNo = ''; globalCurrentMethod = 'CASH';
    wasPrinted = false;
    // Reset tax exempt
    if (taxExemptMode) toggleTaxExempt();
    renderTicketCalculationDisplay();
}

// ── TAX CALC ──────────────────────────────────────────────────────────────────
function getCurrentCalculations() {
    let suball   = currentCart.reduce((s, i) => s + (i.price * i.qty), 0);
    let disc     = suball * (cartDiscountPercent / 100);
    let grocSub  = currentCart.filter(i => groceryCategories.includes(i.category)).reduce((s, i) => s + (i.price * i.qty), 0);
    let stdSub   = suball - grocSub;
    let gRatio   = suball > 0 ? grocSub / suball : 0;
    let sRatio   = suball > 0 ? stdSub  / suball : 0;
    let fGroc    = Math.max(0, grocSub - (disc * gRatio));
    let fStd     = Math.max(0, stdSub  - (disc * sRatio));
    let tax      = taxExemptMode ? 0 : (fGroc * 0.02) + (fStd * 0.0475);
    return { suball, disc, tax, totalDue: suball - disc + tax };
}

function renderTicketCalculationDisplay() {
    const wrapper = document.getElementById('ticketRowsContainer');
    if (!wrapper) return;
    wrapper.innerHTML = '';
    if (!currentCart.length) {
        wrapper.innerHTML = '<p id="emptyFeedback" class="text-stone-400 italic text-center py-12 text-xs">Register empty. Tap menu tiles.</p>';
        document.getElementById('itemsVolumeBadge').textContent = '0 Items';
        ['lblSubtotal','lblTax','lblTotal'].forEach(id => document.getElementById(id).textContent = '$0.00');
        document.getElementById('lblDiscount').textContent = '-$0.00';
        document.getElementById('rowDiscount').style.display = 'none';
        return;
    }
    currentCart.forEach(item => {
        let lp = item.price * item.qty;
        let row = document.createElement('div');
        row.className = 'flex justify-between items-center bg-stone-50 border p-2.5 rounded text-[10px]';
        // Stock warning: check available qty from catalogueMatrix
        let stockAvail = null;
        const catItems = Object.values(catalogueMatrix).flat();
        const srcItem  = catItems.find(ci => ci.id == item.id);
        if (srcItem && srcItem.stock_quantity !== null) stockAvail = parseInt(srcItem.stock_quantity);
        const overStock = stockAvail !== null && item.qty > stockAvail;
        const rowBg = overStock ? 'bg-amber-50 border-amber-300' : 'bg-stone-50 border-stone-200';
        row.className = 'flex justify-between items-center border p-2.5 rounded text-[10px] ' + rowBg;
        const stockWarn = overStock ? '<span class="text-amber-700 font-black text-[8px] block mt-0.5">⚠ Only ' + stockAvail + ' in stock</span>' : '';
        row.innerHTML = '<div class="max-w-[50%]"><span class="font-bold text-stone-900 block truncate">' + item.name + '</span><span class="text-[10px] text-stone-400 font-mono mt-0.5">$' + item.price.toFixed(2) + '</span>' + stockWarn + '</div>'
            + '<div class="flex items-center gap-1 font-mono">'
            + '<button type="button" onclick="adjustCartQty(' + item.id + ',\'' + item.tbl + '\',-1)" class="w-7 h-7 rounded border bg-stone-200 hover:bg-stone-300 font-black text-stone-800 text-sm leading-none flex items-center justify-center">−</button>'
            + '<span class="w-8 text-center font-bold text-stone-900">' + item.qty + '</span>'
            + '<button type="button" onclick="adjustCartQty(' + item.id + ',\'' + item.tbl + '\',1)" class="w-7 h-7 rounded border bg-stone-200 hover:bg-stone-300 font-black text-stone-800 text-sm leading-none flex items-center justify-center">+</button>'
            + '<span class="font-bold text-stone-900 w-14 text-right">$' + lp.toFixed(2) + '</span>'
            + '<button type="button" onclick="deleteRowItem(' + item.id + ',\'' + item.tbl + '\')" class="text-red-500 hover:text-red-700 font-bold text-base pl-1">&times;</button>'
            + '</div>';
        wrapper.appendChild(row);
    });
    let cal = getCurrentCalculations();
    document.getElementById('itemsVolumeBadge').textContent = currentCart.reduce((s, i) => s + parseFloat(i.qty), 0) + ' Units';
    document.getElementById('lblSubtotal').textContent = '$' + cal.suball.toFixed(2);
    if (cal.disc > 0) {
        document.getElementById('lblDiscount').textContent = '-$' + cal.disc.toFixed(2) + ' (' + cartDiscountPercent + '%)';
        document.getElementById('rowDiscount').style.display = 'flex';
    } else {
        document.getElementById('rowDiscount').style.display = 'none';
    }
    document.getElementById('lblTax').textContent   = '$' + cal.tax.toFixed(2);
    document.getElementById('lblTotal').textContent = '$' + cal.totalDue.toFixed(2);
    // Update sticky mobile bar
    const stickyT = document.getElementById('stickyTotal');
    const stickyC = document.getElementById('stickyItemCount');
    if (stickyT) stickyT.textContent = '$' + cal.totalDue.toFixed(2);
    if (stickyC) stickyC.textContent = currentCart.reduce((s,i) => s + parseFloat(i.qty), 0) + ' items';
}

// ── CASH KEYPAD ───────────────────────────────────────────────────────────────
function pressCashKeypadDigit(n) {
    cashCentsAccumulator = n === '00' ? cashCentsAccumulator * 100 : (cashCentsAccumulator * 10) + parseInt(n);
    refreshCashDisplay();
}
function injectQuickCashBillValue(d) { cashCentsAccumulator += d * 100; refreshCashDisplay(); }
function clearCashKeypadBuffer()     { cashCentsAccumulator = 0; refreshCashDisplay(); }
function refreshCashDisplay() {
    let f = cashCentsAccumulator / 100;
    document.getElementById('cashModalInput').value           = f.toFixed(2);
    document.getElementById('cashModalTenderedDisplay').textContent = '$' + f.toFixed(2);
}

// ── SETTLEMENT ────────────────────────────────────────────────────────────────
function generateUniqueReceiptNumber() {
    let n = new Date();
    return 'BCF-' + n.getFullYear() + String(n.getMonth()+1).padStart(2,'0') + String(n.getDate()).padStart(2,'0') + '-' + String(Math.floor(1000+Math.random()*9000));
}

function finalizeSettlement(method) {
    if (!currentCart.length) return;
    closeMobilePaymentModal();
    globalCurrentMethod    = method;
    globalCurrentReceiptNo = generateUniqueReceiptNumber();
    let cal = getCurrentCalculations();

    if (method === 'CASH') {
        cashCentsAccumulator = 0;
        document.getElementById('cashModalOwedSpan').textContent       = '$' + cal.totalDue.toFixed(2);
        document.getElementById('cashModalTenderedDisplay').textContent = '$0.00';
        document.getElementById('cashModalInput').value                = '0.00';
        document.getElementById('cashModalError').classList.add('hidden');
        document.getElementById('cashCalculatorModal').classList.remove('hidden');
    } else {
        // Mobile payment: populate receipt then show confirmation modal
        hydrateReceiptBlock();
        document.getElementById('mobileConfirmAmount').textContent = '$' + cal.totalDue.toFixed(2);
        document.getElementById('mobileConfirmMethod').textContent = 'Via ' + method;
        document.getElementById('mobileConfirmModal').classList.remove('hidden');
    }
}

function processCashSettlementVerification() {
    let cal     = getCurrentCalculations();
    let tendered = parseFloat(document.getElementById('cashModalInput').value);
    if (isNaN(tendered) || tendered < cal.totalDue) {
        document.getElementById('cashModalError').classList.remove('hidden'); return;
    }
    document.getElementById('cashCalculatorModal').classList.add('hidden');
    var changeAmt = tendered - cal.totalDue;
    var changeEl = document.getElementById('cashModalChangeSpan');
    changeEl.textContent = '$' + changeAmt.toFixed(2);
    changeEl.style.color = changeAmt < 0.005 ? '#86efac' : '#4ade80'; // exact change = soft green
    const rnoEl = document.getElementById('changeModalReceiptNo'); if(rnoEl) rnoEl.textContent = globalCurrentReceiptNo;
    hydrateReceiptBlock();
    document.getElementById('cashChangeOutputModal').classList.remove('hidden');
}

// ── PRINT HELPERS ─────────────────────────────────────────────────────────────
function hydrateReceiptBlock() {
    if (!currentCart.length) { console.warn('[POS] hydrateReceiptBlock called with empty cart'); return false; }
    let cal = getCurrentCalculations();
    document.getElementById('printMetaReceiptNo').textContent = 'RECEIPT: ' + globalCurrentReceiptNo;
    document.getElementById('printMetaTimestamp').textContent = 'TIME: ' + new Date().toLocaleString();
    const tbody = document.getElementById('printItemsListTableBody');
    tbody.innerHTML = '';
    let hasThrift = false;
    currentCart.forEach(item => {
        let lp = item.price * item.qty;
        if (item.category && item.category.toLowerCase().includes('thrift')) hasThrift = true;
        let tr = document.createElement('tr');
        tr.innerHTML = '<td style="padding:7px 0;text-align:left;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;word-break:break-word;">' + item.name + '</td>'
            + '<td style="padding:7px 0;text-align:center;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">' + item.qty + '</td>'
            + '<td style="padding:7px 0;text-align:right;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">$' + item.price.toFixed(2) + '</td>'
            + '<td style="padding:7px 0;text-align:right;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">$' + lp.toFixed(2) + '</td>';
        tbody.appendChild(tr);
    });
    document.getElementById('pSub').textContent = '$' + cal.suball.toFixed(2);
    document.getElementById('pTax').textContent = '$' + cal.tax.toFixed(2);
    document.getElementById('pTot').textContent = '$' + cal.totalDue.toFixed(2);
    document.getElementById('pMethod').textContent = globalCurrentMethod;
    if (cal.disc > 0) {
        document.getElementById('pDisc').textContent      = '-$' + cal.disc.toFixed(2) + ' (' + cartDiscountPercent + '%)';
        document.getElementById('pDiscRow').style.display = 'table-row';
    } else {
        document.getElementById('pDiscRow').style.display = 'none';
    }
    document.getElementById('pThriftDisclaimer').style.display = hasThrift ? 'block' : 'none';
    return true; // ← required: posPrintReceipt checks this
}

// ── PRINT + SAVE — fully decoupled so mobile redirect can't kill print job ───
// posPrintReceipt: prints AND auto-saves when dialog closes
function posPrintReceipt() {
    if (!hydrateReceiptBlock()) return;
    ['cashChangeOutputModal','mobileConfirmModal'].forEach(id => {
        document.getElementById(id)?.classList.add('hidden');
    });
    // Mark as printed; auto-commit when print dialog closes
    wasPrinted = true;
    document.getElementById('formWasPrinted').value = '1';
    window.onafterprint = function() {
        window.onafterprint = null;
        executeFormCommit();
    };
    window.print();
}

// posCommitAndClose: saves WITHOUT printing (skip print path)
function posCommitAndClose() {
    ['cashChangeOutputModal','mobileConfirmModal'].forEach(id => {
        document.getElementById(id)?.classList.add('hidden');
    });
    executeFormCommit();
}

function executeFormCommit() {
    let cal = getCurrentCalculations();
    document.getElementById('formReceiptNo').value    = globalCurrentReceiptNo;
    document.getElementById('formCartJson').value     = JSON.stringify(currentCart);
    document.getElementById('formSubtotal').value     = cal.suball.toFixed(2);
    document.getElementById('formDiscount').value     = cal.disc.toFixed(2);
    document.getElementById('formTax').value          = cal.tax.toFixed(2);
    document.getElementById('formTotal').value        = cal.totalDue.toFixed(2);
    document.getElementById('formMethod').value       = globalCurrentMethod;
    document.getElementById('formDiscountPct').value  = cartDiscountPercent;
    document.getElementById('formTaxExempt').value    = taxExemptMode ? '1' : '0';
    // formWasPrinted already set in posPrintReceipt; posCommitAndClose leaves it 0
    document.getElementById('ledgerHiddenSubmissionForm').submit();
}

// ── REPRINT LAST RECEIPT (from session data) ──────────────────────────────────
const lastReceiptData = window.POS.lastReceiptData;

function reprintLastReceipt() {
    try {
        const cart = JSON.parse(lastReceiptData.cart);
        document.getElementById('printMetaReceiptNo').textContent = 'RECEIPT: ' + lastReceiptData.number;
        document.getElementById('printMetaTimestamp').textContent = 'TIME: ' + lastReceiptData.saved_at;
        const tbody = document.getElementById('printItemsListTableBody');
        tbody.innerHTML = '';
        let hasThrift = false;
        cart.forEach(item => {
            let lp = parseFloat(item.price) * parseFloat(item.qty);
            if (item.category && item.category.toLowerCase().includes('thrift')) hasThrift = true;
            let tr = document.createElement('tr');
            tr.innerHTML = '<td style="padding:7px 0;text-align:left;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">' + item.name + '</td>'
                + '<td style="padding:7px 0;text-align:center;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">' + item.qty + '</td>'
                + '<td style="padding:7px 0;text-align:right;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">$' + parseFloat(item.price).toFixed(2) + '</td>'
                + '<td style="padding:7px 0;text-align:right;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">$' + lp.toFixed(2) + '</td>';
            tbody.appendChild(tr);
        });
        document.getElementById('pSub').textContent    = '$' + lastReceiptData.subtotal;
        document.getElementById('pTax').textContent    = '$' + lastReceiptData.tax;
        document.getElementById('pTot').textContent    = '$' + lastReceiptData.total;
        document.getElementById('pMethod').textContent = lastReceiptData.method;
        if (parseFloat(lastReceiptData.discount) > 0) {
            document.getElementById('pDisc').textContent      = '-$' + lastReceiptData.discount + ' (' + lastReceiptData.discount_pct + '%)';
            document.getElementById('pDiscRow').style.display = 'table-row';
        } else {
            document.getElementById('pDiscRow').style.display = 'none';
        }
        document.getElementById('pThriftDisclaimer').style.display = hasThrift ? 'block' : 'none';
        window.print();
    } catch(e) {
        alert('Reprint error: ' + e.message);
    }
}
// NOTE: reprintLastReceipt() is always defined here; its trigger button is gated
// server-side in pos.php, and lastReceiptData is null when there's no prior receipt.

// ── KEYBOARD SHORTCUTS ────────────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close topmost visible modal
        const modals = ['mobileConfirmModal','cashChangeOutputModal','cashCalculatorModal',
                        'mobilePaymentModal','customItemModal','discountModal','clearTicketModal'];
        for (const id of modals) {
            const el = document.getElementById(id);
            if (el && !el.classList.contains('hidden')) {
                el.classList.add('hidden');
                if (id === 'cashCalculatorModal') cashCentsAccumulator = 0;
                break;
            }
        }
    }
    // Ctrl/Cmd + P = print last receipt if exists
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        const banner = document.getElementById('reprintBanner');
        if (banner) { e.preventDefault(); reprintLastReceipt?.(); }
    }
});

// ── BARCODE SCANNING ─────────────────────────────────────────────────────────
// Hardware scanner: emulates keyboard — rapid keystrokes + Enter
// Camera scanner: ZXing via getUserMedia

let scanBuffer       = '';
let scanLastKeyTime  = 0;
let scanTimeout      = null;
const SCAN_GAP_MS    = 80; // scanner fires chars faster than human typing

// Listen for hardware scanner input anywhere on the page
// Ignores keystrokes when user is actively in a text input
document.addEventListener('keydown', function(e) {
    const tag = (e.target.tagName || '').toUpperCase();
    const isTyping = ['INPUT','TEXTAREA','SELECT'].includes(tag) &&
                     e.target.id !== 'manualSkuInput'; // allow manual sku field passthrough
    if (isTyping) return;

    const now = Date.now();
    if (e.key === 'Enter') {
        if (scanBuffer.length >= 3) {
            processScannedSKU(scanBuffer.trim());
        }
        scanBuffer = ''; clearTimeout(scanTimeout); return;
    }
    if (e.key.length === 1) {
        if (now - scanLastKeyTime > 500) scanBuffer = ''; // reset if long pause
        scanBuffer += e.key;
        scanLastKeyTime = now;
        clearTimeout(scanTimeout);
        scanTimeout = setTimeout(() => { scanBuffer = ''; }, 300);
    }
});

function processScannedSKU(sku) {
    if (!sku) return;
    const allItems = Object.values(catalogueMatrix).flat();
    const item = allItems.find(i => i.sku && i.sku.toUpperCase() === sku.toUpperCase());
    if (item) {
        if (item.stock_quantity !== null && item.stock_quantity <= 0) {
            showScanFeedback('⚠ Out of stock: ' + item.name, true);
            return;
        }
        addItemToTicketQueue(item.id, item.name, parseFloat(item.price), item.tbl, item.category);
        showScanFeedback('✓ Added: ' + item.name, false);
        // Close camera modal after successful scan
        closeCameraScanModal();
    } else {
        showScanFeedback('✗ SKU not found: ' + sku, true);
    }
}

function showScanFeedback(msg, isError) {
    // Update scan status in modal if open
    const statusEl = document.getElementById('scanStatus');
    if (statusEl) {
        statusEl.textContent = msg;
        statusEl.style.color = isError ? '#b91c1c' : '#059669';
    }
    // Also flash a brief toast on the main screen
    let toast = document.getElementById('scanToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'scanToast';
        toast.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:9999;padding:10px 20px;border-radius:8px;font-mono;font-size:13px;font-weight:bold;transition:opacity 0.3s;white-space:nowrap;';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.background = isError ? '#991b1b' : '#065f46';
    toast.style.color = '#fff';
    toast.style.opacity = '1';
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { toast.style.opacity = '0'; }, 2000);
}

// ── CAMERA SCANNER (ZXing) ───────────────────────────────────────────────────
let zxingReader   = null;
let scannerStream = null;

function openCameraScanModal() {
    document.getElementById('cameraScanModal').classList.remove('hidden');
    startCameraScanner();
}

function closeCameraScanModal() {
    document.getElementById('cameraScanModal').classList.add('hidden');
    stopCameraScanner();
    document.getElementById('scanStatus').textContent = 'Point camera at barcode…';
    document.getElementById('scanStatus').style.color = '';
}

function startCameraScanner() {
    const video = document.getElementById('scanVideo');
    const statusEl = document.getElementById('scanStatus');

    if (typeof ZXing === 'undefined') {
        statusEl.textContent = 'Barcode library loading…';
        setTimeout(startCameraScanner, 800);
        return;
    }

    try {
        zxingReader = new ZXing.BrowserMultiFormatReader();
        statusEl.textContent = 'Starting camera…';

        zxingReader.listVideoInputDevices().then(devices => {
            if (!devices || devices.length === 0) {
                statusEl.textContent = 'No camera found. Use manual entry below.';
                return;
            }
            // Prefer back camera on mobile
            const backCam = devices.find(d =>
                /back|rear|environment/i.test(d.label)) || devices[devices.length - 1];

            zxingReader.decodeFromVideoDevice(backCam.deviceId, 'scanVideo', (result, err) => {
                if (result) {
                    const scannedText = result.getText();
                    statusEl.textContent = '✓ Scanned: ' + scannedText;
                    statusEl.style.color = '#059669';
                    processScannedSKU(scannedText);
                    // Brief pause before next scan attempt
                    setTimeout(() => {
                        if (statusEl.textContent.startsWith('✓') || statusEl.textContent.startsWith('✗') || statusEl.textContent.startsWith('⚠')) {
                            statusEl.textContent = 'Point camera at barcode…';
                            statusEl.style.color = '';
                        }
                    }, 2500);
                }
                // err is normal when no barcode in frame — ignore
            });
            statusEl.textContent = 'Point camera at barcode…';
        }).catch(err => {
            statusEl.textContent = 'Camera error: ' + err.message;
        });
    } catch(e) {
        statusEl.textContent = 'Scanner error: ' + e.message;
    }
}

function stopCameraScanner() {
    try {
        if (zxingReader) {
            zxingReader.reset();
            zxingReader = null;
        }
    } catch(e) {}
}

// ── LIVE STATS REFRESH — update today's revenue/count every 60 seconds ────────
setInterval(function() {
    fetch('/admin/pos.php?ajax_today_stats=1')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var c = document.getElementById('todayCnt');
            var r = document.getElementById('todayRev');
            if (c) c.textContent = d.cnt;
            if (r) r.textContent = '$' + d.rev;
        })
        .catch(function() {}); // silent — don't disrupt POS on stat failure
}, 60000);

// ── LIVE CLOCK ───────────────────────────────────────────────────────────────
(function liveClock() {
    const el = document.getElementById('posClockDisplay');
    if (!el) return;
    function tick() {
        const n = new Date();
        el.textContent = n.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
    }
    tick();
    setInterval(tick, 10000);
})();

// ── INIT ──────────────────────────────────────────────────────────────────────
if (allCategories.length > 0) changeActiveCategoryTab(allCategories[0]);
