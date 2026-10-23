<?php /* admin/views/schema/panel_ingredient_nutrition.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    <?php
                    $ing_id        = (int)($edit_data['id'] ?? 0);
                    $has_ing_nutr  = !empty($edit_data['calories_per_100g']);
                    $ing_name_for_usda = htmlspecialchars($edit_data['singular_name'] ?? '', ENT_QUOTES);
                    ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-5 w-full border rounded-lg overflow-hidden">

                        <!-- Header -->
                        <div class="flex items-center justify-between px-4 py-2.5 bg-stone-100 border-b">
                            <span class="font-black text-xs uppercase tracking-wider text-stone-700 font-mono">🔬 Nutrition Data (per 100g)</span>
                            <?php if ($ing_id): ?>
                            <button type="button"
                                id="usdaLookupBtn_<?= $ing_id ?>"
                                class="flex items-center gap-1.5 bg-blue-700 hover:bg-blue-900 text-white text-[10px] font-black uppercase tracking-wider px-3 py-1.5 rounded transition font-mono">
                                🔍 Lookup via USDA
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- USDA results panel (hidden until lookup) -->
                        <div id="usdaResultsPanel_<?= $ing_id ?>" class="hidden border-b bg-blue-50 p-3">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-blue-800 mb-2">Select best match:</p>
                            <div id="usdaResultsList_<?= $ing_id ?>" class="space-y-1"></div>
                        </div>

                        <!-- Nutrition fields -->
                        <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
                            <?php
                            $nutr_fields = [
                                ['key' => 'calories_per_100g', 'label' => 'Calories',  'unit' => 'kcal'],
                                ['key' => 'protein_per_100g',  'label' => 'Protein',   'unit' => 'g'],
                                ['key' => 'fat_per_100g',      'label' => 'Fat',       'unit' => 'g'],
                                ['key' => 'carbs_per_100g',    'label' => 'Carbs',     'unit' => 'g'],
                                ['key' => 'fiber_per_100g',    'label' => 'Fiber',     'unit' => 'g'],
                                ['key' => 'sugar_per_100g',    'label' => 'Sugar',     'unit' => 'g'],
                                ['key' => 'sodium_per_100g',   'label' => 'Sodium',    'unit' => 'mg'],
                            ];
                            foreach ($nutr_fields as $nf): ?>
                            <div>
                                <label class="block text-[10px] font-bold text-stone-500 uppercase tracking-wider mb-0.5">
                                    <?= $nf['label'] ?> <span class="font-normal">(<?= $nf['unit'] ?>)</span>
                                </label>
                                <input type="number" step="0.01" min="0"
                                    id="ing_<?= $nf['key'] ?>_<?= $ing_id ?>"
                                    name="fields[<?= $nf['key'] ?>]"
                                    value="<?= htmlspecialchars($edit_data[$nf['key']] ?? '', ENT_QUOTES) ?>"
                                    placeholder="—"
                                    class="w-full p-1.5 border bg-stone-50 rounded font-mono text-sm outline-none">
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Density + count weight -->
                        <div class="px-4 pb-4 grid grid-cols-2 gap-3 border-t pt-3">
                            <div>
                                <label class="block text-[10px] font-bold text-stone-500 uppercase tracking-wider mb-0.5">
                                    Density <span class="font-normal">(g/mL — for volume units)</span>
                                </label>
                                <input type="number" step="0.0001" min="0"
                                    id="ing_density_<?= $ing_id ?>"
                                    name="fields[density_g_per_ml]"
                                    value="<?= htmlspecialchars($edit_data['density_g_per_ml'] ?? '', ENT_QUOTES) ?>"
                                    placeholder="e.g. water=1.0, flour=0.53, honey=1.42"
                                    class="w-full p-1.5 border bg-stone-50 rounded font-mono text-xs outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-stone-500 uppercase tracking-wider mb-0.5">
                                    Weight/unit <span class="font-normal">(g — for count units)</span>
                                </label>
                                <input type="number" step="0.1" min="0"
                                    id="ing_weight_<?= $ing_id ?>"
                                    name="fields[weight_per_unit_g]"
                                    value="<?= htmlspecialchars($edit_data['weight_per_unit_g'] ?? '', ENT_QUOTES) ?>"
                                    placeholder="e.g. egg=50, garlic clove=5"
                                    class="w-full p-1.5 border bg-stone-50 rounded font-mono text-xs outline-none">
                            </div>
                        </div>

                        <!-- Save confirmation message -->
                        <div id="usdaSaveMsg_<?= $ing_id ?>" class="hidden mx-4 mb-3 p-2 rounded text-xs font-mono"></div>
                    </div>

                    <?php if ($ing_id): ?>
                    <script>
                    /* Self-contained USDA lookup — no dependency on main script block */
                    (function() {
                        var ING_ID   = <?= $ing_id ?>;
                        var CSRF     = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

                        var btn     = document.getElementById('usdaLookupBtn_' + ING_ID);
                        var panel   = document.getElementById('usdaResultsPanel_' + ING_ID);
                        var list    = document.getElementById('usdaResultsList_' + ING_ID);
                        var saveMsg = document.getElementById('usdaSaveMsg_' + ING_ID);

                        console.log('[USDA] Self-contained script loaded, btn:', btn);

                        if (!btn) {
                            console.error('[USDA] Button not found: usdaLookupBtn_' + ING_ID);
                            return;
                        }

                        btn.addEventListener('click', function() {
                            console.log('[USDA] Button clicked');

                            var nameInput = document.querySelector('input[name="fields[singular_name]"]');
                            var ingName   = nameInput ? nameInput.value.trim() : '';

                            if (!ingName) {
                                showMsg('amber', '⚠️ Enter a singular name first.');
                                return;
                            }

                            btn.disabled    = true;
                            btn.textContent = '⏳ Searching…';
                            hideMsg();

                            var body = new URLSearchParams({
                                csrf_token: CSRF,
                                action:     'search',
                                query:      ingName
                            });

                            fetch('/admin/handlers/lookup_ingredient_nutrition.php', {
                                method:  'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body:    body.toString()
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data.success) {
                                    showMsg('red', '⚠️ ' + data.message);
                                    return;
                                }
                                renderResults(data.results);
                                if (panel) panel.classList.remove('hidden');
                            })
                            .catch(function(err) {
                                console.error('[USDA] fetch error:', err);
                                showMsg('red', '⚠️ Network error — ' + err.message);
                            })
                            .finally(function() {
                                btn.disabled    = false;
                                btn.innerHTML   = '🔍 Lookup via USDA';
                            });
                        });

                        function renderResults(results) {
                            if (!list) return;
                            list.innerHTML = '';
                            results.forEach(function(food) {
                                var row  = document.createElement('div');
                                row.className = 'flex items-start justify-between gap-2 bg-white border rounded p-2 text-[10px]';

                                var cals = food.nutrients && food.nutrients.calories_per_100g !== null
                                    ? food.nutrients.calories_per_100g + ' kcal/100g' : '';

                                var info = document.createElement('div');
                                info.innerHTML = '<span class="font-bold text-stone-800">'
                                    + escHtml(food.description) + '</span>'
                                    + '<span class="ml-1 text-stone-400">' + escHtml(food.data_type) + '</span>'
                                    + (cals ? '<span class="ml-2 font-mono text-emerald-700">' + cals + '</span>' : '');

                                var useBtn = document.createElement('button');
                                useBtn.type        = 'button';
                                useBtn.textContent = 'Use this';
                                useBtn.className   = 'shrink-0 bg-blue-700 text-white px-2 py-0.5 rounded font-bold hover:bg-blue-900';
                                useBtn.addEventListener('click', (function(f) {
                                    return function() { applyResult(f); };
                                })(food));

                                row.append(info, useBtn);
                                list.appendChild(row);
                            });
                        }

                        function applyResult(food) {
                            var n = food.nutrients || {};
                            var map = {
                                'calories_per_100g': n.calories_per_100g,
                                'protein_per_100g':  n.protein_per_100g,
                                'fat_per_100g':      n.fat_per_100g,
                                'carbs_per_100g':    n.carbs_per_100g,
                                'fiber_per_100g':    n.fiber_per_100g,
                                'sugar_per_100g':    n.sugar_per_100g,
                                'sodium_per_100g':   n.sodium_per_100g
                            };
                            Object.keys(map).forEach(function(key) {
                                var el = document.getElementById('ing_' + key + '_' + ING_ID);
                                if (el && map[key] !== null && map[key] !== undefined) el.value = map[key];
                            });
                            var fdcEl = document.querySelector('input[name="fields[usda_fdc_id]"]');
                            if (fdcEl) fdcEl.value = food.fdc_id || '';

                            if (panel) panel.classList.add('hidden');
                            showMsg('emerald', '✅ Filled from: "' + food.description + '" — click Save Record to store.');
                        }

                        function showMsg(color, text) {
                            if (!saveMsg) return;
                            saveMsg.textContent = text;
                            saveMsg.className   = 'mx-4 mb-3 p-2 rounded text-xs font-mono '
                                + 'bg-' + color + '-50 text-' + color + '-800 border border-' + color + '-200';
                            saveMsg.classList.remove('hidden');
                        }
                        function hideMsg() {
                            if (saveMsg) saveMsg.className = 'hidden';
                        }
                        function escHtml(s) {
                            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        }
                    })();
                    </script>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

