/* Live "Open now / Closed" indicator — recomputes in the farm timezone. CSP-safe (self-hosted). */
(function () {
  var el = document.querySelector('[data-bcrf-hours]');
  if (!el) return;
  var hours, tz;
  try { var cfg = JSON.parse(el.getAttribute('data-bcrf-hours')); hours = cfg.h; tz = cfg.tz || 'America/New_York'; }
  catch (e) { return; }
  var DAYL = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  var MAP  = { Sun:0, Mon:1, Tue:2, Wed:3, Thu:4, Fri:5, Sat:6 };

  function fmt(hhmm) {
    var p = hhmm.split(':'), h = +p[0], ap = h < 12 ? 'AM' : 'PM', h12 = h % 12 || 12;
    return h12 + ':' + p[1] + ' ' + ap;
  }
  function nowInTz() {
    var parts = new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date());
    var wd, hh, mm;
    parts.forEach(function (p) { if (p.type === 'weekday') wd = p.value; if (p.type === 'hour') hh = p.value; if (p.type === 'minute') mm = p.value; });
    if (hh === '24') hh = '00';
    return { dow: MAP[wd], cur: ('0' + hh).slice(-2) + ':' + mm };
  }
  function compute() {
    var n = nowInTz(), today = hours[n.dow];
    if (today && n.cur >= today[0] && n.cur < today[1]) return { open: true, label: 'Open now', detail: 'until ' + fmt(today[1]) };
    for (var i = 0; i < 8; i++) {
      var d = (n.dow + i) % 7, slot = hours[d];
      if (!slot) continue;
      if (i === 0 && n.cur >= slot[0]) continue;
      var when = i === 0 ? 'today' : (i === 1 ? 'tomorrow' : DAYL[d]);
      return { open: false, label: 'Closed', detail: 'Opens ' + fmt(slot[0]) + ' ' + when };
    }
    return { open: false, label: 'Closed', detail: '' };
  }
  function render() {
    var s = compute();
    el.classList.toggle('is-open', s.open);
    el.classList.toggle('is-closed', !s.open);
    var lbl = el.querySelector('.bcrf-status-label'), det = el.querySelector('.bcrf-status-detail');
    if (lbl) lbl.textContent = s.label;
    if (det) det.textContent = s.detail;
    el.setAttribute('aria-label', s.label + (s.detail ? ' \u2014 ' + s.detail : ''));
  }
  render();
  setInterval(render, 60000);
})();
