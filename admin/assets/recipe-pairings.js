/* Self-contained: "Add Pairs Well With" spawns a recipe dropdown row (mirrors the ingredients UI). */
                    (function () {
                        var PAIR_RECIPES = JSON.parse(document.getElementById('pairingRecipeData').textContent);
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
                            rm.addEventListener('click', function () { row.remove(); });

                            row.append(icon, sel, rm);
                            wrap.appendChild(row);
                        });
                    })();
                    
document.getElementById('pairingsWrapper')?.addEventListener('click', function (event) { var button = event.target.closest('[data-remove-pairing]'); if (button) button.closest('.pairing-row')?.remove(); });
