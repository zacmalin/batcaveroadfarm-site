(function () {
    // Reveal/hide each area's custom day editor based on its "same as general" checkbox.
    document.querySelectorAll('[data-scope-toggle]').forEach(function (cb) {
        var ed = document.getElementById('hours-' + cb.dataset.scopeToggle);
        function sync() { if (ed) ed.style.display = cb.checked ? 'none' : 'block'; }
        cb.addEventListener('change', sync);
        sync();
    });

    // Social links: add / remove rows. The per-row image widget (preview, Vault,
    // Upload) is wired by inline handlers from the shared vault picker partial.
    var rows = document.getElementById('siSocialRows');
    var tpl  = document.getElementById('siSocialTpl');
    var addB = document.getElementById('siSocialAdd');
    var nextIdx = Number(rows?.dataset.nextIndex || 0);
    if (rows) {
        rows.addEventListener('input', function (e) {
            var input = e.target.closest('[data-social-image-url]');
            if (input) updateImgPreview(input.id.slice('imgUrl_'.length));
        });
        rows.addEventListener('change', function (e) {
            var file = e.target.closest('[data-social-image-upload]');
            if (file) quickUploadImage(file, file.closest('.si-social-row').querySelector('[data-social-image-url]').id.slice('imgUrl_'.length));
        });
        rows.addEventListener('error', function (e) {
            if (e.target.matches('[data-social-preview-image]')) e.target.closest('[data-social-preview]').style.display = 'none';
        }, true);
        rows.addEventListener('click', function (e) {
            var vault = e.target.closest('[data-social-vault]');
            if (vault) { openVaultPicker(vault.closest('.si-social-row').querySelector('[data-social-image-url]').id.slice('imgUrl_'.length)); return; }
            var del = e.target.closest('.si-social-del');
            if (del) { var row = del.closest('.si-social-row'); if (row) row.remove(); }
        });
    }
    if (addB && tpl && rows) {
        addB.addEventListener('click', function () {
            var wrap = document.createElement('div');
            wrap.innerHTML = tpl.innerHTML.replace(/__I__/g, String(nextIdx++)).trim();
            var node = wrap.firstElementChild;
            rows.appendChild(node);
            var nameInp = node.querySelector('input[name$="[name]"]');
            if (nameInp) nameInp.focus();
        });
    }
})();
