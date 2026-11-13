// admin/assets/pin-lock.js — locks a PIN session on a shared device after 5 minutes without use (Farm OS spec 03, Nov 13, 2026).
// Loaded only in a PIN session (includes/pin_auth.php bcrf_pin_head_tags()). Taps, keys and scrolling count as use; while
// someone is using the screen it tells the server now and then (admin/pin.php?ping=1), so a long waiver read doesn't lock.
(function () {
  'use strict';
  var me = document.currentScript;
  var idle = Math.max(60, +(me && me.getAttribute('data-idle')) || 300) * 1000;
  var last = Date.now(), lastPing = Date.now(), warn = null;
  function lock() { location.href = '/admin/pin.php?lock=1&idle=1'; }
  function used() {
    last = Date.now();
    if (warn) { warn.remove(); warn = null; }
    if (Date.now() - lastPing > 60000) {
      lastPing = Date.now();
      fetch('/admin/pin.php?ping=1', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok === false) lock(); }, function () {});
    }
  }
  ['pointerdown', 'keydown', 'touchstart', 'wheel', 'scroll', 'input'].forEach(function (t) {
    window.addEventListener(t, used, { passive: true, capture: true });
  });
  setInterval(function () {
    var gone = Date.now() - last;
    if (gone >= idle) return lock();
    if (gone >= idle - 30000 && !warn) {
      warn = document.createElement('button');
      warn.type = 'button';
      warn.className = 'pinlock-warn';
      warn.textContent = 'Locking in 30 seconds. Tap to keep going.';
      warn.addEventListener('click', used);
      document.body.appendChild(warn);
    }
  }, 5000);
})();
