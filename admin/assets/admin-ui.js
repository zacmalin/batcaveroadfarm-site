// admin/assets/admin-ui.js
// Shared UI kit behaviour — 02 Navigation & UX (Nov 5, 2026). Replaces common inline handlers (CSP).
//  - table.ui-cards: copies <thead> text into each cell's data-label (phone card labels)
//  - [data-close-details]: closes the surrounding <details>
//  - img[data-fallback]: if the image fails, hide it and show the next element (placeholder)
//  - form[data-autosave="key"]: keeps unsaved edits on this device for 24 hours and offers to restore them
//    after an interruption (phone call, app switch, dead battery). Skips hidden fields (except rich text),
//    passwords and files. Never put data-autosave on forms with payment or personal details.
//  - form[data-switch]: on/off switch that saves in the background, with Undo (Nov 14, 2026; includes/admin_switch.php)
//  - input[type=checkbox][data-confirm-on|off]: asks "Are you sure?" on submit only when that box changed
(function () {
    'use strict';

    // ── Device drafts ───────────────────────────────────────────────────────
    var DRAFT_TTL = 24 * 3600 * 1000;
    var DRAFT_SKIP = /^(csrf_token|id|save_schema_record|post_status)$/;

    function draftFields(form) {
        return Array.prototype.filter.call(form.elements, function (el) {
            if (!el.name || el.disabled || DRAFT_SKIP.test(el.name) || el.hasAttribute('data-no-autosave')) return false;
            var t = (el.type || '').toLowerCase();
            if (t === 'password' || t === 'file' || t === 'submit' || t === 'button' || t === 'reset') return false;
            if (t === 'hidden' && !el.hasAttribute('data-quill')) return false;
            return true;
        });
    }
    function draftKeyed(form, fn) {
        var seen = {};
        draftFields(form).forEach(function (el) {
            var t = (el.type || '').toLowerCase();
            var k = (t === 'checkbox' || t === 'radio') ? el.name + '=' + el.value : el.name;
            seen[k] = (seen[k] || 0) + 1;
            fn(k + '#' + seen[k], el, t);
        });
    }
    function draftSnapshot(form) {
        var o = {};
        draftKeyed(form, function (k, el, t) {
            if (t === 'checkbox' || t === 'radio') o[k] = el.checked;
            else if (el.multiple) o[k] = Array.prototype.filter.call(el.options, function (op) { return op.selected; }).map(function (op) { return op.value; });
            else o[k] = el.value;
        });
        return o;
    }
    function draftApply(form, v) {
        draftKeyed(form, function (k, el, t) {
            if (!(k in v)) return;
            if (t === 'checkbox' || t === 'radio') el.checked = !!v[k];
            else if (el.multiple) Array.prototype.forEach.call(el.options, function (op) { op.selected = v[k].indexOf(op.value) >= 0; });
            else el.value = v[k];
            var q = el.getAttribute('data-quill');
            if (q && window.bcfQuill && window.bcfQuill[q]) window.bcfQuill[q].root.innerHTML = v[k];
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        form.dataset.dirty = 'true';
    }
    function initAutosave(form) {
        var key = 'bcf_draft:' + form.getAttribute('data-autosave');
        // data-autosave-confirm: the server says when a save worked (data-autosave-clear names the draft to drop)
        // or failed (data-autosave-restore puts the typed values straight back). Without it, submit drops the draft.
        var confirmMode = form.hasAttribute('data-autosave-confirm');
        var clearKey = form.getAttribute('data-autosave-clear');
        if (clearKey) { try { localStorage.removeItem('bcf_draft:' + clearKey); } catch (e) {} }
        var base = JSON.stringify(draftSnapshot(form));
        var saved = null;
        try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) {}

        if (saved && saved.v && form.hasAttribute('data-autosave-restore')) {
            draftApply(form, saved.v);
            var note = document.createElement('div');
            note.setAttribute('role', 'status');
            note.className = 'mb-4 p-3 rounded-lg border border-red-300 bg-red-50 text-red-900 text-xs font-semibold';
            note.textContent = "That save didn't go through, so your changes are back in the form. Fix the problem and tap Save again.";
            form.parentNode.insertBefore(note, form);
        } else if (saved && saved.t && Date.now() - saved.t < DRAFT_TTL && JSON.stringify(saved.v) !== base) {
            var bar = document.createElement('div');
            bar.setAttribute('role', 'status');
            bar.className = 'mb-4 p-3 rounded-lg border border-amber-300 bg-amber-50 text-amber-900 text-xs flex flex-wrap items-center gap-2';
            var msg = document.createElement('span');
            msg.className = 'flex-1 min-w-[12rem] font-semibold';
            msg.textContent = 'You have unsaved changes from ' + new Date(saved.t).toLocaleString([], { weekday: 'short', hour: 'numeric', minute: '2-digit' }) + ' on this device.';
            var yes = document.createElement('button');
            yes.type = 'button'; yes.textContent = 'Restore';
            yes.className = 'min-h-10 md:min-h-8 px-3 rounded-lg bg-stone-900 text-white font-bold';
            var no = document.createElement('button');
            no.type = 'button'; no.textContent = 'Discard';
            no.className = 'min-h-10 md:min-h-8 px-3 rounded-lg bg-white border border-amber-300 font-bold';
            yes.addEventListener('click', function () { draftApply(form, saved.v); bar.remove(); });
            no.addEventListener('click', function () { try { localStorage.removeItem(key); } catch (e) {} bar.remove(); });
            bar.append(msg, yes, no);
            form.parentNode.insertBefore(bar, form);
        } else if (saved) {
            try { localStorage.removeItem(key); } catch (e) {}
        }

        var timer = null;
        function store() {
            var now = JSON.stringify(draftSnapshot(form));
            try {
                if (now === base) localStorage.removeItem(key);
                else localStorage.setItem(key, JSON.stringify({ t: Date.now(), v: JSON.parse(now) }));
            } catch (e) { /* storage full or blocked: drafts are a convenience only */ }
        }
        function later() { clearTimeout(timer); timer = setTimeout(store, 700); }
        form.addEventListener('input', later);
        form.addEventListener('change', later);
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { clearTimeout(timer); store(); } });
        form.addEventListener('submit', function () {
            clearTimeout(timer);
            if (confirmMode) { store(); return; }   // keep it until the server confirms the save
            try { localStorage.removeItem(key); } catch (e) {}
        });
    }

    function labelTables() {
        Array.prototype.forEach.call(document.querySelectorAll('table.ui-cards'), function (t) {
            var head = t.tHead && t.tHead.rows[0];
            if (!head) return;
            var names = Array.prototype.map.call(head.cells, function (c) { return (c.getAttribute('data-label') || c.textContent || '').trim(); });
            Array.prototype.forEach.call(t.tBodies, function (tb) {
                Array.prototype.forEach.call(tb.rows, function (r) {
                    if (r.querySelector('td[colspan]')) return;
                    Array.prototype.forEach.call(r.cells, function (c, i) {
                        if (!c.hasAttribute('data-label')) c.setAttribute('data-label', names[i] || '');
                    });
                });
            });
        });
    }

    function showFallback(img) {
        img.style.display = 'none';
        var n = img.nextElementSibling;
        if (n) { n.classList.remove('hidden'); n.style.display = 'flex'; }
    }
    document.addEventListener('error', function (e) {
        var t = e.target;
        if (t && t.tagName === 'IMG' && t.hasAttribute('data-fallback')) showFallback(t);
    }, true);

    document.addEventListener('click', function (e) {
        var c = e.target.closest && e.target.closest('[data-close-details]');
        if (!c) return;
        var d = c.closest('details');
        if (d) d.open = false;
    });

    // ── On/off switches (includes/admin_switch.php) ─────────────────────────
    // form[data-switch] saves in the background and shows "Saved · Undo". Without this script the form simply posts.
    var toastBox = null, toastTimer = null;
    function toast(msg, isErr, undo) {
        if (!toastBox) {
            toastBox = document.createElement('div');
            toastBox.className = 'ui-switch-toast';
            toastBox.setAttribute('role', 'status');
            toastBox.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastBox);
        }
        clearTimeout(toastTimer);
        toastBox.textContent = '';
        toastBox.classList.toggle('is-err', !!isErr);
        var m = document.createElement('span');
        m.textContent = msg;
        toastBox.appendChild(m);
        if (undo) {
            var b = document.createElement('button');
            b.type = 'button'; b.textContent = 'Undo';
            b.addEventListener('click', function () { toastBox.classList.remove('is-on'); undo(); });
            toastBox.appendChild(b);
        }
        toastBox.classList.add('is-on');
        toastTimer = setTimeout(function () { toastBox.classList.remove('is-on'); }, isErr ? 7000 : 5000);
    }
    function switchShow(form, on) {
        var b = form.querySelector('.ui-switch');
        b.setAttribute('aria-checked', on ? 'true' : 'false');
        var t = b.querySelector('.ui-switch-text');
        if (t) t.textContent = on ? (b.getAttribute('data-on-text') || 'On') : (b.getAttribute('data-off-text') || 'Off');
        form.querySelector('input[name="on"]').value = on ? '0' : '1';   // what a plain submit would ask for next
        var row = form.closest('[data-switch-row]');
        if (row) row.classList.toggle('is-off', !on);
    }
    function switchSave(form, want, offerUndo) {
        var b = form.querySelector('.ui-switch');
        if (b.getAttribute('aria-busy') === 'true') return;
        var was = b.getAttribute('aria-checked') === 'true';
        switchShow(form, want);
        b.setAttribute('aria-busy', 'true');
        var fd = new FormData(form);
        fd.set('on', want ? '1' : '0');
        fd.set('ajax', '1');
        fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, msg: "Couldn't save. Reload the page and try again." }; }); })
            .then(function (j) {
                b.removeAttribute('aria-busy');
                if (!j || !j.ok) { switchShow(form, was); toast((j && j.msg) || "Couldn't save. Try again.", true); return; }
                var now = j.on === null || j.on === undefined ? want : !!j.on;
                switchShow(form, now);
                toast(j.msg || 'Saved.', false, offerUndo && now !== was ? function () { switchSave(form, was, false); } : null);
            })
            .catch(function () { b.removeAttribute('aria-busy'); switchShow(form, was); toast("Couldn't reach the server. Check the connection and try again.", true); });
    }
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.hasAttribute || !f.hasAttribute('data-switch') || !window.fetch || !window.FormData) return;
        e.preventDefault();
        var b = f.querySelector('.ui-switch');
        if (b && !b.disabled) switchSave(f, b.getAttribute('aria-checked') !== 'true', true);
    });

    // ── "Are you sure?" only when a checkbox actually changed ───────────────
    // <input type="checkbox" data-confirm-on="…" data-confirm-off="…">: asked on Save if that box was ticked / unticked.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.querySelectorAll || e.defaultPrevented) return;
        var boxes = f.querySelectorAll('input[type=checkbox][data-confirm-on], input[type=checkbox][data-confirm-off]');
        for (var i = 0; i < boxes.length; i++) {
            var c = boxes[i];
            if (c.checked === c.defaultChecked) continue;
            var msg = c.getAttribute(c.checked ? 'data-confirm-on' : 'data-confirm-off');
            if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
        }
    });

    function init() {
        labelTables();
        Array.prototype.forEach.call(document.querySelectorAll('form[data-autosave]'), function (f) {
            try { initAutosave(f); } catch (e) { /* never break the page */ }
        });
        // Images that already failed before this script ran
        Array.prototype.forEach.call(document.querySelectorAll('img[data-fallback]'), function (img) {
            if (img.complete && img.naturalWidth === 0) showFallback(img);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
