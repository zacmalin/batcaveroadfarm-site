/* min.auth.js — drives the #authModal login/register modal.
 * On success it reloads the page; any stashed action (e.g. a pending rating) resumes on load. */
(function () {
  'use strict';

  var sess = null;
  function getSession() {
    if (sess) return Promise.resolve(sess);
    return fetch('/account/session.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { sess = j || { loggedIn: false, csrf: '' }; return sess; })
      .catch(function () { sess = { loggedIn: false, csrf: '' }; return sess; });
  }

  function modal() { return document.getElementById('authModal'); }

  function setMode(mode) {
    var m = modal(); if (!m) return;
    m.querySelectorAll('.auth-pane').forEach(function (p) {
      var on = p.getAttribute('data-pane') === mode;
      if (on) p.removeAttribute('hidden'); else p.setAttribute('hidden', '');
    });
  }

  function open(mode) {
    var m = modal();
    if (!m) { // no modal on this page — fall back to the standalone page
      window.location.href = '/account/' + (mode === 'register' ? 'register' : 'login')
        + '.php?next=' + encodeURIComponent(location.pathname + location.search);
      return;
    }
    setMode(mode || 'login');
    m.removeAttribute('hidden');
    document.addEventListener('keydown', onKey);
    var f = m.querySelector('.auth-pane:not([hidden]) input:not([tabindex])');
    if (f) f.focus();
  }
  function close() { var m = modal(); if (m) m.setAttribute('hidden', ''); document.removeEventListener('keydown', onKey); }
  function onKey(e) { if (e.key === 'Escape') close(); }

  function paneErr(pane, msg) {
    var e = pane.querySelector('.auth-error');
    if (e) { e.textContent = msg || ''; e.style.display = msg ? 'block' : 'none'; }
  }
  function val(pane, name) { var x = pane.querySelector('[name="' + name + '"]'); return x ? x.value : ''; }

  function post(url, body, pane) {
    paneErr(pane, '');
    getSession().then(function (s) {
      body.csrf = s.csrf;
      fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) { location.reload(); } else { paneErr(pane, (j && j.error) || 'Something went wrong.'); } })
        .catch(function () { paneErr(pane, 'A network error occurred. Please try again.'); });
    });
  }
  function submitLogin(pane) {
    post('/account/login_ajax.php', { email: val(pane, 'email'), password: val(pane, 'password'), website: val(pane, 'website') }, pane);
  }
  function submitRegister(pane) {
    post('/account/register_ajax.php', {
      first_name: val(pane, 'first_name'), last_name: val(pane, 'last_name'), email: val(pane, 'email'),
      phone_number: val(pane, 'phone_number'), password: val(pane, 'password'),
      password_confirm: val(pane, 'password_confirm'), website: val(pane, 'website')
    }, pane);
  }

  function wire() {
    var m = modal(); if (!m) return;
    m.addEventListener('click', function (e) {
      if (e.target === m || e.target.closest('[data-auth-close]')) { close(); return; }
      var sw = e.target.closest('[data-auth-switch]');
      if (sw) { e.preventDefault(); setMode(sw.getAttribute('data-auth-switch')); return; }
      var sb = e.target.closest('[data-auth-submit]');
      if (sb) {
        e.preventDefault();
        var pane = sb.closest('.auth-pane');
        if (sb.getAttribute('data-auth-submit') === 'register') submitRegister(pane); else submitLogin(pane);
      }
    });
    m.querySelectorAll('.auth-pane').forEach(function (pane) {
      pane.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); pane.getAttribute('data-pane') === 'register' ? submitRegister(pane) : submitLogin(pane); }
      });
    });
  }

  window.bcrfAuth = { open: open, close: close, getSession: getSession };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire); else wire();
})();
