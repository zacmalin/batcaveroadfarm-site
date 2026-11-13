// admin/assets/kitchen-usda-check.js — Kitchen › Ingredients › USDA check (admin/kitchen_usda_check.php, Nov 13, 2026).
// Goes through the ingredients one at a time: saved USDA match → detail; no match yet → search by name, best
// match → detail. Lists what differs with a tick box per change. "Update ticked" saves through the handler's
// apply action (which refetches USDA and recalculates the recipes that use the ingredient).
(function () {
  'use strict';
  var dataEl = document.getElementById('ucData');
  if (!dataEl || !window.BcrfUsda) return;
  var DATA = JSON.parse(dataEl.textContent);
  var U = window.BcrfUsda, el = U.el, csrf = DATA.csrf;
  var list = document.getElementById('ucList');
  var status = document.getElementById('ucStatus');
  var startBtn = document.getElementById('ucStart');
  var hideOk = document.getElementById('ucHideOk');
  var bar = document.getElementById('ucBar');
  var applyBtn = document.getElementById('ucApply');
  var applyNote = document.getElementById('ucApplyNote');

  var NUT = [
    ['calories_per_100g', 'Calories', 'kcal'], ['protein_per_100g', 'Protein', 'g'], ['fat_per_100g', 'Fat', 'g'],
    ['carbs_per_100g', 'Carbs', 'g'], ['fiber_per_100g', 'Fiber', 'g'], ['sugar_per_100g', 'Sugar', 'g'],
    ['sodium_per_100g', 'Sodium', 'mg']
  ];
  var running = false;

  function num(v) { return (v === null || v === undefined || v === '') ? null : parseFloat(v); }
  function fmt(v) { return v === null ? 'empty' : String(Math.round(v * 100) / 100); }
  // Same if both empty, or within 5% (or a small absolute amount, so 0.1 g vs 0.2 g isn't flagged).
  function differs(cur, usda, tolPct, tolAbs) {
    if (usda === null) return false;
    if (cur === null) return true;
    return Math.abs(cur - usda) > Math.max(tolAbs, Math.abs(usda) * tolPct);
  }

  function makeRow(r) {
    var box = el('div', 'bg-white border border-stone-200 rounded-lg p-3 shadow-sm');
    var head = el('div', 'flex flex-wrap items-baseline gap-2');
    head.appendChild(el('span', 'font-bold text-stone-800 text-sm', r.name));
    if (+r.recipes) head.appendChild(el('span', 'text-[11px] text-stone-500', 'in ' + r.recipes + ' recipe' + (+r.recipes === 1 ? '' : 's')));
    box.appendChild(head);
    var body = el('div', 'text-xs text-stone-600 mt-1', '⏳ Waiting…');
    box.appendChild(body);
    r._box = box; r._body = body; r._state = 'wait';
    list.appendChild(box);
  }

  function setVisible(r) {
    r._box.classList.toggle('hidden', hideOk.checked && (r._state === 'ok' || r._state === 'done'));
  }

  function tickCount() {
    var n = 0;
    DATA.rows.forEach(function (r) { if (r._state === 'diff' && r._checks && r._checks.some(function (c) { return c.box.checked; })) n++; });
    return n;
  }
  function refreshBar() {
    var n = tickCount();
    bar.classList.toggle('hidden', n === 0);
    applyBtn.textContent = 'Update ' + n + ' ticked';
  }

  function showFood(r, food, guessed) {
    r._food = food; r._body.innerHTML = '';
    var m = el('div', 'flex flex-wrap items-center gap-2');
    m.appendChild(el('span', 'text-stone-700', 'USDA: ' + food.description + (food.data_type ? ' (' + food.data_type + ')' : '')));
    if (guessed) m.appendChild(el('span', 'text-[11px] px-2 py-0.5 rounded bg-amber-100 text-amber-900', 'best guess by name'));
    var chg = el('button', 'text-[11px] underline text-amber-800', 'Wrong match? Change');
    chg.type = 'button';
    chg.addEventListener('click', function () { openPicker(r); });
    m.appendChild(chg);
    r._body.appendChild(m);

    var changes = [];
    var nutDiff = NUT.filter(function (n) { return differs(num(r[n[0]]), num(food.nutrients[n[0]]), 0.05, n[0] === 'sodium_per_100g' ? 5 : 0.5); });
    if (nutDiff.length) changes.push(['nutrition', 'Nutrition per 100 g: ' + nutDiff.map(function (n) {
      return n[1] + ' ' + fmt(num(r[n[0]])) + ' → ' + fmt(num(food.nutrients[n[0]])) + ' ' + n[2];
    }).join(', ')]);
    var curD = num(r.density), curW = num(r.weight);
    if (food.density && differs(curD, food.density, 0.1, 0.02)) {
      changes.push(['density', 'Grams per mL: ' + fmt(curD) + ' → ' + food.density + (food.density_from ? ' (USDA ' + food.density_from + ')' : '')]);
    }
    if (food.weight && differs(curW, food.weight, 0.1, 1)) {
      changes.push(['weight', 'Weight of one: ' + fmt(curW) + ' g → ' + food.weight + ' g' + (food.weight_from ? ' (USDA ' + food.weight_from + ')' : '')]);
    }

    r._checks = [];
    if (!changes.length) {
      r._state = 'ok';
      r._body.appendChild(el('p', 'text-green-800 mt-1', '✓ Matches USDA'));
      if (+r.fdc !== food.fdc_id) {
        // Same numbers but not linked yet: link it quietly on Update so next time it uses this match.
        r._state = 'diff';
        r._body.lastChild.textContent = '✓ Numbers match USDA. Update saves the link to this USDA food.';
        r._checks.push({ part: 'link', box: { checked: true } });
      }
    } else {
      r._state = 'diff';
      changes.forEach(function (c) {
        var lab = el('label', 'flex items-start gap-2 mt-1 text-stone-800');
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.checked = true; cb.className = 'mt-0.5';
        cb.addEventListener('change', refreshBar);
        lab.appendChild(cb);
        lab.appendChild(el('span', '', c[1]));
        r._body.appendChild(lab);
        r._checks.push({ part: c[0], box: cb });
      });
    }
    setVisible(r);
    refreshBar();
  }

  function fail(r, msg) {
    r._state = 'fail'; r._body.innerHTML = '';
    r._body.appendChild(el('span', 'text-red-700', '⚠️ ' + msg + ' '));
    var chg = el('button', 'text-[11px] underline text-amber-800', 'Search USDA');
    chg.type = 'button';
    chg.addEventListener('click', function () { openPicker(r); });
    r._body.appendChild(chg);
    setVisible(r);
  }

  function loadDetail(r, fdc, guessed) {
    return U.detail(csrf, fdc).then(function (d) {
      if (!d.success) { fail(r, d.message); return; }
      showFood(r, d.food, guessed);
    });
  }

  function checkOne(r) {
    r._body.textContent = '⏳ Checking…';
    var p = +r.fdc
      ? loadDetail(r, +r.fdc, false)
      : U.post(csrf, { action: 'search', query: r.name }).then(function (d) {
          if (!d.success || !d.results || !d.results.length) { fail(r, 'No USDA match for this name.'); return; }
          return loadDetail(r, d.results[0].fdc_id, true);
        });
    return p.catch(function () { fail(r, 'Network error.'); });
  }

  function openPicker(r) {
    if (r._picker) { r._picker.input.focus(); return; }
    var wrap = el('div', 'mt-2');
    var input = document.createElement('input');
    input.type = 'search'; input.value = r.name;
    input.className = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
    input.placeholder = 'Search USDA';
    var res = el('div', 'hidden space-y-1 mt-1');
    wrap.appendChild(input); wrap.appendChild(res);
    r._box.appendChild(wrap);
    var ls = U.liveSearch({
      input: input, list: res, csrf: csrf,
      onChoose: function (f) {
        wrap.remove(); r._picker = null;
        r._body.textContent = '⏳ Loading ' + f.description + '…';
        loadDetail(r, f.fdc_id, false).catch(function () { fail(r, 'Network error.'); });
      }
    });
    r._picker = { input: input };
    input.focus();
    ls.search(r.name);
  }

  startBtn.addEventListener('click', function () {
    if (running) return;
    running = true; startBtn.disabled = true;
    list.innerHTML = '';
    DATA.rows.forEach(makeRow);
    var i = 0;
    (function next() {
      if (i >= DATA.rows.length) {
        running = false; startBtn.disabled = false; startBtn.textContent = 'Check again';
        var d = DATA.rows.filter(function (r) { return r._state === 'diff'; }).length;
        var f = DATA.rows.filter(function (r) { return r._state === 'fail'; }).length;
        status.textContent = 'Done. ' + d + ' to update, ' + f + ' with no match, the rest match USDA.';
        return;
      }
      var r = DATA.rows[i++];
      status.textContent = 'Checking ' + i + ' of ' + DATA.rows.length + '…';
      checkOne(r).then(next);
    })();
  });

  hideOk.addEventListener('change', function () { DATA.rows.forEach(function (r) { if (r._box) setVisible(r); }); });

  applyBtn.addEventListener('click', function () {
    var todo = DATA.rows.filter(function (r) {
      return r._state === 'diff' && r._checks.some(function (c) { return c.box.checked; });
    });
    if (!todo.length) return;
    applyBtn.disabled = true;
    var done = 0, failed = 0, recipes = 0, i = 0;
    (function next() {
      if (i >= todo.length) {
        applyBtn.disabled = false;
        applyNote.textContent = 'Updated ' + done + (failed ? ', ' + failed + ' failed' : '') + '.' + (recipes ? ' Recipe Nutrition Facts that use them were recalculated.' : '');
        refreshBar(); bar.classList.remove('hidden');
        return;
      }
      var r = todo[i++];
      applyNote.textContent = 'Saving ' + i + ' of ' + todo.length + '…';
      var parts = r._checks.filter(function (c) { return c.box.checked && c.part !== 'link'; }).map(function (c) { return c.part; });
      // A link-only row still needs one part for the handler; nutrition is equal there, so this changes nothing visible.
      if (!parts.length) parts = ['nutrition'];
      U.post(csrf, { action: 'apply', ingredient_id: r.id, fdc_id: r._food.fdc_id, parts: parts.join(',') }).then(function (d) {
        if (d.success) {
          done++; recipes += +d.recipes || 0;
          r._state = 'done'; r.fdc = r._food.fdc_id;
          r._body.innerHTML = '';
          r._body.appendChild(el('p', 'text-green-800', '✓ Updated from USDA: ' + r._food.description));
        } else {
          failed++;
          r._body.appendChild(el('p', 'text-red-700 mt-1', '⚠️ ' + d.message));
        }
        setVisible(r);
      }).catch(function () { failed++; }).then(next);
    })();
  });
})();
