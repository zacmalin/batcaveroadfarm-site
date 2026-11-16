// admin/assets/care-pick.js
// Animal care log form (admin/animal_care.php?type=N), Nov 15, 2026: "Tick a whole species or breed".
// Each animal checkbox carries data-species and data-breed keys; picking a group in #lg ticks every match
// (on top of what's already ticked), then the box goes back to "Pick a group…". "Untick all" clears every box.
// The status line (#lgh) says how many are ticked. Strict CSP: no inline script or styles.
// Nov 16, 2026: the Log button always says how many animals it will log for ("Log feeding for 7 animals"),
// counting the ticked boxes plus everyone in the picked enclosure (data-enc), each animal once.
(function () {
    'use strict';
    var save = document.querySelector('[data-care-save]');
    var form = save ? save.form : null;
    if (!form) return;
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="animals[]"]'));
    var encSel = form.querySelector('select[name="enclosure"]');
    var wrap = form.querySelector('[data-care-pick]');
    var pick = document.getElementById('lg');
    var hint = document.getElementById('lgh');
    var clear = wrap ? wrap.querySelector('[data-pick-clear]') : null;
    var what = save.getAttribute('data-what') || save.textContent;

    function ticked() { return boxes.filter(function (b) { return b.checked; }).length; }
    function total() {
        var enc = encSel ? encSel.value : '0';
        var n = 0;
        boxes.forEach(function (b) { if (b.checked || (enc !== '0' && b.getAttribute('data-enc') === enc)) n++; });
        return n;
    }
    function button() {
        var n = total();
        save.textContent = n ? what + ' for ' + n + ' animal' + (n === 1 ? '' : 's') : what;
    }
    function say(lead) {
        button();
        if (!hint) return;
        var n = ticked();
        var t = n === 0 ? 'None ticked.' : n + ' ticked in all.';
        hint.textContent = lead ? lead + ' ' + t : (n ? t : '');
    }

    if (pick) pick.addEventListener('change', function () {
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
    if (encSel) encSel.addEventListener('change', button);
    button();
})();
