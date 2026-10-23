// admin/assets/schema.js
// Trailing client logic extracted verbatim from admin/views/schema.php (Stage 1).
// PHP-injected data arrives via the inline window.SCHEMA bootstrap in schema.php:
//   window.SCHEMA = { csrf, ingredientRowCount, ingredientOptions, riOptions, unitOptions }

    // ── STARTUP PROBE ─────────────────────────────────────────────────────────
    console.log('[BCRF] schema.php script block executing');
    window._bcrf_usda_ready = false;
    function filterRecordList(val) {
        const q = val.toLowerCase().trim();
        document.querySelectorAll('.record-row').forEach(row => {
            row.style.display = row.dataset.name.includes(q) ? '' : 'none';
        });
    }

    function sortRecordList(mode) {
        if (!mode) return;
        const list = document.getElementById('recordList');
        if (!list) return;
        const rows = Array.from(list.querySelectorAll('.record-row'));
        rows.sort((a, b) => {
            if (mode === 'name-asc')  return a.dataset.name.localeCompare(b.dataset.name);
            if (mode === 'name-desc') return b.dataset.name.localeCompare(a.dataset.name);
            const da = parseInt(a.dataset.date || '0', 10), db = parseInt(b.dataset.date || '0', 10);
            return mode === 'date-asc' ? da - db : db - da;
        });
        rows.forEach(r => list.appendChild(r));
    }

    // Fix 10: dirty form — warn before navigating away with unsaved changes
    const schemaForm = document.getElementById('schemaEditForm');
    if (schemaForm) {
        schemaForm.addEventListener('input', () => schemaForm.dataset.dirty = 'true');
        schemaForm.addEventListener('change', () => schemaForm.dataset.dirty = 'true');
        schemaForm.addEventListener('submit', () => schemaForm.dataset.dirty = 'false');

        window.addEventListener('beforeunload', e => {
            if (schemaForm.dataset.dirty === 'true') {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    // Fix 11: ingredient rows built with createElement instead of innerHTML
    // Options are seeded from PHP-encoded JSON arrays to avoid XSS via ingredient names
    let ingredientRowCount = window.SCHEMA.ingredientRowCount;

    const ingredientOptions = window.SCHEMA.ingredientOptions;

    // Combined ingredient+recipe options for the autocomplete (recipes tagged with a book icon)
    const RI_OPTIONS = window.SCHEMA.riOptions;
    const unitOptions       = window.SCHEMA.unitOptions;

    function buildSelect(name, options) {
        const sel = document.createElement('select');
        sel.name      = name;
        sel.className = 'p-1 border bg-white rounded flex-1';
        options.forEach(opt => {
            const o = document.createElement('option');
            o.value       = opt.id;
            o.textContent = opt.label; // textContent is XSS-safe
            sel.appendChild(o);
        });
        return sel;
    }

    // ── ADD NEW CATEGORY (shared by recipes and blog_posts) ─────────────────────
    const ADD_CAT_CSRF = window.SCHEMA.csrf;

    function toggleAddCategoryForm(context) {
        const form = document.getElementById('addCategoryForm_' + context);
        form.classList.toggle('hidden');
        if (!form.classList.contains('hidden')) {
            document.getElementById('newCategoryInput_' + context).focus();
        }
    }

    async function submitNewCategory(context) {
        const input  = document.getElementById('newCategoryInput_' + context);
        const name   = input.value.trim();
        if (!name) return;

        try {
            const res  = await fetch('/admin/handlers/add_category.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: ADD_CAT_CSRF, category_name: name, context: context }),
            });

            // Read body as text first so we can diagnose non-JSON responses
            const rawText = await res.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                // Server returned non-JSON — show first 200 chars to diagnose
                console.error('add_category non-JSON response:', rawText.substring(0, 200));
                sysAlert('Server error saving category. Check the browser console for details.', 'Server Error', '⚠️');
                return;
            }

            if (data.ok) {
                // Append new checkbox to both lists (it might be open on either context)
                ['recipes', 'blog'].forEach(ctx => {
                    const list = document.getElementById('categoriesCheckboxList_' + ctx);
                    if (!list) return;
                    const label = document.createElement('label');
                    label.className = 'flex items-center space-x-2 text-xxs font-medium cursor-pointer';
                    const cb = document.createElement('input');
                    cb.type    = 'checkbox';
                    cb.name    = 'categories_matrix[]';
                    cb.value   = data.id;
                    cb.checked = true;
                    const span = document.createElement('span');
                    span.textContent = data.name;
                    label.append(cb, span);
                    list.appendChild(label);
                });

                input.value = '';
                toggleAddCategoryForm(context);
            } else {
                sysAlert('Could not save category: ' + (data.message || 'Unknown error'), 'Save Failed', '⚠️');
            }
        } catch (err) {
            console.error('add_category fetch error:', err);
            sysAlert('Network error saving category: ' + err.message, 'Network Error', '⚠️');
        }
    }

    // ── INGREDIENT / SUB-RECIPE AUTOCOMPLETE ─────────────────────────────────
function riFilter(q){
    q = (q||'').trim().toLowerCase();
    const out = [];
    for (const o of RI_OPTIONS){
        if (!q || o.label.toLowerCase().includes(q)){ out.push(o); if(out.length>=60) break; }
    }
    return out;
}
function initRiCombo(combo){
    if (combo.dataset.riReady === '1') return;
    combo.dataset.riReady = '1';
    const hidden = combo.querySelector('input[type=hidden]');
    const search = combo.querySelector('.ri-search');
    const dd     = combo.querySelector('.ri-dropdown');
    let items = [], active = -1;
    function close(){ dd.style.display='none'; active=-1; }
    function render(q){
        items = riFilter(q); active = -1; dd.innerHTML='';
        if(!items.length){ const e=document.createElement('div'); e.className='ri-empty'; e.textContent='No match — pick from the list'; dd.appendChild(e); return; }
        items.forEach((o)=>{
            const d=document.createElement('div');
            d.className='ri-item'+(o.ref.indexOf('rec:')===0?' ri-item-recipe':'');
            d.textContent=o.label;
            d.addEventListener('mousedown', ev=>{ ev.preventDefault(); pick(o); });
            dd.appendChild(d);
        });
    }
    function open(){ render(search.value); dd.style.display='block'; }
    function pick(o){ hidden.value=o.ref; search.value=o.label; close(); }
    function hi(){ [...dd.children].forEach((c,i)=>c.classList.toggle('ri-active',i===active)); }
    search.addEventListener('focus', open);
    search.addEventListener('input', ()=>{ hidden.value=''; open(); });
    search.addEventListener('keydown', e=>{
        if(dd.style.display==='none') return;
        if(e.key==='ArrowDown'){ e.preventDefault(); active=Math.min(active+1,items.length-1); hi(); }
        else if(e.key==='ArrowUp'){ e.preventDefault(); active=Math.max(active-1,0); hi(); }
        else if(e.key==='Enter'){ if(active>=0&&items[active]){ e.preventDefault(); pick(items[active]); } }
        else if(e.key==='Escape'){ close(); }
    });
    search.addEventListener('blur', ()=>{ setTimeout(()=>{ close(); if(!hidden.value) search.value=''; }, 160); });
}
function makeRiCombo(idx, ref, label){
    const wrap=document.createElement('div'); wrap.className='ri-combo relative flex-1';
    const h=document.createElement('input'); h.type='hidden'; h.name=`recipe_ingredients_block[${idx}][ref]`; h.value=ref||'';
    const s=document.createElement('input'); s.type='text'; s.className='ri-search p-1 border bg-white rounded w-full'; s.autocomplete='off'; s.placeholder='Type an ingredient or recipe…'; s.value=label||'';
    const d=document.createElement('div'); d.className='ri-dropdown'; d.style.display='none';
    wrap.append(h,s,d);
    return wrap;
}
(function(){ const go=()=>document.querySelectorAll('.ri-combo').forEach(initRiCombo); if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',go); else go(); })();

    function addNewIngredientRow() {
        const container = document.getElementById('ingredientsDynamicWrapper');
        if (!container) return;

        const rowNum  = container.querySelectorAll('.dynamic-ingredient-row').length + 1;
        const n       = ingredientRowCount;

        const row = document.createElement('div');
        row.className = 'dynamic-ingredient-row text-xs space-y-1.5 pb-2 border-b border-stone-200';

        // Top line: row number + ingredient + qty + unit + remove
        const topLine = document.createElement('div');
        topLine.className = 'flex items-center space-x-2';

        const numLabel = document.createElement('span');
        numLabel.className   = 'text-[10px] font-black text-stone-400 w-5 shrink-0 text-right';
        numLabel.textContent = rowNum + '.';

        const ingSelect = makeRiCombo(n, '', '');

        const qtyInput = document.createElement('input');
        qtyInput.type        = 'text';
        qtyInput.inputMode   = 'decimal';
        qtyInput.name        = `recipe_ingredients_block[${n}][quantity]`;
        qtyInput.placeholder = 'e.g. 1/4';
        qtyInput.className   = 'w-20 p-1 border bg-white rounded font-mono text-center';
        qtyInput.addEventListener('blur', function() {
            this.value = parseFractionJS(this.value);
        });

        const unitSelect = buildSelect(`recipe_ingredients_block[${n}][unit_id]`, unitOptions);
        unitSelect.className = 'p-1 border bg-white rounded w-28';

        const removeBtn = document.createElement('button');
        removeBtn.type        = 'button';
        removeBtn.textContent = '✕';
        removeBtn.className   = 'text-red-600 font-bold px-1 hover:text-red-900 shrink-0';
        removeBtn.onclick     = () => row.remove();

        topLine.append(numLabel, ingSelect, qtyInput, unitSelect, removeBtn);

        // Notes line
        const notesLine = document.createElement('div');
        notesLine.className = 'flex items-center space-x-2 pl-7';

        const notesInput = document.createElement('input');
        notesInput.type        = 'text';
        notesInput.maxLength   = 120;
        notesInput.name        = `recipe_ingredients_block[${n}][notes]`;
        notesInput.placeholder = 'Preparation note e.g. finely chopped (optional)';
        notesInput.className   = 'w-full p-1 border bg-white rounded text-stone-500 italic text-[11px]';
        notesLine.appendChild(notesInput);

        row.append(topLine, notesLine);
        container.appendChild(row);
        initRiCombo(ingSelect);
        ingredientRowCount++;
    }

    // ── BLOG POST PREVIEW ────────────────────────────────────────────────────────
    function openBlogPreview() {
        const titleInput = document.querySelector('[name="fields[title]"]');
        const title = titleInput ? titleInput.value.trim() : '(No title)';

        let bodyHtml = '';
        for (const name of ['content', 'article', 'body', 'post_content', 'description']) {
            const hidden = document.getElementById('hidden_' + name);
            if (hidden && hidden.value.trim() && hidden.value.trim() !== '<p><br></p>') {
                bodyHtml = hidden.value;
                break;
            }
        }
        if (!bodyHtml) bodyHtml = '<p style="color:#999;font-style:italic;">No content written yet.</p>';

        const safeTitle = title.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const preview = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview: ${safeTitle}</title>
    <link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:ital,wght@0,400;0,600;1,400&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #e8d5b0; font-family: Lora, Georgia, serif; color: #3b2010; }
        .preview-bar { background: #3b2010; padding: 12px 24px; display: flex; align-items: center; gap: 12px; }
        .preview-badge { font-size: 10px; font-family: monospace; font-weight: bold; text-transform: uppercase;
            letter-spacing: .1em; background: #e8b84b; color: #3b2010; border-radius: 4px; padding: 3px 8px; }
        .preview-note { font-size: 11px; color: rgba(245,234,214,.5); font-family: monospace; }
        .article-wrap { max-width: 760px; margin: 36px auto; padding: 0 24px 60px; }
        h1.post-title { font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.6rem,4vw,2.4rem);
            color: #3b2010; line-height: 1.25; margin: 0 0 8px; }
        .post-meta { font-size: .8rem; color: #8b5c2a; margin-bottom: 28px; padding-bottom: 16px;
            border-bottom: 2px solid #c9923a; font-family: monospace; }
        .post-body { font-size: 1.05rem; line-height: 1.85; }
        .post-body h1,.post-body h2,.post-body h3 { font-family: 'Playfair Display', Georgia, serif; }
        .post-body a { color: #4a6741; }
        .post-body img { max-width: 100%; border-radius: 8px; border: 2px solid #c9923a; }
        .post-body blockquote { border-left: 4px solid #c9923a; margin: 20px 0; padding: 12px 20px;
            background: #f5ead6; border-radius: 0 6px 6px 0; font-style: italic; color: #5c3317; }
    </style>
</head>
<body>
    <div class="preview-bar">
        <span class="preview-badge">Preview</span>
        <span class="preview-note">Draft preview — not the live page</span>
    </div>
    <div class="article-wrap">
        <h1 class="post-title">${safeTitle}</h1>
        <div class="post-meta">Bat Cave Road Farm &bull; Journal</div>
        <div class="post-body">${bodyHtml}</div>
    </div>
</body>
</html>`;

        document.getElementById('blogPreviewTitle').textContent = title || '(No title)';
        document.getElementById('blogPreviewFrame').srcdoc      = preview;
        document.getElementById('blogPreviewModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeBlogPreview() {
        document.getElementById('blogPreviewModal').classList.add('hidden');
        document.getElementById('blogPreviewFrame').srcdoc = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !document.getElementById('blogPreviewModal')?.classList.contains('hidden')) {
            closeBlogPreview();
        }
    });

    document.getElementById('blogPreviewModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeBlogPreview();
    });


// ── USDA INGREDIENT NUTRITION LOOKUP ─────────────────────────────────────────
async function lookupUSDA(ingId) {
    console.log('[USDA] lookupUSDA called, ingId:', ingId);
    const btn          = document.getElementById('usdaLookupBtn_' + ingId);
    const resultsPanel = document.getElementById('usdaResultsPanel_' + ingId);
    const resultsList  = document.getElementById('usdaResultsList_'  + ingId);
    const saveMsg      = document.getElementById('usdaSaveMsg_' + ingId);

    // Read ingredient name directly from the form field — no encoding issues
    const nameInput = document.querySelector('input[name="fields[singular_name]"]');
    const ingName   = nameInput ? nameInput.value.trim() : '';

    if (!ingName) {
        if (saveMsg) {
            saveMsg.textContent = '⚠️ Enter a singular name for this ingredient first.';
            saveMsg.className   = 'mx-4 mb-3 p-2 rounded text-xs font-mono bg-amber-50 text-amber-700 border border-amber-200';
            saveMsg.classList.remove('hidden');
        }
        return;
    }

    if (btn) { btn.disabled = true; btn.textContent = '⏳ Searching…'; }
    if (saveMsg) saveMsg.className = 'hidden';

    try {
        const res  = await fetch('/admin/handlers/lookup_ingredient_nutrition.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: window.SCHEMA.csrf,
                action:     'search',
                query:      ingName
            })
        });
        const data = await res.json();

        if (!data.success) {
            if (saveMsg) { saveMsg.textContent = '⚠️ ' + data.message; saveMsg.className = 'mx-4 mb-3 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200'; }
            return;
        }

        // Render results
        resultsList.innerHTML = '';
        data.results.forEach(food => {
            const row = document.createElement('div');
            row.className = 'flex items-start justify-between gap-2 bg-white border rounded p-2 text-[10px]';

            const info = document.createElement('div');
            const cals = food.nutrients.calories_per_100g;
            info.innerHTML = `<span class="font-bold text-stone-800">${food.description}</span>
                <span class="ml-1 text-stone-400">${food.data_type}</span>
                ${cals !== null ? `<span class="ml-2 font-mono text-emerald-700">${cals} kcal/100g</span>` : ''}`;

            const useBtn = document.createElement('button');
            useBtn.type      = 'button';
            useBtn.textContent = 'Use this';
            useBtn.className = 'shrink-0 bg-blue-700 text-white px-2 py-0.5 rounded font-bold hover:bg-blue-900 transition';
            useBtn.onclick   = () => applyUSDAResult(ingId, food);

            row.append(info, useBtn);
            resultsList.appendChild(row);
        });

        resultsPanel.classList.remove('hidden');
    } catch (err) {
        if (saveMsg) { saveMsg.textContent = '⚠️ Network error — try again.'; saveMsg.className = 'mx-4 mb-3 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200'; }
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '🔍 Lookup via USDA'; }
    }
}

function applyUSDAResult(ingId, food) {
    const n  = food.nutrients;
    const fields = {
        'calories_per_100g': n.calories_per_100g,
        'protein_per_100g':  n.protein_per_100g,
        'fat_per_100g':      n.fat_per_100g,
        'carbs_per_100g':    n.carbs_per_100g,
        'fiber_per_100g':    n.fiber_per_100g,
        'sugar_per_100g':    n.sugar_per_100g,
        'sodium_per_100g':   n.sodium_per_100g,
    };
    // Fill inputs
    Object.entries(fields).forEach(([key, val]) => {
        const el = document.getElementById('ing_' + key + '_' + ingId);
        if (el && val !== null) el.value = val;
    });
    // Fill hidden fdc_id field if present
    const fdcField = document.querySelector('input[name="fields[usda_fdc_id]"]');
    if (fdcField) fdcField.value = food.fdc_id;

    // Hide results panel
    document.getElementById('usdaResultsPanel_' + ingId)?.classList.add('hidden');

    const saveMsg = document.getElementById('usdaSaveMsg_' + ingId);
    if (saveMsg) {
        saveMsg.textContent = `✅ Filled from USDA: "${food.description}" — click Save Record to store.`;
        saveMsg.className   = 'mx-4 mb-3 p-2 rounded text-xs font-mono bg-emerald-50 text-emerald-800 border border-emerald-200';
    }
}

// ── NUTRITION CALCULATOR ──────────────────────────────────────────────────────
async function calculateNutrition(recipeId) {
    const btn      = document.getElementById('calcNutrBtn');
    const icon     = document.getElementById('calcNutrBtnIcon');
    const label    = document.getElementById('calcNutrBtnLabel');
    const msg      = document.getElementById('nutritionMsg');
    const grid     = document.getElementById('nutritionDataGrid');
    const emptyMsg = document.getElementById('nutritionEmptyMsg');

    // Loading state
    btn.disabled  = true;
    icon.textContent  = '⏳';
    label.textContent = 'Calculating…';
    msg.className = 'hidden';

    try {
        const res  = await fetch('/admin/handlers/calculate_nutrition.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: window.SCHEMA.csrf,
                recipe_id:  recipeId
            })
        });
        const data = await res.json();

        if (data.success) {
            const d = data;
            // Update display
            document.getElementById('nutrCalories').textContent = d.calories;
            document.getElementById('nutrProtein').textContent  = d.protein  + 'g';
            document.getElementById('nutrFat').textContent      = d.fat      + 'g';
            document.getElementById('nutrCarbs').textContent    = d.carbs    + 'g';
            document.getElementById('nutrFiber').textContent    = d.fiber    + 'g';
            document.getElementById('nutrSugar').textContent    = d.sugar    + 'g';
            document.getElementById('nutrSodium').textContent   = d.sodium   + 'mg';

            if (grid)     grid.classList.remove('hidden');
            if (emptyMsg) emptyMsg.classList.add('hidden');

            msg.textContent = '✅ ' + data.message;
            msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-emerald-50 text-emerald-800 border border-emerald-200';

            icon.textContent  = '✅';
            label.textContent = 'Recalculate';
        } else {
            msg.textContent = '⚠️ ' + data.message;
            msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200';
            icon.textContent  = '⚡';
            label.textContent = 'Try Again';
        }
    } catch (err) {
        msg.textContent = '⚠️ Network error — please try again.';
        msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200';
        icon.textContent  = '⚡';
        label.textContent = 'Try Again';
    }

    btn.disabled = false;
    msg.classList.remove('hidden');
    msg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

    console.log('[BCRF] lookupUSDA defined:', typeof lookupUSDA);
    window._bcrf_usda_ready = true;

// ── FRACTION INPUT HELPERS ────────────────────────────────────────────────────
function parseFractionJS(val) {
    val = (val + '').trim();
    if (!val) return '';

    // Unicode fractions
    const u = {'¼':0.25,'½':0.5,'¾':0.75,'⅓':0.3333,'⅔':0.6667,
               '⅛':0.125,'⅜':0.375,'⅝':0.625,'⅞':0.875};
    if (u[val] !== undefined) return u[val];

    // Whole + unicode: "1¾"
    const mu = val.match(/^(\d+)([¼½¾⅓⅔⅛⅜⅝⅞])$/);
    if (mu) return (parseFloat(mu[1]) + (u[mu[2]] || 0)).toString();

    // Mixed number: "1 1/4" or "1-1/4"
    const mm = val.match(/^(\d+)[\s\-]+(\d+)\s*\/\s*(\d+)$/);
    if (mm) {
        const denom = parseFloat(mm[3]);
        if (!denom) return mm[1];
        const result = parseFloat(mm[1]) + parseFloat(mm[2]) / denom;
        return formatDecimal(result);
    }

    // Simple fraction: "1/4"
    const mf = val.match(/^(\d+)\s*\/\s*(\d+)$/);
    if (mf) {
        const denom = parseFloat(mf[2]);
        if (!denom) return '0';
        return formatDecimal(parseFloat(mf[1]) / denom);
    }

    // Already a number — clean it up
    const n = parseFloat(val);
    return isNaN(n) ? val : formatDecimal(n);
}

function formatDecimal(n) {
    // Show up to 4 decimal places, strip trailing zeros
    return parseFloat(n.toFixed(4)).toString();
}

// ── EMOJI PICKER ─────────────────────────────────────────────────────────────
var _emojiPickerLoaded = false;
var _activePickerWrap  = null;

async function toggleEmojiPicker(btn) {

    const wrap      = btn.closest('.space-y-1').querySelector('.emoji-picker-wrap');
    const targetId  = btn.dataset.iconTarget;
    const targetInput = document.getElementById(targetId);

    // Close if already open for this button
    if (_activePickerWrap === wrap) {
        wrap.classList.add('hidden');
        _activePickerWrap = null;
        return;
    }

    // Close any other open picker
    if (_activePickerWrap) {
        _activePickerWrap.classList.add('hidden');
        _activePickerWrap = null;
    }

    // Lazy-load emoji-picker-element (ES module from CDN)
    if (!_emojiPickerLoaded) {
        await import('https://cdn.jsdelivr.net/npm/emoji-picker-element@1/index.js');
        _emojiPickerLoaded = true;
    }

    // Build picker if not already in this wrap
    if (!wrap.querySelector('emoji-picker')) {
        const picker = document.createElement('emoji-picker');
        picker.addEventListener('emoji-click', e => {
            if (targetInput) targetInput.value = e.detail.unicode;
            // Update the trigger button to show the chosen emoji
            btn.textContent = e.detail.unicode;
            wrap.classList.add('hidden');
            _activePickerWrap = null;
        });
        wrap.appendChild(picker);
    }

    wrap.classList.remove('hidden');
    _activePickerWrap = wrap;

    // Focus the search inside the picker after a tick
    setTimeout(() => {
        wrap.querySelector('emoji-picker')?.shadowRoot?.querySelector('input[type=search]')?.focus();
    }, 80);
}

// Close picker when clicking outside
document.addEventListener('click', function(e) {
    if (!_activePickerWrap) return;
    if (!e.target.closest('.emoji-picker-wrap') && !e.target.closest('.emoji-trigger')) {
        _activePickerWrap.classList.add('hidden');
        _activePickerWrap = null;
    }
}, true);

// ── EMOJI PICKER STYLES ───────────────────────────────────────────────────────
(function() {
    const s = document.createElement('style');
    s.textContent = `
        .emoji-picker-wrap emoji-picker {
            --background:                #fff;
            --border-color:              #e7e5e4;
            --button-active-background:  #f5f5f4;
            --button-hover-background:   #f5f5f4;
            --emoji-padding:             .3rem;
            --emoji-size:                1.35rem;
            --indicator-color:           #1c1917;
            --input-border-color:        #d6d3d1;
            --input-font-color:          #1c1917;
            --input-placeholder-color:   #a8a29e;
            --input-font-size:           .8rem;
            --num-columns:               8;
            --outline-color:             #78716c;
            border-radius: .5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,.12);
            width: 100%;
            max-width: 340px;
        }
    `;
    document.head.appendChild(s);
})();


// Delete confirm helper (can't await inside inline onclick attrs)
function sysDeleteConfirm(name, triggerEl) {
    sysConfirm(
        'Permanently delete <strong>' + name + '</strong>?<br><br>This cannot be undone.',
        'Delete Record', '🗑️', 'Delete', true
    ).then(function(confirmed) {
        if (confirmed) window.location.href = triggerEl.href;
    });
    return false;
}

// ── ADD CUISINE MODAL ─────────────────────────────────────────────────────────
function openAddCuisineModal() {
    document.getElementById('addCuisineModal').style.display = 'flex';
    document.getElementById('newCuisineInput').value = '';
    document.getElementById('newCuisineInput').focus();
    document.getElementById('cuisineModalMsg').textContent = '';
}
function closeAddCuisineModal() {
    document.getElementById('addCuisineModal').style.display = 'none';
}
async function submitNewCuisine() {
    const input = document.getElementById('newCuisineInput');
    const msg   = document.getElementById('cuisineModalMsg');
    const name  = input.value.trim();
    if (!name) { msg.textContent = 'Please enter a cuisine name.'; return; }

    const res  = await fetch('/admin/handlers/add_cuisine.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: window.SCHEMA.csrf, name: name })
    });
    const data = await res.json();
    if (data.success) {
        // Add to dropdown and select it
        const sel = document.querySelector('select[name="fields[cuisine_id]"]');
        if (sel) {
            const opt = document.createElement('option');
            opt.value    = data.id;
            opt.textContent = data.name;
            opt.selected = true;
            sel.appendChild(opt);
        }
        closeAddCuisineModal();
    } else {
        msg.textContent = data.message || 'Error saving cuisine.';
    }
}

// ── BARCODE PREVIEW (SKU field on inventory table) ────────────────────────────
function updateBarcodePreview(val) {
    const wrap = document.getElementById('barcodePreviewWrap');
    const svg  = document.getElementById('barcodePreviewSvg');
    if (!wrap || !svg) return;
    const v = (val || '').trim();
    if (!v) { wrap.classList.add('hidden'); return; }
    wrap.classList.remove('hidden');
    if (typeof JsBarcode === 'undefined') {
        // Load JsBarcode on first use
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js';
        s.onload = () => renderBarcode(v);
        document.head.appendChild(s);
    } else {
        renderBarcode(v);
    }
}
function renderBarcode(val) {
    try {
        JsBarcode('#barcodePreviewSvg', val, {
            format:      'CODE128',
            lineColor:   '#000',
            width:       1.8,
            height:      50,
            displayValue: true,
            fontSize:    12,
            margin:      6,
            background:  '#ffffff'
        });
    } catch(e) {
        document.getElementById('barcodePreviewSvg').innerHTML =
            '<text fill="red" font-size="11">Invalid barcode value</text>';
    }
}

// Auto-render on page load if SKU field already has a value
document.addEventListener('DOMContentLoaded', function() {
    const skuField = document.querySelector('[id^="skuField_"]');
    if (skuField && skuField.value) updateBarcodePreview(skuField.value);

    // Delegated listener for USDA lookup button — avoids any inline onclick issues
    document.addEventListener('click', function(e) {
        const usdaBtn = e.target.closest('.usda-lookup-trigger');
        if (usdaBtn) {
            e.preventDefault();
            const ingId = parseInt(usdaBtn.dataset.ingId, 10);
            if (ingId) lookupUSDA(ingId);
        }
    });
});
