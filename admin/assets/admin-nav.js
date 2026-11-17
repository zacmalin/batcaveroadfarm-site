// admin/assets/admin-nav.js
// Farm OS navigation (02 — Navigation & UX, Nov 5 2026). External file, no inline handlers (CSP).
// - Sidebar: accordion sections + icon-rail collapse (tablet 768–1199px defaults to the rail)
// - Phone (<768px): bottom bar with up to 4 shortcuts + More sheet; shortcuts editable, saved on your account
//   (Nov 15, 2026: admin/nav_prefs.php, migration 0068) so every phone you sign in on has them. A setup saved on
//   this phone before then is moved to your account the first time. Until 0068 is run they stay on the phone.
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
    var SC_KEY   = 'bcf_shortcuts_v1:' + (D.userKey || '');        // on-phone setup (before Nov 15, 2026 / before 0068)
    var SC_PEND  = 'bcf_shortcuts_pending:' + (D.userKey || '');   // a change the account didn't take yet (offline)
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
            var btn = document.querySelector('[data-sb-toggle="' + s + '"]');
            if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
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
    function localPins(key) {
        var s = store.get(key || SC_KEY);
        if (s) {
            try { return validIds(JSON.parse(s)); } catch (e) {}
        }
        return [];
    }
    function currentPins() {
        if (D.prefsReady) {
            var a = validIds(D.saved);
            if (a.length) return a;
        }
        var p = localPins();
        return p.length ? p : validIds(D.defaults);
    }
    // Save to the account; on failure keep a copy on this phone so nothing is lost.
    function saveAccount(list, done) {
        if (!D.prefsReady || !D.prefsUrl || !window.fetch) { done(false); return; }
        fetch(D.prefsUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf || '' },
            body: JSON.stringify({ shortcuts: list })
        }).then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (j) { done(!!(j && j.ok)); })
          .catch(function () { done(false); });
    }
    // One-time move: a setup saved on this phone before Nov 15, 2026 goes to the account if the account has none.
    // A change that failed to save earlier (no signal) is sent again and wins.
    if (D.prefsReady) {
        var pend = store.get(SC_PEND), legacy = localPins();
        if (pend !== null) {
            var pl = localPins(SC_PEND);
            D.saved = pl;
            saveAccount(pl, function (ok) { if (ok) store.del(SC_PEND); });
            store.del(SC_KEY);
        } else if (D.saved && D.saved.length) {
            store.del(SC_KEY);
        } else if (legacy.length) {
            D.saved = legacy;
            saveAccount(legacy, function (ok) { if (ok) store.del(SC_KEY); });
        }
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
                b.textContent = it.badge > 99 ? '99+' : it.badge;
                b.setAttribute('aria-label', it.badge + ' need attention');
                ic.appendChild(b);
                a.classList.add('has-badge');
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
        if (hint) { hint.textContent = ''; hint.classList.add('hidden'); }
        if (save) {
            var list = draft.slice();
            if (D.prefsReady) {
                D.saved = list;
                renderBar();
                store.del(SC_KEY);
                saveAccount(list, function (ok) {
                    if (ok) { store.del(SC_PEND); return; }
                    store.set(SC_PEND, JSON.stringify(list));
                    setHint('Saved on this phone for now. It will copy to your account next time a page opens with signal.');
                });
            } else {
                if (list.length) store.set(SC_KEY, JSON.stringify(list)); else store.del(SC_KEY);
                renderBar();
            }
        }
    }
    // Keep keyboard focus inside the phone More dialog, and restore it on close.
    var sheetReturnFocus = null;
    var sheetFocusSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    function sheetFocusable() {
        return Array.prototype.filter.call(sheet.querySelectorAll(sheetFocusSelector), function (el) {
            return el.getClientRects().length > 0 && !el.closest('[hidden], .hidden');
        });
    }
    function openSheet() {
        if (!sheet || !sheet.classList.contains('hidden')) return;
        sheetReturnFocus = document.activeElement;
        sheet.classList.remove('hidden');
        document.documentElement.classList.add('nav-sheet-open');
        if (moreBtn) moreBtn.setAttribute('aria-expanded', 'true');
        var focusable = sheetFocusable();
        if (focusable.length) focusable[0].focus();
    }
    function closeSheet() {
        if (!sheet || sheet.classList.contains('hidden')) return;
        if (editing) stopEdit(false);
        sheet.classList.add('hidden');
        document.documentElement.classList.remove('nav-sheet-open');
        if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
        var previous = sheetReturnFocus;
        sheetReturnFocus = null;
        if (previous && previous.isConnected && typeof previous.focus === 'function') previous.focus();
        else if (moreBtn) moreBtn.focus();
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
        if (!sheet || sheet.classList.contains('hidden')) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            closeSheet();
            return;
        }
        if (e.key !== 'Tab') return;
        var focusable = sheetFocusable();
        if (!focusable.length) { e.preventDefault(); return; }
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey && (document.activeElement === first || !sheet.contains(document.activeElement))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && (document.activeElement === last || !sheet.contains(document.activeElement))) {
            e.preventDefault();
            first.focus();
        }
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
