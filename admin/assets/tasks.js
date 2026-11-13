// admin/assets/tasks.js — Tasks screen helpers (Farm OS Phase 3, Nov 11, 2026; admin/tasks.php)
// Tick checklist items without reloading; show only the chore fields that apply; confirm before cancelling; Start turns into Stop once an item is ticked.
// Everything still works without this file (plain form posts).
(function () {
  'use strict';
  var on = function (el, ev, fn) { if (el) el.addEventListener(ev, fn); };

  // ── Checklist ticks ──
  document.querySelectorAll('form[data-check]').forEach(function (form) {
    on(form, 'submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button');
      if (btn.disabled) return;
      btn.disabled = true;
      var fd = new FormData(form);
      fd.append('ajax', '1');
      fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          btn.disabled = false;
          if (!j.ok) { alertBox(j.error || 'Could not save.'); return; }
          var done = fd.get('done') === '1';
          form.querySelector('input[name=done]').value = done ? '' : '1';
          btn.setAttribute('aria-pressed', done ? 'true' : 'false');
          var box = form.querySelector('[data-box]'), txt = form.querySelector('[data-text]'), by = form.querySelector('[data-by]');
          box.className = box.className.replace(/bg-emerald-600|border-emerald-600|text-white|border-stone-300|text-transparent/g, '').trim() + (done ? ' bg-emerald-600 border-emerald-600 text-white' : ' border-stone-300 text-transparent');
          txt.classList.toggle('line-through', done); txt.classList.toggle('decoration-stone-400', done); txt.classList.toggle('text-stone-500', done);
          if (by) by.textContent = done ? 'Just now' : '';
          var c = document.getElementById('tCount'); if (c) c.textContent = j.checked + ' of ' + j.total;
          var s = document.getElementById('tStatus');
          if (s) { s.textContent = j.status_label; if (j.status === 'in_progress') s.className = 'text-xs font-semibold px-2 py-1 rounded bg-sky-100 text-sky-800'; }
          var st = document.getElementById('tStart');   // ticking an item starts the task: Start becomes Stop
          if (st && j.status === 'in_progress') { st.value = 'stop'; st.textContent = 'Stop working (back to To do)'; st.removeAttribute('id'); }
          var d = document.getElementById('tDone');
          if (d) {
            var left = j.total - j.checked;
            if (left === 0) { if (d.dataset.go) d.className = d.dataset.go; d.textContent = '✓ Done'; }
            else d.textContent = 'Finish anyway (' + left + ' not ticked)';
          }
        })
        .catch(function () { btn.disabled = false; form.submit(); });
    });
  });

  function alertBox(msg) {
    var list = document.getElementById('tList');
    var p = document.getElementById('tErr');
    if (!p) {
      p = document.createElement('div');
      p.id = 'tErr'; p.setAttribute('role', 'alert');
      p.className = 'p-3 mb-3 border rounded text-sm bg-red-50 border-red-200 text-red-800';
      if (list) list.parentNode.insertBefore(p, list);
    }
    p.textContent = msg;
  }

  // ── Chore form: show the fields for the chosen schedule ──
  var freq = document.getElementById('c_freq'), anchor = document.getElementById('c_anchor') || document.getElementById('e_anchor');
  function sync() {
    if (freq) document.querySelectorAll('[data-freq]').forEach(function (el) {
      el.hidden = el.getAttribute('data-freq').split(' ').indexOf(freq.value) < 0;
    });
    if (anchor) document.querySelectorAll('[data-anchor]').forEach(function (el) {
      el.hidden = el.getAttribute('data-anchor').split(' ').indexOf(anchor.value) < 0;
    });
  }
  on(freq, 'change', sync); on(anchor, 'change', sync); sync();

  // ── Confirm before cancelling; skip needs a reason ──
  document.querySelectorAll('button[data-confirm]').forEach(function (b) {
    on(b, 'click', function (e) { if (!window.confirm(b.getAttribute('data-confirm'))) e.preventDefault(); });
  });
  document.querySelectorAll('button[name=to][value=skip]').forEach(function (b) {
    on(b, 'click', function (e) {
      var n = document.getElementById('t_note');
      if (n && !n.value.trim()) { e.preventDefault(); n.focus(); n.setAttribute('placeholder', 'Why is it skipped today?'); }
    });
  });
})();
