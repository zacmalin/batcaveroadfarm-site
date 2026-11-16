
    // Output Filename: prefill from the picked file (or the remote URL) unless staff typed their own.
    (function () {
        var out = document.getElementById('gdOutputName');
        var fileIn = document.getElementById('gdSourceFile');
        var urlIn  = document.getElementById('gdSourceUrl');
        if (!out) return;
        function slug(n) {
            var s = String(n || '').replace(/\.[^.\/]+$/, '');
            if (s.normalize) s = s.normalize('NFKD').replace(/[̀-ͯ]/g, '');
            return s.toLowerCase().replace(/[^a-z0-9_\-]+/g, '-').replace(/-{2,}/g, '-').replace(/^[-_]+|[-_]+$/g, '').slice(0, 80);
        }
        function fill(name) {
            if (out.value.trim() !== '' && out.dataset.auto !== '1') return;   // keep what they typed
            out.value = slug(name);
            out.dataset.auto = '1';
        }
        out.addEventListener('input', function () { out.dataset.auto = ''; });
        if (fileIn) fileIn.addEventListener('change', function () {
            if (fileIn.files && fileIn.files[0]) { fill(fileIn.files[0].name); out.focus(); out.select(); }
        });
        if (urlIn) urlIn.addEventListener('change', function () {
            try { var p = new URL(urlIn.value).pathname.split('/').pop(); if (p) fill(decodeURIComponent(p)); } catch (e) {}
        });
    })();

    function copyVaultPath(btn, url) {
        navigator.clipboard.writeText(url).then(() => {
            const orig = btn.textContent;
            btn.textContent = "Copied ✓";
            btn.classList.add('bg-amber-700', 'text-white');
            btn.classList.remove('bg-stone-100', 'text-stone-700');
            setTimeout(() => {
                btn.textContent = orig;
                btn.classList.remove('bg-amber-700', 'text-white');
                btn.classList.add('bg-stone-100', 'text-stone-700');
            }, 2000);
        }).catch(() => {
            // Fallback for browsers that block clipboard without HTTPS
            prompt('Copy this path:', url);
        });
    }

    document.querySelectorAll('.vault-copy-btn').forEach(btn => btn.addEventListener('click', () => copyVaultPath(btn, btn.dataset.copyUrl)));
    document.querySelectorAll('[data-vault-image]').forEach(img => img.addEventListener('error', () => { img.style.display = 'none'; if (img.nextElementSibling) img.nextElementSibling.style.display = 'flex'; }));
