<?php /* admin/views/schema/panel_linked_recipe_picker.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    <div class="space-y-1 col-span-2">
                        <label class="block font-bold text-stone-600">Linked Recipe</label>
                        <select name="fields[linked_recipe_id]" id="linkedRecipeSelect"
                            class="w-full p-2 border bg-white rounded font-medium text-xs">
                            <option value="">— None —</option>
                            <option value="0"  <?= isset($edit_data['linked_recipe_id']) && (string)$edit_data['linked_recipe_id'] === '0'  ? 'selected' : '' ?>>⬆ Always show newest recipe</option>
                            <option value="-1" <?= isset($edit_data['linked_recipe_id']) && (string)$edit_data['linked_recipe_id'] === '-1' ? 'selected' : '' ?>>🔀 Show a random recipe</option>
                            <optgroup label="── Specific Recipe ──">
                                <?php foreach ($all_recipes as $recipe): ?>
                                    <option value="<?= (int)$recipe['id'] ?>"
                                        <?= (isset($edit_data['linked_recipe_id']) && $edit_data['linked_recipe_id'] !== null && (int)$edit_data['linked_recipe_id'] === (int)$recipe['id'] && (int)$recipe['id'] > 0) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($recipe['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <p class="text-[10px] text-stone-400 font-mono mt-0.5">
                            0 = newest &bull; -1 = random &bull; positive ID = specific recipe
                        </p>
                    </div>

