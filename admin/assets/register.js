// admin/assets/register.js — Farm OS register (Phase 1, slice 1, Nov 6, 2026)
// The browser builds the cart and the payments; the server (includes/sales.php) decides prices, tax and
// totals and assigns the receipt number. Totals here use the same integer-cent maths so the payment
// amounts match the server exactly. A sale is only shown as saved after the server says so; until then
// the cart, payments and save code stay in this browser (localStorage) and Retry sends the same save
// code, so a double tap or a dropped connection can never make two sales.
(function () {
  'use strict';
  var BOOT = JSON.parse(document.getElementById('regBoot').textContent);
  var STORE = 'bcrf.register.v1';
  var $ = function (id) { return document.getElementById(id); };
  var S = { items: [], byId: {}, customRate: 0, cat: null, q: '', cart: [], discPct: 0, exempt: false,
            payments: [], key: null, tender: null, lastSale: null, saving: false };

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
    try { localStorage.setItem(STORE, JSON.stringify({ cart: S.cart, discPct: S.discPct, exempt: S.exempt, payments: S.payments, key: S.key, last: S.lastSale })); } catch (e) {}
  }
  function load() {
    try {
      var d = JSON.parse(localStorage.getItem(STORE) || 'null');
      if (d) { S.cart = d.cart || []; S.discPct = d.discPct || 0; S.exempt = !!d.exempt; S.payments = d.payments || []; S.key = d.key || null; S.lastSale = d.last || null; }
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
  function price() {
    var lines = S.cart.map(function (l) {
      var gross = muldiv(Math.round(l.qty * 1000), l.unit_price_cents, 1000);
      return { gross: gross, rate: S.exempt ? 0 : l.rate_bp };
    });
    var sub = lines.reduce(function (s, l) { return s + l.gross; }, 0);
    var disc = muldiv(sub, Math.round(S.discPct * 100), 10000);
    var alloc = [], rem = [], given = 0;
    lines.forEach(function (l, i) {
      var exact = sub > 0 ? l.gross * disc : 0;
      alloc[i] = sub > 0 ? Math.floor(exact / sub) : 0;
      rem.push([i, sub > 0 ? exact % sub : 0]);
      given += alloc[i];
    });
    rem.sort(function (a, b) { return b[1] - a[1]; });
    for (var k = 0; k < rem.length && given < disc; k++) { alloc[rem[k][0]]++; given++; }
    var tax = 0;
    lines.forEach(function (l, i) { tax += muldiv(l.gross - alloc[i], l.rate, 10000); });
    return { sub: sub, disc: disc, tax: tax, total: sub - disc + tax };
  }
  function paid() { return S.payments.reduce(function (s, p) { return s + p.amount_cents; }, 0); }

  // ── catalogue ─────────────────────────────────────────────────────────────
  function loadCatalog(quiet) {
    if (!BOOT.ready) { $('grid').replaceChildren(el('p', { class: 'empty', text: 'Waiting for migration 0009.' })); return; }
    if (!quiet) $('grid').replaceChildren(el('p', { class: 'empty', text: 'Loading products…' }));
    api('catalog').then(function (j) {
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
      return el('div', { class: 'line' }, [
        el('div', {}, [el('div', { class: 'nm', text: l.name }), el('div', { class: 'sub', text: money(l.unit_price_cents) + (l.unit !== 'each' ? ' / ' + l.unit : ' each') })]),
        el('div', { class: 'amt', text: money(muldiv(Math.round(l.qty * 1000), l.unit_price_cents, 1000)) }),
        qtyBox,
        (st !== null && l.qty > st) ? el('div', { class: 'warn', text: 'Only ' + qtyText(Math.max(0, st)) + ' in stock' }) : null
      ]);
    }));
    var p = price(), n = S.cart.reduce(function (s, l) { return s + (l.mode === 'each' ? l.qty : 1); }, 0);
    $('tSub').textContent = money(p.sub);
    $('rowDisc').hidden = !p.disc;
    $('tDiscLbl').textContent = 'Discount (' + S.discPct + '%)';
    $('tDisc').textContent = money(-p.disc);
    $('tTaxLbl').textContent = S.exempt ? 'Tax (exempt)' : 'Tax';
    $('tTax').textContent = money(p.tax);
    $('tTot').textContent = money(p.total);
    $('barCount').textContent = qtyText(n) + (n === 1 ? ' item' : ' items');
    $('barTotal').textContent = money(p.total);
    $('btnPay').disabled = $('btnPay2').disabled = !S.cart.length || !BOOT.ready;
    $('btnExempt').setAttribute('aria-pressed', S.exempt ? 'true' : 'false');
    $('btnDiscount').textContent = S.discPct ? 'Discount ' + S.discPct + '%' : 'Discount';
  }
  function setQty(i, q) {
    if (!isFinite(q) || q <= 0) S.cart.splice(i, 1);
    else S.cart[i].qty = Math.round(q * 1000) / 1000;
    changed();
  }
  function clearSale() {
    S.cart = []; S.payments = []; S.discPct = 0; S.exempt = false; S.key = null; S.tender = null;
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
  [5, 10, 15, 20, 25, 50].forEach(function (p) {
    $('discChips').appendChild(el('button', { type: 'button', text: p + '%', onclick: function () { setDisc(p); } }));
  });
  function setDisc(p) { S.discPct = Math.max(0, Math.min(100, p)); changed(); $('dlgDiscount').close(); }
  $('btnDiscount').addEventListener('click', function () { $('discPct').value = S.discPct || ''; $('dlgDiscount').showModal(); });
  $('discOk').addEventListener('click', function () { var p = parseFloat($('discPct').value); if (p >= 0 && p <= 100) setDisc(p); });
  $('discNone').addEventListener('click', function () { setDisc(0); });
  $('btnExempt').addEventListener('click', function () { S.exempt = !S.exempt; changed(); });
  $('btnClear').addEventListener('click', function () {
    if (!S.cart.length) return;
    confirmBox('Clear this sale?', 'Removes every item and payment. Nothing has been saved yet.', clearSale);
  });
  function confirmBox(title, text, yes) {
    $('confTitle').textContent = title; $('confText').textContent = text;
    $('confYes').onclick = function () { $('dlgConfirm').close(); yes(); };
    $('confNo').onclick = function () { $('dlgConfirm').close(); };
    $('dlgConfirm').showModal();
  }

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
  $('btnScan').addEventListener('click', function () {
    $('dlgScan').showModal(); $('skuInput').value = ''; $('skuInput').focus();
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
  });
  function stopScan() {
    clearInterval(scanTimer); scanTimer = null;
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    stream = null; $('scanVideo').hidden = true;
  }
  $('dlgScan').addEventListener('close', stopScan);
  $('scanClose').addEventListener('click', function () { $('dlgScan').close(); });
  $('formSku').addEventListener('submit', function (e) { e.preventDefault(); scanned($('skuInput').value.trim()); $('skuInput').value = ''; });

  // ── payment ──────────────────────────────────────────────────────────────
  Object.keys(BOOT.tenders).forEach(function (t) {
    $('tenderBtns').appendChild(el('button', { type: 'button', class: 'btn', 'data-t': t, text: BOOT.tenders[t], 'aria-pressed': 'false', onclick: function () { chooseTender(t); } }));
  });
  function openPay() {
    if (!S.cart.length) return;
    if (!S.key) S.key = newKey();
    S.tender = null; $('saveStatus').hidden = true; $('btnRetry').hidden = true; $('payErr').hidden = true;
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
      var extra = [py.payer_handle, py.reference ? '#' + py.reference : '', py.tender === 'cash' && py.tendered_cents > py.amount_cents ? 'gave ' + money(py.tendered_cents) + ', change ' + money(py.tendered_cents - py.amount_cents) : '']
        .filter(Boolean).join(' · ');
      return el('li', {}, [el('span', {}, [el('b', { text: BOOT.tenders[py.tender] + ' ' + money(py.amount_cents) }), extra ? el('br') : null, extra ? el('small', { text: extra }) : null]),
        el('button', { type: 'button', class: 'link', text: 'Remove', disabled: S.saving, onclick: function () { S.payments.splice(i, 1); save(); renderPay(); } })]);
    }));
    var done = lf === 0 && S.payments.length > 0;
    $('payAdd').hidden = done || S.saving;
    $('payDone').hidden = !done || S.saving;
    if (lf < 0) { $('payErr').textContent = 'Payments are more than the total. Remove one.'; $('payErr').hidden = false; }
    Array.prototype.forEach.call($('tenderBtns').children, function (b) { b.setAttribute('aria-pressed', b.dataset.t === S.tender ? 'true' : 'false'); });
    $('payCash').hidden = S.tender !== 'cash';
    $('payOther').hidden = !S.tender || S.tender === 'cash';
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
    } else {
      var p2p = t === 'paypal' || t === 'venmo' || t === 'cashapp';
      $('payAmt').value = (lf / 100).toFixed(2);
      $('payer').value = ''; $('payRef').value = '';
      $('payerWrap').hidden = !p2p; $('p2pHint').hidden = !p2p;
      $('refLbl').textContent = t === 'check' ? 'Check number (optional)' : 'Last characters of the transaction ID (optional)';
      setTimeout(function () { (p2p ? $('payer') : $('payAmt')).focus(); }, 0);
    }
    renderPay();
  }
  function cashChange() {
    var g = cents($('cashGiven').value), lf = left();
    $('cashChange').textContent = g > lf ? 'Change due: ' + money(g - lf) : (g > 0 && g < lf ? money(lf - g) + ' still to pay after this' : '');
  }
  $('cashGiven').addEventListener('input', cashChange);
  $('btnAddPay').addEventListener('click', addPayment);
  [$('cashGiven'), $('payAmt'), $('payer'), $('payRef')].forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addPayment(); } }); });
  function addPayment() {
    var lf = left(), t = S.tender, err = function (m) { $('payErr').textContent = m; $('payErr').hidden = false; };
    if (!t || lf <= 0) return;
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

  $('btnComplete').addEventListener('click', complete);
  $('btnRetry').addEventListener('click', complete);
  function status(msg, bad) { var s = $('saveStatus'); s.textContent = msg; s.className = 'status ' + (bad ? 'bad' : 'saving'); s.hidden = false; }
  function complete() {
    if (S.saving) return;
    if (left() !== 0) return;
    S.saving = true; $('btnRetry').hidden = true; renderPay();
    status('Saving… (not saved yet)');
    var printIt = $('chkPrint').checked;
    var body = {
      idempotency_key: S.key, discount_pct: S.discPct, tax_exempt: S.exempt, printed: printIt,
      lines: S.cart.map(function (l) { return { product_id: l.product_id, name: l.name, qty: l.qty, unit_price_cents: l.unit_price_cents }; }),
      payments: S.payments
    };
    api('complete', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BOOT.csrf }, body: JSON.stringify(body) })
      .then(function (j) {
        S.saving = false;
        if (j.ok) return saved(j.sale, printIt);
        status('Not saved: ' + (j.message || 'unknown problem.'), true);
        $('btnRetry').hidden = j.status === 422;   // a problem with the sale itself needs fixing, not retrying
        renderPay();
      }, function () {
        S.saving = false;
        status('Not saved: no connection to the server. The sale is kept here; tap Retry.', true);
        $('btnRetry').hidden = false; renderPay();
      });
  }
  function saved(sale, printIt) {
    S.lastSale = sale;
    S.cart = []; S.payments = []; S.discPct = 0; S.exempt = false; S.key = null; S.tender = null;
    save(); renderCart();
    $('dlgPay').close();
    var change = sale.payments.reduce(function (s, p) { return s + (+p.change_cents || 0); }, 0);
    $('doneNo').textContent = sale.sale_number + ' · ' + money(sale.total_cents);
    $('doneChange').hidden = !change; $('doneChange').textContent = 'Change ' + money(change);
    $('dlgDone').showModal();
    if (printIt) printReceipt(sale);
    loadCatalog(true); loadToday();
  }
  $('btnNew').addEventListener('click', function () { $('dlgDone').close(); $('search').focus(); });
  $('btnReprint').addEventListener('click', function () { if (S.lastSale) printReceipt(S.lastSale); });

  // ── receipt ──────────────────────────────────────────────────────────────
  function printReceipt(s) {
    var f = BOOT.farm, rows = [], thrift = false;
    s.lines.forEach(function (l) {
      if ((l.category_label || '').toLowerCase().indexOf('thrift') >= 0) thrift = true;
      var q = parseFloat(l.quantity), each = l.unit === 'each';
      rows.push(el('tr', {}, [el('td', { text: l.description }), el('td', { class: 'r', text: money(l.gross_cents) })]));
      if (q !== 1 || !each) rows.push(el('tr', {}, [el('td', { text: '  ' + qtyText(q) + (each ? ' × ' : ' ' + l.unit + ' × ') + money(l.unit_price_cents) }), el('td')]));
    });
    var tot = [['Subtotal', s.subtotal_cents]];
    if (s.discount_cents) tot.push(['Discount (' + (s.discount_bp / 100) + '%)', -s.discount_cents]);
    if (s.tax_exempt) tot.push(['Tax (exempt)', 0]);
    else Object.keys(s.tax_by_rate).forEach(function (r) { if (+r > 0) tot.push(['Tax ' + (r / 100) + '%', s.tax_by_rate[r]]); });
    var pays = s.payments.map(function (p) {
      var r = [el('tr', {}, [el('td', { text: BOOT.tenders[p.tender] || p.tender }), el('td', { class: 'r', text: money(p.amount_cents) })])];
      if (p.tender === 'cash' && +p.change_cents > 0) {
        r.push(el('tr', {}, [el('td', { text: '  Cash given' }), el('td', { class: 'r', text: money(p.tendered_cents) })]));
        r.push(el('tr', {}, [el('td', { text: '  Change' }), el('td', { class: 'r', text: money(p.change_cents) })]));
      }
      return r;
    }).reduce(function (a, b) { return a.concat(b); }, []);
    $('receipt').replaceChildren(
      el('div', { class: 'c b', text: f.name }),
      f.address ? el('div', { class: 'c', text: f.address }) : null,
      f.phone ? el('div', { class: 'c', text: f.phone }) : null,
      el('hr'),
      el('div', { text: 'Receipt ' + s.sale_number }),
      el('div', { text: s.completed_local + ' · ' + BOOT.staff }),
      el('hr'),
      el('table', {}, rows),
      el('hr'),
      el('table', {}, tot.map(function (t) { return el('tr', {}, [el('td', { text: t[0] }), el('td', { class: 'r', text: money(t[1]) })]); })
        .concat([el('tr', { class: 'b' }, [el('td', { text: 'TOTAL' }), el('td', { class: 'r', text: money(s.total_cents) })])])),
      el('hr'),
      el('table', {}, pays),
      thrift ? el('div', { class: 'note', text: 'Thrift shop items are sold as-is.' }) : null,
      f.policy ? el('p', { class: 'c', text: f.policy }) : null,
      el('p', { class: 'c', text: 'Thank you! ' + f.domain })
    );
    setTimeout(function () { window.print(); }, 50);
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
  renderCart();
  loadCatalog();
  loadToday();
  setInterval(loadToday, 60000);
  if (S.payments.length && S.cart.length) setTimeout(function () { if (BOOT.ready) openPay(); }, 300);   // an unsaved sale was waiting: pick it back up
  window.addEventListener('beforeunload', function (e) { if (S.saving) { e.preventDefault(); e.returnValue = ''; } });
})();
