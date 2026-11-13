// admin/assets/inventory-counts.js
// Inventory Counts screen (admin/views/inventory_update.php): save counts on blur + product label printing.
// Moved out of the view on Nov 5, 2026 (02 Navigation & UX / CSP): no inline handlers; everything is wired here.
(function () {
    'use strict';

    var root = document.getElementById('invCounts');
    if (!root) return;
    var CSRF_TOKEN = root.getAttribute('data-csrf') || '';
    var LABEL_CSRF = CSRF_TOKEN;
    var $ = function (id) { return document.getElementById(id); };

    // ── COUNT INPUTS ─────────────────────────────────────────────────────────
    async function saveField(input) {
        const field    = input.dataset.field;
        const id       = input.dataset.id;
        const original = input.dataset.original;
        const value    = input.value.trim();

        if (parseFloat(value) === parseFloat(original)) return;   // unchanged

        input.disabled = true;
        input.style.borderColor = '#a8a29e';

        try {
            const res = await fetch('/admin/handlers/update_inventory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: CSRF_TOKEN, id, field, value }),
            });
            const data = await res.json();

            if (data.ok) {
                input.dataset.original  = value;
                input.style.borderColor = '#22c55e';

                if (field === 'stock_quantity') {
                    const newQty  = parseFloat(value);
                    const row     = $('inv-row-' + id);
                    const display = $('qty-display-' + id);
                    const badge   = $('inv-badge-' + id);
                    const minVal  = row ? parseFloat(row.dataset.min) : 0;
                    const isLow   = minVal > 0 && newQty <= minVal;

                    if (display) {
                        display.textContent = newQty;
                        display.classList.toggle('text-red-600', isLow);
                        display.classList.toggle('text-stone-700', !isLow);
                    }
                    if (badge) badge.classList.toggle('hidden', !isLow);
                    if (row)   row.classList.toggle('bg-red-50/60', isLow);
                }
                showInvToast('✓ Saved', false);
            } else {
                input.style.borderColor = '#ef4444';
                showInvToast('❌ ' + (data.message || 'Save failed'), true);
            }
        } catch (err) {
            input.style.borderColor = '#ef4444';
            showInvToast('❌ Network error — change not saved.', true);
        } finally {
            input.disabled = false;
            setTimeout(() => { input.style.borderColor = ''; }, 2500);
        }
    }

    document.querySelectorAll('.inv-input').forEach(input => {
        input.addEventListener('focus', () => { input.dataset.original = input.value; });
        input.addEventListener('blur', () => saveField(input));
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        });
    });

    // +/- steppers (phone): change by 1 and save
    root.addEventListener('click', e => {
        const step = e.target.closest('[data-step]');
        if (!step) return;
        const input = $('inv-input-' + step.getAttribute('data-for'));
        if (!input || input.disabled) return;
        input.dataset.original = input.value;
        const next = Math.max(0, (parseFloat(input.value) || 0) + parseFloat(step.getAttribute('data-step')));
        input.value = Math.round(next * 100) / 100;
        saveField(input);
    });

    function showInvToast(msg, isError) {
        let toast = $('invToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id        = 'invToast';
            toast.setAttribute('role', 'status');
            toast.className = 'fixed bottom-24 md:bottom-5 right-5 z-50 px-4 py-2 rounded-lg shadow-lg font-mono text-xs font-bold transition-opacity duration-300';
            document.body.appendChild(toast);
        }
        toast.textContent       = msg;
        toast.style.background  = isError ? '#fef2f2' : '#f0fdf4';
        toast.style.color       = isError ? '#991b1b' : '#166534';
        toast.style.border      = isError ? '1px solid #fecaca' : '1px solid #bbf7d0';
        toast.style.opacity     = '1';
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, isError ? 4000 : 1500);
    }

    // ── LABEL PRINT MODAL ────────────────────────────────────────────────────
    let labelItemId   = null;
    let labelItemData = null;

    // Ingredients, heaviest first (food labels list ingredients by weight)
    let labelIngs = [], labelAllIngs = [], labelIngDirty = false, labelIngOrderSet = true;
    let labelAllergenList = {}, labelIngOpen = null;   // labelIngOpen = id of the ingredient whose details are open

    async function openLabelModal(itemId, itemName) {
        labelItemId = itemId;
        $('labelItemName').textContent = itemName;
        $('labelPreviewName').textContent = itemName;
        $('labelModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        $('labelCopies').value  = '1';
        $('labelStartPos').value = '1';
        $('labelBestBy').value  = '';
        $('labelPreviewBestBy').textContent = '';
        $('labelPreviewIngredients').textContent = 'Loading...';
        labelIngs = []; labelAllIngs = []; labelIngDirty = false; labelIngOrderSet = true; labelIngOpen = null;
        $('labelPreviewContains').textContent = '';
        $('labelAllergenWarn').classList.add('hidden');
        $('labelIngList').replaceChildren();
        $('labelIngStatus').textContent = '';

        try {
            const res  = await fetch('/admin/handlers/get_label_data.php?item_id=' + itemId);
            const data = await res.json();
            if (data.ok) {
                labelItemData = data;
                $('labelPreviewSize').textContent = data.size_label || '—';
                $('labelPreviewMadeBy').textContent = data.made_by || '';
                labelIngs     = (data.ingredient_rows || []).map(r => ({ id: r.id, name: r.name, allergens: r.allergens, sub_ingredients: r.sub_ingredients || '' }));
                labelAllIngs  = data.all_ingredients || [];
                labelAllergenList = data.allergen_list || {};
                labelIngDirty = false;
                labelIngOrderSet = data.order_set !== false;
                renderLabelIngs();
            } else {
                $('labelPreviewIngredients').textContent = itemName;
            }
        } catch (err) {
            $('labelPreviewIngredients').textContent = itemName;
        }
    }

    // "milk,tree_nuts:pecans" -> {milk:'', tree_nuts:'pecans'}  (same rules as bcrf_allergens_parse)
    function parseAllergens(raw) {
        const out = {};
        String(raw || '').split(',').forEach(p => {
            p = p.trim(); if (!p) return;
            const i = p.indexOf(':');
            const k = (i < 0 ? p : p.slice(0, i)).trim().toLowerCase().replace(/[ -]/g, '_');
            if (labelAllergenList[k]) out[k] = i < 0 ? '' : p.slice(i + 1).trim();
        });
        return out;
    }

    // Same output as bcrf_label_statement() in admin/includes/label_ingredients.php
    function labelStatement(rows) {
        const keys = Object.keys(labelAllergenList), found = [], unchecked = [], needs = [];
        const add = (k, label) => { if (!found.some(f => f.key === k)) found.push({ key: k, label }); };
        const parts = rows.map(r => {
            let sub = String(r.sub_ingredients || '').replace(/^\s*ingredients\s*:\s*/i, '').replace(/\s+/g, ' ').replace(/[\s.]+$/, '');
            if (r.allergens === null || r.allergens === undefined) unchecked.push(r.name);
            else Object.entries(parseAllergens(r.allergens)).forEach(([k, d]) => {
                const [label, named] = labelAllergenList[k];
                if (!named) return add(k, label);
                if (!d) { needs.push(r.name); return add(k + ':', label); }
                d.split(/\s*(?:&|\/|\band\b)\s*/i).forEach(one => {
                    one = one.trim(); if (!one) return;
                    add(k + ':' + one.toLowerCase(), one.replace(/\w\S*/g, w => w[0].toUpperCase() + w.slice(1).toLowerCase()));
                });
            });
            return r.name + (sub ? ' (' + sub + ')' : '');
        });
        found.sort((a, b) => (keys.indexOf(a.key.split(':')[0]) - keys.indexOf(b.key.split(':')[0])) || (a.key < b.key ? -1 : a.key > b.key ? 1 : 0));
        // Sulfites (not one of the nine) print as their own sentence after the Contains line.
        const sulfites = rows.some(r => /(^|,)\s*sulfites\s*(,|$)/i.test(String(r.allergens || '')));
        return { ingredients: parts.join(', '), contains: found.map(f => f.label).join(', '), unchecked, needs, sulfites };
    }

    function ingSummary(ing) {
        if (ing.allergens === null || ing.allergens === undefined) return { text: 'Allergens not checked', warn: true };
        const a = parseAllergens(ing.allergens), names = Object.entries(a).map(([k, d]) => d || labelAllergenList[k][0]);
        let t = names.length ? 'Allergens: ' + names.join(', ') : 'No major allergens';
        if (ing.sub_ingredients) t += ' · has sub-ingredients';
        return { text: t, warn: false };
    }

    function buildIngDetails(ing) {
        const box = document.createElement('div');
        box.className = 'mt-2 mb-1 ml-7 p-2 border rounded bg-white space-y-2';
        const cur = parseAllergens(ing.allergens);
        const help = document.createElement('p');
        help.className = 'text-[11px] text-stone-500';
        help.textContent = 'Tick every major allergen this ingredient contains (check the bought product\'s label). Saving with none ticked records "no major allergens".';
        box.append(help);
        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-2 gap-x-3 gap-y-1';
        Object.entries(labelAllergenList).forEach(([k, [label, named]]) => {
            const wrap = document.createElement('div');
            const lab = document.createElement('label');
            lab.className = 'flex items-center gap-2 py-1 cursor-pointer';
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = k; cb.className = 'ing-allergen w-4 h-4'; cb.checked = k in cur;
            const sp = document.createElement('span'); sp.textContent = label;
            lab.append(cb, sp); wrap.append(lab);
            if (named) {
                const inp = document.createElement('input');
                inp.type = 'text'; inp.className = 'ing-allergen-detail w-full p-1.5 border rounded bg-stone-50 mb-1';
                inp.dataset.key = k; inp.value = cur[k] || '';
                inp.placeholder = { tree_nuts: 'Which nut? e.g. pecans', fish: 'Which fish? e.g. cod', shellfish: 'Which shellfish? e.g. shrimp' }[k];
                inp.classList.toggle('hidden', !cb.checked);
                cb.addEventListener('change', () => inp.classList.toggle('hidden', !cb.checked));
                wrap.append(inp);
            }
            grid.append(wrap);
        });
        box.append(grid);
        const subLab = document.createElement('label');
        subLab.className = 'block font-bold text-stone-600 mt-1';
        subLab.textContent = 'Sub-ingredients (only for bought ingredients with their own ingredient list)';
        const sub = document.createElement('textarea');
        sub.rows = 2; sub.className = 'ing-sub w-full p-2 border rounded bg-stone-50';
        sub.placeholder = 'Copy it from the package, e.g. sugar, chocolate, cocoa butter, soy lecithin, vanilla';
        sub.value = ing.sub_ingredients || '';
        const row = document.createElement('div');
        row.className = 'flex items-center gap-3';
        const save = document.createElement('button');
        save.type = 'button'; save.textContent = 'Save ingredient';
        save.className = 'bg-stone-900 text-white font-bold px-3 py-2 rounded hover:bg-stone-700';
        const st = document.createElement('span');
        st.className = 'text-[11px] text-stone-600'; st.setAttribute('role', 'status');
        save.addEventListener('click', () => saveIngDetails(ing.id, box, st, save));
        row.append(save, st);
        box.append(subLab, sub, row);
        return box;
    }

    async function saveIngDetails(id, box, st, btn) {
        const body = new URLSearchParams({ csrf_token: LABEL_CSRF, ingredient_id: id, sub_ingredients: box.querySelector('.ing-sub').value });
        box.querySelectorAll('.ing-allergen:checked').forEach(cb => body.append('allergens[]', cb.value));
        box.querySelectorAll('.ing-allergen-detail').forEach(inp => body.append('allergen_detail[' + inp.dataset.key + ']', inp.value));
        btn.disabled = true; st.textContent = 'Saving…';
        try {
            const res  = await fetch('/admin/handlers/ingredient_label_info_save.php', { method: 'POST', body });
            const data = await res.json();
            btn.disabled = false;
            if (!data.ok) { st.textContent = data.message || 'Could not save.'; return; }
            [labelIngs, labelAllIngs].forEach(arr => arr.filter(x => x.id === id).forEach(x => { x.allergens = data.allergens; x.sub_ingredients = data.sub_ingredients; }));
            labelIngOpen = null;
            renderLabelIngs();
        } catch (err) {
            btn.disabled = false; st.textContent = 'Could not save — check your connection.';
        }
    }

    function renderLabelIngs() {
        const list = $('labelIngList');
        list.replaceChildren();
        labelIngs.forEach((ing, i) => {
            const row = document.createElement('li');
            row.className = 'flex items-center gap-2 py-1.5' + (labelIngOpen === ing.id ? '' : ' border-b border-stone-200 last:border-0');
            const num = document.createElement('span');
            num.className = 'w-5 text-right font-mono text-stone-400';
            num.textContent = (i + 1) + '.';
            const name = document.createElement('span');
            name.className = 'flex-1 min-w-0';
            const nm = document.createElement('span');
            nm.className = 'block font-medium text-stone-800';
            nm.textContent = ing.name;
            const sm = ingSummary(ing), smEl = document.createElement('span');
            smEl.className = 'block text-[10px] ' + (sm.warn ? 'text-amber-700 font-semibold' : 'text-stone-500');
            smEl.textContent = sm.text;
            name.append(nm, smEl);
            const det = document.createElement('button');
            det.type = 'button'; det.textContent = labelIngOpen === ing.id ? 'Close' : 'Details';
            det.setAttribute('aria-expanded', labelIngOpen === ing.id ? 'true' : 'false');
            det.className = 'h-9 px-2 border rounded bg-white text-stone-700 hover:bg-stone-100 text-[11px] font-bold';
            det.addEventListener('click', () => { labelIngOpen = labelIngOpen === ing.id ? null : ing.id; renderLabelIngs(); });
            row.append(num, name, det);
            [['▲', 'Move up', () => moveLabelIng(i, -1), i === 0],
             ['▼', 'Move down', () => moveLabelIng(i, 1), i === labelIngs.length - 1],
             ['✕', 'Remove', () => removeLabelIng(i), false]].forEach(([txt, title, fn, off]) => {
                const b = document.createElement('button');
                b.type = 'button'; b.textContent = txt; b.title = title; b.setAttribute('aria-label', title + ': ' + ing.name);
                b.className = 'w-9 h-9 border rounded bg-white text-stone-700 hover:bg-stone-100 disabled:opacity-30';
                b.disabled = off;
                b.addEventListener('click', fn);
                row.append(b);
            });
            if (labelIngOpen === ing.id) {
                const li = document.createElement('li');
                li.className = 'border-b border-stone-200';
                li.append(buildIngDetails(ing));
                list.append(row, li);
            } else {
                list.append(row);
            }
        });
        $('labelIngEmpty').classList.toggle('hidden', labelIngs.length > 0);

        // Picker: only ingredients not already on the list
        const sel = $('labelIngAdd');
        sel.replaceChildren(new Option('Add an ingredient…', ''));
        const used = new Set(labelIngs.map(x => x.id));
        labelAllIngs.filter(x => !used.has(x.id)).forEach(x => sel.append(new Option(x.name, x.id)));

        // Warning + save state
        $('labelIngWarn').classList.toggle('hidden', labelIngOrderSet || labelIngDirty || labelIngs.length < 2);
        $('labelIngSave').disabled = !labelIngDirty;
        $('labelIngStatus').textContent = labelIngDirty ? 'Not saved yet' : '';

        // Preview (ingredient statement with sub-ingredients + Contains line)
        const stmt = labelStatement(labelIngs);
        $('labelPreviewIngredients').textContent = labelIngs.length ? stmt.ingredients : (labelItemData ? labelItemData.name : '—');
        $('labelPreviewContains').textContent = (stmt.contains ? 'Contains: ' + stmt.contains + (stmt.sulfites ? '. ' : '') : '') + (stmt.sulfites ? 'Contains sulfites.' : '');
        const al = $('labelAllergenWarn');
        const msgs = [];
        if (stmt.unchecked.length) msgs.push('Allergens not checked yet for: ' + stmt.unchecked.join(', ') + '. Tap Details on each one.');
        if (stmt.needs.length) msgs.push('Name the specific nut, fish or shellfish for: ' + stmt.needs.join(', ') + '.');
        al.textContent = msgs.join(' ');
        al.classList.toggle('hidden', !msgs.length);
    }

    function moveLabelIng(i, d) {
        const j = i + d;
        if (j < 0 || j >= labelIngs.length) return;
        [labelIngs[i], labelIngs[j]] = [labelIngs[j], labelIngs[i]];
        labelIngDirty = true; renderLabelIngs();
    }
    function removeLabelIng(i) { labelIngs.splice(i, 1); labelIngDirty = true; renderLabelIngs(); }
    function addLabelIng() {
        const id = parseInt($('labelIngAdd').value, 10);
        const found = labelAllIngs.find(x => x.id === id);
        if (!found) return;
        labelIngs.push({ id: found.id, name: found.name, allergens: found.allergens, sub_ingredients: found.sub_ingredients || '' });
        labelIngDirty = true; renderLabelIngs();
    }

    async function saveLabelIngs() {
        if (!labelItemId) return;
        const status = $('labelIngStatus');
        const btn = $('labelIngSave');
        btn.disabled = true; status.textContent = 'Saving…';
        const body = new URLSearchParams({ csrf_token: LABEL_CSRF, item_id: labelItemId });
        labelIngs.forEach(x => body.append('ingredient_ids[]', x.id));
        try {
            const res  = await fetch('/admin/handlers/label_ingredients_save.php', { method: 'POST', body });
            const data = await res.json();
            if (data.ok) {
                labelIngDirty = false; labelIngOrderSet = true;
                renderLabelIngs();
                status.textContent = 'Saved ✓';
            } else {
                status.textContent = data.message || 'Could not save.';
                btn.disabled = false;
            }
        } catch (err) {
            status.textContent = 'Could not save — check your connection.';
            btn.disabled = false;
        }
    }

    function closeLabelModal() {
        $('labelModal').classList.add('hidden');
        document.body.style.overflow = '';
        labelItemId = null;
        labelItemData = null;
    }

    function updateLabelPreview() {
        const bestBy = $('labelBestBy').value;
        if (bestBy) {
            const d = new Date(bestBy + 'T12:00:00');
            $('labelPreviewBestBy').textContent = 'Best By: ' + d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        } else {
            $('labelPreviewBestBy').textContent = '';
        }
    }

    function printLabel() {
        if (!labelItemId) return;
        if (labelIngDirty) {
            $('labelIngStatus').textContent = 'Save the ingredient order before printing.';
            return;
        }
        const params = new URLSearchParams({ item_id: labelItemId, copies: $('labelCopies').value, start_pos: $('labelStartPos').value });
        const bestBy = $('labelBestBy').value;
        if (bestBy) params.set('best_by', bestBy);
        window.open('/admin/print_label.php?' + params.toString(), '_blank');
        closeLabelModal();
    }

    // ── WIRING (replaces the old inline onclick/onchange attributes) ─────────
    root.addEventListener('click', e => {
        const b = e.target.closest('[data-label-item]');
        if (b) openLabelModal(parseInt(b.getAttribute('data-label-item'), 10), b.getAttribute('data-label-name') || '');
    });
    const modal = $('labelModal');
    if (modal) {
        modal.addEventListener('click', e => {
            if (e.target === modal || e.target.closest('[data-label-close]')) closeLabelModal();
        });
        $('labelBestBy').addEventListener('change', updateLabelPreview);
        $('labelIngAddBtn').addEventListener('click', addLabelIng);
        $('labelIngSave').addEventListener('click', saveLabelIngs);
        $('labelPrintBtn').addEventListener('click', printLabel);
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeLabelModal();
        });
    }
}());
