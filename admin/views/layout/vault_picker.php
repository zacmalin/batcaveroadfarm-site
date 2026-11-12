<?php
/**
 * admin/views/layout/vault_picker.php
 * Reusable Media Vault picker (modal + JS), lifted from the schema editor so any
 * admin view can offer the same "📁 Vault / ⬆ Upload" image widget.
 *
 * USAGE — render this once per page, then for each image field emit:
 *   <div id="imgPreview_<KEY>" class="hidden ..."><img onerror="..."></div>
 *   <input id="imgUrl_<KEY>" name="..." oninput="updateImgPreview('<KEY>')">
 *   <button onclick="openVaultPicker('<KEY>')">Vault</button>
 *   <input type="file" onchange="quickUploadImage(this,'<KEY>')">
 *   (optional) <span id="imgProgress_<KEY>"></span>
 * <KEY> is any bracket-free token; it only builds element IDs and need not match
 * the input's name. Requires sysAlert() (defined in layout/head.php).
 *
 * Picking a file opens a "Name this image" dialog (file name prefilled from the
 * picked file, plus the folder) before anything is uploaded. The folder defaults
 * to the widget's own <select id="imgSubfolder_<KEY>"> if it has one, else to
 * data-folder="..." on the file input, else inventory.
 */
if (defined('BCRF_VAULT_PICKER_RENDERED')) return;
define('BCRF_VAULT_PICKER_RENDERED', 1);
?>
<!-- ── VAULT PICKER MODAL ── -->
<div id="vaultPickerModal" class="fixed inset-0 z-[998] hidden" aria-modal="true">
    <div id="vaultPickerBackdrop" class="absolute inset-0 bg-stone-900/70 backdrop-blur-sm"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl flex flex-col" style="max-height:88vh;">
            <div class="flex justify-between items-center px-5 py-4 border-b shrink-0">
                <div>
                    <h3 class="font-bold text-stone-800 font-serif text-lg">Media Vault</h3>
                    <p id="vaultPickerCount" class="text-xs text-stone-400 font-mono mt-0.5">Click an image to use it</p>
                </div>
                <button type="button" onclick="closeVaultPicker()"
                    class="text-stone-400 hover:text-stone-700 text-2xl font-bold leading-none">&times;</button>
            </div>
            <div class="px-5 py-3 border-b shrink-0 space-y-2">
                <input type="search" placeholder="Filter by filename..."
                    oninput="filterVaultImages(this.value)"
                    class="w-full p-2 border rounded bg-stone-50 text-xs font-mono outline-none">
                <div id="vaultPickerTabs" class="flex flex-wrap gap-1.5"></div>
            </div>
            <div id="vaultPickerGrid"
                style="padding:1rem;display:flex;flex-wrap:wrap;gap:8px;overflow-y:auto;max-height:340px;align-content:flex-start;">
            </div>
            <div id="vaultPickerPager"
                style="display:none;padding:0.5rem 1rem;border-top:1px solid #e7e5e4;align-items:center;justify-content:space-between;gap:0.5rem;flex-shrink:0;">
                <button type="button" id="vaultPagerPrev" onclick="vaultChangePage(-1)"
                    style="border:1px solid #d6d3d1;border-radius:4px;padding:4px 12px;font-size:11px;font-family:monospace;font-weight:700;cursor:pointer;background:#fafaf9;">← Prev</button>
                <span id="vaultPagerInfo" style="font-size:11px;font-family:monospace;color:#78716c;"></span>
                <button type="button" id="vaultPagerNext" onclick="vaultChangePage(1)"
                    style="border:1px solid #d6d3d1;border-radius:4px;padding:4px 12px;font-size:11px;font-family:monospace;font-weight:700;cursor:pointer;background:#fafaf9;">Next →</button>
            </div>
        </div>
    </div>
</div>

<!-- ── NAME-BEFORE-UPLOAD DIALOG ── -->
<div id="uploadNameModal" role="dialog" aria-modal="true" aria-labelledby="uploadNameTitle"
    style="display:none;position:fixed;inset:0;z-index:999;align-items:center;justify-content:center;padding:1rem;">
    <div id="uploadNameBackdrop" style="position:absolute;inset:0;background:rgba(28,25,23,0.7);"></div>
    <form id="uploadNameForm" novalidate
        style="position:relative;background:#fff;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.3);width:100%;max-width:26rem;padding:1.25rem;font-size:13px;color:#292524;">
        <h3 id="uploadNameTitle" class="font-serif" style="font-weight:700;font-size:1.1rem;margin:0 0 .75rem;">Name this image</h3>
        <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;">
            <img id="uploadNamePreview" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #e7e5e4;background:#f5f5f4;flex-shrink:0;">
            <div style="min-width:0;">
                <div id="uploadNameOrig" style="font-family:monospace;font-size:11px;color:#57534e;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                <div style="font-size:11px;color:#a8a29e;margin-top:2px;">Saved as WebP, resized to 1200px wide if larger.</div>
            </div>
        </div>
        <label for="uploadNameInput" style="display:block;font-weight:700;color:#57534e;margin-bottom:4px;">File name</label>
        <div style="display:flex;align-items:center;border:1px solid #d6d3d1;border-radius:6px;background:#fafaf9;">
            <input id="uploadNameInput" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="80"
                placeholder="e.g. miniature-donkey-stand"
                style="flex:1;min-width:0;border:0;background:transparent;padding:.55rem .6rem;font-family:monospace;font-size:13px;outline:none;">
            <span style="padding-right:.6rem;font-family:monospace;color:#a8a29e;">.webp</span>
        </div>
        <p id="uploadNameHint" style="font-size:11px;color:#a8a29e;margin:4px 0 0;">Letters, numbers, hyphens and underscores; anything else becomes _. Leave blank for an automatic name.</p>
        <label for="uploadNameFolder" style="display:block;font-weight:700;color:#57534e;margin:.85rem 0 4px;">Folder</label>
        <select id="uploadNameFolder" style="width:100%;border:1px solid #d6d3d1;border-radius:6px;background:#fafaf9;padding:.5rem;font-family:monospace;font-size:13px;"></select>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.1rem;">
            <button type="button" id="uploadNameCancel"
                style="border:1px solid #d6d3d1;background:#fafaf9;border-radius:6px;padding:.55rem 1rem;font-weight:700;cursor:pointer;">Cancel</button>
            <button type="submit" id="uploadNameGo"
                style="border:0;background:#1c1917;color:#fff;border-radius:6px;padding:.55rem 1.1rem;font-weight:700;cursor:pointer;">⬆ Upload</button>
        </div>
    </form>
</div>

<script>
// ── IMAGE FIELD WIDGET (shared) ───────────────────────────────────────────────
var IMG_CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

function updateImgPreview(fieldName) {
    const url  = document.getElementById('imgUrl_' + fieldName)?.value?.trim();
    const wrap = document.getElementById('imgPreview_' + fieldName);
    const img  = wrap?.querySelector('img');
    if (!wrap || !img) return;
    if (url) { img.src = url; img.style.display = ''; wrap.classList.remove('hidden'); wrap.style.display = ''; }
    else     { wrap.classList.add('hidden'); }
}

// ── VAULT PICKER ──────────────────────────────────────────────────────────────
var _vaultTargetField = null;
var _vaultAllImages   = [];
var _vaultPage        = 0;

async function openVaultPicker(fieldName) {
    try {
        _vaultTargetField = fieldName;
        const modal = document.getElementById('vaultPickerModal');
        if (!modal) { sysAlert('Vault modal not in DOM. Reload the page.', 'Error', '⚠️'); return; }
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        await loadVaultImages();
    } catch (e) {
        sysAlert('Vault error: ' + e.message, 'Error', '⚠️');
    }
}

function closeVaultPicker() {
    var m = document.getElementById('vaultPickerModal');
    if (m) m.style.display = 'none';
    document.body.style.overflow = '';
    _vaultTargetField = null;
}

async function loadVaultImages(folder) {
    const grid = document.getElementById('vaultPickerGrid');
    const tabs = document.getElementById('vaultPickerTabs');
    grid.innerHTML = '<p style="text-align:center;color:#a8a29e;font-size:12px;padding:2rem;width:100%;">Loading vault...</p>';
    try {
        const url = '/admin/handlers/get_vault_images.php' + (folder ? '?folder=' + encodeURIComponent(folder) : '');
        const res = await fetch(url);
        const raw = await res.text();
        var data;
        try { data = JSON.parse(raw); }
        catch (parseErr) {
            sysAlert('Vault: server returned non-JSON response.<br><code style="font-size:10px;word-break:break-all;">' + raw.substring(0, 300).replace(/</g, '&lt;') + '</code>', 'Vault Error', '⚠️');
            grid.innerHTML = '<p style="text-align:center;color:#ef4444;font-size:12px;padding:1rem;">Server error — see popup.</p>';
            return;
        }
        if (!data.ok) {
            sysAlert('Vault error from server: ' + data.message, 'Vault Error', '⚠️');
            grid.innerHTML = '<p style="text-align:center;color:#ef4444;font-size:12px;padding:1rem;">Error: ' + data.message + '</p>';
            return;
        }
        if (tabs && data.folders && data.folders.length > 1) {
            tabs.innerHTML = '';
            [''].concat(data.folders).forEach(function (f) {
                var label = !f ? 'All' : (f === '_root' ? 'Root' : (f === 'recipies' ? 'Recipes' : f));
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = label;
                btn.style.cssText = 'padding:2px 10px;border:1px solid #d6d3d1;border-radius:4px;font-size:9px;font-family:monospace;font-weight:700;text-transform:uppercase;cursor:pointer;background:#fafaf9;margin-right:4px;';
                btn.onclick = (function (folder) { return function () { _vaultPage = 0; loadVaultImages(folder); }; })(f);
                tabs.appendChild(btn);
            });
        }
        if (!data.images || !data.images.length) {
            grid.innerHTML = '<p style="text-align:center;color:#a8a29e;font-size:12px;padding:2rem;font-style:italic;width:100%;">No images found in vault.</p>';
            var ce = document.getElementById('vaultPickerCount');
            if (ce) ce.textContent = '0 images';
            return;
        }
        _vaultAllImages = data.images;
        _vaultPage = 0;
        vaultRenderPage();
    } catch (err) {
        grid.innerHTML = '<p style="text-align:center;color:#ef4444;font-size:12px;padding:2rem;width:100%;">Failed to load vault: ' + err.message + '</p>';
    }
}

function vaultRenderPage() {
    var grid    = document.getElementById('vaultPickerGrid');
    var pager   = document.getElementById('vaultPickerPager');
    var info    = document.getElementById('vaultPagerInfo');
    var prev    = document.getElementById('vaultPagerPrev');
    var next    = document.getElementById('vaultPagerNext');
    var countEl = document.getElementById('vaultPickerCount');
    var total   = _vaultAllImages.length;
    var perPage = 20;
    var pages   = Math.ceil(total / perPage);
    var start   = _vaultPage * perPage;
    var slice   = _vaultAllImages.slice(start, start + perPage);

    if (countEl) countEl.textContent = total + ' image' + (total !== 1 ? 's' : '') + ' — click to use';

    var html = '';
    for (var idx = 0; idx < slice.length; idx++) {
        var img     = slice[idx];
        var bgUrl   = img.url.replace(/'/g, '%27');
        var fullUrl = (img.full_url || img.url).replace(/'/g, '%27');
        var fname   = img.filename.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        html += '<div onclick="selectVaultImage(\'' + fullUrl + '\')" '
             +  'style="width:calc(25% - 6px);flex-shrink:0;cursor:pointer;border-radius:6px;overflow:hidden;border:2px solid transparent;background:#f3f4f6;" '
             +  'onmouseover="this.style.borderColor=\'#1c1917\'" '
             +  'onmouseout="this.style.borderColor=\'transparent\'">'
             +    '<div style="width:100%;height:90px;background-color:#d1d5db;'
             +         'background-image:url(&quot;' + bgUrl + '&quot;);'
             +         'background-size:cover;background-position:center;"></div>'
             +    '<div style="font-size:8px;font-family:monospace;color:#78716c;'
             +         'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:3px 5px;">'
             +      fname
             +    '</div>'
             +  '</div>';
    }
    grid.innerHTML = html || '<p style="color:#a8a29e;font-size:12px;padding:1rem;">No images found.</p>';

    if (pages > 1) {
        pager.style.display = 'flex';
        info.textContent    = 'Page ' + (_vaultPage + 1) + ' of ' + pages + ' (' + total + ' images)';
        prev.disabled       = _vaultPage === 0;
        next.disabled       = _vaultPage >= pages - 1;
        prev.style.opacity  = prev.disabled ? '0.4' : '1';
        next.style.opacity  = next.disabled ? '0.4' : '1';
    } else {
        pager.style.display = 'none';
    }
    grid.scrollTop = 0;
}

function vaultChangePage(dir) {
    var pages = Math.ceil(_vaultAllImages.length / 20);
    _vaultPage = Math.max(0, Math.min(pages - 1, _vaultPage + dir));
    vaultRenderPage();
}

function filterVaultImages(query) {
    document.querySelectorAll('#vaultPickerGrid > div').forEach(function (card) {
        var label = card.querySelector('div:last-child');
        var name  = (label && label.textContent ? label.textContent : '').toLowerCase();
        card.style.display = (!query || name.includes(query.toLowerCase())) ? '' : 'none';
    });
}

function selectVaultImage(url) {
    if (!_vaultTargetField) return;
    const input = document.getElementById('imgUrl_' + _vaultTargetField);
    if (!input) { sysAlert('Could not find input #imgUrl_' + _vaultTargetField, 'Vault', '⚠️'); closeVaultPicker(); return; }
    input.value = url;
    input.style.outline = '2px solid #22c55e';
    setTimeout(function () { input.style.outline = ''; }, 2500);
    updateImgPreview(_vaultTargetField);
    closeVaultPicker();
}

// ── QUICK UPLOAD ──────────────────────────────────────────────────────────────
// Folders offered in the naming dialog (value => label). recipies keeps its
// historical spelling because existing recipes already point at it.
var UPLOAD_FOLDERS = [['inventory','inventory'],['recipies','recipes'],['zoo','zoo'],['stand','stand'],['blog','blog'],['thrift','thrift'],['social','social']];

// "IMG 2041 (1).JPG" -> "img-2041-1". Mirrors the server rule, plus lowercase and hyphens.
function bcrfSlugFromFilename(n) {
    var s = String(n || '').replace(/\.[^.\/]+$/, '');
    if (s.normalize) s = s.normalize('NFKD').replace(/[̀-ͯ]/g, '');
    return s.toLowerCase().replace(/[^a-z0-9_\-]+/g, '-').replace(/-{2,}/g, '-').replace(/^[-_]+|[-_]+$/g, '').slice(0, 80);
}

// Opens the naming dialog. Resolves {name, folder} on Upload, or null on Cancel.
function askUploadName(file, defaultFolder) {
    return new Promise(function (resolve) {
        var modal  = document.getElementById('uploadNameModal');
        var form   = document.getElementById('uploadNameForm');
        var input  = document.getElementById('uploadNameInput');
        var sel    = document.getElementById('uploadNameFolder');
        var prev   = document.getElementById('uploadNamePreview');
        var orig   = document.getElementById('uploadNameOrig');
        if (!modal || !form || !input || !sel) { resolve({ name: '', folder: defaultFolder }); return; }

        sel.innerHTML = '';
        var folders = UPLOAD_FOLDERS.slice();
        if (defaultFolder && !folders.some(function (f) { return f[0] === defaultFolder; })) folders.unshift([defaultFolder, defaultFolder]);
        folders.forEach(function (f) {
            var o = document.createElement('option');
            o.value = f[0]; o.textContent = f[1];
            if (f[0] === defaultFolder) o.selected = true;
            sel.appendChild(o);
        });

        input.value = bcrfSlugFromFilename(file.name);
        orig.textContent = file.name + ' · ' + (file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.round(file.size / 1024) + ' KB');
        orig.title = file.name;
        var objUrl = null;
        try { objUrl = URL.createObjectURL(file); prev.src = objUrl; prev.style.visibility = ''; } catch (e) { prev.style.visibility = 'hidden'; }

        var prevOverflow = document.body.style.overflow;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        setTimeout(function () { input.focus(); input.select(); }, 30);

        function finish(result) {
            modal.style.display = 'none';
            document.body.style.overflow = prevOverflow;
            if (objUrl) { try { URL.revokeObjectURL(objUrl); } catch (e) {} }
            prev.removeAttribute('src');
            form.removeEventListener('submit', onSubmit);
            document.getElementById('uploadNameCancel').removeEventListener('click', onCancel);
            document.getElementById('uploadNameBackdrop').removeEventListener('click', onCancel);
            document.removeEventListener('keydown', onKey);
            resolve(result);
        }
        function onSubmit(e) { e.preventDefault(); finish({ name: input.value.trim(), folder: sel.value || defaultFolder }); }
        function onCancel() { finish(null); }
        function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); finish(null); } }
        form.addEventListener('submit', onSubmit);
        document.getElementById('uploadNameCancel').addEventListener('click', onCancel);
        document.getElementById('uploadNameBackdrop').addEventListener('click', onCancel);
        document.addEventListener('keydown', onKey);
    });
}

async function quickUploadImage(fileInput, fieldName) {
    const file = fileInput.files[0];
    if (!file) return;
    const progress  = document.getElementById('imgProgress_' + fieldName);
    const widgetSel = document.getElementById('imgSubfolder_' + fieldName);
    const defFolder = (widgetSel && widgetSel.value) || fileInput.dataset.folder || 'inventory';

    const choice = await askUploadName(file, defFolder);
    if (!choice) { fileInput.value = ''; return; }   // cancelled: nothing uploaded
    if (widgetSel) widgetSel.value = choice.folder;  // remember the folder for this field

    if (progress) progress.classList.remove('hidden');
    const fd = new FormData();
    fd.append('csrf_token', IMG_CSRF);
    fd.append('image', file);
    fd.append('subfolder', choice.folder);
    fd.append('filename', choice.name);
    try {
        const res = await fetch('/admin/handlers/quick_upload.php', { method: 'POST', body: fd });
        const rawText = await res.text();
        let data;
        try { data = JSON.parse(rawText); }
        catch { console.error('Upload non-JSON:', rawText.slice(0, 200)); throw new Error('Server returned non-JSON'); }
        if (data.ok) {
            const input = document.getElementById('imgUrl_' + fieldName);
            if (input) { input.value = data.full_url; updateImgPreview(fieldName); }
            const saved = data.filename || '';
            if (progress) {
                progress.textContent = data.renamed ? ('✅ Name taken, saved as ' + saved) : ('✅ Uploaded' + (saved ? ' as ' + saved : '!'));
                setTimeout(function () { progress.classList.add('hidden'); progress.textContent = 'Uploading...'; }, data.renamed ? 6000 : 2500);
            } else if (data.renamed) {
                sysAlert(data.message, 'Uploaded', 'ℹ️');
            }
        } else {
            sysAlert(data.message || 'Upload failed.', 'Upload Error', '⚠️');
            if (progress) progress.classList.add('hidden');
        }
    } catch (err) {
        sysAlert('Upload error: ' + err.message, 'Upload Error', '⚠️');
        if (progress) progress.classList.add('hidden');
    } finally {
        fileInput.value = '';
    }
}

document.getElementById('vaultPickerModal')?.addEventListener('click', function (e) {
    if (e.target === this || e.target.id === 'vaultPickerBackdrop') closeVaultPicker();
});
</script>
