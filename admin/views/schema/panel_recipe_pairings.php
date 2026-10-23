<?php /* admin/views/schema/panel_recipe_pairings.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    <?php
                    $cur_recipe_id   = (int)($edit_data['id'] ?? 0);
                    $active_pair_ids = [];
                    if ($cur_recipe_id) {
                        try {
                            // Canonical storage: lower id is recipe_id. Query both directions.
                            $ps = $pdo->prepare("
                                SELECT CASE WHEN recipe_id = ? THEN paired_recipe_id ELSE recipe_id END
                                FROM recipe_pairings WHERE recipe_id = ? OR paired_recipe_id = ?
                            ");
                            $ps->execute([$cur_recipe_id, $cur_recipe_id, $cur_recipe_id]);
                            $active_pair_ids = $ps->fetchAll(PDO::FETCH_COLUMN);
                        } catch (Throwable $e) {}
                    }
                    try {
                        $all_pair_recipes = $pdo->query("SELECT id, title FROM recipes ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) { $all_pair_recipes = []; }
                    ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <div class="flex justify-between items-center mb-2">
                            <label class="block font-bold text-stone-700 text-xs">🍽 Pairs Well With</label>
                            <span class="text-[10px] text-stone-400">Add recipes that pair well with this one — works both ways automatically</span>
                        </div>
                        <div id="pairingsWrapper" class="space-y-2 border p-3 rounded bg-stone-50">
                            <?php foreach ($active_pair_ids as $apid): ?>
                            <div class="pairing-row flex items-center gap-2">
                                <span class="text-stone-400 shrink-0 text-xs">🍽</span>
                                <select name="pairings_matrix[]" class="p-1 border bg-white rounded flex-1 text-xs">
                                    <option value="">— Select a recipe —</option>
                                    <?php foreach ($all_pair_recipes as $pr): if ($cur_recipe_id && (int)$pr['id'] === $cur_recipe_id) continue; ?>
                                        <option value="<?= (int)$pr['id'] ?>" <?= (int)$pr['id'] === (int)$apid ? 'selected' : '' ?>><?= htmlspecialchars($pr['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" onclick="this.closest('.pairing-row').remove()" class="text-red-600 font-bold px-1 hover:text-red-900 shrink-0">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="addPairingBtn"
                            class="mt-2 text-xxs font-bold uppercase tracking-wider bg-stone-800 text-white px-2.5 py-1.5 rounded hover:bg-stone-950 transition">
                            Add Pairs Well With +
                        </button>
                    </div>

                    <script>
                    /* Self-contained: "Add Pairs Well With" spawns a recipe dropdown row (mirrors the ingredients UI). */
                    (function () {
                        var PAIR_RECIPES = <?= json_encode(array_values(array_filter(array_map(function ($pr) use ($cur_recipe_id) {
                            return ($cur_recipe_id && (int)$pr['id'] === $cur_recipe_id) ? null : ['id' => (int)$pr['id'], 'title' => $pr['title']];
                        }, $all_pair_recipes))), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
                        var wrap = document.getElementById('pairingsWrapper');
                        var btn  = document.getElementById('addPairingBtn');
                        if (!wrap || !btn) return;
                        btn.addEventListener('click', function () {
                            var row = document.createElement('div');
                            row.className = 'pairing-row flex items-center gap-2';

                            var icon = document.createElement('span');
                            icon.className = 'text-stone-400 shrink-0 text-xs';
                            icon.textContent = '\uD83C\uDF7D';

                            var sel = document.createElement('select');
                            sel.name = 'pairings_matrix[]';
                            sel.className = 'p-1 border bg-white rounded flex-1 text-xs';
                            var none = document.createElement('option');
                            none.value = ''; none.textContent = '\u2014 Select a recipe \u2014';
                            sel.appendChild(none);
                            PAIR_RECIPES.forEach(function (r) {
                                var op = document.createElement('option');
                                op.value = r.id; op.textContent = r.title;
                                sel.appendChild(op);
                            });

                            var rm = document.createElement('button');
                            rm.type = 'button'; rm.textContent = '\u2715';
                            rm.className = 'text-red-600 font-bold px-1 hover:text-red-900 shrink-0';
                            rm.onclick = function () { row.remove(); };

                            row.append(icon, sel, rm);
                            wrap.appendChild(row);
                        });
                    })();
                    </script>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

