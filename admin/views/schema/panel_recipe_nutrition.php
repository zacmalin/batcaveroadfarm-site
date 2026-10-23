<?php /* admin/views/schema/panel_recipe_nutrition.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    <?php
                    $has_nutrition = !empty($edit_data['calories']);
                    $nutr_source   = $edit_data['nutrition_source']     ?? null;
                    $nutr_updated  = $edit_data['nutrition_updated_at'] ?? null;
                    $rec_id_for_nutr = (int)($edit_data['id'] ?? 0);
                    ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-6 w-full border rounded-lg overflow-hidden" id="nutritionPanel">

                        <!-- Header bar -->
                        <div class="flex items-center justify-between px-4 py-2.5 bg-stone-100 border-b">
                            <div>
                                <span class="font-black text-xs uppercase tracking-wider text-stone-700 font-mono">🧮 Nutrition Facts</span>
                                <?php if ($has_nutrition && $nutr_updated): ?>
                                <span class="ml-2 text-[10px] text-stone-400 font-mono">
                                    via <?= htmlspecialchars($nutr_source ?? 'edamam') ?>
                                    · <?= date('M j, Y', strtotime($nutr_updated)) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($rec_id_for_nutr): ?>
                            <button type="button"
                                id="calcNutrBtn"
                                class="flex items-center gap-1.5 bg-emerald-700 hover:bg-emerald-900 text-white text-[10px] font-black uppercase tracking-wider px-3 py-1.5 rounded transition font-mono">
                                <span id="calcNutrBtnIcon">⚡</span>
                                <span id="calcNutrBtnLabel"><?= $has_nutrition ? 'Recalculate' : 'Calculate' ?></span>
                            </button>
                            <?php else: ?>
                            <span class="text-[10px] text-stone-400 font-mono italic">Save the recipe first to enable calculation</span>
                            <?php endif; ?>
                        </div>

                        <!-- Nutrition label -->
                        <div class="p-4">
                            <?php if (!$has_nutrition): ?>
                            <p class="text-stone-400 text-xs italic text-center py-4" id="nutritionEmptyMsg">
                                No nutrition data yet. Add ingredients and click Calculate.
                            </p>
                            <?php endif; ?>

                            <div id="nutritionDataGrid" class="<?= $has_nutrition ? '' : 'hidden' ?>">
                                <!-- Per-serving label -->
                                <p class="text-[10px] text-stone-500 font-mono uppercase tracking-wider mb-3">Per serving</p>

                                <!-- Big calorie display -->
                                <div class="flex items-baseline gap-2 mb-4 pb-3 border-b-4 border-stone-900">
                                    <span class="text-4xl font-black text-stone-900" id="nutrCalories"><?= $edit_data['calories'] ?? '—' ?></span>
                                    <span class="text-sm font-bold text-stone-600">Calories</span>
                                </div>

                                <!-- Macro grid -->
                                <div class="grid grid-cols-3 gap-3 mb-3">
                                    <?php
                                    $macros = [
                                        ['label' => 'Protein',    'id' => 'nutrProtein', 'val' => $edit_data['protein_g'] ?? null, 'unit' => 'g',  'color' => 'text-blue-700'],
                                        ['label' => 'Fat',        'id' => 'nutrFat',     'val' => $edit_data['fat_g']     ?? null, 'unit' => 'g',  'color' => 'text-amber-700'],
                                        ['label' => 'Carbs',      'id' => 'nutrCarbs',   'val' => $edit_data['carbs_g']   ?? null, 'unit' => 'g',  'color' => 'text-emerald-700'],
                                    ];
                                    foreach ($macros as $m): ?>
                                    <div class="bg-stone-50 border rounded p-2.5 text-center">
                                        <div class="text-xl font-black <?= $m['color'] ?>" id="<?= $m['id'] ?>">
                                            <?= $m['val'] !== null ? $m['val'] . $m['unit'] : '—' ?>
                                        </div>
                                        <div class="text-[10px] text-stone-500 font-bold uppercase tracking-wide mt-0.5"><?= $m['label'] ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Minor nutrients -->
                                <div class="space-y-1 border-t pt-2 text-xs font-mono">
                                    <?php
                                    $minors = [
                                        ['label' => 'Dietary Fiber', 'id' => 'nutrFiber',  'val' => $edit_data['fiber_g']  ?? null, 'unit' => 'g'],
                                        ['label' => 'Sugar',         'id' => 'nutrSugar',  'val' => $edit_data['sugar_g']  ?? null, 'unit' => 'g'],
                                        ['label' => 'Sodium',        'id' => 'nutrSodium', 'val' => $edit_data['sodium_mg'] ?? null, 'unit' => 'mg'],
                                    ];
                                    foreach ($minors as $mn): ?>
                                    <div class="flex justify-between text-stone-600 py-0.5 border-b border-stone-100">
                                        <span><?= $mn['label'] ?></span>
                                        <span class="font-bold" id="<?= $mn['id'] ?>">
                                            <?= $mn['val'] !== null ? $mn['val'] . $mn['unit'] : '—' ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Disclaimer -->
                                <p class="text-[9px] text-stone-400 mt-3 leading-relaxed">
                                    Nutritional values calculated via Edamam and are estimates only. Actual values may vary based on preparation, ingredient brands, and portion sizes.
                                </p>
                            </div>

                            <!-- Error/status message -->
                            <div id="nutritionMsg" class="hidden mt-2 p-2 rounded text-xs font-mono"></div>
                        </div>
                    </div>

                    <?php if ($rec_id_for_nutr): ?>
                    <script>
                    /* Self-contained nutrition calculator — no dependency on main script block */
                    (function() {
                        var REC_ID = <?= $rec_id_for_nutr ?>;
                        var CSRF   = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

                        var btn   = document.getElementById('calcNutrBtn');
                        var icon  = document.getElementById('calcNutrBtnIcon');
                        var label = document.getElementById('calcNutrBtnLabel');
                        var msg   = document.getElementById('nutritionMsg');
                        var grid  = document.getElementById('nutritionDataGrid');
                        var empty = document.getElementById('nutritionEmptyMsg');

                        if (!btn) return;

                        btn.addEventListener('click', function() {
                            btn.disabled     = true;
                            icon.textContent = '⏳';
                            label.textContent = 'Calculating…';
                            if (msg) msg.className = 'hidden';

                            fetch('/admin/handlers/calculate_nutrition.php', {
                                method:  'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body:    new URLSearchParams({csrf_token: CSRF, recipe_id: REC_ID}).toString()
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    var d = data;
                                    var setEl = function(id, val) {
                                        var el = document.getElementById(id);
                                        if (el) el.textContent = val;
                                    };
                                    setEl('nutrCalories', d.calories);
                                    setEl('nutrProtein',  d.protein  + 'g');
                                    setEl('nutrFat',      d.fat      + 'g');
                                    setEl('nutrCarbs',    d.carbs    + 'g');
                                    setEl('nutrFiber',    d.fiber    + 'g');
                                    setEl('nutrSugar',    d.sugar    + 'g');
                                    setEl('nutrSodium',   d.sodium   + 'mg');

                                    // Sync calories into the form field so Save Record preserves it
                                    var calField = document.querySelector('input[name="fields[calories]"], input[name="fields[calories]"]');
                                    if (calField) calField.value = d.calories;

                                    if (grid)  grid.classList.remove('hidden');
                                    if (empty) empty.classList.add('hidden');

                                    if (msg) {
                                        msg.textContent = '✅ ' + data.message;
                                        msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-emerald-50 text-emerald-800 border border-emerald-200';
                                    }
                                    icon.textContent  = '✅';
                                    label.textContent = 'Recalculate';
                                } else {
                                    if (msg) {
                                        msg.textContent = '⚠️ ' + data.message;
                                        msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200';
                                    }
                                    icon.textContent  = '⚡';
                                    label.textContent = 'Try Again';
                                }
                                if (msg) msg.classList.remove('hidden');
                            })
                            .catch(function(err) {
                                if (msg) {
                                    msg.textContent = '⚠️ Network error — ' + err.message;
                                    msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200';
                                    msg.classList.remove('hidden');
                                }
                                icon.textContent  = '⚡';
                                label.textContent = 'Try Again';
                            })
                            .finally(function() {
                                btn.disabled = false;
                            });
                        });
                    })();
                    </script>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

