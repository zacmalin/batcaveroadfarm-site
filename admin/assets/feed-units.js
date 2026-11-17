// Repeatable animal feeding rows. Strict CSP: no inline handlers.
(function () {
    'use strict';
    var holder = document.querySelector('[data-feed-rows]');
    var add = document.querySelector('[data-feed-add]');
    if (!holder || !add) return;
    var serial = 1;
    function number(value) { var n = Number(String(value || '').replace(/,/g, '')); return isFinite(n) ? n : 0; }
    function trim(n, d) { return String(Math.round(n * Math.pow(10, d)) / Math.pow(10, d)); }
    function initialize(row) {
        var feed = row.querySelector('select[name$="[product]"]');
        var qty = row.querySelector('input[name$="[qty]"]');
        var unit = row.querySelector('select[name$="[unit]"]');
        var wrap = row.querySelector('[data-unit-wrap]');
        var hint = row.querySelector('[data-feed-hint]');
        var qtyLabel = row.querySelector('label[for^="lq"]');
        function show() {
            var o = feed.options[feed.selectedIndex];
            var u = unit.options[unit.selectedIndex];
            var grams = number(u && u.getAttribute('data-g')) * number(qty.value);
            var bag = number(o && o.getAttribute('data-unit-g'));
            hint.textContent = !unit.disabled && grams > 0 && bag > 0
                ? '= ' + trim(grams / 453.59237, 2) + ' lb' + (o.getAttribute('data-word') === 'bag' ? ', ' + trim(grams / bag, 3) + ' of a bag' : '')
                : '';
        }
        function rebuild() {
            var o = feed.options[feed.selectedIndex], units = [];
            try { units = JSON.parse(o.getAttribute('data-units') || '[]'); } catch (e) {}
            unit.innerHTML = '';
            units.forEach(function (u) {
                var option = document.createElement('option');
                option.value = u.key; option.textContent = u.label;
                option.setAttribute('data-g', String(u.g));
                option.setAttribute('data-name', u.name || u.label);
                unit.appendChild(option);
                if (u.name === o.getAttribute('data-last') || u.key === o.getAttribute('data-last')) unit.value = u.key;
            });
            wrap.hidden = !units.length;
            unit.disabled = !units.length;
            if (qtyLabel) qtyLabel.textContent = o.value !== '' && !units.length ? "Amount (in product's unit)" : 'Amount';
            show();
        }
        feed.addEventListener('change', rebuild);
        qty.addEventListener('input', show);
        unit.addEventListener('change', show);
        row.querySelector('[data-feed-remove]').addEventListener('click', function () { row.remove(); refresh(); });
        rebuild();
    }
    function refresh() {
        var rows = holder.querySelectorAll('[data-feed-row]');
        rows.forEach(function (row, i) {
            row.querySelector('[data-feed-title]').textContent = 'Feed ' + (i + 1);
            row.querySelector('[data-feed-remove]').style.display = rows.length === 1 ? 'none' : 'inline-flex';
        });
        add.disabled = rows.length >= 30;
    }
    initialize(holder.querySelector('[data-feed-row]'));
    add.addEventListener('click', function () {
        if (holder.querySelectorAll('[data-feed-row]').length >= 30) return;
        var row = holder.querySelector('[data-feed-row]').cloneNode(true);
        var index = serial++;
        row.querySelectorAll('[name]').forEach(function (field) {
            field.name = field.name.replace(/^feeds\[[0-9]+\]/, 'feeds[' + index + ']');
            if (field.tagName === 'SELECT') field.selectedIndex = 0;
            else field.value = '';
            var oldId = field.getAttribute('id');
            if (oldId) {
                var newId = oldId + '-' + index;
                field.id = newId;
                row.querySelectorAll('label[for]').forEach(function (label) {
                    if (label.getAttribute('for') === oldId) label.setAttribute('for', newId);
                });
            }
        });
        holder.appendChild(row);
        initialize(row);
        refresh();
        row.querySelector('select[name$="[product]"]').focus();
    });
    refresh();
})();
