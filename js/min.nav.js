/* js/min.nav.js — Visit dropdown toggle for desktop click + mobile expand.
   Desktop hover is handled purely in CSS (no JS needed).
   JS handles: click-to-open on desktop, expand-in-drawer on mobile,
   close-on-outside-click, close-on-Escape, close drawer when item chosen. */
(function () {
  'use strict';

  var dropdowns = document.querySelectorAll('.nav-dropdown');
  if (!dropdowns.length) return;

  function closeAll() {
    dropdowns.forEach(function (dd) {
      dd.classList.remove('is-open');
      var btn = dd.querySelector('.nav-dropdown-toggle');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }

  function closeDrawer() {
    var nav = document.getElementById('main-nav');
    var btn = document.getElementById('hamburger-btn');
    if (nav) nav.classList.remove('open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  dropdowns.forEach(function (dd) {
    var btn = dd.querySelector('.nav-dropdown-toggle');
    if (!btn) return;

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = dd.classList.contains('is-open');
      closeAll();
      if (!isOpen) {
        dd.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
      }
    });

    // Clicking a dropdown item closes everything (sub-menu + mobile drawer)
    dd.querySelectorAll('.nav-dropdown-item').forEach(function (link) {
      link.addEventListener('click', function () {
        closeAll();
        closeDrawer();
      });
    });
  });

  document.addEventListener('click', closeAll);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll();
  });
})();
