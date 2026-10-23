/* js/min.booking.js — booking form interactions.
   Loaded by footer.php after full DOM parse — no DOMContentLoaded needed.
   CSP-safe: external 'self' script, zero inline handlers. */
(function () {
  'use strict';

  var sel       = document.getElementById('type_key');
  var prev      = document.getElementById('price-preview');
  var flatInput = document.getElementById('party_size_flat');
  var tierHid   = document.getElementById('party_size_tier');
  var partySect = document.getElementById('party-size-section');
  var tierSect  = document.getElementById('tier-section');

  if (!sel) return; // form not on this page

  // ── Flat-rate price preview ──────────────────────────────────────────────────
  function updatePreview() {
    if (!prev) return;
    var opt    = sel.options[sel.selectedIndex];
    if (!opt) { prev.style.display = 'none'; return; }
    var model  = opt.dataset.model  || 'flat';
    var price  = parseFloat(opt.dataset.price) || 0;
    var reqPay = opt.dataset.reqpay === '1';
    if (!reqPay || price <= 0) { prev.style.display = 'none'; return; }
    var qty   = (model === 'per_person' && flatInput) ? (parseInt(flatInput.value, 10) || 1) : 1;
    var total = (price * qty).toFixed(2);
    prev.textContent = model === 'per_person'
      ? '💳 $' + total + ' (' + qty + ' × $' + price.toFixed(2) + '/person)'
      : '💳 $' + total + ' flat rate';
    prev.style.display = 'block';
  }

  // ── Tier total ───────────────────────────────────────────────────────────────
  function updateTierTotal() {
    var total = 0, people = 0;
    document.querySelectorAll('.tier-qty-input').forEach(function (inp) {
      var q = Math.max(0, parseInt(inp.value, 10) || 0);
      var p = parseFloat(inp.dataset.price) || 0;
      total  += q * p;
      people += q;
    });
    var ta = document.getElementById('tier-amount');
    var tp = document.getElementById('tier-people');
    if (ta) ta.textContent = '$' + total.toFixed(2);
    if (tp) tp.textContent = people;
    if (tierHid) tierHid.value = people;
    if (prev) {
      if (total > 0) {
        prev.textContent  = '💳 $' + total.toFixed(2) + ' · ' + people + ' guests';
        prev.style.display = 'block';
      } else {
        prev.style.display = 'none';
      }
    }
  }

  // ── Tier qty +/− buttons ─────────────────────────────────────────────────────
  document.querySelectorAll('[data-tier-inc],[data-tier-dec]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id  = btn.dataset.tierInc || btn.dataset.tierDec;
      var inp = document.getElementById(id);
      if (!inp) return;
      inp.value = Math.max(0, (parseInt(inp.value, 10) || 0) + (btn.dataset.tierInc ? 1 : -1));
      updateTierTotal();
    });
  });
  document.querySelectorAll('.tier-qty-input').forEach(function (inp) {
    inp.addEventListener('input', updateTierTotal);
  });
  if (flatInput) flatInput.addEventListener('input', updatePreview);

  // ── Show/hide tier vs flat section ──────────────────────────────────────────
  function switchMode() {
    var opt         = sel.options[sel.selectedIndex];
    var model       = opt ? (opt.dataset.model || 'flat') : 'flat';
    var isPerPerson = (model === 'per_person') && tierSect;

    if (partySect) partySect.style.display = isPerPerson ? 'none' : '';
    if (tierSect)  tierSect.style.display  = isPerPerson ? ''     : 'none';

    if (flatInput) {
      flatInput.disabled = isPerPerson;
      flatInput.required = !isPerPerson;
    }
    if (tierHid) tierHid.disabled = !isPerPerson;

    if (isPerPerson) updateTierTotal();
    else             updatePreview();
  }

  sel.addEventListener('change', switchMode);
  switchMode(); // run once on load
})();
