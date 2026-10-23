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
                var label = !f ? 'All' : (f === '_root' ? 'Root' : f);
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
async function quickUploadImage(fileInput, fieldName) {
    const file = fileInput.files[0];
    if (!file) return;
    const progress = document.getElementById('imgProgress_' + fieldName);
    const subRow   = document.getElementById('imgSubfolderRow_' + fieldName);
    if (subRow)   subRow.classList.remove('hidden');
    if (progress) progress.classList.remove('hidden');
    const subfolder = document.getElementById('imgSubfolder_' + fieldName)?.value || 'inventory';
    const fd = new FormData();
    fd.append('csrf_token', IMG_CSRF);
    fd.append('image', file);
    fd.append('subfolder', subfolder);
    try {
        const res = await fetch('/admin/handlers/quick_upload.php', { method: 'POST', body: fd });
        const rawText = await res.text();
        let data;
        try { data = JSON.parse(rawText); }
        catch { console.error('Upload non-JSON:', rawText.slice(0, 200)); throw new Error('Server returned non-JSON'); }
        if (data.ok) {
            const input = document.getElementById('imgUrl_' + fieldName);
            if (input) { input.value = data.full_url; updateImgPreview(fieldName); }
            if (progress) { progress.textContent = '✅ Uploaded!'; setTimeout(function () { progress.classList.add('hidden'); progress.textContent = 'Uploading...'; }, 2500); }
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
