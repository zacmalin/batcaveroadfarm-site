// admin/assets/quotes.js — Quotes & payments page (admin/quotes.php; Farm OS Phase 3 slice 4, Nov 12, 2026).
// Live line and quote totals while typing, Copy for the customer's link, and a confirm before sending, refunding
// or removing. The server re-checks everything; this is only for the person typing.
(function () {
  'use strict';
  function cents(v) {
    var s = String(v || '').replace(/[$,\s]/g, '');
    if (!/^\d{1,7}(\.\d{1,2})?$/.test(s)) return null;
    var p = s.split('.');
    return parseInt(p[0], 10) * 100 + (p[1] ? parseInt((p[1] + '0').slice(0, 2), 10) : 0);
  }
  function money(c) { return '$' + (c / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  var editor = document.getElementById('q-editor');
  if (editor) {
    var total = document.getElementById('q-total');
    var redo = function () {
      var t = 0;
      editor.querySelectorAll('[data-q-line]').forEach(function (row) {
        var q = parseInt(row.querySelector('[data-q-qty]').value, 10);
        var u = cents(row.querySelector('[data-q-unit]').value);
        var out = row.querySelector('[data-q-sum]');
        if (q > 0 && u !== null) { t += q * u; out.textContent = money(q * u); } else { out.textContent = ''; }
      });
      if (total) total.textContent = money(t);
    };
    editor.addEventListener('input', redo);
    redo();
  }

  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.getAttribute('data-copy'));
      if (!el) return;
      el.select();
      var done = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); };
      if (navigator.clipboard) navigator.clipboard.writeText(el.value).then(done, function () { document.execCommand('copy'); done(); });
      else { document.execCommand('copy'); done(); }
    });
  });

  document.querySelectorAll('button[data-confirm]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm'))) e.preventDefault(); });
  });
})();
