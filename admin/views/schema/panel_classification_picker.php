<?php /* admin/views/schema/panel_classification_picker.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">Classification</label>
                        <select name="classifications_matrix[]" class="w-full p-2 border bg-white rounded font-medium">
                            <option value="">-- None --</option>
                            <?php foreach ($all_classes as $cl): ?>
                                <option value="<?= (int)$cl['id'] ?>"
                                    <?= in_array($cl['id'], $active_c_ids) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cl['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

