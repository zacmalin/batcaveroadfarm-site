// admin/assets/admin-nav.js
// Farm OS navigation (02 — Navigation & UX, Nov 5 2026). External file, no inline handlers (CSP).
// - Sidebar: accordion sections + icon-rail collapse (tablet 768–1199px defaults to the rail)
// - Phone (<768px): bottom bar with up to 4 shortcuts + More sheet; shortcuts editable, saved on this device
// - Page tabs for grouped tools are moved to the top of <main>
(function () {
    'use strict';

    var dataEl = document.getElementById('navData');
    if (!dataEl) return;
    var D;
    try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }

    var store = {
        get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
        del: function (k) { try { localStorage.removeItem(k); } catch (e) {} }
    };
    var COLL_KEY = 'bcf_sidebar_collapsed';
    var SECT_KEY = 'bcf_sidebar_open';
    var SC_KEY   = 'bcf_shortcuts_v1:' + (D.userKey || '');
    var MAX_PINS = 4;

    // ── Sidebar collapse (icon rail) ────────────────────────────────────────
    var aside = document.getElementById('adminSidebar');
    function applyCollapse(on) {
        if (aside) aside.classList.toggle('sb-collapsed', on);
        var ic = document.getElementById('sbCollapseIcon');
        if (ic) ic.textContent = on ? '»' : '«';
    }
    var storedColl = store.get(COLL_KEY);
    var isTablet = window.matchMedia && window.matchMedia('(min-width:768px) and (max-width:1199.98px)').matches;
    applyCollapse(storedColl === '1' || (storedColl === null && isTablet));
    var collBtn = document.getElementById('sbCollapseBtn');
    if (collBtn) collBtn.addEventListener('click', function () {
        var now = !(aside && aside.classList.contains('sb-collapsed'));
        applyCollapse(now);
        store.set(COLL_KEY, now ? '1' : '0');
    });

    // ── Sidebar accordion (one section open) ────────────────────────────────
    var sections = D.sections || [];
    function setOpen(name) {
        sections.forEach(function (s) {
            var body = document.getElementById('sec-' + s);
            var arr  = document.getElementById('arr-' + s);
            var open = (s === name);
            if (body) body.style.display = open ? '' : 'none';
            if (arr)  arr.textContent = open ? '▾' : '▸';
        });
    }
    var storedSect = store.get(SECT_KEY);
    var initial = D.currentSection || (sections.indexOf(storedSect) >= 0 ? storedSect : null);
    setOpen(initial);
    store.set(SECT_KEY, initial || '');
    Array.prototype.forEach.call(document.querySelectorAll('[data-sb-toggle]'), function (btn) {
        btn.addEventListener('click', function () {
            var name = btn.getAttribute('data-sb-toggle');
            var body = document.getElementById('sec-' + name);
            if (!body) return;
            var next = body.style.display !== 'none' ? null : name;
            setOpen(next);
            store.set(SECT_KEY, next || '');
        });
    });

    // ── Phone bottom bar ────────────────────────────────────────────────────
    var cat = D.catalog || {};
    function validIds(list) {
        var out = [];
        (list || []).forEach(function (id) {
            if (cat[id] && out.indexOf(id) < 0 && out.length < MAX_PINS) out.push(id);
        });
        return out;
    }
    function currentPins() {
        var s = store.get(SC_KEY);
        if (s) {
            try { var p = validIds(JSON.parse(s)); if (p.length) return p; } catch (e) {}
        }
        return validIds(D.defaults);
    }
    var slots   = document.getElementById('navBarSlots');
    var moreBtn = document.getElementById('navMoreBtn');
    function renderBar() {
        if (!slots || !moreBtn) return;
        Array.prototype.forEach.call(slots.querySelectorAll('[data-sc-slot]'), function (n) { n.parentNode.removeChild(n); });
        currentPins().forEach(function (id) {
            var it = cat[id];
            var a = document.createElement('a');
            a.href = it.href;
            a.className = 'nav-slot' + (it.active ? ' is-active' : '');
            a.setAttribute('data-sc-slot', id);
            if (it.active) a.setAttribute('aria-current', 'page');
            var ic = document.createElement('span');
            ic.className = 'nav-slot-icon';
            ic.textContent = it.icon;
            if (it.badge) {
                var b = document.createElement('span');
                b.className = 'nav-badge';
                b.textContent = it.badge;
                ic.appendChild(b);
            }
            var lb = document.createElement('span');
            lb.className = 'nav-slot-label';
            lb.textContent = it.short || it.label;
            a.appendChild(ic);
            a.appendChild(lb);
            slots.insertBefore(a, moreBtn);
        });
    }
    renderBar();

    // ── More sheet + shortcut editing ───────────────────────────────────────
    var sheet   = document.getElementById('navMoreSheet');
    var editBtn = document.getElementById('navEditBtn');
    var resetBtn = document.getElementById('navResetBtn');
    var hint    = document.getElementById('navEditHint');
    var editing = false;
    var draft   = [];

    function markPins(pins) {
        if (!sheet) return;
        Array.prototype.forEach.call(sheet.querySelectorAll('[data-sc-id]'), function (t) {
            t.classList.toggle('is-pinned', pins.indexOf(t.getAttribute('data-sc-id')) >= 0);
        });
    }
    function setHint(msg) {
        if (!hint) return;
        hint.classList.remove('hidden');
        hint.textContent = msg || ('Tap to pin up to ' + MAX_PINS + ' shortcuts (' + draft.length + ' of ' + MAX_PINS + ' pinned). Tap Done to save.');
    }
    function startEdit() {
        editing = true;
        draft = currentPins().slice();
        sheet.classList.add('is-editing');
        if (editBtn) editBtn.textContent = 'Done';
        if (resetBtn) resetBtn.classList.remove('hidden');
        markPins(draft);
        setHint();
    }
    function stopEdit(save) {
        editing = false;
        sheet.classList.remove('is-editing');
        if (editBtn) editBtn.textContent = 'Edit shortcuts';
        if (resetBtn) resetBtn.classList.add('hidden');
        if (save) {
            if (draft.length) store.set(SC_KEY, JSON.stringify(draft)); else store.del(SC_KEY);
            renderBar();
        }
        if (hint) { hint.textContent = ''; hint.classList.add('hidden'); }
    }
    function openSheet() {
        if (!sheet) return;
        sheet.classList.remove('hidden');
        document.documentElement.classList.add('nav-sheet-open');
        if (moreBtn) moreBtn.setAttribute('aria-expanded', 'true');
    }
    function closeSheet() {
        if (!sheet) return;
        if (editing) stopEdit(false);
        sheet.classList.add('hidden');
        document.documentElement.classList.remove('nav-sheet-open');
        if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
    }

    if (moreBtn) moreBtn.addEventListener('click', openSheet);
    if (editBtn) editBtn.addEventListener('click', function () { if (editing) stopEdit(true); else startEdit(); });
    if (resetBtn) resetBtn.addEventListener('click', function () {
        draft = validIds(D.defaults);
        markPins(draft);
        setHint('Back to the defaults for your role. Tap Done to save.');
    });
    if (sheet) {
        sheet.addEventListener('click', function (e) {
            if (e.target.closest('[data-nav-close]')) { e.preventDefault(); closeSheet(); return; }
            if (!editing) return;
            var tile = e.target.closest('[data-sc-id]');
            if (!tile) return;
            e.preventDefault();
            var id = tile.getAttribute('data-sc-id');
            var i = draft.indexOf(id);
            if (i >= 0) {
                draft.splice(i, 1);
            } else if (draft.length >= MAX_PINS) {
                setHint('You already have ' + MAX_PINS + ' shortcuts. Unpin one first.');
                return;
            } else {
                draft.push(id);
            }
            markPins(draft);
            setHint();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet && !sheet.classList.contains('hidden')) closeSheet();
    });

    // ── "/" opens search (02 › Search, Nov 13 2026), unless typing in a field ──
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) return;
        var t = e.target, tag = t && t.tagName ? t.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
        if (!D.catalog || !D.catalog.search) return;
        var box = document.getElementById('srchBox');
        e.preventDefault();
        if (box) { box.focus(); box.select(); return; }
        window.location.href = '/admin/search.php';
    });

    // ── Page tabs: move to the top of <main> ────────────────────────────────
    function mountTabs() {
        var tabs = document.getElementById('navTabs');
        var main = document.querySelector('body > main');
        if (!tabs || !main) return;
        main.insertBefore(tabs, main.firstChild);
        tabs.hidden = false;
        var strip = tabs.querySelector('.nav-tabs');
        var act = tabs.querySelector('.nav-tab.is-active');
        if (strip && act) strip.scrollLeft = Math.max(0, act.offsetLeft - 16);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountTabs);
    else mountTabs();
}());
