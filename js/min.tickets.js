/* js/min.tickets.js — day-admission ticket selector interactions.
   CSP-safe: external 'self' script, no inline handlers.
   Reads data attributes from the form and ticket inputs. */
(function () {
  'use strict';

  var form = document.getElementById('tickets-form');
  if (!form) return;

  var blackouts  = JSON.parse(form.dataset.blackouts  || '[]');
  var closedDays = JSON.parse(form.dataset.closedDays || '[1]');

  var totalEl   = document.getElementById('tickets-total');
  var peopleEl  = document.getElementById('tickets-people');
  var warningEl = document.getElementById('group-warning');
  var submitBtn = document.getElementById('tickets-submit');
  var dateInput = document.getElementById('visit_date');
  var dateFeedback = document.getElementById('date-feedback');

  // ── Qty controls ────────────────────────────────────────────────────────────
  document.querySelectorAll('[data-qty-btn]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var inp = document.getElementById(btn.dataset.target);
      if (!inp) return;
      var val = parseInt(inp.value, 10) || 0;
      var delta = btn.dataset.qtyBtn === 'inc' ? 1 : -1;
      inp.value = Math.max(0, val + delta);
      updateTotal();
    });
  });

  document.querySelectorAll('.ticket-qty-input').forEach(function (inp) {
    inp.addEventListener('input', updateTotal);
    inp.addEventListener('change', updateTotal);
  });

  function updateTotal() {
    var total  = 0;
    var people = 0;
    document.querySelectorAll('.ticket-qty-input').forEach(function (inp) {
      var qty   = Math.max(0, parseInt(inp.value, 10) || 0);
      var price = parseFloat(inp.dataset.price) || 0;
      total  += qty * price;
      people += qty;
    });

    if (totalEl)  totalEl.textContent  = '$' + total.toFixed(2);
    if (peopleEl) peopleEl.textContent = people;

    var isGroup = (people >= 6);
    if (warningEl) warningEl.style.display = isGroup ? '' : 'none';

    if (submitBtn) {
      var dateOk = dateInput && dateInput.value !== '' && dateInput.validity.valid;
      submitBtn.disabled = (people < 1 || isGroup || !dateOk);
      submitBtn.textContent = isGroup ? 'Use group booking form →' : 'Buy Tickets →';
      if (isGroup && submitBtn.tagName === 'BUTTON') {
        submitBtn.setAttribute('type', 'button');
        submitBtn.onclick = function () { window.location.href = '/booking/'; };
      } else {
        submitBtn.setAttribute('type', 'submit');
        submitBtn.onclick = null;
      }
    }
  }

  // ── Date validation ──────────────────────────────────────────────────────────
  function validateDate(val) {
    if (!val) return 'Please choose a visit date.';
    var d   = new Date(val + 'T12:00:00');
    var day = d.getDay(); // 0=Sun, 1=Mon ... 6=Sat
    if (closedDays.indexOf(day) !== -1) return 'We\'re closed that day — please choose another date.';
    if (blackouts.indexOf(val) !== -1)  return 'That date isn\'t available — please choose another.';
    return '';
  }

  if (dateInput) {
    dateInput.addEventListener('input', function () {
      var msg = validateDate(this.value);
      this.setCustomValidity(msg);
      if (dateFeedback) {
        dateFeedback.textContent = msg;
        dateFeedback.className   = 'tickets-date-feedback' + (msg ? ' is-error' : ' is-ok');
      }
      updateTotal(); // re-check submit enable state
    });
    dateInput.addEventListener('change', function () {
      var msg = validateDate(this.value);
      this.setCustomValidity(msg);
      updateTotal();
    });
  }

  // ── Init ─────────────────────────────────────────────────────────────────────
  updateTotal();
})();
