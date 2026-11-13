// admin/assets/manager-pin.js — manager approval by PIN on the register and gate (Farm OS spec 03, Nov 13, 2026).
// When the server refuses something only an Owner or Manager may do, it answers need_manager with the managers who have a
// PIN (includes/pin_auth.php). bcrfManagerRetry() asks one of them to tap their name and type their PIN, then sends the
// same request again with approver: {id, pin}. Cancel gives back the original refusal. Strict CSP: no inline script or style.
(function () {
  'use strict';
  var dlg = null, parts = {};

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'hidden') e.hidden = !!attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }

  function build() {
    parts.why = el('p', { class: 'mpin-why' });
    parts.err = el('p', { class: 'mpin-err', role: 'alert', hidden: true });
    parts.names = el('div', { class: 'mpin-names', role: 'group', 'aria-label': 'Manager' });
    parts.pin = el('input', { type: 'password', inputmode: 'numeric', pattern: '[0-9]*', autocomplete: 'off', maxlength: '8', class: 'mpin-pin', 'aria-label': 'Manager PIN', placeholder: 'PIN' });
    parts.cancel = el('button', { type: 'button', class: 'btn', text: 'Cancel' });
    parts.ok = el('button', { type: 'submit', class: 'btn primary', text: 'Approve' });
    parts.form = el('form', { method: 'dialog', class: 'mpin-form' }, [
      el('h3', { text: 'Manager approval' }), parts.why, parts.names,
      el('label', { class: 'mpin-label' }, ['PIN', parts.pin]), parts.err,
      el('div', { class: 'mpin-act' }, [parts.cancel, parts.ok])
    ]);
    dlg = el('dialog', { class: 'mpin', 'aria-label': 'Manager approval' }, [parts.form]);
    document.body.appendChild(dlg);
  }

  // Ask for a manager's PIN. Resolves {id, pin}, or null if they cancel.
  function ask(j, error) {
    if (!dlg) build();
    return new Promise(function (resolve) {
      var chosen = null, done = false;
      var mgrs = j.managers || [];
      parts.why.textContent = (j.code === 'manager_pin' ? '' : (j.message || '')) + ' A manager can approve it with their PIN.';
      parts.err.hidden = !error; parts.err.textContent = error || '';
      parts.pin.value = '';
      parts.names.replaceChildren.apply(parts.names, mgrs.map(function (m) {
        var b = el('button', { type: 'button', class: 'mpin-name', 'aria-pressed': 'false', text: m.name });
        b.addEventListener('click', function () {
          chosen = m.id;
          Array.prototype.forEach.call(parts.names.children, function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
          parts.pin.focus();
        });
        return b;
      }));
      if (mgrs.length === 1) { chosen = mgrs[0].id; parts.names.firstChild.setAttribute('aria-pressed', 'true'); }
      if (j.last_approver) {
        Array.prototype.forEach.call(parts.names.children, function (x, i) {
          if (mgrs[i].id === j.last_approver) { chosen = mgrs[i].id; x.setAttribute('aria-pressed', 'true'); }
        });
      }
      function finish(v) {
        if (done) return; done = true;
        parts.form.onsubmit = null; parts.cancel.onclick = null; dlg.oncancel = null;
        if (dlg.open) dlg.close();
        resolve(v);
      }
      parts.form.onsubmit = function (e) {
        e.preventDefault();
        if (!chosen) { parts.err.hidden = false; parts.err.textContent = 'Tap the manager’s name first.'; return; }
        if (!/^\d{4,8}$/.test(parts.pin.value)) { parts.err.hidden = false; parts.err.textContent = 'Type the 4 to 8 digit PIN.'; parts.pin.focus(); return; }
        finish({ id: chosen, pin: parts.pin.value });
      };
      parts.cancel.onclick = function () { finish(null); };
      dlg.oncancel = function (e) { e.preventDefault(); finish(null); };
      dlg.showModal();
      if (chosen) parts.pin.focus();
    });
  }

  // j: the server's refusal. body: the request body that was refused. send(body) → Promise of the next reply.
  window.bcrfManagerRetry = function (j, body, send, error) {
    if (!j || !j.need_manager || !body) return Promise.resolve(j);
    return ask(j, error).then(function (a) {
      if (!a) return j;
      var b = {}; Object.keys(body).forEach(function (k) { b[k] = body[k]; });
      b.approver = a;
      return send(b).then(function (j2) {
        if (j2 && j2.need_manager && j2.code === 'manager_pin') {   // wrong PIN: ask again, keeping the first reason
          var again = {}; Object.keys(j).forEach(function (k) { again[k] = j[k]; });
          again.managers = j2.managers || j.managers; again.last_approver = a.id;
          return window.bcrfManagerRetry(again, body, send, j2.message);
        }
        return j2;
      });
    });
  };
})();
