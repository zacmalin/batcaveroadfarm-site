// admin/assets/admin-dashboard.js
// Dashboard v1 (02 — Navigation & UX): dismiss FYI alerts in the Action Center.
// Dismissals are kept on this device and keyed to the alert's current count, so an alert
// comes back by itself when something new happens (e.g. a new comment arrives).
(function () {
    'use strict';
    var KEY = 'bcf_dash_dismissed_v1';
    function load() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
    function save(d) { try { localStorage.setItem(KEY, JSON.stringify(d)); } catch (e) {} }

    var list = document.getElementById('acList');
    if (!list) return;
    var restore = document.getElementById('acRestore');
    var allClear = document.getElementById('acAllClear');

    function apply() {
        var d = load(), hidden = 0, visible = 0;
        Array.prototype.forEach.call(list.querySelectorAll('[data-alert]'), function (card) {
            var id = card.getAttribute('data-alert'), sig = card.getAttribute('data-sig');
            var off = card.hasAttribute('data-dismissible') && d[id] === sig;
            card.hidden = off;
            if (off) hidden++; else visible++;
        });
        if (restore) {
            restore.hidden = hidden === 0;
            restore.textContent = 'Show ' + hidden + ' dismissed';
        }
        if (allClear) allClear.hidden = visible > 0;
    }

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-dismiss]');
        if (!btn) return;
        var card = btn.closest('[data-alert]');
        if (!card) return;
        var d = load();
        d[card.getAttribute('data-alert')] = card.getAttribute('data-sig');
        save(d);
        apply();
    });
    if (restore) restore.addEventListener('click', function () { save({}); apply(); });
    apply();
}());
