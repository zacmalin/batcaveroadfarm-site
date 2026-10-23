/* js/min.passes.js — pass-specific JS: QR code generation + card button selection.
   Depends on qrcode.min.js (self-hosted at /js/qrcode.min.js).
   Loaded by footer.php after full DOM parse. */
(function () {
  'use strict';

  // ── QR code on success/verify page ──────────────────────────────────────────
  var qrBox = document.getElementById('pass-qr');
  if (qrBox && typeof QRCode !== 'undefined') {
    var url = qrBox.dataset.url;
    if (url) {
      new QRCode(qrBox, { text: url, width: 200, height: 200,
                          correctLevel: QRCode.CorrectLevel.M });
    }
  }

  // ── Pass type card → pre-select form ────────────────────────────────────────
  var typeSelect  = document.getElementById('pass_type_id');
  var formWrap    = document.getElementById('pass-form-wrap');
  var selectedLbl = document.getElementById('selected-pass-label');

  document.querySelectorAll('.pass-select-btn[data-pass-id]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      var id    = btn.dataset.passId;
      var label = btn.dataset.passLabel;

      if (typeSelect) {
        typeSelect.value = id;
        // Fire change so any dependent logic updates
        typeSelect.dispatchEvent(new Event('change'));
      }
      if (selectedLbl && label) selectedLbl.textContent = label;
      if (formWrap) formWrap.style.display = '';

      // Smooth scroll to form after a tick so display:'' has taken effect
      setTimeout(function () {
        var target = document.getElementById('buy-form');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 50);
    });
  });

  // Also handle direct change of the select (if user changes it manually)
  if (typeSelect && formWrap) {
    typeSelect.addEventListener('change', function () {
      if (typeSelect.value) {
        formWrap.style.display = '';
        var opt = typeSelect.options[typeSelect.selectedIndex];
        if (selectedLbl && opt) selectedLbl.textContent = opt.text.split(' —')[0];
      }
    });
  }
})();
