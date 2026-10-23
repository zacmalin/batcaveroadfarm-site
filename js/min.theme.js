/* min.theme.js — gentle falling particles for the active seasonal theme.
 * Reads the glyph from .theme-particles[data-theme-glyph]; respects reduced-motion. */
(function () {
  'use strict';
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var box = document.querySelector('.theme-particles');
  if (!box) return;
  var glyph = box.getAttribute('data-theme-glyph') || '';
  if (!glyph) return;

  var COUNT = 14;
  for (var i = 0; i < COUNT; i++) {
    var s = document.createElement('span');
    s.className = 'theme-particle';
    s.textContent = glyph;
    var dur = 6 + Math.random() * 7;
    s.style.left = (Math.random() * 100).toFixed(2) + 'vw';
    s.style.fontSize = (0.8 + Math.random() * 1.1).toFixed(2) + 'rem';
    s.style.opacity = (0.3 + Math.random() * 0.35).toFixed(2);
    s.style.animationDuration = dur.toFixed(2) + 's';
    s.style.animationDelay = (-Math.random() * dur).toFixed(2) + 's';
    box.appendChild(s);
  }
})();
