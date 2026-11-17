<?php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
// Active folder filter — comes from GET, defaults to all
$active_folder = $_GET['folder'] ?? '_all';
$site_base     = (bcrf_farm_url()); // prepended to all copied/displayed paths

// Helper: build full URL, avoiding double-prepend if already absolute
function full_img_url(string $path, string $base): string {
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
    return $base . $path;
}

// Helper: human-readable file size
function fmt_filesize(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024)       . ' KB';
    return $bytes . ' B';
}

// Filter vault images by selected folder
$displayed_images = ($active_folder === '_all')
    ? $vault_images
    : array_values(array_filter($vault_images, fn($i) => $i['folder'] === $active_folder));
?>

<h1 class="text-2xl font-bold text-stone-800 mb-6">Media Library</h1>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-8">

    <!-- ── GD PROCESSING FORM ── -->
    <div class="bg-white border border-stone-200 rounded p-6 shadow-sm self-start">
        <h3 class="font-semibold text-base border-b pb-2 mb-4 text-stone-800">Image settings</h3>
        <form method="POST" enctype="multipart/form-data" class="space-y-4 font-sans text-xs">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="execute_image_processing" value="1">

            <div>
                <label for="upload_subdirectory_target" class="block font-bold text-stone-600 mb-1">Target Subfolder</label>
                <select name="upload_subdirectory_target" id="upload_subdirectory_target" class="w-full p-2 border border-stone-300 bg-white rounded text-xxs">
                    <option value="/">/img/ (Root)</option>
                    <?php // one folder list for every upload box (admin/includes/image_names.php, Nov 15, 2026)
                    require_once __DIR__ . '/../includes/image_names.php';
                    foreach (bcrf_image_folders() as $__f => $__l): ?>
                    <option value="/<?= htmlspecialchars($__f, ENT_QUOTES) ?>/">/img/<?= htmlspecialchars($__f, ENT_QUOTES) ?>/ (<?= htmlspecialchars($__l, ENT_QUOTES) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="border border-stone-200 p-3 rounded bg-stone-50 space-y-3">
                <div>
                    <label for="gdSourceFile" class="block font-bold text-stone-500 mb-0.5">Method A: Upload File</label>
                    <!-- Fix 9: accept restricts browser picker to images -->
                    <input type="file" name="image_source_file" id="gdSourceFile" accept="image/*"
                        class="w-full text-xxs text-stone-500 bg-white border border-stone-300 p-1 rounded">
                </div>
                <div class="text-center font-bold text-stone-300 text-[9px] uppercase tracking-widest">&mdash; OR &mdash;</div>
                <div>
                    <label for="gdSourceUrl" class="block font-bold text-stone-500 mb-0.5">Method B: Remote HTTPS URL</label>
                    <input type="url" name="image_source_url" id="gdSourceUrl"
                        class="w-full p-2 border border-stone-300 bg-white rounded text-xxs placeholder-stone-300"
                        placeholder="https://example.com/image.jpg">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="gdOutputName" class="block font-bold text-stone-600 mb-1">Output Filename</label>
                    <!-- Fix 4: hint that only safe chars are kept -->
                    <input type="text" name="output_filename_override" id="gdOutputName"
                        class="w-full p-2 border border-stone-300 bg-stone-50 rounded"
                        placeholder="e.g., miniature-donkey" maxlength="80"
                        autocomplete="off" autocapitalize="off" spellcheck="false"
                        title="Letters, numbers, hyphens, underscores only">
                    <p class="text-[10px] text-stone-400 mt-1">Filled in from the picked file. Blank = automatic name.</p>
                </div>
                <div>
                    <label for="resize_dimension_width" class="block font-bold text-stone-600 mb-1">Scale Width (px)</label>
                    <input type="number" name="resize_dimension_width" id="resize_dimension_width" min="1" max="8000"
                        class="w-full p-2 border border-stone-300 bg-stone-50 rounded"
                        placeholder="Original width">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="format_conversion_mode" class="block font-bold text-stone-600 mb-1">Output Format</label>
                    <select name="format_conversion_mode" id="format_conversion_mode" class="w-full p-2 border border-stone-300 bg-white rounded font-bold text-stone-800">
                        <option value="webp">WebP</option>
                        <option value="jpg">JPG</option>
                        <option value="png">PNG</option>
                    </select>
                </div>
                <div>
                    <label for="compression_quality_scale" class="block font-bold text-stone-600 mb-1">Quality (10–100)</label>
                    <input type="number" name="compression_quality_scale" id="compression_quality_scale" min="10" max="100" value="80"
                        class="w-full p-2 border border-stone-300 bg-stone-50 rounded">
                </div>
            </div>

            <label class="flex items-start gap-2 text-stone-600">
                <input type="checkbox" name="replace_existing" value="1" class="mt-0.5">
                <span>Replace an existing image with the same name <span class="text-stone-400">(otherwise -2, -3&hellip; is added)</span></span>
            </label>

            <button type="submit"
                class="w-full bg-amber-700 text-white font-semibold py-3 rounded text-center text-sm hover:bg-amber-800 transition">
                Process &amp; Save Image →
            </button>
        </form>
    </div>

    <!-- ── MEDIA VAULT PICKER ── -->
    <div class="xl:col-span-2 bg-white border border-stone-200 rounded p-6 shadow-sm flex flex-col gap-4">

        <!-- Header + count -->
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="font-semibold text-base text-stone-800">Media Vault</h3>
            <span class="text-xxs text-stone-400 font-bold">
                <?= count($displayed_images) ?> image<?= count($displayed_images) !== 1 ? 's' : '' ?>
                <?= $vault_scan_capped ? '<span class="text-amber-600 ml-1">(capped at ' . $vault_cap . ')</span>' : '' ?>
            </span>
        </div>

        <!-- Fix 7: folder filter tabs -->
        <?php if (!empty($vault_folders)): ?>
        <nav class="social-period-nav" aria-label="Media folder filters"><div class="social-period-options">
            <?php
            $all_folders   = array_merge(['_all'], $vault_folders);
            $folder_labels = ['_all' => 'All', '_root' => 'Root', 'recipies' => 'Recipes', 'supplies' => 'In-house supplies'];
            foreach ($all_folders as $f):
                $label    = $folder_labels[$f] ?? ucfirst($f);
                $is_active = $active_folder === $f;
                $count_f   = $f === '_all' ? count($vault_images) : count(array_filter($vault_images, fn($i) => $i['folder'] === $f));
            ?>
                <a href="?view=media&folder=<?= urlencode($f) ?>"
                    class="social-period-pill<?= $is_active ? ' is-active' : '' ?>"<?= $is_active ? ' aria-current="page"' : '' ?>>
                    <?= htmlspecialchars($label) ?> <span class="opacity-60">(<?= $count_f ?>)</span>
                </a>
            <?php endforeach; ?>
        </div></nav>
        <?php endif; ?>

        <!-- Image grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 max-h-[58vh] overflow-y-auto p-0.5">
            <?php if (empty($displayed_images)): ?>
                <p class="text-xs italic text-stone-400 py-12 col-span-4 text-center border border-stone-200 border-dashed rounded">
                    No images found<?= $active_folder !== '_all' ? ' in this folder' : '' ?>.
                </p>
            <?php endif; ?>

            <?php foreach ($displayed_images as $img): ?>
            <div class="group border bg-stone-50 p-2 rounded relative flex flex-col justify-between hover:border-stone-400 transition">
                <?php $__mt = @filemtime(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $img['url']); ?>
                <img src="<?= htmlspecialchars($img['url'] . ($__mt ? '?v=' . $__mt : ''), ENT_QUOTES) ?>"
                    class="w-full h-24 object-cover border bg-white rounded"
                    alt="<?= htmlspecialchars(basename($img['url']), ENT_QUOTES) ?>"
                    loading="lazy"
                    data-vault-image>
                <!-- Fallback if image fails to load -->
                <div class="w-full h-24 border border-stone-200 bg-stone-100 rounded hidden items-center justify-center text-stone-300 text-2xl">🖼</div>

                <!-- Fix 8: filename + file size -->
                <div class="mt-1.5 space-y-0.5">
                    <div class="text-[9px] text-stone-500 truncate font-semibold select-all"
                        title="<?= htmlspecialchars(full_img_url($img['url'], $site_base), ENT_QUOTES) ?>">
                        <?= htmlspecialchars(full_img_url($img['url'], $site_base), ENT_QUOTES) ?>
                    </div>
                    <div class="text-[9px] text-stone-400"><?= fmt_filesize($img['size']) ?></div>
                </div>

                <!-- Fix 1: ENT_QUOTES on onclick attribute value -->
                <button type="button"
                    data-copy-url="<?= htmlspecialchars(full_img_url($img['url'], $site_base), ENT_QUOTES) ?>"
                    class="vault-copy-btn w-full mt-1.5 text-[9px] uppercase tracking-wider font-bold bg-stone-100 hover:bg-stone-900 border hover:text-white font-sans py-2 rounded transition text-stone-700" style="min-height:44px;">
                    Copy Path
                </button>

                <!-- Rotate & save (GD, in place) -->
                <form method="POST" class="flex gap-1 mt-1">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="execute_image_rotate" value="1">
                    <input type="hidden" name="rotate_image_path" value="<?= htmlspecialchars($img['url'], ENT_QUOTES) ?>">
                    <input type="hidden" name="rotate_return_folder" value="<?= htmlspecialchars($active_folder, ENT_QUOTES) ?>">
                    <button type="submit" name="rotate_direction" value="left" title="Rotate left 90° and save" aria-label="Rotate <?= htmlspecialchars(basename($img['url']), ENT_QUOTES) ?> left 90 degrees and save"
                        style="min-height:44px;" class="flex-1 text-xs leading-none bg-stone-100 hover:bg-stone-900 hover:text-white border border-stone-200 py-1 rounded transition text-stone-700">&#8634;</button>
                    <button type="submit" name="rotate_direction" value="180" title="Rotate 180° and save" aria-label="Rotate <?= htmlspecialchars(basename($img['url']), ENT_QUOTES) ?> 180 degrees and save"
                        style="min-height:44px;" class="flex-1 text-[9px] font-bold leading-none bg-stone-100 hover:bg-stone-900 hover:text-white border border-stone-200 py-1 rounded transition text-stone-700">180&deg;</button>
                    <button type="submit" name="rotate_direction" value="right" title="Rotate right 90° and save" aria-label="Rotate <?= htmlspecialchars(basename($img['url']), ENT_QUOTES) ?> right 90 degrees and save"
                        style="min-height:44px;" class="flex-1 text-xs leading-none bg-stone-100 hover:bg-stone-900 hover:text-white border border-stone-200 py-1 rounded transition text-stone-700">&#8635;</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($vault_scan_capped): ?>
        <p class="text-xxs text-amber-700 bg-amber-50 border border-amber-200 px-3 py-2 rounded">
            ⚠️ Vault display capped at <?= $vault_cap ?> images. Use the folder filter to browse subfolders individually.
        </p>
        <?php endif; ?>
    </div>

</div>

<script src="/admin/assets/media-library.js" defer></script>
