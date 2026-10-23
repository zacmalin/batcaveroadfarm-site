<?php /* admin/views/schema/panel_blog_categories.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <div class="flex justify-between items-center mb-2">
                            <label class="block font-bold text-stone-700 text-xs">Categories</label>
                            <button type="button" onclick="toggleAddCategoryForm('blog')"
                                class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 hover:text-emerald-900 border border-emerald-200 bg-emerald-50 px-2 py-0.5 rounded">
                                + Add New
                            </button>
                        </div>
                        <div id="addCategoryForm_blog" class="hidden mb-2 flex gap-2">
                            <input type="text" id="newCategoryInput_blog" maxlength="80"
                                placeholder="New category name..."
                                class="flex-1 p-1.5 border rounded bg-white text-xs font-medium outline-none">
                            <button type="button" onclick="submitNewCategory('blog')"
                                class="bg-stone-900 text-white text-[10px] font-bold uppercase px-3 py-1 rounded hover:bg-stone-700">Save</button>
                            <button type="button" onclick="toggleAddCategoryForm('blog')"
                                class="border text-stone-500 text-[10px] font-bold px-2 py-1 rounded hover:bg-stone-100">Cancel</button>
                        </div>
                        <div id="categoriesCheckboxList_blog" class="grid grid-cols-4 gap-2 p-3 border rounded bg-stone-50 max-h-28 overflow-y-auto">
                            <?php foreach (($all_blog_categories ?? []) as $ct): ?>
                            <label class="flex items-center space-x-2 text-xxs font-medium cursor-pointer">
                                <input type="checkbox" name="categories_matrix[]"
                                    value="<?= (int)$ct['id'] ?>"
                                    <?= in_array($ct['id'], $active_cat_ids ?? []) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($ct['name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

