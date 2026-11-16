<?php /* admin/views/schema/panel_recipe_categories.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <div class="flex justify-between items-center mb-2">
                            <span id="categoryHeading_recipes" class="block font-bold text-stone-700 text-xs">Categories</span>
                            <button type="button" onclick="toggleAddCategoryForm('recipes')"
                                class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 hover:text-emerald-900 border border-emerald-200 bg-emerald-50 px-2 py-0.5 rounded">
                                + Add New
                            </button>
                        </div>
                        <div id="addCategoryForm_recipes" class="hidden mb-2 flex gap-2">
                            <input type="text" id="newCategoryInput_recipes" maxlength="80"
                                aria-label="New category name" placeholder="New category name..."
                                class="flex-1 p-1.5 border rounded bg-white text-xs font-medium outline-none">
                            <button type="button" onclick="submitNewCategory('recipes')"
                                class="bg-stone-900 text-white text-[10px] font-bold uppercase px-3 py-1 rounded hover:bg-stone-700">Save</button>
                            <button type="button" onclick="toggleAddCategoryForm('recipes')"
                                class="border text-stone-500 text-[10px] font-bold px-2 py-1 rounded hover:bg-stone-100">Cancel</button>
                        </div>
                        <div id="categoriesCheckboxList_recipes" role="group" aria-labelledby="categoryHeading_recipes" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))" class="grid gap-2 p-3 border rounded bg-stone-50 max-h-24 overflow-y-auto">
                            <?php foreach ($all_categories as $ct): ?>
                            <label class="text-xxs font-medium cursor-pointer" style="display:grid;grid-template-columns:24px minmax(0,1fr);align-items:center;gap:10px;min-width:0;">
                                <input type="checkbox" style="flex-shrink:0;" name="categories_matrix[]"
                                    value="<?= (int)$ct['id'] ?>"
                                    <?= in_array($ct['id'], $active_cat_ids ?? []) ? 'checked' : '' ?>>
                                <span style="display:block;min-width:0;max-width:100%;white-space:normal;overflow-wrap:anywhere;word-break:break-word;"><?= htmlspecialchars($ct['category_name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

