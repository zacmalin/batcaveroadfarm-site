// admin/assets/feed-units.js
// Animal care log form (admin/animal_care.php?type=N), Nov 15, 2026: the amount of feed can be entered in scoops, lb, oz
// or bags. Each feed <option> carries data-units (JSON list of {key, label, g}), data-unit-g (grams in one bag) and
// data-last (the unit used last time for that feed). Picking a feed rebuilds the unit list, picks the last unit used and
// shows what the amount comes to ("= 8 lb, 0.16 of a bag"). Feeds with no bag weight keep the old "in the product's unit" box.
// Strict CSP: no inline script or styles.
(function () {
    'use strict';
    var feed = document.getElementById('lf');
    var qty = document.getElementById('lq');
    var unit = document.getElementById('lu');
    var hint = document.getElementById('lqh');
    var qtyLabel = document.querySelector('label[for="lq"]');
    if (!feed || !qty || !unit) return;
    var unitWrap = unit.closest('[data-unit-wrap]') || unit.parentNode;

    function opt() { return feed.options[feed.selectedIndex] || null; }
    function units(o) {
        try { return JSON.parse((o && o.getAttribute('data-units')) || '[]'); } catch (e) { return []; }
    }
    function num(s) { var n = parseFloat(String(s || '').replace(/,/g, '')); return isFinite(n) ? n : 0; }
    function trim(n, d) { return String(Math.round(n * Math.pow(10, d)) / Math.pow(10, d)); }
    function weight(lb) { return lb > 0 && lb < 1 ? trim(lb * 16, 1) + ' oz' : trim(lb, 2) + ' lb'; }

    function rebuild() {
        var o = opt(), list = units(o);
        unit.innerHTML = '';
        if (!o || o.value === '0' || !list.length) {
            unitWrap.hidden = true;
            unit.disabled = true;
            if (qtyLabel) qtyLabel.textContent = o && o.value !== '0' ? "Amount (in the product's unit)" : 'Amount';
            show();
            return;
        }
        var last = o.getAttribute('data-last') || '', pick = '';
        list.forEach(function (u) {
            var op = document.createElement('option');
            op.value = u.key; op.textContent = u.label; op.setAttribute('data-g', String(u.g)); op.setAttribute('data-name', u.name || u.label);
            unit.appendChild(op);
            if (u.name === last || u.key === last) pick = u.key;
        });
        unit.value = pick || list[0].key;
        unit.disabled = false;
        unitWrap.hidden = false;
        if (qtyLabel) qtyLabel.textContent = 'Amount';
        show();
    }

    function show() {
        if (!hint) return;
        var o = opt(), u = unit.options[unit.selectedIndex];
        var n = num(qty.value), bagG = num(o && o.getAttribute('data-unit-g'));
        if (!o || o.value === '0' || unit.disabled || !u || n <= 0 || bagG <= 0) { hint.textContent = ''; return; }
        var g = n * num(u.getAttribute('data-g'));
        var bags = g / bagG;
        var txt = '= ' + weight(g / 453.59237);
        if (u.value !== 'bag' && o.getAttribute('data-word') === 'bag') txt += ', ' + trim(bags, 3) + ' of a bag';
        hint.textContent = txt;
    }

    feed.addEventListener('change', rebuild);
    unit.addEventListener('change', show);
    qty.addEventListener('input', show);
    rebuild();
})();
