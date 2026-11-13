// admin/assets/usda-picker.js — USDA FoodData Central picker shared by the ingredient editor and the
// recipe Nutrition Facts chart (Nov 13, 2026). Type a name, matches show as you type, tap one.
// Talks to admin/handlers/lookup_ingredient_nutrition.php (actions search, detail, pick).
(function () {
  'use strict';
  if (window.BcrfUsda) return;

  var ENDPOINT = '/admin/handlers/lookup_ingredient_nutrition.php';

  function post(csrf, data) {
    var body = new URLSearchParams(Object.assign({ csrf_token: csrf }, data));
    return fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  /**
   * Live search box. opts: input (text input), list (element for results), csrf, onChoose(result),
   * auto (bool: search while typing, default true), min (chars, default 3).
   * Returns { search(q), clear() }.
   */
  function liveSearch(opts) {
    var timer = null, seq = 0, last = '';
    var min = opts.min || 3;

    function clear() { opts.list.innerHTML = ''; opts.list.classList.add('hidden'); last = ''; }

    function show(results, note) {
      opts.list.innerHTML = '';
      opts.list.classList.remove('hidden');
      if (note) opts.list.appendChild(el('p', 'text-[11px] text-stone-500 px-1 py-1', note));
      (results || []).forEach(function (f) {
        var b = el('button', 'w-full text-left bg-white border border-stone-200 hover:border-blue-500 hover:bg-blue-50 rounded px-2 py-1.5 text-xs');
        b.type = 'button';
        b.appendChild(el('span', 'font-bold text-stone-800', f.description));
        var meta = [f.data_type];
        if (f.nutrients && f.nutrients.calories_per_100g != null) meta.push(f.nutrients.calories_per_100g + ' kcal/100g');
        b.appendChild(el('span', 'block text-[10px] text-stone-500', meta.join(' · ')));
        b.addEventListener('click', function () { clear(); opts.onChoose(f); });
        opts.list.appendChild(b);
      });
    }

    function search(q) {
      q = (q || '').trim();
      if (q.length < min) { clear(); return; }
      if (q === last) return;
      last = q;
      var my = ++seq;
      show([], '⏳ Searching USDA for “' + q + '”…');
      post(opts.csrf, { action: 'search', query: q }).then(function (d) {
        if (my !== seq) return;
        if (!d.success) { show([], '⚠️ ' + d.message); return; }
        show(d.results, 'Tap the best match:');
      }).catch(function () { if (my === seq) show([], '⚠️ Network error. Try again.'); });
    }

    if (opts.auto !== false) {
      opts.input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { search(opts.input.value); }, 450);
      });
    }
    opts.input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); last = ''; search(opts.input.value); }
    });
    return { search: function (q) { last = ''; search(q); }, clear: clear };
  }

  window.BcrfUsda = {
    post: post,
    el: el,
    liveSearch: liveSearch,
    detail: function (csrf, fdc) { return post(csrf, { action: 'detail', fdc_id: fdc }); },
    pick: function (csrf, ingId, fdc) { return post(csrf, { action: 'pick', ingredient_id: ingId, fdc_id: fdc }); }
  };
})();
