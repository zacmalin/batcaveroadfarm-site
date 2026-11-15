// admin/assets/care-pick.js
// Animal care log form (admin/animal_care.php?type=N), Nov 15, 2026: "Tick a whole species or breed".
// Each animal checkbox carries data-species and data-breed keys; picking a group in #lg ticks every match
// (on top of what's already ticked), then the box goes back to "Pick a group…". "Untick all" clears every box.
// The status line (#lgh) says how many are ticked. Strict CSP: no inline script or styles.
(function () {
    'use strict';
    var wrap = document.querySelector('[data-care-pick]');
    var pick = document.getElementById('lg');
    var hint = document.getElementById('lgh');
    if (!wrap || !pick) return;
    var form = pick.form;
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="animals[]"]'));
    var clear = wrap.querySelector('[data-pick-clear]');

    function say(lead) {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        var total = n === 0 ? 'None ticked.' : n + ' ticked in all.';
        if (hint) hint.textContent = lead ? lead + ' ' + total : (n ? total : '');
    }

    pick.addEventListener('change', function () {
        var v = pick.value;
        if (!v) return;
        var kind = v.slice(0, v.indexOf(':'));
        var key = v.slice(v.indexOf(':') + 1);
        var attr = kind === 'breed' ? 'data-breed' : 'data-species';
        var added = 0;
        boxes.forEach(function (b) {
            if (b.getAttribute(attr) === key && !b.checked) { b.checked = true; added++; }
        });
        var label = pick.options[pick.selectedIndex].text.replace(/^All /, '').replace(/ \(\d+\)$/, '');
        pick.value = '';
        say(added ? 'Ticked ' + added + ' more: ' + label + '.' : 'All ' + label + ' were already ticked.');
    });

    if (clear) clear.addEventListener('click', function () {
        boxes.forEach(function (b) { b.checked = false; });
        say('');
    });

    boxes.forEach(function (b) { b.addEventListener('change', function () { say(''); }); });
})();
