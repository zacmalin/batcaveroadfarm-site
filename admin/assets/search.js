// admin/assets/search.js — live results for admin/search.php (Farm OS global search, Nov 13, 2026).
// Results come from search.php?api=1&q=…, already limited to what this person can open.
// Recent searches live in sessionStorage only (this tab, this visit): nothing is kept on the server (02).
(function () {
  'use strict';
  var box = document.getElementById('srchBox');
  var out = document.getElementById('srchOut');
  var recentEl = document.getElementById('srchRecent');
  if (!box || !out) return;

  var KEY = 'bcrf.search.recent';
  var timer = null, seq = 0;

  function recent() {
    try { var a = JSON.parse(sessionStorage.getItem(KEY) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; }
  }
  function remember(q) {
    q = (q || '').trim();
    if (q.length < 2) return;
    try {
      var a = recent().filter(function (x) { return x !== q; });
      a.unshift(q);
      sessionStorage.setItem(KEY, JSON.stringify(a.slice(0, 8)));
    } catch (e) { /* storage off: fine */ }
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function showRecent() {
    var a = recent();
    recentEl.textContent = '';
    if (box.value.trim() !== '' || !a.length) { recentEl.hidden = true; return; }
    recentEl.appendChild(el('div', 'srch-gh', 'Recent searches'));
    a.forEach(function (q) {
      var b = el('button', 'srch-chip', q);
      b.type = 'button';
      b.addEventListener('click', function () { box.value = q; run(); box.focus(); });
      recentEl.appendChild(b);
    });
    recentEl.hidden = false;
  }

  function render(j) {
    out.textContent = '';
    var q = j.q || '';
    if (q.length < 2) { if (q.length) out.appendChild(el('p', 'srch-empty', 'Type at least 2 letters.')); return; }
    if (j.jump) {
      var a = el('a', 'srch-jump', 'Open ' + j.jump.label + ' →');
      a.href = j.jump.url;
      out.appendChild(a);
    }
    if (!j.groups.length && !j.jump) {
      out.appendChild(el('p', 'srch-empty', 'Nothing found for “' + q + '” in the records you can open.'));
      return;
    }
    j.groups.forEach(function (g) {
      var s = el('section', 'srch-group');
      s.appendChild(el('h2', 'srch-gh', g.icon + ' ' + g.label));
      var ul = el('ul', 'srch-list');
      g.rows.forEach(function (r) {
        var li = el('li'), a = el('a', 'srch-item');
        a.href = r.url;
        a.appendChild(el('span', 'srch-t', r.title));
        if (r.sub) a.appendChild(el('span', 'srch-s', r.sub));
        li.appendChild(a);
        ul.appendChild(li);
      });
      s.appendChild(ul);
      if (g.more) {
        var m = el('a', 'srch-more', 'See all ' + g.label.toLowerCase() + ' →');
        m.href = g.more;
        s.appendChild(m);
      }
      out.appendChild(s);
    });
  }

  function run() {
    var q = box.value.trim();
    showRecent();
    if (q === '') { out.textContent = ''; history.replaceState(null, '', '/admin/search.php'); return; }
    var my = ++seq;
    fetch('/admin/search.php?api=1&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (my !== seq || !j || !j.ok) return;
        render(j);
        history.replaceState(null, '', '/admin/search.php?q=' + encodeURIComponent(q));
      })
      .catch(function () { if (my === seq) { out.textContent = ''; out.appendChild(el('p', 'srch-empty', 'Search did not answer. Check the connection and try again.')); } });
  }

  box.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 200); });
  // Enter submits the form: the server opens a code's record straight away, or shows the results.
  box.form.addEventListener('submit', function () { remember(box.value); });
  out.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a');
    if (a) remember(box.value);
  });
  box.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') {
      var first = out.querySelector('a');
      if (first) { e.preventDefault(); first.focus(); }
    }
  });
  out.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    var links = Array.prototype.slice.call(out.querySelectorAll('a'));
    var i = links.indexOf(document.activeElement);
    if (i < 0) return;
    e.preventDefault();
    if (e.key === 'ArrowDown' && i < links.length - 1) links[i + 1].focus();
    else if (e.key === 'ArrowUp') (i > 0 ? links[i - 1] : box).focus();
  });

  showRecent();
  // Put the cursor at the end of a carried-over search
  try { var v = box.value; box.focus(); box.setSelectionRange(v.length, v.length); } catch (e) { /* type=search may refuse */ }
})();
