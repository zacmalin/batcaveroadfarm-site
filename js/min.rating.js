/* min.rating.js — enhances .rate--interactive widgets.
 * Cache-safe: fetches the CSRF token + login state at runtime (never baked into cached HTML).
 * Rule: anyone can rate 3-5; a rating under 3 requires login (we stash it and resume after login). */
(function () {
  'use strict';

  var session = null; // { loggedIn, csrf }
  function getSession() {
    if (session) return Promise.resolve(session);
    return fetch('/account/session.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { session = j || { loggedIn: false, csrf: '' }; return session; })
      .catch(function () { session = { loggedIn: false, csrf: '' }; return session; });
  }

  function pct(avg) { return Math.max(0, Math.min(100, Math.round(avg / 5 * 100))); }
  function num(avg) { return (Math.round(avg * 10) / 10).toString(); }
  function requireLogin(recipeId, val) {
    stash(recipeId, val);
    if (window.bcrfAuth && typeof window.bcrfAuth.open === 'function') window.bcrfAuth.open('login');
    else window.location.href = '/account/login.php?next=' + encodeURIComponent(location.pathname + location.search);
  }
  function stash(recipeId, val) {
    try { sessionStorage.setItem('bcrf_pending_rating', JSON.stringify({ recipeId: recipeId, val: val })); } catch (e) {}
  }

  function widgetsFor(recipeId) {
    return document.querySelectorAll('.rate--interactive[data-recipe-id="' + recipeId + '"]');
  }
  function setFill(w, p) { var f = w.querySelector('.rate-fill'); if (f) f.style.width = p + '%'; }

  function applyAggregate(recipeId, avg, count, thanks) {
    widgetsFor(recipeId).forEach(function (w) {
      w.setAttribute('data-pct', pct(avg));
      setFill(w, pct(avg));
      var label = w.querySelector('.rate-label');
      if (label) {
        var base = count > 0 ? (num(avg) + ' (' + count + ')') : 'Be the first to rate';
        label.textContent = thanks ? ('Thanks! ' + base) : base;
      }
      if (thanks) w.classList.add('rate--rated');
    });
  }

  function submit(recipeId, rating) {
    return getSession().then(function (s) {
      return fetch('/recipes/rate.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ recipe_id: recipeId, rating: rating, csrf: s.csrf })
      }).then(function (r) { return r.json(); });
    });
  }

  function rate(w, val) {
    var recipeId = parseInt(w.getAttribute('data-recipe-id'), 10);
    getSession().then(function (s) {
      if (val < 3 && !s.loggedIn) { requireLogin(recipeId, val); return; }
      submit(recipeId, val).then(function (j) {
        if (j && j.ok) {
          applyAggregate(recipeId, j.avg, j.count, true);
        } else if (j && j.needLogin) {
          requireLogin(recipeId, val);
        }
      }).catch(function () {});
    });
  }

  function wire(w) {
    var stars = w.querySelector('.rate-stars');
    w.querySelectorAll('.rate-hit').forEach(function (btn) {
      var val = parseInt(btn.getAttribute('data-val'), 10);
      btn.addEventListener('mouseenter', function () { setFill(w, val / 5 * 100); });
      btn.addEventListener('focus', function () { setFill(w, val / 5 * 100); });
      btn.addEventListener('click', function () { rate(w, val); });
    });
    if (stars) stars.addEventListener('mouseleave', function () { setFill(w, parseInt(w.getAttribute('data-pct'), 10) || 0); });
  }

  function init() {
    var widgets = document.querySelectorAll('.rate--interactive');
    if (!widgets.length) return;
    widgets.forEach(wire);

    // Resume a rating that was stashed before a forced login.
    getSession().then(function (s) {
      if (!s.loggedIn) return;
      var raw; try { raw = sessionStorage.getItem('bcrf_pending_rating'); } catch (e) { return; }
      if (!raw) return;
      try { sessionStorage.removeItem('bcrf_pending_rating'); } catch (e) {}
      var p; try { p = JSON.parse(raw); } catch (e) { return; }
      if (!p || !p.val) return;
      var w = document.querySelector('.rate--interactive[data-recipe-id="' + p.recipeId + '"]');
      if (w) rate(w, p.val);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
