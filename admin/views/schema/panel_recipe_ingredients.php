<?php /* admin/views/schema/panel_recipe_ingredients.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <label class="block font-bold text-stone-800 mb-2 text-xs">Ingredients</label>
                        <p class="text-[11px] text-stone-500 mb-2 -mt-1">Type to search — pick a raw ingredient or another recipe (recipes are marked 📖) to use it as a sub-recipe.</p>
                        <style>
                          .ri-combo{position:relative}
                          .ri-dropdown{position:absolute;z-index:60;left:0;right:0;top:100%;margin-top:2px;max-height:240px;overflow:auto;background:#fff;border:1px solid #d6d3d1;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,.15)}
                          .ri-item{padding:6px 10px;font-size:12px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                          .ri-item:hover,.ri-item.ri-active{background:#f5f5f4}
                          .ri-item-recipe{color:#4a6741;font-weight:600}
                          .ri-empty{padding:6px 10px;font-size:12px;color:#a8a29e}
                        </style>
                        <div id="ingredientsDynamicWrapper" class="space-y-2 border p-3 rounded bg-stone-50">
                            <?php foreach ($ri_data as $idx => $ri_row): ?>
                            <div class="dynamic-ingredient-row text-xs space-y-1.5 pb-2 border-b border-stone-200 last:border-0">
                                <div class="flex items-center space-x-2">
                                    <span class="text-[10px] font-black text-stone-400 w-5 shrink-0 text-right"><?= $idx + 1 ?>.</span>
                                    <?php $ri_pick = $ri_resolve($ri_row); ?>
                                    <div class="ri-combo relative flex-1">
                                        <input type="hidden" name="recipe_ingredients_block[<?= $idx ?>][ref]" value="<?= htmlspecialchars($ri_pick['ref'], ENT_QUOTES) ?>">
                                        <input type="text" class="ri-search p-1 border bg-white rounded w-full" autocomplete="off" placeholder="Type an ingredient or recipe…" value="<?= htmlspecialchars($ri_pick['label'], ENT_QUOTES) ?>">
                                        <div class="ri-dropdown" style="display:none"></div>
                                    </div>
                                    <input type="text" inputmode="decimal"
                                        name="recipe_ingredients_block[<?= $idx ?>][quantity]"
                                        value="<?= htmlspecialchars(formatQtyForDisplay((float)$ri_row['quantity']), ENT_QUOTES) ?>"
                                        placeholder="e.g. 1/4"
                                        onblur="this.value = parseFractionJS(this.value)"
                                        class="w-20 p-1 border bg-white rounded font-mono text-center">
                                    <select name="recipe_ingredients_block[<?= $idx ?>][unit_id]" class="p-1 border bg-white rounded w-28">
                                        <?php foreach ($all_units as $u): ?>
                                            <option value="<?= (int)$u['id'] ?>" <?= $ri_row['unit_id'] == $u['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($u['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" onclick="this.closest('.dynamic-ingredient-row').remove()"
                                        class="text-red-600 font-bold px-1 hover:text-red-900 shrink-0">✕</button>
                                </div>
                                <div class="flex items-center space-x-2 pl-7">
                                    <input type="text" maxlength="120"
                                        name="recipe_ingredients_block[<?= $idx ?>][notes]"
                                        value="<?= htmlspecialchars($ri_row['notes'] ?? '', ENT_QUOTES) ?>"
                                        placeholder="Preparation note e.g. finely chopped (optional)"
                                        class="w-full p-1 border bg-white rounded text-stone-500 italic text-[11px]">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addNewIngredientRow()"
                            class="mt-2 text-xxs font-bold uppercase tracking-wider bg-stone-800 text-white px-2.5 py-1.5 rounded hover:bg-stone-950 transition">
                            Add Ingredient +
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

