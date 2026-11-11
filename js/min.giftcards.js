// js/min.giftcards.js — gift card order form (gift-cards/index.php, Nov 11, 2026): show "Other amount" and the
// recipient fields only when they apply. Without JavaScript every field shows and the server sorts it out.
(function () {
  var f = document.getElementById('gc-form');
  if (!f) return;
  var other = document.getElementById('gc-other-wrap'), rec = document.getElementById('gc-recipient'), remail = document.getElementById('gc_remail');
  function val(n) { var c = f.querySelector('input[name="' + n + '"]:checked'); return c ? c.value : ''; }
  function sync() {
    var o = val('amount') === 'other', them = val('send_to') !== 'me';
    other.hidden = !o; document.getElementById('gc_other').required = o;
    rec.hidden = !them; remail.required = them;
  }
  f.addEventListener('change', sync);
  sync();
})();
