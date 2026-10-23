<?php /* admin/views/schema/panel_rich_text.php — included by views/schema.php; inherits loop scope ($name, $type, $val, $raw_val, and this panel's vars) */ ?>
<?php if (empty($target_table)) return; /* included by views/schema.php; block direct HTTP access */ ?>
                    <?php // Fix 6: close and reopen grid cleanly using a full-width wrapper outside the grid ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <label class="block font-bold text-stone-600 capitalize mb-2">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <?php if (!$quill_inited): ?>
                        <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
                        <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.min.js"></script>


<!-- ── BLOG POST PREVIEW MODAL ── -->
<div id="blogPreviewModal"
    class="fixed inset-0 bg-stone-900/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl flex flex-col" style="max-height:90vh;">
        <div class="flex justify-between items-center px-5 py-3 border-b bg-stone-50 rounded-t-lg shrink-0">
            <span id="blogPreviewTitle" class="font-bold text-stone-800 text-sm font-serif truncate max-w-lg"></span>
            <button type="button" onclick="closeBlogPreview()"
                class="text-[10px] font-bold uppercase tracking-wider font-mono border px-3 py-1.5 rounded hover:bg-stone-900 hover:text-white transition shrink-0">
                Close ✕
            </button>
        </div>
        <div class="flex-1 overflow-hidden rounded-b-lg">
            <iframe id="blogPreviewFrame"
                srcdoc=""
                class="w-full h-full border-0"
                style="min-height:520px;"
                sandbox="allow-same-origin">
            </iframe>
        </div>
    </div>
</div>
                        <?php $quill_inited = true; endif; ?>
                        <!-- Fix 3: raw_val goes through htmlspecialchars before echoing into the div -->
                        <div id="quill_<?= $name ?>" class="bg-white w-full border border-stone-300 rounded"
                            style="height:400px;"><?= htmlspecialchars($raw_val) ?></div>
                        <input type="hidden" name="fields[<?= $name ?>]" id="hidden_<?= $name ?>"
                            value="<?= $val ?>">
                        <script>
                            (function() {
                                const q = new Quill('#quill_<?= $name ?>', {
                                    theme: 'snow',
                                    modules: { toolbar: [
                                        [{ header: [1,2,3,false] }],
                                        ['bold','italic','underline','strike'],
                                        ['link','image','blockquote','code-block'],
                                        [{ list:'ordered' }, { list:'bullet' }],
                                        ['clean']
                                    ]}
                                });
                                // Seed with existing HTML (Quill expects raw HTML, not escaped)
                                q.root.innerHTML = <?= json_encode($raw_val) ?>;
                                q.on('text-change', () => {
                                    document.getElementById('hidden_<?= $name ?>').value = q.root.innerHTML;
                                    document.getElementById('schemaEditForm').dataset.dirty = 'true';
                                });
                            })();
                        </script>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

