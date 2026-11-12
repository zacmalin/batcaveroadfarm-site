// admin/assets/waiver-pad.js — finger signature box for admin/waiver_sign.php (Farm OS Phase 3, Nov 12, 2026).
// Draws with a finger, mouse or pen on the canvas; on submit puts a PNG of it in the hidden "signature" field and
// refuses to submit an empty box.
(function () {
  'use strict';
  var pad = document.getElementById('sigPad');
  var form = document.getElementById('waiverForm');
  if (!pad || !form) return;
  var ctx = pad.getContext('2d'), drawing = false, inked = false, last = null;
  ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1c1917';
  function at(e) {
    var r = pad.getBoundingClientRect();
    return { x: (e.clientX - r.left) * pad.width / r.width, y: (e.clientY - r.top) * pad.height / r.height };
  }
  pad.addEventListener('pointerdown', function (e) {
    e.preventDefault(); drawing = true; last = at(e);
    if (pad.setPointerCapture) pad.setPointerCapture(e.pointerId);
  });
  pad.addEventListener('pointermove', function (e) {
    if (!drawing) return;
    e.preventDefault();
    var p = at(e);
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    last = p; inked = true;
    document.getElementById('sigMsg').hidden = true;
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (n) { pad.addEventListener(n, function () { drawing = false; }); });
  document.getElementById('sigClear').addEventListener('click', function () {
    ctx.clearRect(0, 0, pad.width, pad.height); inked = false;
  });
  form.addEventListener('submit', function (e) {
    if (!inked) { e.preventDefault(); document.getElementById('sigMsg').hidden = false; pad.scrollIntoView({ block: 'center' }); return; }
    document.getElementById('sigData').value = pad.toDataURL('image/png');
  });
})();
