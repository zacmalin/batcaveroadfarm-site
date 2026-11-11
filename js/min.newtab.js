// js/min.newtab.js — warn before a link opens a new tab (WCAG 3.2.5 / AudioEye "Link opens a new window
// without warning the user", Nov 11, 2026). Built into js/min.site.js by tools/build-js.mjs.
// Every <a target="_blank"> on the page gets screen-reader text "(opens in a new tab)" and a small ↗ icon,
// and rel="noopener". Links added later (lightbox, comments) are caught by a MutationObserver.
(function () {
  var HINT = 'opens in a new tab';
  function mark(a) {
    if (!a || a.getAttribute('data-nt') === '1') return;
    a.setAttribute('data-nt', '1');
    var rel = a.getAttribute('rel') || '';
    if (!/\bnoopener\b/.test(rel)) a.setAttribute('rel', (rel + ' noopener').trim());
    var label = a.getAttribute('aria-label');
    if (label && label.toLowerCase().indexOf('new tab') === -1) a.setAttribute('aria-label', label + ' (' + HINT + ')');
    var icon = document.createElement('span');
    icon.className = 'nt-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '↗';
    a.appendChild(icon);
    if (!label) {
      var sr = document.createElement('span');
      sr.className = 'visually-hidden';
      sr.textContent = ' (' + HINT + ')';
      a.appendChild(sr);
    }
  }
  function scan(root) {
    var list = (root || document).querySelectorAll('a[target="_blank"]');
    for (var i = 0; i < list.length; i++) mark(list[i]);
  }
  function start() {
    scan(document);
    if (window.MutationObserver && document.body) {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var n = muts[i].addedNodes;
          for (var j = 0; j < n.length; j++) {
            var el = n[j];
            if (el.nodeType !== 1) continue;
            if (el.matches && el.matches('a[target="_blank"]')) mark(el); else if (el.querySelectorAll) scan(el);
          }
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
