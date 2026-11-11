// admin/assets/register.js — Farm OS register (Phase 1, slice 1, Nov 6, 2026)
// The browser builds the cart and the payments; the server (includes/sales.php) decides prices, tax and
// totals and assigns the receipt number. Totals here use the same integer-cent maths so the payment
// amounts match the server exactly. A sale is only shown as saved after the server says so; until then
// the cart, payments and save code stay in this browser (localStorage) and Retry sends the same save
// code, so a double tap or a dropped connection can never make two sales.
// Slice 2 (Nov 6): cash drawers. Each device remembers its register; sales need that register's drawer open.
// Slice 3 (Nov 6): Sales screen: find a sale, reprint, refund by line, or void it (includes/refunds.php).
// Slice 4a (Nov 6): admission sold here checks the party in; when the farm is full an Owner or Manager can override.
// Slice 7 (Nov 6): tap an item for its own % off and a note; attach a customer (search, pass link, or add new);
// hold a cart and take it back later on any device. Item discounts come off first, then the whole-sale discount.
// Slice 8 (Nov 6): with stock kept by location, the stock badges show what this register's location holds.
// Slice 9 (Nov 6): discount % buttons come from Register settings; any other % (or Free) only for an Owner or Manager.
// PayPal/Venmo/Cash App show the farm's handle and a QR code; receipts can be emailed after the sale or from the sale record.
(function () {
  'use strict';
  var BOOT = JSON.parse(document.getElementById('regBoot').textContent);
  var STORE = 'bcrf.register.v1';
  var $ = function (id) { return document.getElementById(id); };
  var S = { items: [], byId: {}, customRate: 0, cat: null, q: '', cart: [], discPct: 0, exempt: false, customer: null, holds: [],
            payments: [], key: null, tender: null, lastSale: null, saving: false,
            regId: null, drawer: null, dinfo: null };
  var REGKEY = 'bcrf.register.reg';

  // ── helpers ───────────────────────────────────────────────────────────────
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) n.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c !== null && c !== undefined) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function money(c) { c = Math.round(c || 0); return (c < 0 ? '−$' : '$') + (Math.abs(c) / 100).toFixed(2); }
  function cents(v) { var n = parseFloat(v); return isFinite(n) ? Math.round(n * 100) : NaN; }
  function muldiv(a, b, d) { return d > 0 ? Math.floor((2 * a * b + d) / (2 * d)) : 0; }
  function qtyText(q) { return String(Math.round(q * 1000) / 1000); }
  function newKey() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var a = new Uint8Array(16); crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function save() {
    try { localStorage.setItem(STORE, JSON.stringify({ cart: S.cart, discPct: S.discPct, exempt: S.exempt, payments: S.payments, key: S.key, last: S.lastSale, customer: S.customer })); } catch (e) {}
  }
  function load() {
    try {
      var d = JSON.parse(localStorage.getItem(STORE) || 'null');
      if (d) { S.cart = d.cart || []; S.discPct = d.discPct || 0; S.exempt = !!d.exempt; S.payments = d.payments || []; S.key = d.key || null; S.lastSale = d.last || null; S.customer = d.customer || null; }
    } catch (e) {}
  }
  function api(path, opts) {
    var ctl = window.AbortController ? new AbortController() : null;
    var t = ctl ? setTimeout(function () { ctl.abort(); }, 20000) : null;
    opts = opts || {};
    if (ctl) opts.signal = ctl.signal;
    opts.credentials = 'same-origin';
    return fetch('register.php?api=' + path, opts).then(function (r) {
      if (t) clearTimeout(t);
      return r.json().catch(function () { return { ok: false, message: 'Unexpected reply from the server (' + r.status + ').' }; })
        .then(function (j) { j.status = r.status; return j; });
    }, function (e) { if (t) clearTimeout(t); throw e; });
  }

  // ── pricing: same rules as bcrf_sale_price() ─────────────────────────────
  function lineBp(l) { return Math.max(0, Math.min(10000, Math.round((l.disc_pct || 0) * 100))); }
  function price() {
    var lines = S.cart.map(function (l) {
      var gross = muldiv(Math.round(l.qty * 1000), l.unit_price_cents, 1000);
      return { gross: gross, item: muldiv(gross, lineBp(l), 10000), rate: S.exempt ? 0 : l.rate_bp };
    });
    var sub = lines.reduce(function (s, l) { return s + l.gross; }, 0);
    var itemDisc = lines.reduce(function (s, l) { return s + l.item; }, 0);
    var base = sub - itemDisc;                                  // item discounts first, then the whole-sale discount on what's left
    var receipt = muldiv(base, Math.round(S.discPct * 100), 10000);
    var alloc = [], rem = [], given = 0;
    lines.forEach(function (l, i) {
      var exact = base > 0 ? (l.gross - l.item) * receipt : 0;
      alloc[i] = base > 0 ? Math.floor(exact / base) : 0;
      rem.push([i, base > 0 ? exact % base : 0]);
      given += alloc[i];
    });
    rem.sort(function (a, b) { return b[1] - a[1] || a[0] - b[0]; });
    for (var k = 0; k < rem.length && given < receipt; k++) { alloc[rem[k][0]]++; given++; }
    var tax = 0;
    lines.forEach(function (l, i) { tax += muldiv(l.gross - l.item - alloc[i], l.rate, 10000); });
    return { sub: sub, item: itemDisc, receipt: receipt, disc: itemDisc + receipt, tax: tax, total: sub - itemDisc - receipt + tax, lines: lines };
  }
  function paid() { return S.payments.reduce(function (s, p) { return s + p.amount_cents; }, 0); }

  // ── catalogue ─────────────────────────────────────────────────────────────
  function loadCatalog(quiet) {
    if (!BOOT.ready) { $('grid').replaceChildren(el('p', { class: 'empty', text: 'Waiting for migration 0009.' })); return; }
    if (!quiet) $('grid').replaceChildren(el('p', { class: 'empty', text: 'Loading products…' }));
    var rid = S.regId; if (!rid) { try { rid = +localStorage.getItem(REGKEY) || 0; } catch (e) { rid = 0; } }
    api('catalog&register_id=' + (rid || 0)).then(function (j) {   // slice 8: stock shown is what this register's location holds
      if (!j.ok) throw new Error(j.message);
      S.items = j.items; S.customRate = j.custom_rate_bp; S.byId = {};
      j.items.forEach(function (it) { S.byId[it.id] = it; });
      // Refresh rates/prices of lines already in the cart from the server's list
      S.cart.forEach(function (l) { if (l.product_id && S.byId[l.product_id]) { var it = S.byId[l.product_id]; l.rate_bp = it.rate_bp; if (it.mode !== 'open_price') l.unit_price_cents = it.price_cents; } else if (!l.product_id) l.rate_bp = S.customRate; });
      renderCats(); renderGrid(); renderCart();
    }).catch(function (e) {
      $('grid').replaceChildren(el('p', { class: 'empty' }, ['Could not load products. ', el('button', { class: 'link', text: 'Try again', onclick: function () { loadCatalog(); } })]));
    });
  }
  function cats() {
    var seen = [], out = [];
    S.items.forEach(function (it) { if (seen.indexOf(it.cat) < 0) { seen.push(it.cat); out.push(it.cat); } });
    return out;
  }
  function renderCats() {
    var list = cats();
    if (S.cat === null || list.indexOf(S.cat) < 0) S.cat = list[0] || null;
    $('cats').replaceChildren.apply($('cats'), list.map(function (c) {
      return el('button', { type: 'button', text: c, 'aria-current': (!S.q && c === S.cat) ? 'true' : 'false',
        onclick: function () { S.cat = c; S.q = ''; $('search').value = ''; renderCats(); renderGrid(); } });
    }));
  }
  function renderGrid() {
    var q = S.q.toLowerCase();
    var items = S.items.filter(function (it) {
      return q ? (it.name.toLowerCase().indexOf(q) >= 0 || (it.sku && it.sku.toLowerCase() === q)) : it.cat === S.cat;
    });
    if (!items.length) { $('grid').replaceChildren(el('p', { class: 'empty', text: q ? 'Nothing matches.' : 'No products here.' })); return; }
    $('grid').replaceChildren.apply($('grid'), items.map(function (it) {
      var out = it.stock !== null && it.stock <= 0;
      var t = el('button', { type: 'button', class: 'tile' + (it.img ? ' img' : '') + (out ? ' out' : ''), disabled: out,
        'aria-label': it.name + ', ' + money(it.price_cents) + (out ? ', out of stock' : ''), onclick: function () { pick(it); } }, [
        el('span', { class: 'nm', text: it.name }),
        el('span', { class: 'pr', text: it.mode === 'open_price' ? 'Enter price' : money(it.price_cents) + (it.mode === 'by_weight' ? ' / ' + it.unit : '') }),
        (it.stock !== null && it.stock <= 3) ? el('span', { class: 'stk' + (out ? ' out' : ''), text: out ? 'Out' : 'Low ' + qtyText(it.stock) }) : null
      ]);
      if (it.img) t.style.backgroundImage = 'url("' + it.img.replace(/"/g, '%22') + '")';
      return t;
    }));
  }

  // ── cart ──────────────────────────────────────────────────────────────────
  function pick(it) {
    if (it.mode === 'by_weight' || it.mode === 'open_price') return itemDialog(it);
    addLine(it, 1, it.price_cents);
  }
  function addLine(it, qty, unitCents) {
    var m = it.mode === 'each' ? S.cart.find(function (l) { return l.product_id === it.id; }) : null;
    if (m) m.qty += qty;
    else S.cart.push({ product_id: it.id, name: it.name, qty: qty, unit_price_cents: unitCents, rate_bp: it.rate_bp, mode: it.mode, unit: it.unit, cat: it.cat });
    changed();
    flash('Added ' + it.name);
  }
  function changed() { if (!S.key) S.key = newKey(); save(); renderCart(); }
  function stockOf(l) { var it = l.product_id && S.byId[l.product_id]; return it ? it.stock : null; }
  function renderCart() {
    var box = $('lines');
    if (!S.cart.length) box.replaceChildren(el('p', { class: 'empty', text: 'Tap a product to start a sale.' }));
    else box.replaceChildren.apply(box, S.cart.map(function (l, i) {
      var st = stockOf(l);
      var each = l.mode === 'each' && l.product_id;
      var qtyBox = el('div', { class: 'qty' }, [
        each ? el('button', { type: 'button', text: '−', 'aria-label': 'One less', onclick: function () { setQty(i, l.qty - 1); } }) : null,
        el('input', { type: 'number', inputmode: 'decimal', step: 'any', min: '0', value: qtyText(l.qty), 'aria-label': 'Quantity',
          onchange: function (e) { setQty(i, parseFloat(e.target.value)); } }),
        each ? el('button', { type: 'button', text: '+', 'aria-label': 'One more', onclick: function () { setQty(i, l.qty + 1); } }) : null,
        el('span', { class: 'sub', text: l.unit !== 'each' ? l.unit : '' }),
        el('button', { type: 'button', class: 'rm', text: '×', 'aria-label': 'Remove ' + l.name, onclick: function () { S.cart.splice(i, 1); changed(); } })
      ]);
      var gross = muldiv(Math.round(l.qty * 1000), l.unit_price_cents, 1000), off = muldiv(gross, lineBp(l), 10000);
      return el('div', { class: 'line' }, [
        el('button', { type: 'button', class: 'lnbtn', 'aria-label': 'Discount or note for ' + l.name, onclick: function () { lineDialog(i); } }, [
          el('div', { class: 'nm', text: l.name }),
          el('div', { class: 'sub', text: money(l.unit_price_cents) + (l.unit !== 'each' ? ' / ' + l.unit : ' each') + (BOOT.lineDisc ? ' · tap for discount' : '') }),
          l.disc_pct ? el('div', { class: 'ldisc', text: l.disc_pct + '% off (' + money(-off) + ')' }) : null,
          l.note ? el('div', { class: 'lnote', text: l.note }) : null]),
        el('div', { class: 'amt' }, [l.disc_pct ? el('s', { class: 'was', text: money(gross) }) : null, document.createTextNode(money(gross - off))]),
        qtyBox,
        (st !== null && l.qty > st) ? el('div', { class: 'warn', text: 'Only ' + qtyText(Math.max(0, st)) + ' in stock' }) : null
      ]);
    }));
    var p = price(), n = S.cart.reduce(function (s, l) { return s + (l.mode === 'each' ? l.qty : 1); }, 0);
    $('tSub').textContent = money(p.sub);
    $('rowDisc').hidden = !p.disc;
    $('tDiscLbl').textContent = p.item && p.receipt ? 'Discounts (items + ' + S.discPct + '%)' : (p.item ? 'Item discounts' : 'Discount (' + S.discPct + '%)');
    $('tDisc').textContent = money(-p.disc);
    renderCustomer();
    $('tTaxLbl').textContent = S.exempt ? 'Tax (exempt)' : 'Tax';
    $('tTax').textContent = money(p.tax);
    $('tTot').textContent = money(p.total);
    $('barCount').textContent = qtyText(n) + (n === 1 ? ' item' : ' items');
    $('barTotal').textContent = money(p.total);
    $('btnPay').disabled = $('btnPay2').disabled = !S.cart.length || !BOOT.ready || (BOOT.drawers && !S.drawer);
    $('btnExempt').setAttribute('aria-pressed', S.exempt ? 'true' : 'false');
    $('btnDiscount').textContent = S.discPct ? 'Discount ' + S.discPct + '%' : 'Discount';
  }
  function setQty(i, q) {
    if (!isFinite(q) || q <= 0) S.cart.splice(i, 1);
    else S.cart[i].qty = Math.round(q * 1000) / 1000;
    changed();
  }
  function clearSale() {
    S.cart = []; S.payments = []; S.discPct = 0; S.exempt = false; S.key = null; S.tender = null; S.customer = null;
    save(); renderCart();
  }
  var flashT;
  function flash(msg) {
    var n = $('toast'); if (!n) return;
    clearTimeout(flashT); n.textContent = msg; n.hidden = false;
    flashT = setTimeout(function () { n.hidden = true; }, 1400);
  }

  // ── item dialog (weight, open price, custom) ─────────────────────────────
  var itemFor = null;
  function itemDialog(it) {
    itemFor = it;
    var custom = !it;
    $('itemTitle').textContent = custom ? 'Custom item' : it.name;
    $('itemPriceWrap').firstChild.textContent = it && it.type === 'gift_card' ? 'Amount on each card ($5–$500)' : 'Price ($)';   // gift cards (Nov 11)
    $('itemNameWrap').hidden = !custom;
    $('itemPriceWrap').hidden = !(custom || it.mode === 'open_price');
    $('itemUnit').textContent = it && it.unit !== 'each' ? '(' + it.unit + ')' : '';
    $('itemQty').value = it && it.mode === 'by_weight' ? '' : '1';
    $('itemPrice').value = ''; $('itemName').value = custom ? '' : it.name;
    $('itemErr').hidden = true;
    $('dlgItem').showModal();
    (it && it.mode === 'by_weight' ? $('itemQty') : custom ? $('itemName') : $('itemPrice')).focus();
  }
  $('formItem').addEventListener('submit', function (e) {
    e.preventDefault();
    var it = itemFor, q = parseFloat($('itemQty').value), pc = cents($('itemPrice').value);
    var err = function (m) { $('itemErr').textContent = m; $('itemErr').hidden = false; };
    if (!(q > 0)) return err('Enter a quantity above zero.');
    if (!it) {
      var nm = $('itemName').value.trim();
      if (!nm) return err('Give the item a name.');
      if (!(pc >= 0)) return err('Enter a price.');
      S.cart.push({ product_id: null, name: nm, qty: q, unit_price_cents: pc, rate_bp: S.customRate, mode: 'open_price', unit: 'each', cat: 'Custom' });
      changed();
    } else if (it.mode === 'open_price') {
      if (!(pc >= 0)) return err('Enter a price.');
      if (it.type === 'gift_card') {
        if (pc < 500 || pc > 50000) return err('A gift card can be $5.00 to $500.00.');
        if (q !== Math.floor(q) || q > 20) return err('Gift cards are sold in whole numbers (1 to 20).');
      }
      S.cart.push({ product_id: it.id, name: it.name, qty: q, unit_price_cents: pc, rate_bp: it.rate_bp, mode: it.mode, unit: it.unit, cat: it.cat });
      changed();
    } else {
      addLine(it, Math.round(q * 1000) / 1000, it.price_cents);
    }
    $('dlgItem').close();
  });
  $('itemCancel').addEventListener('click', function () { $('dlgItem').close(); });
  $('btnCustom').addEventListener('click', function () { itemDialog(null); });

  // ── discount, exempt, clear ──────────────────────────────────────────────
  var POS = BOOT.pos || { presets: [5, 10, 15, 20, 25, 50], canOverride: true, handles: {} };
  function presetOk(p) { return !p || POS.canOverride || POS.presets.indexOf(p) >= 0; }
  POS.presets.forEach(function (p) {
    $('discChips').appendChild(el('button', { type: 'button', text: p + '%', onclick: function () { setDisc(p); } }));
  });
  $('discOther').hidden = !POS.canOverride; $('discOtherNote').hidden = POS.canOverride;
  function setDisc(p) { S.discPct = Math.max(0, Math.min(100, p)); changed(); $('dlgDiscount').close(); }
  $('btnDiscount').addEventListener('click', function () { $('discPct').value = S.discPct || ''; $('dlgDiscount').showModal(); });
  $('discOk').addEventListener('click', function () { var p = parseFloat($('discPct').value); if (p >= 0 && p <= 100 && presetOk(p)) setDisc(p); });
  $('discNone').addEventListener('click', function () { setDisc(0); });
  $('btnExempt').addEventListener('click', function () { S.exempt = !S.exempt; changed(); });
  $('btnClear').addEventListener('click', function () {
    if (!S.cart.length) return;
    confirmBox('Clear this sale?', 'Removes every item and payment. Nothing has been saved yet.', clearSale);
  });
  // confirmBox(title, text, yes [, askReason]): with askReason the box asks for a reason and passes it to yes(reason).
  function confirmBox(title, text, yes, askReason) {
    $('confTitle').textContent = title; $('confText').textContent = text;
    $('confReasonWrap').hidden = !askReason; $('confReason').value = ''; $('confErr').hidden = true;
    $('confYes').onclick = function () {
      var r = $('confReason').value.trim();
      if (askReason && !r) { $('confErr').textContent = 'Give a reason.'; $('confErr').hidden = false; return; }
      $('dlgConfirm').close(); yes(r);
    };
    $('confNo').onclick = function () { $('dlgConfirm').close(); };
    $('dlgConfirm').showModal();
    if (askReason) $('confReason').focus();
  }

  // -- slice 7: item sheet (discount + note) --
  var lineIdx = null;
  [0].concat(POS.presets, POS.canOverride ? [100] : []).forEach(function (p) {
    $('lineChips').appendChild(el('button', { type: 'button', text: p === 100 ? 'Free' : (p ? p + '%' : 'None'), onclick: function () { $('linePct').value = p || ''; } }));
  });
  function lineDialog(i) {
    var l = S.cart[i]; if (!l) return;
    lineIdx = i;
    $('lineTitle').textContent = l.name;
    $('lineSub').textContent = qtyText(l.qty) + (l.unit !== 'each' ? ' ' + l.unit : '') + ' × ' + money(l.unit_price_cents);
    $('linePct').value = l.disc_pct || '';
    $('lineNote').value = l.note || '';
    $('lineErr').hidden = true;
    var canDisc = !!BOOT.lineDisc;
    $('lineChips').hidden = !canDisc; $('linePct').closest('label').hidden = !canDisc || !POS.canOverride;
    $('lineSub').textContent += canDisc ? '' : ' · item discounts need migration 0014';
    $('dlgLine').showModal();
  }
  $('lineOk').addEventListener('click', function () {
    var l = S.cart[lineIdx]; if (!l) return $('dlgLine').close();
    var raw = $('linePct').value.trim(), p = raw === '' ? 0 : parseFloat(raw);
    if (!(p >= 0 && p <= 100)) { $('lineErr').textContent = 'Enter a % from 0 to 100.'; $('lineErr').hidden = false; return; }
    if (!presetOk(p)) { $('lineErr').textContent = 'That % needs an Owner or Manager. Use one of the buttons.'; $('lineErr').hidden = false; return; }
    l.disc_pct = BOOT.lineDisc ? Math.round(p * 100) / 100 : 0;
    l.note = $('lineNote').value.trim().slice(0, 120);
    $('dlgLine').close(); changed();
  });
  $('lineCancel').addEventListener('click', function () { $('dlgLine').close(); });

  // -- slice 7: customer --
  function renderCustomer() {
    var c = S.customer;
    $('custChip').hidden = !c;
    if (c) $('custChip').replaceChildren.apply($('custChip'), [el('span', { text: '👤 ' + c.name }),
      c.pass ? el('small', { class: c.pass.active ? 'ok' : 'warn', text: ' · ' + c.pass.label + (c.pass.active ? '' : ' (expired)') }) : null,
      el('span', { class: 'x', text: ' ×', 'aria-hidden': 'true' })].filter(Boolean));
    $('custChip').setAttribute('aria-label', c ? 'Remove customer ' + c.name : '');
    $('btnCustomer').textContent = c ? 'Change customer' : 'Customer';
  }
  $('custChip').addEventListener('click', function () { S.customer = null; save(); renderCart(); flash('Customer removed'); });
  function attach(c) { S.customer = { id: c.id, name: c.name, email: c.email || '', pass: c.pass || null }; save(); renderCart(); $('dlgCustomer').close(); flash('Customer: ' + c.name); }
  var custT = null, custSeq = 0;
  function custSearch() {
    var q = $('custQ').value.trim(), seq = ++custSeq;
    $('custErr').hidden = true;
    if (q.length < 2) { $('custResults').replaceChildren(); return; }
    api('customers&q=' + encodeURIComponent(q)).then(function (j) {
      if (seq !== custSeq) return;
      if (!j.ok) { $('custErr').textContent = j.message || 'Search failed.'; $('custErr').hidden = false; return; }
      if (!j.results.length) { $('custResults').replaceChildren(el('p', { class: 'muted', text: 'No one found. Add them below.' })); return; }
      $('custResults').replaceChildren.apply($('custResults'), j.results.map(function (r) {
        return el('button', { type: 'button', class: 'custrow', onclick: function () { pickCustomer(r); } }, [
          el('b', { text: r.name }),
          el('small', { class: 'muted', text: [r.email, r.phone].filter(Boolean).join(' · ') }),
          r.pass ? el('small', { class: r.pass.active ? 'ok' : 'warn', text: r.pass.label + (r.pass.active ? ' · valid to ' + r.pass.valid_until : ' · expired') + (r.type === 'pass' ? ' · new account' : '') }) : null]);
      }));
    }, function () { if (seq === custSeq) { $('custErr').textContent = 'No connection.'; $('custErr').hidden = false; } });
  }
  function pickCustomer(r) {
    if (r.type === 'customer') return attach(r);
    post('customer_pass', { pass_id: r.pass_id }).then(function (j) {   // a pass holder without an account: make one from the pass
      if (j.ok) attach(j.customer); else { $('custErr').textContent = j.message; $('custErr').hidden = false; }
    }, function () { $('custErr').textContent = 'No connection.'; $('custErr').hidden = false; });
  }
  $('custQ').addEventListener('input', function () { clearTimeout(custT); custT = setTimeout(custSearch, 250); });
  $('custQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(custT); custSearch(); } });
  $('custAdd').addEventListener('click', function () {
    $('custErr').hidden = true;
    post('customer_new', { first: $('custFirst').value, last: $('custLast').value, email: $('custEmail').value, phone: $('custPhone').value }).then(function (j) {
      if (j.ok) { attach(j.customer); if (j.customer.existing) flash('Already a customer: ' + j.customer.name); }
      else { $('custErr').textContent = j.message; $('custErr').hidden = false; }
    }, function () { $('custErr').textContent = 'No connection.'; $('custErr').hidden = false; });
  });
  $('btnCustomer').addEventListener('click', function () {
    $('custQ').value = ''; $('custResults').replaceChildren(); $('custErr').hidden = true; $('custNewWrap').open = false;
    ['custFirst', 'custLast', 'custEmail', 'custPhone'].forEach(function (id) { $(id).value = ''; });
    $('dlgCustomer').showModal(); $('custQ').focus();
  });
  $('custClose').addEventListener('click', function () { $('dlgCustomer').close(); });

  // -- slice 7: held carts --
  function renderHoldsBtn() { $('btnHold').textContent = S.holds.length ? 'Hold · ' + S.holds.length + ' waiting' : 'Hold'; }
  function loadHolds() {
    if (!BOOT.holds) return;
    post('holds_list', {}).then(function (j) { if (j.ok) { S.holds = j.holds; renderHoldsBtn(); if ($('dlgHolds').open) renderHolds(); } }, function () {});
  }
  function holdErr(m) { $('holdErr').textContent = m; $('holdErr').hidden = !m; }
  function renderHolds() {
    var busy = S.cart.length > 0;
    $('holdNewWrap').hidden = !busy;
    $('holdTitle').textContent = busy ? 'Hold a cart' : 'Held carts';
    $('holdWaiting').hidden = !busy;
    $('holdList').replaceChildren.apply($('holdList'), S.holds.length ? S.holds.map(function (h) {
      return el('div', { class: 'holdrow' }, [
        el('div', {}, [el('b', { text: h.label }), el('small', { class: 'muted', text: h.lines + (h.lines === 1 ? ' item · ' : ' items · ') + money(h.total_cents) + ' · ' + h.when + (h.by ? ' · ' + h.by : '') })]),
        el('div', { class: 'holdact' }, [
          el('button', { type: 'button', class: 'btn sm primary', text: 'Take back', onclick: function () { recall(h); } }),
          el('button', { type: 'button', class: 'btn sm danger', text: 'Remove', onclick: function () {
            confirmBox('Remove "' + h.label + '"?', 'The held cart is thrown away. Nothing was charged.', function () {
              post('hold_discard', { id: h.id }).then(function (j) { if (j.ok) { S.holds = j.holds; renderHoldsBtn(); renderHolds(); } else holdErr(j.message); });
            });
          } })])]);
    }) : [el('p', { class: 'muted', text: 'No carts waiting.' })]);
  }
  function recall(h) {
    holdErr('');
    if (S.cart.length) return holdErr('Finish or hold the cart you have open first.');
    post('recall', { id: h.id }).then(function (j) {
      if (!j.ok) { holdErr(j.message); loadHolds(); return; }
      var st = j.state || {};
      S.cart = (st.cart || []).map(function (l) {   // keep today's price and tax rate for stocked items (the server checks again anyway)
        var it = l.product_id && S.byId[l.product_id];
        if (it) { l.rate_bp = it.rate_bp; if (it.mode !== 'open_price') l.unit_price_cents = it.price_cents; }
        else if (!l.product_id) l.rate_bp = S.customRate;
        return l;
      });
      S.discPct = st.discPct || 0; S.exempt = !!st.exempt; S.customer = st.customer || null;
      S.payments = []; S.key = null; S.holds = j.holds;
      changed(); renderHoldsBtn();
      $('dlgHolds').close(); flash('Took back: ' + h.label);
    }, function () { holdErr('No connection. Try again.'); });
  }
  $('holdSave').addEventListener('click', function () {
    holdErr('');
    if (!S.cart.length) return;
    if (S.payments.length) return holdErr('This cart has payments entered. Remove them (Back to cart → Remove) or finish the sale first.');
    post('hold', { register_id: S.regId, label: $('holdLabel').value, total_cents: price().total,
                   state: { cart: S.cart, discPct: S.discPct, exempt: S.exempt, customer: S.customer } }).then(function (j) {
      if (!j.ok) return holdErr(j.message);
      S.holds = j.holds;
      S.cart = []; S.payments = []; S.discPct = 0; S.exempt = false; S.key = null; S.tender = null; S.customer = null;
      save(); renderCart(); renderHoldsBtn(); $('dlgHolds').close(); flash('Cart held');
    }, function () { holdErr('No connection. The cart is still here; try again.'); });
  });
  $('btnHold').addEventListener('click', function () { $('holdLabel').value = S.customer ? S.customer.name : ''; holdErr(''); renderHolds(); $('dlgHolds').showModal(); loadHolds(); });
  $('holdClose').addEventListener('click', function () { $('dlgHolds').close(); });

  // ── cart sheet on phones ─────────────────────────────────────────────────
  $('btnCartOpen').addEventListener('click', function () { $('cart').classList.add('open'); });
  $('btnCartClose').addEventListener('click', function () { $('cart').classList.remove('open'); });

  // ── search ───────────────────────────────────────────────────────────────
  $('search').addEventListener('input', function (e) { S.q = e.target.value.trim(); renderCats(); renderGrid(); });
  $('search').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var hit = bySku(e.target.value.trim());
    if (hit) { pick(hit); e.target.value = ''; S.q = ''; renderCats(); renderGrid(); }
  });
  function bySku(code) {
    if (!code) return null;
    code = code.toUpperCase();
    return S.items.find(function (it) { return it.sku && it.sku.toUpperCase() === code; }) || null;
  }
  function scanned(code) {
    if (S.scanFor === 'gift') {   // gift card QR (Nov 11): back to the payment with the code
      S.scanFor = null; $('dlgScan').close(); $('gcCode').value = code; gcLookup(); return;
    }
    var it = bySku(code);
    if (!it) { $('scanMsg').textContent = 'No product with SKU ' + code + '.'; flash('SKU not found: ' + code); return; }
    if (it.stock !== null && it.stock <= 0) { flash('Out of stock: ' + it.name); return; }
    pick(it);
    $('scanMsg').textContent = 'Added ' + it.name + '. Scan the next one.';
  }

  // Hardware scanners type fast and end with Enter, anywhere on the page (except in other inputs).
  var buf = '', lastKey = 0;
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toUpperCase();
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    var now = Date.now();
    if (e.key === 'Enter') { if (buf.length >= 3) scanned(buf); buf = ''; return; }
    if (e.key.length === 1) { if (now - lastKey > 120) buf = ''; buf += e.key; lastKey = now; }
  });

  // Camera scanning where the browser has a built-in barcode reader (Chrome on Android); typing otherwise.
  var stream = null, scanTimer = null;
  $('btnScan').addEventListener('click', function () { S.scanFor = null; startScan(); });
  $('btnGcScan').addEventListener('click', function () { S.scanFor = 'gift'; startScan(); });
  function startScan() {
    $('dlgScan').showModal(); $('skuInput').value = ''; $('skuInput').focus();
    $('dlgScan').querySelector('h3').textContent = S.scanFor === 'gift' ? 'Scan the gift card' : 'Scan';
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices) { $('scanMsg').textContent = 'This browser can\'t read barcodes with the camera. A scanner works anywhere on this screen, or type the SKU:'; return; }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s; var v = $('scanVideo'); v.srcObject = s; v.hidden = false; v.play();
      var det = new window.BarcodeDetector(), busy = false, last = '', lastAt = 0;
      scanTimer = setInterval(function () {
        if (busy || v.readyState < 2) return; busy = true;
        det.detect(v).then(function (codes) {
          busy = false;
          if (!codes.length) return;
          var c = codes[0].rawValue;
          if (c === last && Date.now() - lastAt < 2500) return;
          last = c; lastAt = Date.now(); scanned(c);
        }, function () { busy = false; });
      }, 300);
    }, function () { $('scanMsg').textContent = 'Camera not available. Type the SKU:'; });
  }
  function stopScan() {
    clearInterval(scanTimer); scanTimer = null;
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    stream = null; $('scanVideo').hidden = true;
  }
  $('dlgScan').addEventListener('close', function () { stopScan(); S.scanFor = null; });
  $('scanClose').addEventListener('click', function () { $('dlgScan').close(); });
  $('formSku').addEventListener('submit', function (e) { e.preventDefault(); scanned($('skuInput').value.trim()); $('skuInput').value = ''; });

  // ── payment ──────────────────────────────────────────────────────────────
  (BOOT.payTenders || Object.keys(BOOT.tenders)).forEach(function (t) {   // only the types turned on in Settings, in that order
    $('tenderBtns').appendChild(el('button', { type: 'button', class: 'btn', 'data-t': t, text: BOOT.tenders[t], 'aria-pressed': 'false', onclick: function () { chooseTender(t); } }));
  });
  function openPay() {
    if (!S.cart.length) return;
    if (!S.key) S.key = newKey();
    S.tender = null; $('saveStatus').hidden = true; $('btnRetry').hidden = true; $('payErr').hidden = true; $('capBox').hidden = true;
    renderPay();
    $('cart').classList.remove('open');
    $('dlgPay').showModal();
  }
  $('btnPay').addEventListener('click', openPay);
  $('btnPay2').addEventListener('click', openPay);
  $('payClose').addEventListener('click', function () { if (!S.saving) $('dlgPay').close(); });
  $('dlgPay').addEventListener('cancel', function (e) { if (S.saving) e.preventDefault(); });

  function left() { return price().total - paid(); }
  function renderPay() {
    var p = price(), lf = left();
    $('pTotal').textContent = money(p.total); $('pPaid').textContent = money(paid()); $('pLeft').textContent = money(lf);
    $('payList').replaceChildren.apply($('payList'), S.payments.map(function (py, i) {
      var extra = [py.payer_handle, py.masked || (py.reference ? '#' + py.reference : ''), py.tender === 'cash' && py.tendered_cents > py.amount_cents ? 'gave ' + money(py.tendered_cents) + ', change ' + money(py.tendered_cents - py.amount_cents) : '']
        .filter(Boolean).join(' · ');
      return el('li', {}, [el('span', {}, [el('b', { text: BOOT.tenders[py.tender] + ' ' + money(py.amount_cents) }), extra ? el('br') : null, extra ? el('small', { text: extra }) : null]),
        el('button', { type: 'button', class: 'link', text: 'Remove', disabled: S.saving, onclick: function () { S.payments.splice(i, 1); save(); renderPay(); } })]);
    }));
    var done = lf === 0 && (S.payments.length > 0 || (p.total === 0 && S.cart.length > 0));   // a $0 sale (free admission) needs no payment
    $('payAdd').hidden = done || S.saving;
    $('payDone').hidden = !done || S.saving;
    if (lf < 0) { $('payErr').textContent = 'Payments are more than the total. Remove one.'; $('payErr').hidden = false; }
    Array.prototype.forEach.call($('tenderBtns').children, function (b) { b.setAttribute('aria-pressed', b.dataset.t === S.tender ? 'true' : 'false'); });
    $('payCash').hidden = S.tender !== 'cash';
    $('payGift').hidden = S.tender !== 'gift_card';
    $('payOther').hidden = !S.tender || S.tender === 'cash' || S.tender === 'gift_card';
    $('btnAddPay').hidden = !S.tender;
  }
  function chooseTender(t) {
    S.tender = t; $('payErr').hidden = true;
    var lf = Math.max(0, left());
    if (t === 'cash') {
      $('cashGiven').value = '';
      var q = $('cashQuick'); q.replaceChildren();
      var opts = [lf];
      [100, 500, 1000, 2000, 5000, 10000].forEach(function (b) { var v = Math.ceil(lf / b) * b; if (v > lf && opts.indexOf(v) < 0) opts.push(v); });
      opts.sort(function (a, b) { return a - b; }).slice(0, 6).forEach(function (v, i) {
        q.appendChild(el('button', { type: 'button', text: i === 0 ? 'Exact ' + money(v) : money(v), onclick: function () { $('cashGiven').value = (v / 100).toFixed(2); cashChange(); } }));
      });
      cashChange();
      setTimeout(function () { $('cashGiven').focus(); }, 0);
    } else if (t === 'gift_card') {   // gift cards (Nov 11): check the balance first
      S.gc = null; $('gcCode').value = ''; $('gcInfo').textContent = 'Type the code on the card, or scan its QR.'; $('gcAmtWrap').hidden = true;
      if (S.cart.some(function (l) { var it = l.product_id && S.byId[l.product_id]; return it && it.type === 'gift_card'; })) {
        $('gcInfo').textContent = 'Gift cards can\'t be bought with a gift card. Use another payment type.';
      }
      setTimeout(function () { $('gcCode').focus(); }, 0);
    } else {
      var p2p = t === 'paypal' || t === 'venmo' || t === 'cashapp';
      $('payAmt').value = (lf / 100).toFixed(2);
      $('payer').value = ''; $('payRef').value = '';
      $('payerWrap').hidden = !p2p; $('p2pHint').hidden = !p2p;
      showP2P(p2p ? t : null, lf);
      $('refLbl').textContent = t === 'check' ? 'Check number (optional)' : 'Last characters of the transaction ID (optional)';
      setTimeout(function () { (p2p ? $('payer') : $('payAmt')).focus(); }, 0);
    }
    renderPay();
  }
  // -- slice 9: the farm's handle and a QR code for the app the customer pays with --
  function p2pLink(t, h, c) {
    if (!h) return '';
    var amt = c > 0 ? '/' + (c / 100).toFixed(2) : '';
    if (t === 'paypal') return 'https://paypal.me/' + encodeURIComponent(h) + amt;
    if (t === 'cashapp') return 'https://cash.app/$' + encodeURIComponent(h) + amt;
    if (t === 'venmo') return 'https://venmo.com/u/' + encodeURIComponent(h);
    return '';
  }
  function showP2P(t, c) {
    var h = t ? (POS.handles || {})[t] : '', link = p2pLink(t, h, c);
    $('p2pPay').hidden = !link;
    if (!link) return;
    $('p2pHandle').textContent = (t === 'venmo' ? '@' : t === 'cashapp' ? '$' : 'paypal.me/') + h;
    $('p2pAmt').textContent = (t === 'venmo' ? 'Customer types ' : 'Asks for ') + money(c);
    $('p2pLink').href = link;
    $('p2pQr').replaceChildren();
    if (window.QRCode) { try { new QRCode($('p2pQr'), { text: link, width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {} }
  }
  $('payAmt').addEventListener('input', function () { if (!$('p2pPay').hidden) showP2P(S.tender, cents($('payAmt').value)); });

  function cashChange() {
    var g = cents($('cashGiven').value), lf = left();
    $('cashChange').textContent = g > lf ? 'Change due: ' + money(g - lf) : (g > 0 && g < lf ? money(lf - g) + ' still to pay after this' : '');
  }
  $('cashGiven').addEventListener('input', cashChange);
  $('btnAddPay').addEventListener('click', addPayment);
  [$('cashGiven'), $('payAmt'), $('payer'), $('payRef')].forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addPayment(); } }); });
  // -- gift cards (Nov 11) --
  function gcLookup() {
    var code = $('gcCode').value.trim();
    if (!code) return;
    $('gcInfo').textContent = 'Checking…'; $('gcAmtWrap').hidden = true; S.gc = null;
    api('gift_card&code=' + encodeURIComponent(code)).then(function (j) {
      if (!j.ok) { $('gcInfo').textContent = j.message; return; }
      var c = j.card, already = S.payments.filter(function (p) { return p.gift_card_code === c.code; }).reduce(function (s, p) { return s + p.amount_cents; }, 0);
      var bal = c.balance_cents - already;
      if (!c.usable || bal <= 0) { $('gcInfo').textContent = 'Card ' + c.masked + ': ' + (c.message || 'nothing left after the payment already added.'); return; }
      S.gc = { code: c.code, masked: c.masked, balance: bal };
      $('gcInfo').textContent = 'Card ' + c.masked + ' has ' + money(bal) + ' on it.';
      $('gcAmt').value = (Math.min(bal, Math.max(0, left())) / 100).toFixed(2);
      $('gcAmtWrap').hidden = false; $('gcAmt').focus();
    }, function () { $('gcInfo').textContent = 'No connection. Try again.'; });
  }
  $('formGift').addEventListener('submit', function (e) { e.preventDefault(); gcLookup(); });
  $('gcAmt').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addPayment(); } });
  function addPayment() {
    var lf = left(), t = S.tender, err = function (m) { $('payErr').textContent = m; $('payErr').hidden = false; };
    if (!t || lf <= 0) return;
    if (t === 'gift_card') {
      if (!S.gc) return err('Check the gift card first.');
      var ga = cents($('gcAmt').value);
      if (!(ga > 0)) return err('Enter the amount to take from the card.');
      if (ga > S.gc.balance) return err('The card only has ' + money(S.gc.balance) + '.');
      if (ga > lf) return err('That is more than the ' + money(lf) + ' left.');
      S.payments.push({ tender: 'gift_card', amount_cents: ga, gift_card_code: S.gc.code, masked: S.gc.masked });
      S.gc = null; S.tender = null; $('payErr').hidden = true; save(); renderPay();
      return;
    }
    if (t === 'cash') {
      var g = cents($('cashGiven').value);
      if (!(g > 0)) return err('Enter the cash handed over.');
      S.payments.push({ tender: 'cash', amount_cents: Math.min(g, lf), tendered_cents: g });
    } else {
      var a = cents($('payAmt').value), payer = $('payer').value.trim(), ref = $('payRef').value.trim();
      if (!(a > 0)) return err('Enter the amount.');
      if (a > lf) return err('That is more than the ' + money(lf) + ' left.');
      if ((t === 'paypal' || t === 'venmo' || t === 'cashapp') && !payer) return err('Add the payer\'s name or handle.');
      S.payments.push({ tender: t, amount_cents: a, payer_handle: payer || null, reference: ref || null });
    }
    S.tender = null; $('payErr').hidden = true; save(); renderPay();
  }

  $('btnComplete').addEventListener('click', function () { complete(); });
  $('btnRetry').addEventListener('click', function () { complete(); });
  $('btnCapOverride').addEventListener('click', function () {
    var r = $('capReason').value.trim();
    if (!r) { $('capReason').focus(); return; }
    complete(r);
  });
  function status(msg, bad) { var s = $('saveStatus'); s.textContent = msg; s.className = 'status ' + (bad ? 'bad' : 'saving'); s.hidden = false; }
  function complete(capOverride) {
    if (S.saving) return;
    if (left() !== 0) return;
    S.saving = true; $('btnRetry').hidden = true; $('capBox').hidden = true; renderPay();
    status('Saving… (not saved yet)');
    var printIt = $('chkPrint').checked;
    var body = {
      idempotency_key: S.key, register_id: S.regId, discount_pct: S.discPct, tax_exempt: S.exempt, printed: printIt,
      customer_id: S.customer ? S.customer.id : null,
      lines: S.cart.map(function (l) { return { product_id: l.product_id, name: l.name, qty: l.qty, unit_price_cents: l.unit_price_cents, disc_pct: l.disc_pct || 0, note: l.note || '' }; }),
      payments: S.payments
    };
    if (capOverride) body.capacity_override = capOverride;
    api('complete', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BOOT.csrf }, body: JSON.stringify(body) })
      .then(function (j) {
        S.saving = false;
        if (j.ok) return saved(j.sale, printIt);
        status('Not saved: ' + (j.message || 'unknown problem.'), true);
        if (/drawer|register/i.test(j.message || '')) loadDrawer();
        $('btnRetry').hidden = j.status === 422 || j.status === 409;   // a problem with the sale itself needs fixing, not retrying
        if (j.code === 'capacity') { $('capBox').hidden = !j.can_override; $('capReason').value = ''; }
        renderPay();
      }, function () {
        S.saving = false;
        status('Not saved: no connection to the server. The sale is kept here; tap Retry.', true);
        $('btnRetry').hidden = false; renderPay();
      });
  }
  function saved(sale, printIt) {
    S.lastSale = sale; S.lastEmail = S.customer && S.customer.email || '';   // slice 9: suggest it for the emailed receipt
    S.cart = []; S.payments = []; S.discPct = 0; S.exempt = false; S.key = null; S.tender = null; S.customer = null;
    save(); renderCart();
    $('dlgPay').close();
    var change = sale.payments.reduce(function (s, p) { return s + (+p.change_cents || 0); }, 0);
    $('doneNo').textContent = sale.sale_number + ' · ' + money(sale.total_cents);
    $('doneChange').hidden = !change; $('doneChange').textContent = 'Change ' + money(change);
    renderDoneGift(sale);
    $('dlgDone').showModal();
    if (printIt) printReceipt(sale);
    loadCatalog(true); loadToday(); loadDrawer();
  }
  // Gift cards sold on this sale (Nov 11): print the card(s), or email one.
  function renderDoneGift(sale) {
    var cards = (sale.gift_cards || []).filter(function (c) { return c.status === 'active'; });
    $('doneGift').hidden = !cards.length;
    if (!cards.length) return;
    $('btnGiftPrint').href = 'gift_card_print.php?sale=' + sale.id;
    $('btnGiftPrint').textContent = cards.length === 1 ? 'Print gift card' : 'Print ' + cards.length + ' gift cards';
    $('doneGiftList').replaceChildren.apply($('doneGiftList'), cards.map(function (c) {
      return el('li', {}, [el('span', {}, [el('b', { text: money(c.initial_cents) + ' gift card' }), el('br'), el('small', { text: c.code_fmt })]),
        el('button', { type: 'button', class: 'link', text: 'Email', onclick: function () { emailDialog(sale, S.lastEmail || '', c); } })]);
    }));
  }
  $('btnNew').addEventListener('click', function () { $('dlgDone').close(); $('search').focus(); });
  $('btnReprint').addEventListener('click', function () { if (S.lastSale) printReceipt(S.lastSale); });

  // -- slice 9: email the receipt --
  var emSale = null, emCard = null;
  function emailDialog(sale, suggest, card) {
    emSale = sale; emCard = card || null;
    $('dlgEmail').querySelector('h3').textContent = emCard ? 'Email the gift card' : 'Email the receipt';
    $('emSale').textContent = emCard ? money(emCard.initial_cents) + ' gift card ' + emCard.code_fmt + '. Send it to whoever will use it.' : sale.sale_number + ' · ' + money(sale.total_cents);
    $('emTo').value = suggest || ''; $('emErr').hidden = true; $('emSend').disabled = false;
    $('dlgEmail').showModal(); $('emTo').focus();
  }
  $('btnEmailDone').addEventListener('click', function () { if (S.lastSale) emailDialog(S.lastSale, S.lastEmail || ''); });
  $('emCancel').addEventListener('click', function () { $('dlgEmail').close(); });
  $('emTo').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('emSend').click(); } });
  $('emSend').addEventListener('click', function () {
    var to = $('emTo').value.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) { $('emErr').textContent = 'Check the email address.'; $('emErr').hidden = false; return; }
    $('emSend').disabled = true;
    (emCard ? post('gift_card_email', { card_id: emCard.id, email: to }) : post('email_receipt', { sale_id: emSale.id, email: to })).then(function (j) {
      $('emSend').disabled = false;
      if (!j.ok) { $('emErr').textContent = j.message; $('emErr').hidden = false; return; }
      $('dlgEmail').close(); flash((emCard ? 'Gift card' : 'Receipt') + ' sent to ' + j.email);
    }, function () { $('emSend').disabled = false; $('emErr').textContent = 'No connection. Try again.'; $('emErr').hidden = false; });
  });

  // ── receipt ──────────────────────────────────────────────────────────────
  function printReceipt(s) {
    var f = BOOT.farm, rows = [], thrift = false;
    s.lines.forEach(function (l) {
      if ((l.category_label || '').toLowerCase().indexOf('thrift') >= 0) thrift = true;
      var q = parseFloat(l.quantity), each = l.unit === 'each';
      rows.push(el('tr', {}, [el('td', { text: l.description }), el('td', { class: 'r', text: money(l.gross_cents) })]));
      if (q !== 1 || !each) rows.push(el('tr', {}, [el('td', { text: '  ' + qtyText(q) + (each ? ' × ' : ' ' + l.unit + ' × ') + money(l.unit_price_cents) }), el('td')]));
      var lb = +l.line_discount_bp || 0;
      if (lb) rows.push(el('tr', {}, [el('td', { text: '  ' + (lb / 100) + '% off' }), el('td', { class: 'r', text: money(-muldiv(+l.gross_cents, lb, 10000)) })]));
      if (l.note) rows.push(el('tr', {}, [el('td', { text: '  ' + l.note }), el('td')]));
    });
    var tot = [['Subtotal', s.subtotal_cents]];
    var itemOff = s.lines.reduce(function (a, l) { return a + (+l.line_discount_bp ? muldiv(+l.gross_cents, +l.line_discount_bp, 10000) : 0); }, 0);
    if (itemOff) tot.push(['Item discounts', -itemOff]);
    if (s.discount_cents - itemOff) tot.push(['Discount (' + (s.discount_bp / 100) + '%)', -(s.discount_cents - itemOff)]);
    if (s.tax_exempt) tot.push(['Tax (exempt)', 0]);
    else Object.keys(s.tax_by_rate).forEach(function (r) { if (+r > 0) tot.push(['Tax ' + (r / 100) + '%', s.tax_by_rate[r]]); });
    var pays = s.payments.map(function (p) {
      var r = [el('tr', {}, [el('td', { text: (BOOT.tenders[p.tender] || p.tender) + (p.gift_card ? ' ' + p.gift_card.masked : '') }), el('td', { class: 'r', text: money(p.amount_cents) })])];
      if (p.gift_card) r.push(el('tr', {}, [el('td', { text: '  Left on card' }), el('td', { class: 'r', text: money(p.gift_card.balance_after_cents) })]));
      if (p.tender === 'cash' && +p.change_cents > 0) {
        r.push(el('tr', {}, [el('td', { text: '  Cash given' }), el('td', { class: 'r', text: money(p.tendered_cents) })]));
        r.push(el('tr', {}, [el('td', { text: '  Change' }), el('td', { class: 'r', text: money(p.change_cents) })]));
      }
      return r;
    }).reduce(function (a, b) { return a.concat(b); }, []);
    $('receipt').replaceChildren.apply($('receipt'), [   // optional parts are null: filter them out (replaceChildren would print "null")
      el('div', { class: 'c b', text: f.name }),
      f.address ? el('div', { class: 'c', text: f.address }) : null,
      f.phone ? el('div', { class: 'c', text: f.phone }) : null,
      el('hr'),
      el('div', { text: 'Receipt ' + s.sale_number }),
      el('div', { text: s.completed_local + ' · ' + BOOT.staff }),
      s.customer_name ? el('div', { text: 'Customer: ' + s.customer_name }) : null,
      el('hr'),
      el('table', {}, rows),
      el('hr'),
      el('table', {}, tot.map(function (t) { return el('tr', {}, [el('td', { text: t[0] }), el('td', { class: 'r', text: money(t[1]) })]); })
        .concat([el('tr', { class: 'b' }, [el('td', { text: 'TOTAL' }), el('td', { class: 'r', text: money(s.total_cents) })])])),
      el('hr'),
      el('table', {}, pays),
      s.status === 'voided' ? el('div', { class: 'note', text: 'VOIDED' + (s.voided_local ? ' ' + s.voided_local : '') }) : null,
      (s.refunds && s.refunds.length) ? el('table', {}, s.refunds.map(function (r) { return el('tr', {}, [el('td', { text: 'Refund ' + r.refund_number + ' ' + r.when }), el('td', { class: 'r', text: money(-r.total_cents) })]); })) : null,
      (s.gift_cards && s.gift_cards.length) ? el('table', {}, s.gift_cards.map(function (c) {   // gift cards sold (Nov 11): the buyer keeps the code
        return el('tr', {}, [el('td', { text: 'Gift card ' + c.code_fmt + (c.status === 'void' ? ' (cancelled)' : '') }), el('td', { class: 'r', text: money(c.initial_cents) })]);
      })) : null,
      (s.gift_cards && s.gift_cards.length) ? el('div', { class: 'note', text: 'Gift cards never expire. Treat the code like cash.' }) : null,
      thrift ? el('div', { class: 'note', text: 'Thrift shop items are sold as-is.' }) : null,
      f.policy ? el('p', { class: 'c', text: f.policy }) : null,
      el('p', { class: 'c', text: 'Thank you! ' + f.domain })
    ].filter(Boolean));
    setTimeout(function () { window.print(); }, 50);
  }

  // ── cash drawer ──────────────────────────────────────────────────────────
  function post(path, body) {
    return api(path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BOOT.csrf }, body: JSON.stringify(body) });
  }
  function loadDrawer() {
    if (!BOOT.drawers) return;
    try { S.regId = +localStorage.getItem(REGKEY) || null; } catch (e) {}
    api('drawer&register_id=' + (S.regId || 0)).then(function (j) {
      if (!j.ok) return;
      S.dinfo = j;
      var ids = Object.keys(j.registers).map(Number);
      if (!S.regId || ids.indexOf(S.regId) < 0) {
        if (ids.length === 1) { setReg(ids[0]); return; }
        return chooseReg(j.registers);
      }
      S.drawer = j.session;
      renderDrawer();
    }, function () {});
  }
  function setReg(id) { var changed = S.regId !== id; S.regId = id; try { localStorage.setItem(REGKEY, String(id)); } catch (e) {} loadDrawer(); if (changed && BOOT.stock) loadCatalog(true); }
  function chooseReg(regs) {
    $('regChips').replaceChildren.apply($('regChips'), Object.keys(regs).map(function (id) {
      return el('button', { type: 'button', text: regs[id], onclick: function () { $('dlgRegister').close(); setReg(+id); } });
    }));
    if (!$('dlgRegister').open) $('dlgRegister').showModal();
  }
  function regName() { return S.dinfo && S.regId ? S.dinfo.registers[S.regId] : 'Register'; }
  function renderDrawer() {
    var b = $('btnDrawer'), d = S.drawer;
    b.hidden = false;
    $('btnSales').hidden = !BOOT.refunds;
    b.textContent = d ? 'Drawer open' : 'Drawer closed';
    b.classList.toggle('closed', !d);
    $('drawerBanner').hidden = !!d;
    $('drawerBannerText').textContent = 'The ' + regName() + ' drawer is closed. Open it before taking payment.';
    renderCart();
    if (d && $('dlgDrawer').open) fillDrawerSheet();
  }
  function fillDrawerSheet() {
    var d = S.drawer;
    $('drTitle').textContent = regName() + ' drawer';
    $('drOpened').textContent = 'Opened ' + d.opened + ' by ' + d.opened_by + ' · ' + d.sales.n + (d.sales.n === 1 ? ' sale · ' : ' sales · ') + money(d.sales.tot);
    var rows = [['Starting float', d.float_cents], ['Cash sales', d.cash_sales_cents], ['Paid in', d.paid_in_cents], ['Paid out', -d.paid_out_cents], ['Cash drops', -d.drops_cents]];
    $('drRows').replaceChildren.apply($('drRows'), rows.filter(function (r, i) { return i < 2 || r[1]; }).map(function (r) {
      return el('div', {}, [el('dt', { text: r[0] }), el('dd', { text: money(r[1]) })]);
    }).concat([el('div', { class: 'grand' }, [el('dt', { text: 'Should be in drawer' }), el('dd', { text: money(d.expected_cents) })])]));
    $('drReport').href = 'register.php?view=drawer&id=' + d.id;
  }
  $('btnDrawer').addEventListener('click', function () {
    if (!S.drawer) return openCount('open');
    fillDrawerSheet(); $('dlgDrawer').showModal();
    api('drawer&register_id=' + S.regId).then(function (j) { if (j.ok) { S.dinfo = j; S.drawer = j.session; if (S.drawer) fillDrawerSheet(); else { $('dlgDrawer').close(); renderDrawer(); } } }, function () {});
  });
  $('btnOpenDrawer').addEventListener('click', function () { openCount('open'); });
  $('drClose').addEventListener('click', function () { $('dlgDrawer').close(); });
  [['paid_in', 'Paid in'], ['paid_out', 'Paid out'], ['drop', 'Cash drop']].forEach(function (t) {
    $('drMoves').appendChild(el('button', { type: 'button', class: 'btn', text: t[1], onclick: function () { openMove(t[0], t[1]); } }));
  });

  var moveType = null;
  function openMove(type, label) {
    moveType = type;
    $('mvTitle').textContent = label;
    $('mvAmt').value = ''; $('mvReason').value = ''; $('mvErr').hidden = true;
    $('mvReason').placeholder = type === 'paid_out' ? 'e.g. ice for the cooler' : type === 'drop' ? 'e.g. to the safe' : 'e.g. more change';
    $('dlgMove').showModal(); $('mvAmt').focus();
  }
  $('mvCancel').addEventListener('click', function () { $('dlgMove').close(); });
  $('formMove').addEventListener('submit', function (e) {
    e.preventDefault();
    var a = cents($('mvAmt').value), r = $('mvReason').value.trim(), err = function (m) { $('mvErr').textContent = m; $('mvErr').hidden = false; };
    if (!(a > 0)) return err('Enter the amount.');
    if (!r) return err('Say what the cash was for.');
    $('mvOk').disabled = true;
    post('drawer_move', { session_id: S.drawer.id, type: moveType, amount_cents: a, reason: r }).then(function (j) {
      $('mvOk').disabled = false;
      if (!j.ok) return err(j.message);
      S.drawer = j.session; $('dlgMove').close(); fillDrawerSheet(); flash('Saved');
    }, function () { $('mvOk').disabled = false; err('No connection. Try again.'); });
  });

  // Count dialog: opening float or closing count, by denomination
  var countMode = 'open';
  function countTotal() {
    var t = 0;
    Array.prototype.forEach.call($('cnRows').querySelectorAll('input'), function (i) { t += (+i.dataset.c) * Math.max(0, parseInt(i.value, 10) || 0); });
    return t;
  }
  function countChanged() {
    var t = countTotal();
    $('cnTotal').textContent = money(t);
    if (countMode !== 'close') return;
    var v = t - S.drawer.expected_cents, over = Math.abs(v) > S.dinfo.threshold_cents;
    $('cnVarLbl').textContent = v < 0 ? 'Short' : v > 0 ? 'Over' : 'Variance';
    $('cnVar').textContent = money(v);
    $('cnVarRow').className = over ? 'off' : (v === 0 ? 'even' : '');
    $('cnNoteWrap').hidden = v === 0;
    $('cnNote').required = over;
  }
  function openCount(mode) {
    if (!S.dinfo) return;
    countMode = mode;
    var closing = mode === 'close';
    $('cnTitle').textContent = (closing ? 'Close the ' : 'Open the ') + regName() + ' drawer';
    $('cnHint').textContent = closing ? 'Count every bill and coin in the drawer.' : 'Count the starting cash (float) going into the drawer.';
    $('cnOk').textContent = closing ? 'Close drawer' : 'Open drawer';
    $('cnRows').replaceChildren.apply($('cnRows'), Object.keys(S.dinfo.denominations).map(Number).sort(function (a, b) { return b - a; }).map(function (c) {
      return el('label', {}, [el('span', { text: S.dinfo.denominations[c] }), el('input', { type: 'number', inputmode: 'numeric', min: '0', step: '1', 'data-c': c, placeholder: '0', 'aria-label': S.dinfo.denominations[c] + ' count', oninput: countChanged })]);
    }));
    $('cnExpRow').hidden = $('cnVarRow').hidden = !closing;
    if (closing) $('cnExp').textContent = money(S.drawer.expected_cents);
    $('cnNote').value = ''; $('cnNoteWrap').hidden = true; $('cnErr').hidden = true; $('cnOk').disabled = false;
    countChanged();
    if ($('dlgDrawer').open) $('dlgDrawer').close();
    $('dlgCount').showModal();
  }
  $('btnCloseDrawer').addEventListener('click', function () {
    // refresh expected cash first, so a sale on another device a moment ago is included
    api('drawer&register_id=' + S.regId).then(function (j) { if (j.ok && j.session) { S.dinfo = j; S.drawer = j.session; openCount('close'); } }, function () { openCount('close'); });
  });
  $('cnCancel').addEventListener('click', function () { $('dlgCount').close(); });
  $('formCount').addEventListener('submit', function (e) {
    e.preventDefault();
    var counts = {}, err = function (m) { $('cnErr').textContent = m; $('cnErr').hidden = false; };
    Array.prototype.forEach.call($('cnRows').querySelectorAll('input'), function (i) { var q = parseInt(i.value, 10) || 0; if (q > 0) counts[i.dataset.c] = q; });
    $('cnOk').disabled = true;
    var req = countMode === 'close'
      ? post('drawer_close', { session_id: S.drawer.id, counts: counts, note: $('cnNote').value.trim() })
      : post('drawer_open', { register_id: S.regId, counts: counts });
    req.then(function (j) {
      $('cnOk').disabled = false;
      if (!j.ok) return err(j.message);
      $('dlgCount').close();
      if (countMode === 'close') { location.href = 'register.php?view=drawer&id=' + j.session.id; return; }
      S.drawer = j.session; renderDrawer(); flash('Drawer open with ' + money(j.session.float_cents));
    }, function () { $('cnOk').disabled = false; err('No connection. Try again.'); });
  });

  // ── sales screen: reprint, refund, void ─────────────────────────────────
  function openSales(q) {
    $('salesHint').textContent = q ? 'Search: ' + q : 'Today on this register. Tap a sale to reprint, refund or void it.';
    $('salesList').replaceChildren(el('li', { class: 'muted', text: 'Loading…' }));
    if (!$('dlgSales').open) $('dlgSales').showModal();
    api('sales&register_id=' + (S.regId || 0) + (q ? '&q=' + encodeURIComponent(q) : '')).then(function (j) {
      if (!j.ok) { $('salesList').replaceChildren(el('li', { class: 'err', text: j.message })); return; }
      if (!j.sales.length) { $('salesList').replaceChildren(el('li', { class: 'muted', text: q ? 'No sale with that number.' : 'No sales yet today.' })); return; }
      $('salesList').replaceChildren.apply($('salesList'), j.sales.map(function (x) {
        var tag = x.status === 'voided' ? 'voided' : x.status === 'refunded' ? 'refunded' : x.status === 'partially_refunded' ? 'part refunded' : '';
        return el('li', {}, [el('button', { type: 'button', onclick: function () { openSale(x.id); } }, [
          el('span', {}, [el('b', { text: x.sale_number }), el('br'), el('small', { text: x.time + ' · ' + x.lines + (x.lines === 1 ? ' item · ' : ' items · ') + x.tenders.split(',').map(function (t) { return BOOT.tenders[t] || t; }).join(' + ') })]),
          el('span', { class: 'amt' + (tag ? ' off' : '') }, [money(x.total_cents), tag ? el('br') : null, tag ? el('small', { text: tag }) : null])
        ])]);
      }));
    }, function () { $('salesList').replaceChildren(el('li', { class: 'err', text: 'No connection. Try again.' })); });
  }
  $('btnSales').addEventListener('click', function () { $('salesQ').value = ''; openSales(''); });
  $('formSalesFind').addEventListener('submit', function (e) { e.preventDefault(); openSales($('salesQ').value.trim()); });
  $('salesClose').addEventListener('click', function () { $('dlgSales').close(); });

  S.cur = null;
  function openSale(id) {
    api('sale&id=' + id).then(function (j) {
      if (!j.ok) return flash(j.message);
      S.cur = j.sale; S.refundRule = { threshold: j.refund_threshold_cents, canApprove: j.can_approve };
      renderSale();
      if ($('dlgSales').open) $('dlgSales').close();
      if (!$('dlgSale').open) $('dlgSale').showModal();
    }, function () { flash('No connection. Try again.'); });
  }
  function renderSale() {
    var s = S.cur;
    $('sdTitle').textContent = s.sale_number;
    $('sdMeta').textContent = s.completed_local + ' · ' + s.payments.map(function (p) { return (BOOT.tenders[p.tender] || p.tender) + ' ' + money(p.amount_cents); }).join(' + ');
    $('sdVoided').hidden = s.status !== 'voided';
    $('sdVoided').textContent = 'Voided ' + (s.voided_local || '') + (s.voided_by_name ? ' by ' + s.voided_by_name : '') + (s.void_reason ? ': ' + s.void_reason : '');
    $('sdLines').replaceChildren.apply($('sdLines'), s.lines.map(function (l) {
      var q = parseFloat(l.quantity), each = l.unit === 'each';
      return el('tr', {}, [el('td', {}, [l.description, el('br'), el('small', { class: 'muted', text: qtyText(q) + (each ? ' × ' : ' ' + l.unit + ' × ') + money(l.unit_price_cents) + (l.refunded_quantity > 0 ? ' · ' + qtyText(l.refunded_quantity) + ' refunded' : '') })]),
                           el('td', { class: 'r', text: money(l.line_total_cents) })]);
    }));
    var rows = [['Subtotal', s.subtotal_cents]];
    if (s.discount_cents) rows.push(['Discount', -s.discount_cents]);
    rows.push(['Tax', s.tax_cents], ['Total', s.total_cents]);
    if (s.refunded_cents) rows.push(['Refunded', -s.refunded_cents]);
    $('sdTotals').replaceChildren.apply($('sdTotals'), rows.map(function (r) { return el('div', { class: r[0] === 'Total' ? 'grand' : '' }, [el('dt', { text: r[0] }), el('dd', { text: money(r[1]) })]); }));
    $('sdRefunds').replaceChildren.apply($('sdRefunds'), s.refunds.map(function (r) {
      return el('p', { class: 'muted' }, ['Refund ' + r.refund_number + ' · ' + r.when + ' · ' + r.by + ' · ' + money(r.total_cents) + ' (' +
        r.payments.map(function (p) { return (BOOT.tenders[p.tender] || p.tender) + ' ' + money(p.amount_cents); }).join(', ') + '): ' + r.reason]);
    }));
    $('sdRefund').disabled = s.refundable_cents <= 0;
    $('sdVoid').disabled = !!s.void_blocker;
    $('sdVoidWhy').hidden = !s.void_blocker || s.status === 'voided';
    $('sdVoidWhy').textContent = s.void_blocker || '';
  }
  $('sdBack').addEventListener('click', function () { $('dlgSale').close(); openSales($('salesQ').value.trim()); });
  $('sdPrint').addEventListener('click', function () { if (S.cur) printReceipt(S.cur); });
  $('sdEmail').addEventListener('click', function () { if (S.cur) emailDialog(S.cur, S.cur.customer_email || ''); });
  $('sdVoid').addEventListener('click', function () {
    var s = S.cur;
    confirmBox('Void ' + s.sale_number + '?', 'Cancels the whole sale (' + money(s.total_cents) + ') and puts the items back in stock.' +
      (s.payments.some(function (p) { return p.tender !== 'cash'; }) ? ' Money paid by app or check has to be given back there.' : ' Give the cash back from the drawer.'), function (reason) {
      post('void', { sale_id: s.id, reason: reason }).then(function (j) {
        if (!j.ok) return flash(j.message);
        S.cur = j.sale; renderSale(); flash('Voided'); loadDrawer(); loadToday(); loadCatalog(true);
      }, function () { flash('No connection. Try again.'); });
    }, true);
  });

  // Refund: pick quantities → server works out the money → confirm
  var rfQuote = null;
  function rfReset() { rfQuote = null; $('rfMoney').hidden = true; $('rfOk').textContent = 'Work out refund'; $('rfStatus').hidden = true; }
  $('sdRefund').addEventListener('click', function () {
    var s = S.cur; S.refundKey = newKey(); rfReset();
    $('rfTitle').textContent = 'Refund on ' + s.sale_number;
    $('rfReason').value = ''; $('rfErr').hidden = true;
    $('rfLines').replaceChildren.apply($('rfLines'), s.lines.filter(function (l) { return l.remaining_quantity > 0; }).map(function (l) {
      var inp = el('input', { type: 'number', inputmode: 'decimal', min: '0', max: String(l.remaining_quantity), step: 'any', value: '0', 'data-id': l.id, 'aria-label': 'How many ' + l.description, oninput: rfReset });
      var chk = l.stockable ? el('input', { type: 'checkbox', checked: true, 'data-restock': l.id, onchange: rfReset }) : null;
      return el('div', { class: 'rfline' }, [
        el('div', {}, [el('b', { text: l.description }), el('br'), el('small', { class: 'muted', text: qtyText(l.remaining_quantity) + ' left to refund · ' + money(l.remaining_cents) })]),
        el('div', { class: 'rfq' }, [inp, el('button', { type: 'button', class: 'btn sm', text: 'All', onclick: function () { inp.value = qtyText(l.remaining_quantity); rfReset(); } })]),
        chk ? el('label', { class: 'check' }, [chk, ' Back in stock (untick if damaged)']) : el('small', { class: 'muted', text: 'Not a stocked item' })
      ]);
    }));
    $('dlgSale').close(); $('dlgRefund').showModal();
  });
  $('rfReason').addEventListener('input', function () { $('rfErr').hidden = true; });
  $('rfCancel').addEventListener('click', function () { $('dlgRefund').close(); $('dlgSale').showModal(); });
  function rfLines() {
    return Array.prototype.map.call($('rfLines').querySelectorAll('input[data-id]'), function (i) {
      var c = $('rfLines').querySelector('input[data-restock="' + i.dataset.id + '"]');
      return { sale_line_id: +i.dataset.id, qty: parseFloat(i.value) || 0, restock: c ? c.checked : false };
    }).filter(function (x) { return x.qty > 0; });
  }
  function rfErr(m) { $('rfErr').textContent = m; $('rfErr').hidden = false; }
  $('rfOk').addEventListener('click', function () {
    var lines = rfLines(), reason = $('rfReason').value.trim();
    if (!lines.length) return rfErr('Enter how many of an item to refund.');
    if (!reason) return rfErr('Give a reason for the refund.');
    if (!rfQuote) {
      post('refund_quote', { sale_id: S.cur.id, lines: lines }).then(function (j) {
        if (!j.ok) return rfErr(j.message);
        rfQuote = j.quote; showRefundMoney();
      }, function () { rfErr('No connection. Try again.'); });
      return;
    }
    var pays = Array.prototype.map.call($('rfPays').querySelectorAll('.rfpay'), function (row) {
      var a = row.querySelector('input[data-amt]'), r = row.querySelector('input[data-ref]');
      return { tender: row.dataset.t, amount_cents: cents(a.value) || 0, reference: r ? r.value.trim() : null };
    }).filter(function (p) { return p.amount_cents > 0; });
    var sum = pays.reduce(function (s, p) { return s + p.amount_cents; }, 0);
    if (sum !== rfQuote.total) return rfErr('The amounts add up to ' + money(sum) + ' but the refund is ' + money(rfQuote.total) + '.');
    confirmBox('Refund ' + money(rfQuote.total) + '?', pays.map(function (p) { return (BOOT.tenders[p.tender] || p.tender) + ' ' + money(p.amount_cents); }).join(', ') + '. This can\'t be undone.', function () {
      $('rfOk').disabled = true; $('rfStatus').className = 'status saving'; $('rfStatus').textContent = 'Saving refund… (not saved yet)'; $('rfStatus').hidden = false;
      post('refund', { idempotency_key: S.refundKey, sale_id: S.cur.id, register_id: S.regId, reason: reason, lines: lines, payments: pays }).then(function (j) {
        $('rfOk').disabled = false;
        if (!j.ok) { $('rfStatus').className = 'status bad'; $('rfStatus').textContent = 'Not saved: ' + j.message; return; }
        var cash = pays.filter(function (p) { return p.tender === 'cash'; }).reduce(function (s, p) { return s + p.amount_cents; }, 0);
        $('dlgRefund').close(); S.cur = j.sale; renderSale(); $('dlgSale').showModal();
        flash('Refund ' + j.refund_number + ' saved' + (cash ? ': give back ' + money(cash) + ' cash' : ''));
        loadDrawer(); loadCatalog(true);
      }, function () { $('rfOk').disabled = false; $('rfStatus').className = 'status bad'; $('rfStatus').textContent = 'Not saved: no connection. Tap Refund again (it won\'t refund twice).'; });
    });
  });
  function showRefundMoney() {
    var q = rfQuote;
    $('rfTotals').replaceChildren(
      el('div', {}, [el('dt', { text: 'Items' }), el('dd', { text: money(q.net) })]),
      el('div', {}, [el('dt', { text: 'Tax' }), el('dd', { text: money(q.tax) })]),
      el('div', { class: 'grand' }, [el('dt', { text: 'Refund' }), el('dd', { text: money(q.total) })]));
    var sug = {}; q.suggested.forEach(function (p) { sug[p.tender] = p.amount_cents; });
    var tenders = Object.keys(q.tender_left).filter(function (t) { return q.tender_left[t] > 0; });
    if (tenders.indexOf('cash') < 0) tenders.push('cash');
    $('rfPays').replaceChildren.apply($('rfPays'), tenders.map(function (t) {
      var p2p = t === 'paypal' || t === 'venmo' || t === 'cashapp';
      return el('div', { class: 'rfpay', 'data-t': t }, [
        el('label', {}, [(BOOT.tenders[t] || t) + (t !== 'cash' ? ' (up to ' + money(q.tender_left[t]) + ')' : ''),
          el('input', { type: 'number', inputmode: 'decimal', step: '0.01', min: '0', 'data-amt': t, value: sug[t] ? (sug[t] / 100).toFixed(2) : '' })]),
        (p2p || t === 'check') ? el('label', {}, [p2p ? 'Refund transaction ID (last characters), after sending it in ' + BOOT.tenders[t] : 'Check number (optional)',
          el('input', { type: 'text', 'data-ref': t, maxlength: '80', autocomplete: 'off' })]) : null
      ]);
    }));
    $('rfApproval').hidden = !q.needs_approval;
    $('rfApproval').textContent = q.needs_approval ? (q.can_approve ? 'Over ' + money(q.threshold_cents) + ': you are approving this refund.' : 'Over ' + money(q.threshold_cents) + ': an Owner or Manager has to do this refund.') : '';
    $('rfMoney').hidden = false;
    $('rfOk').textContent = 'Refund ' + money(q.total);
  }

  // ── today's total ────────────────────────────────────────────────────────
  function loadToday() {
    if (!BOOT.ready) { $('today').textContent = 'Register not set up'; return; }
    api('today').then(function (j) {
      if (j.ok) $('today').textContent = 'Today: ' + j.count + (j.count === 1 ? ' sale · ' : ' sales · ') + money(j.total_cents);
    }, function () {});
  }

  // ── start ────────────────────────────────────────────────────────────────
  load();
  $('btnCustomer').hidden = !BOOT.customers || !BOOT.ready;
  $('btnHold').hidden = !BOOT.holds || !BOOT.ready;
  if (!BOOT.lineDisc) S.cart.forEach(function (l) { l.disc_pct = 0; });
  renderCart();
  loadHolds();
  setInterval(loadHolds, 60000);
  loadCatalog();
  loadToday();
  loadDrawer();
  setInterval(loadToday, 60000);
  if (S.payments.length && S.cart.length) setTimeout(function () { if (BOOT.ready) openPay(); }, 300);   // an unsaved sale was waiting: pick it back up
  window.addEventListener('beforeunload', function (e) { if (S.saving) { e.preventDefault(); e.returnValue = ''; } });
})();
