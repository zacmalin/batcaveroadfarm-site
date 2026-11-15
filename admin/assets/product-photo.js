// admin/assets/product-photo.js
// Product photo box on admin/products.php (Nov 15, 2026): "Pick from vault" opens the Media vault in a dialog,
// "Upload to vault" saves a photo into the chosen vault folder (named after the product) and fills the address.
// Uses the same endpoints as the console's vault picker: handlers/get_vault_images.php and handlers/quick_upload.php.
// Strict CSP: no inline script or styles; looks come from admin-ui.css (.pv-*).
(function () {
    'use strict';
    var box = document.querySelector('[data-pphoto]');
    var url = document.getElementById('pPhotoUrl');
    if (!box || !url) return;
    var form = url.form;
    var prev = document.getElementById('pPhotoPrev');
    var msg = box.querySelector('[data-pphoto-msg]');
    var folderSel = box.querySelector('[data-pphoto-folder]');
    var fileIn = box.querySelector('[data-pphoto-file]');
    var typeSel = form ? form.querySelector('select[name="product_type"]') : null;
    box.hidden = false;

    function say(t, bad) { msg.textContent = t || ''; msg.classList.toggle('pv-bad', !!bad); }
    function showPrev() {
        var v = url.value.trim();
        if (prev) { if (/^(https:\/\/|\/)/.test(v)) { prev.src = v; prev.hidden = false; } else { prev.hidden = true; prev.removeAttribute('src'); } }
    }
    url.addEventListener('input', showPrev);
    function setUrl(v) {
        url.value = v;
        url.dispatchEvent(new Event('input', { bubbles: true }));
        showPrev();
    }
    // An in-house supply defaults to the In-house supplies folder, anything else to Inventory (until someone picks).
    var folderTouched = false;
    if (folderSel) folderSel.addEventListener('change', function () { folderTouched = true; });
    if (typeSel && folderSel) typeSel.addEventListener('change', function () {
        if (!folderTouched) folderSel.value = typeSel.value === 'supply' ? 'supplies' : 'inventory';
    });
    function slug(s) {
        s = String(s || '');
        if (s.normalize) s = s.normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
        return s.toLowerCase().replace(/[^a-z0-9_\-]+/g, '-').replace(/-{2,}/g, '-').replace(/^[-_]+|[-_]+$/g, '').slice(0, 60);
    }

    // -- Upload to vault --
    fileIn.addEventListener('change', function () {
        var f = fileIn.files && fileIn.files[0];
        if (!f) return;
        var nameBox = form.querySelector('input[name="name"]');
        var fd = new FormData();
        fd.append('csrf_token', (form.querySelector('input[name="csrf_token"]') || {}).value || '');
        fd.append('image', f);
        fd.append('subfolder', folderSel ? folderSel.value : 'inventory');
        fd.append('filename', slug(nameBox && nameBox.value) || slug(f.name.replace(/\.[^.]+$/, '')));
        say('Uploading...');
        fetch('/admin/handlers/quick_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var d; try { d = JSON.parse(t); } catch (e) { throw new Error('The server sent something unexpected.'); }
                if (!d.ok) throw new Error(d.message || 'Upload failed.');
                setUrl(d.full_url || d.url);
                say('Saved to the vault as ' + (d.filename || 'a new photo') + '. Tap Save to keep it on this product.');
            })
            .catch(function (e) { say(e.message || 'Upload failed.', true); })
            .then(function () { fileIn.value = ''; });
    });

    // -- Pick from vault --
    var dlg = null, grid, tabs, info, search, all = [];
    var native = typeof HTMLDialogElement === 'function' && 'showModal' in HTMLDialogElement.prototype;
    function build() {
        dlg = document.createElement(native ? 'dialog' : 'div');
        dlg.className = 'ui-modal';
        dlg.innerHTML = '<div class="ui-modal-back" data-pv-close></div>'
            + '<div class="ui-modal-card pv-card" role="dialog" aria-modal="true" aria-labelledby="pvTitle" tabindex="-1">'
            + '<h2 id="pvTitle" class="ui-modal-title">Media vault</h2>'
            + '<input type="search" class="pv-search" placeholder="Filter by file name" aria-label="Filter by file name">'
            + '<div class="pv-tabs" role="group" aria-label="Folders"></div>'
            + '<p class="pv-info muted" role="status"></p><div class="pv-grid"></div>'
            + '<div class="ui-modal-btns"><button type="button" class="ui-modal-no" data-pv-close>Close</button></div></div>';
        document.body.appendChild(dlg);
        grid = dlg.querySelector('.pv-grid'); tabs = dlg.querySelector('.pv-tabs');
        info = dlg.querySelector('.pv-info'); search = dlg.querySelector('.pv-search');
        dlg.addEventListener('click', function (e) {
            if (e.target.closest('[data-pv-close]')) { close(); return; }
            var pick = e.target.closest('[data-pv-url]');
            if (pick) { setUrl(pick.getAttribute('data-pv-url')); say('Picked from the vault. Tap Save to keep it on this product.'); close(); return; }
            var tab = e.target.closest('[data-pv-folder]');
            if (tab) load(tab.getAttribute('data-pv-folder'));
        });
        if (native) dlg.addEventListener('close', function () { dlg.classList.remove('is-on'); });
        else document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && dlg.classList.contains('is-on')) close(); });
        search.addEventListener('input', render);
    }
    function close() { dlg.classList.remove('is-on'); if (native && dlg.open) dlg.close(); }
    function label(f) {
        if (f === '') return 'All';
        if (f === '_root') return 'Top level';
        var o = folderSel && folderSel.querySelector('option[value="' + f.replace(/"/g, '') + '"]');
        return o ? o.textContent.replace(/ \(.*\)$/, '') : f;
    }
    function drawTabs(folders, cur) {
        tabs.textContent = '';
        [''].concat(folders).forEach(function (f) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'pv-tab' + (f === cur ? ' is-on' : '');
            b.setAttribute('data-pv-folder', f); b.textContent = label(f);
            if (f === cur) b.setAttribute('aria-pressed', 'true');
            tabs.appendChild(b);
        });
    }
    function render() {
        var q = search.value.trim().toLowerCase();
        var list = all.filter(function (i) { return !q || i.filename.toLowerCase().indexOf(q) !== -1; });
        grid.textContent = '';
        list.slice(0, 120).forEach(function (i) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'pv-item';
            b.setAttribute('data-pv-url', i.full_url || i.url);
            var im = document.createElement('img'); im.src = i.url; im.alt = ''; im.loading = 'lazy';
            var s = document.createElement('span'); s.textContent = i.filename;
            b.appendChild(im); b.appendChild(s); grid.appendChild(b);
        });
        info.textContent = list.length ? list.length + ' photo' + (list.length === 1 ? '' : 's') + (list.length > 120 ? ' (first 120 shown; type to narrow)' : '') + '. Tap one to use it.' : 'No photos here yet.';
    }
    var folderList = null;
    function load(folder) {
        info.textContent = 'Loading...'; grid.textContent = '';
        var get = function (f) { return fetch('/admin/handlers/get_vault_images.php' + (f ? '?folder=' + encodeURIComponent(f) : ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); }); };
        // The folder tabs come from the All list (it names every folder that has photos).
        var tabsReady = folderList ? Promise.resolve() : get('').then(function (d) { folderList = (d && d.ok && d.folders) ? d.folders : []; });
        tabsReady.then(function () { return get(folder); }).then(function (d) {
            if (!d || !d.ok) throw new Error((d && d.message) || 'Could not open the vault.');
            if (folder && folderList.indexOf(folder) === -1) folderList.push(folder);
            drawTabs(folderList, folder);
            all = d.images || [];
            render();
        }).catch(function (e) { info.textContent = e.message || 'Could not open the vault.'; });
    }
    box.querySelector('[data-pphoto-pick]').addEventListener('click', function () {
        if (!dlg) build();
        search.value = '';
        dlg.classList.add('is-on');
        if (native && !dlg.open) dlg.showModal();
        dlg.querySelector('.pv-card').focus({ preventScroll: true });   // not the filter box: no phone keyboard popping up
        load(folderSel ? folderSel.value : '');
    });
})();
