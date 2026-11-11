// admin/assets/giftcard.js — printable gift cards (admin/gift_card_print.php, Nov 11, 2026): draw each QR, Print button.
(function () {
  function draw() {
    Array.prototype.forEach.call(document.querySelectorAll('.gc-qr[data-url]'), function (n) {
      if (!window.QRCode) return;
      try { new QRCode(n, { text: n.getAttribute('data-url'), width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {}
    });
  }
  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function () {
    draw();
    var b = document.getElementById('btnPrint');
    if (b) b.addEventListener('click', function () { window.print(); });
  });
})();
