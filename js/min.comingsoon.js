/* js/min.comingsoon.js — dismissible "Coming soon" floating badge.
   Injects a close (×) button into #comingsoon and animates it out on click,
   using the .comingsoon-close / .comingsoon-dismissing styles in min.style.css.
   No persistence: the badge reappears on every fresh page load, and dismissing
   it only affects the current view of the current page.
   CSP-safe: external 'self' script, no inline handlers. */
(function () {
  'use strict';

  function remove(el) {
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  function init() {
    var el = document.getElementById('comingsoon');
    if (!el) return;
    if (el.querySelector('.comingsoon-close')) return; // already wired

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'comingsoon-close';
    btn.setAttribute('aria-label', 'Dismiss');
    btn.innerHTML = '&times;';
    el.appendChild(btn);

    btn.addEventListener('click', function () {
      var reduce = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) { remove(el); return; }

      var done = false;
      var finish = function () { if (!done) { done = true; remove(el); } };
      el.classList.add('comingsoon-dismissing');
      el.addEventListener('animationend', finish, { once: true });
      setTimeout(finish, 600); // fallback if animationend doesn't fire
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
