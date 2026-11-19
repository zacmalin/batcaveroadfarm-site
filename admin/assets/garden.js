// admin/assets/garden.js — Garden bed planner (Nov 19, 2026; admin/garden/areas.php map tab, admin/garden/plantings.php add form,
//   includes/garden_planner.php builds the data, admin/assets/garden.css styles it).
//   Map: the beds drawn to scale from #garden-map-data (feet), plantings shown as colored strips on each bed (by crop family
//   or by status), a ⚠ on beds with a rotation or crowding note, tap a bed for its details. "Move beds" lets managers drag
//   beds (snaps to half a foot) and turn them; each drop saves at once (act=area_move, JSON). Top of the map is north.
//   Planting form: says right away when the bed grew the same crop family lately, or already has something in it then.
// The admin pages run a strict policy (no inline script or style), so colors go on SVG fill attributes.
(function () {
    'use strict';
    var NS = 'http://www.w3.org/2000/svg';

    function svg(tag, attrs, parent) {
        var e = document.createElementNS(NS, tag);
        for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]);
        if (parent) parent.appendChild(e);
        return e;
    }
    function html(tag, cls, text, parent) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        if (parent) parent.appendChild(e);
        return e;
    }
    function swatch(color, parent) {
        var s = svg('svg', { 'class': 'gm-swatch', viewBox: '0 0 10 10', 'aria-hidden': 'true' }, parent);
        svg('rect', { width: 10, height: 10, rx: 2, fill: color }, s);
        return s;
    }
    function store(k, v) { try { if (v === undefined) return window.localStorage.getItem(k); window.localStorage.setItem(k, v); } catch (e) { return null; } return null; }
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ── Season picker: pick = show ──
    var seasonForm = document.querySelector('[data-season-form]');
    if (seasonForm) {
        var go = seasonForm.querySelector('[data-season-go]');
        if (go) go.hidden = true;
        var sel = seasonForm.querySelector('select');
        if (sel) sel.addEventListener('change', function () { seasonForm.submit(); });
    }

    // ── Lay out many: how big the block will be ──
    var grid = document.getElementById('form-area_grid');
    if (grid) {
        var prev = grid.querySelector('[data-grid-preview]');
        var num = function (n) { var v = parseFloat(String((grid.elements[n] || {}).value || '').replace(/[^0-9.]/g, '')); return isFinite(v) ? v : 0; };
        var upd = function () {
            var rows = Math.round(num('rows')), cols = Math.round(num('cols')), len = num('length_ft'), wid = num('width_ft'), path = num('path_ft');
            var down = (grid.elements.runs || {}).value === 'down';
            var fw = down ? wid : len, fh = down ? len : wid;
            if (!prev) return;
            if (rows < 1 || cols < 1 || fw <= 0 || fh <= 0) { prev.textContent = ''; return; }
            var n = rows * cols, first = Math.round(num('first')), name = ((grid.elements.prefix || {}).value || 'Bed').trim();
            var across = cols * fw + (cols - 1) * path, tall = rows * fh + (rows - 1) * path;
            prev.textContent = n + ' bed' + (n === 1 ? '' : 's') + ' (' + name + ' ' + first + (n > 1 ? ' to ' + name + ' ' + (first + n - 1) : '') + '), taking ' +
                Math.round(across * 10) / 10 + ' ft across × ' + Math.round(tall * 10) / 10 + ' ft down.' + (n > 200 ? ' That\'s over 200; make them in batches.' : '');
        };
        grid.addEventListener('input', upd); grid.addEventListener('change', upd); upd();
    }

    // ── Planting form: rotation and crowding, before saving ──
    var pform = document.getElementById('form-plant_add');
    var pdata = document.getElementById('garden-plant-data');
    if (pform && pdata) {
        var P = {};
        try { P = JSON.parse(pdata.textContent || '{}'); } catch (e) { P = {}; }
        var warnBox = pform.querySelector('[data-plant-warn]');
        var check = function () {
            if (!warnBox) return;
            var bed = (pform.elements.location_id || {}).value, v = (pform.elements.variety || {}).value, day = (pform.elements.planted_on || {}).value;
            var end = (pform.elements.expected_end_on || {}).value;
            var msgs = [];
            var fam = (P.families || {})[v] || '';
            var list = (P.beds || {})[bed] || [];
            if (bed && v && day) {
                var cutoff = (parseInt(day.slice(0, 4), 10) - 3) + day.slice(4);
                var years = {}, crops = {};
                list.forEach(function (p) {
                    if (fam && p.family && p.family.toLowerCase() === fam.toLowerCase() && p.status !== 'planned' && p.start < day && p.start > cutoff && p.end && p.end < day) { years[p.start.slice(0, 4)] = 1; crops[p.crop] = 1; }
                });
                if (Object.keys(years).length) msgs.push('Same family as before: ' + ((P.familyNames || {})[fam] || fam) + ' grew in this bed in ' + Object.keys(years).sort().join(' and ') + ' (' + Object.keys(crops).join(', ') + '). Rotate if you can. You can still save.');
                var dEnd = end || day;
                var here = list.filter(function (p) { return p.status !== 'failed' && p.status !== 'completed' && p.start <= dEnd && (!p.end || p.end >= day); });
                if (here.length) {
                    var cap = (P.cap || {})[bed], measured = here.every(function (p) { return p.sqft; }), used = 0;
                    here.forEach(function (p) { used += p.sqft || 0; });
                    var tail = measured && cap ? ' Their bed space adds up to ' + Math.round(used) + ' of ' + Math.round(cap) + ' sq ft' + (used >= cap ? ', so the bed is full then.' : '; give this one ' + Math.floor(cap - used) + ' sq ft or less.')
                                               : ' Set bed space on each so the garden can check they fit.';
                    msgs.push('Already in this bed then: ' + here.map(function (p) { return p.crop + ' (' + p.from + (p.to ? '–' + p.to : ' on') + ')'; }).join(', ') + '.' + tail);
                }
            }
            warnBox.textContent = '';
            warnBox.hidden = !msgs.length;
            msgs.forEach(function (m) { html('p', '', '⚠️ ' + m, warnBox); });
        };
        ['location_id', 'variety', 'planted_on', 'expected_end_on'].forEach(function (n) { var f = pform.elements[n]; if (f) f.addEventListener('change', check); });
        check();
    }

    // ── Map ──
    var root = document.querySelector('[data-garden-map]');
    var dataEl = document.getElementById('garden-map-data');
    if (!root || !dataEl) return;
    var D;
    try { D = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
    var stage = root.querySelector('[data-stage]');
    var statusEl = root.querySelector('[data-status]');
    var legendEl = root.querySelector('[data-legend]');
    var scaleEl = root.querySelector('[data-scale]');
    var editbar = root.querySelector('[data-editbar]');
    var panel = document.querySelector('[data-panel]');
    var moveBtn = root.querySelector('[data-move-toggle]');
    var turnBtns = root.querySelectorAll('[data-turn]');
    var byId = {}, kids = {};
    (D.beds || []).forEach(function (b) { byId[b.id] = b; });
    (D.beds || []).forEach(function (b) { if (b.placed && b.parent && byId[b.parent] && byId[b.parent].placed) (kids[b.parent] = kids[b.parent] || []).push(b); });
    var roots = (D.beds || []).filter(function (b) { return b.placed && !(b.parent && byId[b.parent] && byId[b.parent].placed); });
    var colorBy = store('bcrf-garden-color') === 'status' ? 'status' : 'family';
    var editing = false, selected = D.focus && byId[D.focus] ? D.focus : 0;
    var svgEl = null, pxPerFt = 10;

    function say(msg, bad) { if (!statusEl) return; statusEl.textContent = msg || ''; statusEl.classList.toggle('is-bad', !!bad); }
    function tf(b) { return 'translate(' + (b.x + b.len / 2) + ' ' + (b.y + b.wid / 2) + ') rotate(' + b.rot + ') translate(' + (-b.len / 2) + ' ' + (-b.wid / 2) + ')'; }
    function corners(b) {   // the four corners of a root bed, in garden feet
        var r = b.rot * Math.PI / 180, c = Math.cos(r), s = Math.sin(r), cx = b.x + b.len / 2, cy = b.y + b.wid / 2, out = [];
        [[-1, -1], [1, -1], [1, 1], [-1, 1]].forEach(function (k) { var dx = k[0] * b.len / 2, dy = k[1] * b.wid / 2; out.push([cx + dx * c - dy * s, cy + dx * s + dy * c]); });
        return out;
    }
    function extent() {
        var minX = 0, minY = 0, maxX = 1, maxY = 1;
        roots.forEach(function (b) { corners(b).forEach(function (p) { minX = Math.min(minX, p[0]); minY = Math.min(minY, p[1]); maxX = Math.max(maxX, p[0]); maxY = Math.max(maxY, p[1]); }); });
        return { minX: minX, minY: minY, maxX: maxX, maxY: maxY };
    }
    function niceStep(span) { return span <= 30 ? 1 : span <= 80 ? 5 : span <= 300 ? 10 : 50; }
    function fill(p) { return colorBy === 'status' ? p.statusColor : p.familyColor; }

    function render() {
        if (!stage) return;
        var e = extent(), pad = Math.max(1, (e.maxX - e.minX) * 0.03);
        var room = editing ? Math.max(8, (e.maxX - e.minX) * 0.25) : 0;
        var x0 = e.minX - pad, y0 = e.minY - pad, w = e.maxX - e.minX + 2 * pad + room, h = e.maxY - e.minY + 2 * pad + room * 0.5;
        stage.textContent = '';
        svgEl = svg('svg', { 'class': 'gm-svg' + (editing ? ' is-editing' : ''), viewBox: [x0, y0, w, h].join(' '), 'aria-hidden': 'true' }, stage);
        pxPerFt = (stage.clientWidth || 320) / w;
        var fs = 12 / pxPerFt;   // 12 px text, in feet
        // Grid every step feet; heavier every 5 steps.
        var step = niceStep(Math.max(w, h)), gG = svg('g', { 'class': 'gm-grid' }, svgEl);
        for (var gx = Math.ceil(x0 / step) * step; gx <= x0 + w; gx += step) svg('line', { x1: gx, y1: y0, x2: gx, y2: y0 + h, 'class': Math.round(gx / step) % 5 === 0 ? 'gm-grid-major' : '' }, gG);
        for (var gy = Math.ceil(y0 / step) * step; gy <= y0 + h; gy += step) svg('line', { x1: x0, y1: gy, x2: x0 + w, y2: gy, 'class': Math.round(gy / step) % 5 === 0 ? 'gm-grid-major' : '' }, gG);
        var north = svg('text', { x: x0 + w - fs * 0.6, y: y0 + fs * 1.3, 'class': 'gm-north', 'text-anchor': 'end', 'font-size': fs }, svgEl); north.textContent = 'N ↑';
        var layer = svg('g', { 'class': 'gm-layer' }, svgEl);
        roots.forEach(function (b) { drawBed(layer, b, fs); });
        if (scaleEl) scaleEl.textContent = 'Each grid square is ' + step + ' ft. Top of the map is north.' + (editing ? '' : ' Tap a bed for its details.');
        legend();
    }

    function drawBed(parent, b, fs) {
        var g = svg('g', { 'class': 'gm-bed' + (b.id === selected ? ' is-selected' : ''), 'data-id': b.id, transform: tf(b), tabindex: editing ? '-1' : '0', role: 'button',
                           'aria-label': b.path + (b.plantings.length ? ': ' + b.plantings.map(function (p) { return p.crop; }).join(', ') : ': empty') + (b.warnings.length ? '. Needs a look.' : '') }, parent);
        svg('rect', { x: 0, y: 0, width: b.len, height: b.wid, 'class': 'gm-base gm-cover-' + (b.cover || 'none') }, g);
        // Plantings as strips along the long side: by bed space when every one has it, else equal.
        var list = b.plantings, long = b.len >= b.wid, L = long ? b.len : b.wid, S = long ? b.wid : b.len;
        if (list.length) {
            var all = b.sqft && list.every(function (p) { return p.sqft; }), total = 0;
            list.forEach(function (p) { total += all ? p.sqft : 1; });
            var denom = all ? Math.max(total, b.sqft) : total, at = 0;
            list.forEach(function (p) {
                var part = L * (all ? p.sqft : 1) / denom;
                var r = long ? { x: at, y: 0, width: part, height: S } : { x: 0, y: at, width: S, height: part };
                r['class'] = 'gm-plant gm-st-' + p.statusKey; r.fill = fill(p);
                svg('rect', r, g);
                // Crop name in the strip when it fits.
                var lf = Math.min(fs, S * 0.42), pxLong = part * pxPerFt;
                if (lf * pxPerFt >= 7 && pxLong >= 28) {
                    var maxChars = Math.max(2, Math.floor(part / (lf * 0.58)));
                    var name = p.crop.length > maxChars ? p.crop.slice(0, maxChars - 1) + '…' : p.crop;
                    var t = long ? svg('text', { x: at + part / 2, y: S / 2, 'class': 'gm-label', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': lf, 'stroke-width': lf * 0.22 }, g)
                                 : svg('text', { x: S / 2, y: at + part / 2, 'class': 'gm-label', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': lf, 'stroke-width': lf * 0.22 }, g);
                    t.textContent = name;
                }
                at += part;
            });
        }
        // Bed name in the corner when the bed is big enough on screen and nothing else is written there.
        var nf = Math.min(fs * 0.85, Math.min(b.len, b.wid) * 0.35);
        if (kids[b.id] && fs * pxPerFt >= 7) {   // an area with beds in it: its name sits just above it
            var ta = svg('text', { x: 0, y: -fs * 0.3, 'class': 'gm-name', 'font-size': fs * 0.9 }, g); ta.textContent = b.name;
        } else if (!list.length && nf * pxPerFt >= 7 && b.len * pxPerFt >= 30) {
            var maxN = Math.max(2, Math.floor((b.len - nf) / (nf * 0.58)));
            var tn = svg('text', { x: b.len / 2, y: b.wid / 2, 'class': 'gm-name', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': nf }, g);
            tn.textContent = b.name.length > maxN ? b.name.slice(0, maxN - 1) + '…' : b.name;
        }
        svg('rect', { x: 0, y: 0, width: b.len, height: b.wid, 'class': 'gm-outline', 'vector-effect': 'non-scaling-stroke' }, g);
        if (b.warnings.length) {
            var wf = Math.max(Math.min(fs, Math.min(b.len, b.wid) * 0.8), 0.6);
            var wt = svg('text', { x: b.len - wf * 0.15, y: wf * 0.95, 'class': 'gm-warn', 'text-anchor': 'end', 'font-size': wf, 'stroke-width': wf * 0.12 }, g); wt.textContent = '⚠';
        }
        var title = svg('title', {}, g); title.textContent = b.path + ' · ' + b.len + ' × ' + b.wid + ' ft';
        (kids[b.id] || []).forEach(function (k) { drawBed(g, k, fs); });
    }

    function legend() {
        if (!legendEl) return;
        legendEl.textContent = '';
        var items = (D.legend || {})[colorBy] || [];
        items.forEach(function (it) { var s = html('span', 'gm-key', null, legendEl); swatch(it.color, s); html('span', '', it.label, s); });
        if (colorBy === 'status') { var pl = html('span', 'gm-key', null, legendEl); html('span', '', 'Planned = faded with a dashed edge', pl); }
        if ((D.beds || []).some(function (b) { return b.placed && b.warnings.length; })) html('span', 'gm-key', '⚠ needs a look (tap the bed)', legendEl);
        if (!items.length) html('span', 'gm-key', 'Nothing planted in ' + (D.season === 'Today' ? 'the beds today' : D.season) + '.', legendEl);
    }

    function showPanel(b) {
        if (!panel) return;
        panel.textContent = '';
        if (!b) { panel.hidden = true; return; }
        var head = html('div', 'gm-panel-head', null, panel);
        html('h2', 'gm-panel-title', b.path, head);
        var x = html('button', 'gm-x', '✕', head); x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.setAttribute('data-no-icons', '');
        x.addEventListener('click', function () { select(0); });
        html('p', 'gm-panel-kind', b.kind, panel);
        if (b.facts.length) { var dl = html('dl', 'gm-facts', null, panel); b.facts.forEach(function (f) { var d = html('div', '', null, dl); html('dt', '', f[0], d); html('dd', '', f[1], d); }); }
        if (b.notes) html('p', 'gm-notes', b.notes, panel);
        html('h3', 'gm-panel-sub', D.season === 'Today' ? 'In the bed today' : 'In the bed, ' + D.season, panel);
        if (!b.plantings.length) html('p', 'gm-muted', 'Nothing.', panel);
        else {
            var ul = html('ul', 'gm-plist', null, panel);
            b.plantings.forEach(function (p) {
                var li = html('li', '', null, ul);
                swatch(fill(p), li);
                var a = html('a', 'gm-link', p.crop + ' — ' + p.variety, li); a.href = 'plantings.php?id=' + p.id;
                html('span', 'gm-muted', ' · ' + p.status + ' · ' + p.from + (p.to ? '–' + p.to + (p.estimated ? ' (about)' : '') : ' on') + (p.sqft ? ' · ' + p.sqft + ' sq ft' : ''), li);
                html('span', 'gm-pfam', p.family, li);
            });
        }
        if (b.warnings.length) { var wl = html('ul', 'gm-warnings', null, panel); b.warnings.forEach(function (w) { html('li', '', '⚠️ ' + w, wl); }); }
        var btns = html('div', 'gm-panel-btns', null, panel);
        var o = html('a', 'gm-btn', 'Open bed', btns); o.href = 'areas.php?bed=' + b.id;
        var ad = html('a', 'gm-btn', 'Add a planting', btns); ad.href = 'plantings.php?location=' + b.id + '#form-plant_add';
        panel.hidden = false;
        if (panel.getBoundingClientRect().top > window.innerHeight - 80) panel.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
    }

    function select(id) {
        selected = id;
        if (svgEl) Array.prototype.forEach.call(svgEl.querySelectorAll('.gm-bed'), function (g) { g.classList.toggle('is-selected', +g.getAttribute('data-id') === id); });
        Array.prototype.forEach.call(turnBtns, function (t) { t.disabled = !(editing && id); });
        if (editing) { var b = byId[id]; var help = editbar && editbar.querySelector('[data-edit-help]'); if (help) help.textContent = b ? b.path + ' picked. Drag it, or turn it.' : 'Drag a bed to move it. Tap one to pick it, then turn it.'; }
        else showPanel(byId[id] || null);
    }

    // Save one bed's spot. On a refusal it goes back where it was.
    function save(b, was) {
        var body = new URLSearchParams();
        body.set('act', 'area_move'); body.set('csrf_token', D.csrf); body.set('site_id', D.site); body.set('area_id', b.id);
        body.set('x_ft', (Math.round(b.x * 100) / 100).toString()); body.set('y_ft', (Math.round(b.y * 100) / 100).toString()); body.set('rotation', String(b.rot));
        say('Saving ' + b.name + '…');
        return fetch('areas.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.ok) { say('Saved ' + b.name + '.'); return true; }
                var e = new Error((j && j.error) || 'Could not save.'); e.fromServer = true; throw e;
            })
            .catch(function (err) {
                if (was) { b.x = was.x; b.y = was.y; b.rot = was.rot; render(); }
                say(err && err.fromServer ? err.message : 'Could not save ' + b.name + '. Check your connection and try again.', true);
                return false;
            });
    }

    // Pointer: tap = pick; in Move mode, drag = move (snaps to half a foot).
    var drag = null;
    function localPoint(gParent, ev) {
        var m = gParent.getScreenCTM(); if (!m) return null;
        var pt = svgEl.createSVGPoint(); pt.x = ev.clientX; pt.y = ev.clientY;
        return pt.matrixTransform(m.inverse());
    }
    if (stage) {
        stage.addEventListener('pointerdown', function (ev) {
            var g = ev.target.closest && ev.target.closest('.gm-bed');
            if (!g || !editing) return;
            var b = byId[+g.getAttribute('data-id')]; if (!b) return;
            var p = localPoint(g.parentNode, ev); if (!p) return;
            drag = { b: b, g: g, start: p, was: { x: b.x, y: b.y, rot: b.rot }, moved: false, id: ev.pointerId };
            try { stage.setPointerCapture(ev.pointerId); } catch (e) { /* older browsers */ }
            ev.preventDefault();
        });
        stage.addEventListener('pointermove', function (ev) {
            if (!drag || ev.pointerId !== drag.id) return;
            var p = localPoint(drag.g.parentNode, ev); if (!p) return;
            var dx = p.x - drag.start.x, dy = p.y - drag.start.y;
            if (!drag.moved && Math.abs(dx) * pxPerFt < 4 && Math.abs(dy) * pxPerFt < 4) return;
            drag.moved = true;
            drag.b.x = Math.round((drag.was.x + dx) * 2) / 2; drag.b.y = Math.round((drag.was.y + dy) * 2) / 2;
            drag.g.setAttribute('transform', tf(drag.b));
            say(drag.b.name + ': ' + drag.b.x + ' ft across, ' + drag.b.y + ' ft down');
        });
        var end = function (ev) {
            if (!drag || ev.pointerId !== drag.id) return;
            var d = drag; drag = null;
            if (d.moved) { select(d.b.id); render(); save(d.b, d.was); }
            else select(d.b.id);
        };
        stage.addEventListener('pointerup', end);
        stage.addEventListener('pointercancel', function (ev) { if (drag && ev.pointerId === drag.id) { var d = drag; drag = null; d.b.x = d.was.x; d.b.y = d.was.y; render(); } });
        stage.addEventListener('click', function (ev) {
            if (editing) return;
            var g = ev.target.closest && ev.target.closest('.gm-bed');
            select(g ? +g.getAttribute('data-id') : 0);
        });
        stage.addEventListener('keydown', function (ev) {
            var g = ev.target.closest && ev.target.closest('.gm-bed');
            if (g && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); select(+g.getAttribute('data-id')); }
        });
    }
    Array.prototype.forEach.call(turnBtns, function (t) {
        t.addEventListener('click', function () {
            var b = byId[selected]; if (!b || !editing) return;
            var was = { x: b.x, y: b.y, rot: b.rot };
            b.rot = (b.rot + parseInt(t.getAttribute('data-turn'), 10)) % 360;
            render(); save(b, was);
        });
    });
    if (moveBtn) moveBtn.addEventListener('click', function () {
        editing = !editing;
        moveBtn.setAttribute('aria-pressed', editing ? 'true' : 'false');
        moveBtn.textContent = editing ? 'Done moving' : 'Move beds';
        if (editbar) editbar.hidden = !editing;
        if (editing) showPanel(null);
        render(); select(editing ? 0 : selected);
        say(editing ? 'Move mode on. Each drop saves.' : '');
    });
    Array.prototype.forEach.call(root.querySelectorAll('[data-color-by]'), function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-color-by') === colorBy ? 'true' : 'false');
        b.addEventListener('click', function () {
            colorBy = b.getAttribute('data-color-by'); store('bcrf-garden-color', colorBy);
            Array.prototype.forEach.call(root.querySelectorAll('[data-color-by]'), function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
            render(); if (!editing && selected) showPanel(byId[selected]);
        });
    });
    // "Add to map": drop the bed below everything else, then reload on it.
    Array.prototype.forEach.call(document.querySelectorAll('[data-place]'), function (btn) {
        btn.addEventListener('click', function () {
            var b = byId[+btn.getAttribute('data-place')]; if (!b) return;
            var e = extent();
            b.x = 0; b.y = roots.length ? Math.ceil(e.maxY + 2) : 0; b.rot = 0;
            if (b.parent && byId[b.parent] && byId[b.parent].placed) b.y = 0;   // inside its area: start in the corner
            btn.disabled = true;
            save(b, null).then(function (ok) {
                if (ok) window.location.href = 'areas.php?site=' + D.site + '&tab=map&focus=' + b.id;
                else btn.disabled = false;
            });
        });
    });
    var rt = null;
    window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 150); });
    render();
    if (selected) {
        select(selected);
        var g = svgEl && svgEl.querySelector('.gm-bed[data-id="' + selected + '"]');
        if (g && stage) stage.scrollIntoView({ block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' });
    }
})();
