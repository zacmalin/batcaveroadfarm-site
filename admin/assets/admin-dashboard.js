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
        var d = load(), hidden = 0, visible = 0, counts = { urgent: 0, today: 0, fyi: 0 };
        Array.prototype.forEach.call(list.querySelectorAll('[data-alert]'), function (card) {
            var id = card.getAttribute('data-alert'), sig = card.getAttribute('data-sig');
            var off = card.hasAttribute('data-dismissible') && d[id] === sig;
            card.hidden = off;
            if (off) hidden++; else { visible++; var priority = card.getAttribute('data-priority'); if (Object.prototype.hasOwnProperty.call(counts, priority)) counts[priority]++; }
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-priority-count]'), function (chip) {
            var priority = chip.getAttribute('data-priority-count');
            var count = counts[priority] || 0;
            chip.hidden = count === 0;
            chip.textContent = count + ' ' + (priority === 'fyi' ? 'FYI' : priority === 'urgent' ? 'Urgent' : 'Today');
        });
        if (restore) {
            restore.hidden = hidden === 0;
            restore.textContent = 'Show ' + hidden + ' dismissed';
        }
        if (allClear) {
            allClear.hidden = visible > 0;
            var title = allClear.querySelector('[data-clear-title]');
            var detail = allClear.querySelector('[data-clear-detail]');
            if (title) title.textContent = hidden ? 'No alerts displayed' : 'All clear';
            if (detail) detail.textContent = hidden
                ? 'You have dismissed FYI alerts. Use Show dismissed to review them.'
                : 'Anything that needs you (bookings, inquiries, low stock, system problems) shows up here.';
        }
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
