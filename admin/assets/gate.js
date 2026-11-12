// admin/assets/gate.js — Farm OS gate screen (Phase 1, slice 4a, Nov 6, 2026). Server logic: includes/admission.php via admin/gate.php.
// Groups today (Phase 3 slice 5, Nov 12, 2026): confirmed bookings and parties, with Arrived (actual headcount) against their hold.
// Waivers (Phase 3, Nov 12, 2026): a waiver badge and "Sign now" on each card when waivers are on; in block mode a check-in
// without one is refused (code 'waiver') until someone signs or a manager lets them in with a reason.
(function () {
  'use strict';
  var BOOT = JSON.parse(document.getElementById('gateBoot').textContent);
  var $ = function (id) { return document.getElementById(id); };
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
  function api(path, body) {
    var opts = { credentials: 'same-origin' };
    if (body) { opts.method = 'POST'; opts.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': BOOT.csrf }; opts.body = JSON.stringify(body); }
    return fetch('gate.php?api=' + path, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'Unexpected reply (' + r.status + ').' }; }).then(function (j) { j.status = r.status; return j; });
    });
  }
  // Waiver badge for a search result or group (null when waivers are off).
  function waiverLine(w) {
    if (!w) return null;
    var kids = [el('span', { class: 'wv ' + (w.signed ? 'ok' : (w.block ? 'block' : 'no')), text: w.label })];
    if (!w.signed && w.sign_url) kids.push(el('a', { class: 'btn sm', href: w.sign_url, text: 'Sign now' }));
    return el('div', { class: 'admit' }, kids);
  }
  // A refused check-in for want of a waiver: how to sign, and (managers) let them in anyway. retry(reason).
  function waiverRefused(box, j, w, retry) {
    var reason = el('input', { type: 'text', maxlength: '200', placeholder: 'Reason (e.g. signed on paper)' });
    box.replaceChildren.apply(box, [el('p', { class: 'err', text: j.message }),
      w && w.sign_url ? el('a', { class: 'btn primary', href: w.sign_url, text: 'Sign on this device' }) : null,
      j.can_override ? el('label', {}, ['Let them in without one', reason]) : null,
      j.can_override ? el('button', { type: 'button', class: 'btn danger', text: 'Check in without a waiver', onclick: function () {
        if (!reason.value.trim()) { reason.focus(); return; }
        retry(reason.value.trim());
      } }) : null].filter(Boolean));
    box.hidden = false;
  }
  var toastT;
  function toast(m) { var t = $('toast'); t.textContent = m; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(function () { t.hidden = true; }, 2200); }

  // ── occupancy and on-site list ───────────────────────────────────────────
  function renderState(s) {
    $('mOn').textContent = s.on_site; $('mLimit').textContent = s.limit;
    $('meter').className = 'meter ' + s.level;
    $('mBar').style.width = Math.min(100, s.pct) + '%';
    var sub = s.level === 'full' ? 'Full. ' + (s.wait_minutes > 0 ? 'About ' + s.wait_minutes + ' min until there is room.' : '') : s.free + (s.free === 1 ? ' space free.' : ' spaces free.');
    if (s.held_waiting > 0) sub += ' ' + s.held_waiting + ' more held for groups.';
    $('mSub').textContent = sub + ' Today: ' + s.visitors_today + ' visitor' + (s.visitors_today === 1 ? '' : 's') + ' in ' + s.parties_today + ' part' + (s.parties_today === 1 ? 'y' : 'ies') + '.';
    renderGroups(s.groups || []);
    if (!s.parties.length) { $('onsite').replaceChildren(el('li', { class: 'muted', text: 'Nobody checked in right now.' })); return; }
    $('onsite').replaceChildren.apply($('onsite'), s.parties.map(function (p) {
      var src = { ticket: 'Online ticket', pass: 'Annual pass', gate_sale: 'Gate sale', booking: 'Group booking', party: 'Party', comp: 'Comp' }[p.source] || p.source;
      return el('li', {}, [
        el('span', {}, [el('b', { text: p.who + ' · ' + p.party }), el('br'), el('small', { class: 'muted', text: src + ' · in ' + p.in + ' · out by ' + p.out + (p.override ? ' · override: ' + p.override : '') })]),
        el('button', { type: 'button', class: 'btn sm', text: 'Out', 'aria-label': 'Check out ' + p.who, onclick: function () {
          api('checkout', { id: p.id }).then(function (j) { if (j.ok) { renderState(j.state); toast(p.who + ' checked out'); } else toast(j.message); });
        } })
      ]);
    }));
  }
  // ── groups today (bookings and parties holding capacity) ─────────────────
  var groupOpen = false;
  function renderGroups(gs) {
    if (groupOpen) return;   // don't wipe a headcount someone is typing
    $('groupsWrap').hidden = !gs.length;
    $('groups').replaceChildren.apply($('groups'), gs.map(groupCard));
  }
  function groupCard(g) {
    var left = Math.max(0, g.guests - g.arrived);
    var num = el('input', { type: 'number', inputmode: 'numeric', min: '1', max: '500', value: String(left || 1), class: 'qinput', 'aria-label': 'Guests arriving' });
    var upd = g.per_guest_quote ? el('input', { type: 'checkbox', checked: true }) : null;
    var cap = el('div', { class: 'capbox', hidden: true });
    var form = el('div', { class: 'gform', hidden: true });
    var status = g.arrived ? g.arrived + ' of ' + g.guests + ' arrived' + (g.on_site ? ' · ' + g.on_site + ' here now' : '') : 'Expected ' + g.guests;
    var wov = null;
    var go = function (override) {
      var body = { type: g.type, id: g.id, n: +num.value, update_quote: !!(upd && upd.checked) };
      if (!(body.n > 0)) { num.focus(); return; }
      if (override) body.override = override;
      if (wov) body.waiver_override = wov;
      api('arrive', body).then(function (j) {
        if (j.ok) { groupOpen = false; renderState(j.state); toast(body.n + ' in: ' + g.name + (j.quote ? '. ' + j.quote : '')); return; }
        if (j.code === 'waiver') { waiverRefused(cap, j, g.waiver, function (why) { wov = why; go(override); }); return; }
        if (j.code === 'capacity') {
          var reason = el('input', { type: 'text', maxlength: '255', placeholder: 'Reason (e.g. extra parents with the party)' });
          cap.replaceChildren.apply(cap, [el('p', { class: 'err', text: j.message }),
            j.can_override ? el('label', {}, ['Let them in anyway', reason]) : null,
            j.can_override ? el('button', { type: 'button', class: 'btn danger', text: 'Override and check in', onclick: function () {
              if (!reason.value.trim()) { reason.focus(); return; }
              go(reason.value.trim());
            } }) : null].filter(Boolean));
          cap.hidden = false; return;
        }
        cap.replaceChildren(el('p', { class: 'err', text: j.message })); cap.hidden = false;
      }, function () { cap.replaceChildren(el('p', { class: 'err', text: 'No connection. Try again.' })); cap.hidden = false; });
    };
    form.replaceChildren.apply(form, [
      el('label', { class: 'gcount' }, ['How many arrived? ', num]),
      upd ? el('label', { class: 'gcheck' }, [upd, ' Update the quote to this many guests']) : null,
      el('div', { class: 'admit' }, [
        el('button', { type: 'button', class: 'btn', text: 'Cancel', onclick: function () { form.hidden = true; cap.hidden = true; groupOpen = false; } }),
        el('button', { type: 'button', class: 'btn primary', text: 'Check them in', onclick: function () { go(null); } })
      ]),
      cap
    ].filter(Boolean));
    return el('div', { class: 'gcard group' + (g.active ? '' : ' later') }, [
      el('div', { class: 'gtop' }, [el('b', { text: g.name }), el('span', { class: 'chip ' + (g.type === 'booking' ? 'ticket' : 'pass'), text: g.what })]),
      el('div', { class: 'muted', text: g.when + (g.money ? ' · ' + g.money : '') }),
      el('div', { text: status }),
      waiverLine(g.waiver),
      el('div', { class: 'admit' }, [el('button', { type: 'button', class: 'btn primary', text: g.arrived ? 'More arrived' : 'Arrived', onclick: function () {
        form.hidden = false; groupOpen = true; num.focus(); num.select();
      } })]),
      form
    ]);
  }

  function loadState() {
    if (!BOOT.ready) return;
    api('state').then(function (j) { if (j.ok) renderState(j.state); }, function () {});
  }

  // ── search and admit ─────────────────────────────────────────────────────
  function find(q) {
    q = (q || '').trim();
    if (!q) return;
    $('findMsg').textContent = 'Looking…';
    api('search&q=' + encodeURIComponent(q)).then(function (j) {
      if (!j.ok) { $('findMsg').textContent = j.message; return; }
      $('findMsg').textContent = j.results.length ? '' : 'Nothing found for "' + q + '". Try a name, email, or the code on the ticket.';
      $('results').replaceChildren.apply($('results'), j.results.map(card));
    }, function () { $('findMsg').textContent = 'No connection. Try again.'; });
  }
  function card(r) {
    var max = r.kind === 'ticket' ? r.remaining : r.max_guests;
    var n = r.kind === 'ticket' ? r.remaining : Math.min(r.max_guests, 2);
    var num = el('span', { class: 'qnum', text: String(n) });
    var cap = el('div', { class: 'capbox', hidden: true });
    var lines = [];
    if (r.kind === 'ticket') {
      var bd = Object.keys(r.breakdown || {}).map(function (k) { return r.breakdown[k] + ' ' + k; }).join(', ');
      lines.push(el('div', { class: 'muted', text: 'Ticket ' + r.code + ' · bought ' + r.bought + (r.visit_date ? ' · for ' + r.visit_date : ' · any day') }));
      lines.push(el('div', { text: (bd || r.quantity + ' admission' + (r.quantity === 1 ? '' : 's')) + ' · ' + r.remaining + ' of ' + r.quantity + ' left' }));
      if (r.expires) lines.push(el('div', { class: 'muted', text: 'Good until ' + r.expires }));
      if (r.visits && r.visits.length) lines.push(el('div', { class: 'muted', text: 'Used: ' + r.visits.join(', ') }));
    } else {
      lines.push(el('div', { class: 'muted', text: r.type + (r.valid_until ? ' · valid until ' + r.valid_until : '') }));
      lines.push(el('div', { text: 'Covers up to ' + r.max_guests + ' guest' + (r.max_guests === 1 ? '' : 's') }));
      if (r.on_site) lines.push(el('div', { class: 'warn', text: r.on_site }));
    }
    var wov = null;
    var admit = function (override) {
      var body = { kind: r.kind, id: r.id, n: +num.textContent };
      if (override) body.override = override;
      if (wov) body.waiver_override = wov;
      api('admit', body).then(function (j) {
        if (j.ok) { renderState(j.state); $('results').replaceChildren(); $('q').value = ''; $('findMsg').textContent = ''; toast(body.n + ' admitted: ' + r.name); return; }
        if (j.code === 'waiver') { waiverRefused(cap, j, r.waiver, function (why) { wov = why; admit(override); }); return; }
        if (j.code === 'capacity') {
          var reason = el('input', { type: 'text', maxlength: '255', placeholder: 'Reason (e.g. family of passholders, short visit)' });
          cap.replaceChildren.apply(cap, [el('p', { class: 'err', text: j.message }),   // nulls filtered (replaceChildren would print "null")
            j.can_override ? el('label', {}, ['Let them in anyway', reason]) : null,
            j.can_override ? el('button', { type: 'button', class: 'btn danger', text: 'Override and admit', onclick: function () {
              if (!reason.value.trim()) { reason.focus(); return; }
              admit(reason.value.trim());
            } }) : null].filter(Boolean));
          cap.hidden = false;
          return;
        }
        cap.replaceChildren(el('p', { class: 'err', text: j.message })); cap.hidden = false;
      }, function () { cap.replaceChildren(el('p', { class: 'err', text: 'No connection. Try again.' })); cap.hidden = false; });
    };
    return el('div', { class: 'gcard' + (r.blocker ? ' blocked' : '') }, [
      el('div', { class: 'gtop' }, [el('b', { text: r.name || '(no name)' }), el('span', { class: 'chip ' + r.kind, text: r.kind === 'ticket' ? 'Ticket' : 'Pass' })]),
    ].concat(lines).concat([
      waiverLine(r.waiver),
      r.blocker ? el('p', { class: 'err', text: r.blocker }) : el('div', { class: 'admit' }, [
        el('button', { type: 'button', class: 'btn', text: '−', 'aria-label': 'Fewer', onclick: function () { num.textContent = String(Math.max(1, +num.textContent - 1)); } }),
        num,
        el('button', { type: 'button', class: 'btn', text: '+', 'aria-label': 'More', onclick: function () { num.textContent = String(Math.min(max, +num.textContent + 1)); } }),
        el('button', { type: 'button', class: 'btn primary', text: 'Admit', onclick: function () { admit(null); } })
      ]),
      cap
    ]));
  }
  $('formFind').addEventListener('submit', function (e) { e.preventDefault(); find($('q').value); });

  // Hardware scanners type fast and end with Enter, anywhere on the screen
  var buf = '', last = 0;
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toUpperCase();
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (e.key === 'Enter') { if (buf.length >= 6) { $('q').value = buf; find(buf); } buf = ''; return; }
    if (e.key.length === 1) { if (Date.now() - last > 120) buf = ''; buf += e.key; last = Date.now(); }
  });

  // Camera (browsers with a built-in barcode reader)
  var stream = null, timer = null;
  function stopScan() { clearInterval(timer); timer = null; if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; $('scanVideo').hidden = true; $('btnScan').textContent = 'Scan'; }
  $('btnScan').addEventListener('click', function () {
    if (stream) return stopScan();
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices) { $('findMsg').textContent = 'This browser can\'t read QR codes with the camera. Type the code or a name instead.'; return; }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s; var v = $('scanVideo'); v.srcObject = s; v.hidden = false; v.play(); $('btnScan').textContent = 'Stop';
      var det = new window.BarcodeDetector({ formats: ['qr_code', 'code_128', 'code_39', 'ean_13'] }), busy = false;
      timer = setInterval(function () {
        if (busy || v.readyState < 2) return; busy = true;
        det.detect(v).then(function (codes) { busy = false; if (codes.length) { stopScan(); $('q').value = codes[0].rawValue; find(codes[0].rawValue); } }, function () { busy = false; });
      }, 250);
    }, function () { $('findMsg').textContent = 'Camera not available. Type the code or a name instead.'; });
  });

  loadState();
  setInterval(loadState, 30000);
})();
