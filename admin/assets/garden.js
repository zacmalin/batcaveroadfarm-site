// admin/assets/garden.js — Garden bed planner (Nov 19, 2026; admin/garden/areas.php map tab, admin/garden/plantings.php add form,
//   includes/garden_planner.php builds the data, admin/assets/garden.css styles it).
//   Map: the beds drawn to scale from #garden-map-data (feet) as rectangles, circles/ovals or triangles. "Pictures" (the default)
//   draws grass, soil beds in a wood frame and the plants themselves in rows, spaced by the crop's spacing (built-in drawings,
//   the crop's own picture, or an emoji); the other views show plantings as colored strips (by crop family, status or bed color), a ⚠ on beds with a rotation or crowding note, tap a bed for its details. "Move beds" lets managers drag
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
        // Suggested dates from the crop's timing and the farm's frost dates (Nov 19, 2026).
        var sugBox = pform.querySelector('[data-plant-suggest]');
        var suggest = function () {
            if (!sugBox) return;
            var v = (pform.elements.variety || {}).value, plan = (P.plans || {})[v];
            sugBox.textContent = '';
            sugBox.hidden = !plan;
            if (!plan) return;
            html('span', '', '📅 ' + plan.words, sugBox);
            var b = html('button', 'px-3 py-1 rounded text-sm border border-green-700 text-green-900 bg-white', plan.indoors ? 'Plan the plant-out date' : 'Use this date', sugBox);
            b.type = 'button';
            b.setAttribute('data-no-icons', '');
            b.addEventListener('click', function () {
                var d = pform.elements.planted_on, m = pform.elements.method, st = pform.elements.status;
                if (d) d.value = plan.out;
                if (m) m.value = plan.how === 'transplant' ? 'transplant' : 'direct_sow';
                if (st && plan.out > (P.today || '')) st.value = 'planned';
                check();
                if (d) d.focus();
            });
        };
        var vf = pform.elements.variety; if (vf) vf.addEventListener('change', suggest);
        suggest();
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
    var nudgeBtns = root.querySelectorAll('[data-nudge]');
    var snapSelect = root.querySelector('[data-snap]');
    var zoomBtns = root.querySelectorAll('[data-zoom]');
    var zoomLevel = 1, panX = 0, panY = 0;
    var lengthInput = root.querySelector('[data-length-ft]');
    var widthInput = root.querySelector('[data-width-ft]');
    var dimensionButton = root.querySelector('[data-apply-dimensions]');
    var byId = {}, kids = {};
    (D.beds || []).forEach(function (b) { byId[b.id] = b; });
    (D.beds || []).forEach(function (b) { if (b.placed && b.parent && byId[b.parent] && byId[b.parent].placed) (kids[b.parent] = kids[b.parent] || []).push(b); });
    var roots = (D.beds || []).filter(function (b) { return b.placed && !(b.parent && byId[b.parent] && byId[b.parent].placed); });
    var colorBy = store('bcrf-garden-color'); if (['family', 'status', 'bed'].indexOf(colorBy) < 0) colorBy = 'picture';
    var ART = D.art || {}, picDefs = {}, defsEl = null, picN = 0;
    var editing = false, selected = D.focus && byId[D.focus] ? D.focus : 0;
    var svgEl = null, pxPerFt = 10;
    var history = [], future = [], busy = false;
    var editRevision = 0;
    var revision = D.layoutRevision || '';
    var baseline = {};
    (D.beds || []).forEach(function (bed) { baseline[bed.id] = { id: bed.id, x: bed.x, y: bed.y, rot: bed.rot, len: bed.len, wid: bed.wid }; });
    var saveButton = root.querySelector('[data-save-layout]');
    var undoButton = root.querySelector('[data-undo-layout]');
    var redoButton = root.querySelector('[data-redo-layout]');
    function state(bed) { return { id: bed.id, x: bed.x, y: bed.y, rot: bed.rot, len: bed.len, wid: bed.wid }; }
    function differs(a,b) { return a.x !== b.x || a.y !== b.y || a.rot !== b.rot || a.len !== b.len || a.wid !== b.wid; }
    function dirty() { return (D.beds || []).some(function (bed) { return differs(bed, baseline[bed.id]); }); }
    function snap() { return snapSelect ? Number(snapSelect.value) || 0.5 : 0.5; }
    function quantize(value) { var step = snap(); return Math.round(value / step) * step; }
    function controls() {
        if (saveButton) saveButton.disabled = busy || !!drag || !dirty();
        if (undoButton) undoButton.disabled = busy || !!drag || !history.length;
        if (redoButton) redoButton.disabled = busy || !!drag || !future.length;
        Array.prototype.forEach.call(nudgeBtns, function (b) { b.disabled = busy || !!drag || !editing || !selected; });
        Array.prototype.forEach.call(turnBtns, function (b) { b.disabled = busy || !!drag || !editing || !selected; });
        if (moveBtn) moveBtn.disabled = busy || !!drag;
        if (snapSelect) snapSelect.disabled = !!drag;
        var selectedBed = byId[selected];
        var canResize = !!(editing && selectedBed && !(kids[selected] || []).length && !busy && !drag);
        if (dimensionButton) dimensionButton.disabled = !canResize;
        if (lengthInput) lengthInput.disabled = !canResize;
        if (widthInput) widthInput.disabled = !canResize;
    }
    function record(bed, old) {
        if (!differs(bed,old)) return;
        history.push({ before: old, after: state(bed) });
        future = []; editRevision++; controls(); say('Unsaved layout changes.');
    }
    function restore(v) { var b = byId[v.id]; b.x = v.x; b.y = v.y; b.rot = v.rot; b.len = v.len; b.wid = v.wid; editRevision++; render(); select(b.id); controls(); }
    if (undoButton) undoButton.addEventListener('click', function () { if (busy || drag || !history.length) return; var v = history.pop(); future.push(v); restore(v.before); });
    if (redoButton) redoButton.addEventListener('click', function () { if (busy || drag || !future.length) return; var v = future.pop(); history.push(v); restore(v.after); });
    window.addEventListener('beforeunload', function (ev) { if (dirty()) { ev.preventDefault(); ev.returnValue = ''; } });


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
    function fill(p, b) { return colorBy === 'bed' ? (b && b.color) || '#d9c7a7' : colorBy === 'status' ? p.statusColor : p.familyColor; }
    // The bed's outline in its own feet (0..len across, 0..wid down). Round = circle or oval; triangle = long bottom edge, point at the top.
    function shapeEl(b, attrs, parent) {
        var a = {}; for (var k in attrs) a[k] = attrs[k];
        if (b.shape === 'round') { a.cx = b.len / 2; a.cy = b.wid / 2; a.rx = b.len / 2; a.ry = b.wid / 2; return svg('ellipse', a, parent); }
        if (b.shape === 'triangle') { a.points = '0,' + b.wid + ' ' + b.len + ',' + b.wid + ' ' + b.len / 2 + ',0'; return svg('polygon', a, parent); }
        a.x = 0; a.y = 0; a.width = b.len; a.height = b.wid; return svg('rect', a, parent);
    }
    // Is a point (bed feet) inside the shape, at least m feet from the edge?
    function inside(b, x, y, m) {
        if (x < m || y < m || x > b.len - m || y > b.wid - m) return false;
        if (b.shape === 'round') { var rx = b.len / 2 - m, ry = b.wid / 2 - m; if (rx <= 0 || ry <= 0) return false; var dx = (x - b.len / 2) / rx, dy = (y - b.wid / 2) / ry; return dx * dx + dy * dy <= 1; }
        if (b.shape === 'triangle') { var top = b.wid * Math.abs(x - b.len / 2) / (b.len / 2); return y >= top + m * 1.6; }
        return true;
    }
    // A plant picture used once per kind, then copied (<use>): built-in drawing, the crop's own picture, or none (emoji).
    function picRef(p) {
        var src = p.pic || (p.art && ART[p.art]) || '';
        if (!src || !defsEl) return '';
        if (!picDefs[src]) {
            var id = 'gmp' + (++picN);
            var sym = svg('symbol', { id: id, viewBox: '0 0 10 10' }, defsEl);
            svg('image', { href: src, x: 0, y: 0, width: 10, height: 10, preserveAspectRatio: 'xMidYMid meet' }, sym);
            picDefs[src] = '#' + id;
        }
        return picDefs[src];
    }
    // Plants in rows inside one strip of a bed: gap apart along the row, row apart across. Never smaller than ~16 px on
    // screen (so a carrot bed shows rows of carrots, not a smudge), and at most ~250 plants per strip.
    function drawPlants(g, b, p, r, long) {
        var minFt = 16 / pxPerFt;
        var along = Math.max(p.gap || 1, minFt), across = Math.max(p.row || p.gap || 1, minFt);
        var aLen = long ? r.width : r.height, cLen = long ? r.height : r.width;
        while ((aLen / along) * (cLen / across) > 250) { along *= 1.25; across *= 1.25; }
        var size = Math.min(along, across) * 0.92, ref = picRef(p);
        var nA = Math.max(1, Math.floor(aLen / along)), nC = Math.max(1, Math.floor(cLen / across));
        var offA = (aLen - (nA - 1) * along) / 2, offC = (cLen - (nC - 1) * across) / 2;
        var pg = svg('g', { 'class': 'gm-pics gm-pst-' + p.statusKey }, g);
        for (var i = 0; i < nA; i++) for (var j = 0; j < nC; j++) {
            var x = long ? r.x + offA + i * along : r.x + offC + j * across;
            var y = long ? r.y + offC + j * across : r.y + offA + i * along;
            if (!inside(b, x, y, size * 0.3)) continue;
            var turn = b.rot ? ' rotate(' + (-b.rot) + ' ' + x + ' ' + y + ')' : '';   // plants stay upright when the bed is turned
            if (ref) svg('use', { href: ref, x: x - size / 2, y: y - size / 2, width: size, height: size, transform: turn.trim() }, pg);
            else { var t = svg('text', { x: x, y: y, 'class': 'gm-emoji', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': size * 0.8, transform: turn.trim() }, pg); t.textContent = p.emoji || '🌱'; }
        }
    }

    function render() {
        if (!stage) return;
        var e = extent(), pad = Math.max(1, (e.maxX - e.minX) * 0.03);
        var room = editing ? Math.max(8, (e.maxX - e.minX) * 0.25) : 0;
        var x0 = e.minX - pad, y0 = e.minY - pad, w = e.maxX - e.minX + 2 * pad + room, h = e.maxY - e.minY + 2 * pad + room * 0.5;
        var focus = selected && byId[selected] && byId[selected].placed && roots.some(function (b) { return b.id === selected; }) ? byId[selected] : null;
        var midX = zoomLevel > 1 && focus ? focus.x + focus.len / 2 : x0 + w / 2;
        var midY = zoomLevel > 1 && focus ? focus.y + focus.wid / 2 : y0 + h / 2;
        w /= zoomLevel; h /= zoomLevel;
        x0 = midX - w / 2 + panX; y0 = midY - h / 2 + panY;
        stage.textContent = '';
        var pics = colorBy === 'picture';
        stage.classList.toggle('is-pic', pics);
        stage.classList.toggle('is-pannable', zoomLevel > 1);
        svgEl = svg('svg', { 'class': 'gm-svg' + (editing ? ' is-editing' : '') + (pics ? ' is-pic' : ''), viewBox: [x0, y0, w, h].join(' '), 'aria-hidden': 'true' }, stage);
        defsEl = svg('defs', {}, svgEl); picDefs = {}; picN = 0;
        pxPerFt = Math.min((stage.clientWidth || 320) / w, (window.innerHeight || 700) * 0.78 / h);   // the drawing is capped at 78% of the screen height
        var fs = 12 / pxPerFt;   // 12 px text, in feet
        // Grid every step feet; heavier every 5 steps.
        var step = niceStep(Math.max(w, h)), gG = svg('g', { 'class': 'gm-grid' }, svgEl);
        // The grid runs past the drawing so it fills the whole card when the map is narrower than the screen.
        var gx0 = x0 - w * 2, gx1 = x0 + w * 3;
        for (var gx = Math.ceil(gx0 / step) * step; gx <= gx1; gx += step) svg('line', { x1: gx, y1: y0, x2: gx, y2: y0 + h, 'class': Math.round(gx / step) % 5 === 0 ? 'gm-grid-major' : '' }, gG);
        for (var gy = Math.ceil(y0 / step) * step; gy <= y0 + h; gy += step) svg('line', { x1: gx0, y1: gy, x2: gx1, y2: gy, 'class': Math.round(gy / step) % 5 === 0 ? 'gm-grid-major' : '' }, gG);
        var north = svg('text', { x: x0 + w - fs * 0.6, y: y0 + fs * 1.3, 'class': 'gm-north', 'text-anchor': 'end', 'font-size': fs }, svgEl); north.textContent = 'N ↑';
        var layer = svg('g', { 'class': 'gm-layer' }, svgEl);
        roots.forEach(function (b) { drawBed(layer, b, fs); });
        if (scaleEl) scaleEl.textContent = 'Each grid square is ' + step + ' ft. Top of the map is north. Zoom ' + Math.round(zoomLevel * 100) + '%.' + (editing ? '' : ' Tap a bed for its details.');
        legend();
    }

    function drawBed(parent, b, fs) {
        var pics = colorBy === 'picture', area = !!kids[b.id];
        var g = svg('g', { 'class': 'gm-bed' + (b.id === selected ? ' is-selected' : '') + (area ? ' is-area' : ''), 'data-id': b.id, transform: tf(b), tabindex: editing ? '-1' : '0', role: 'button',
                           'aria-label': b.path + (b.plantings.length ? ': ' + b.plantings.map(function (p) { return p.crop; }).join(', ') : ': empty') + (b.warnings.length ? '. Needs a look.' : '') }, parent);
        if (pics) shapeEl(b, { 'class': area ? 'gm-path' : 'gm-soil' + (b.cover && b.cover !== 'none' ? ' gm-cover-' + b.cover : '') }, g);
        else if (b.color) shapeEl(b, { 'class': 'gm-base-own', fill: b.color }, g);   // a color picked for the bed
        else shapeEl(b, { 'class': 'gm-base gm-cover-' + (b.cover || 'none') }, g);
        // Plantings as strips along the long side: by bed space when every one has it, else equal. Cut to the bed's shape.
        var list = b.plantings, long = b.len >= b.wid, L = long ? b.len : b.wid, S = long ? b.wid : b.len;
        if (list.length) {
            var cid = 'gmc' + b.id, cp = svg('clipPath', { id: cid }, defsEl || g);
            shapeEl(b, {}, cp);
            var inner = svg('g', { 'clip-path': 'url(#' + cid + ')' }, g);
            var all = b.sqft && list.every(function (p) { return p.sqft; }), total = 0;
            list.forEach(function (p) { total += all ? p.sqft : 1; });
            var denom = all ? Math.max(total, b.sqft) : total, at = 0;
            list.forEach(function (p, i) {
                var part = L * (all ? p.sqft : 1) / denom;
                var r = long ? { x: at, y: 0, width: part, height: S } : { x: 0, y: at, width: S, height: part };
                if (pics) {
                    if (i > 0) svg('line', long ? { x1: at, y1: 0, x2: at, y2: S, 'class': 'gm-split' } : { x1: 0, y1: at, x2: S, y2: at, 'class': 'gm-split' }, inner);
                    drawPlants(inner, b, p, r, long);
                } else {
                    var rr = { x: r.x, y: r.y, width: r.width, height: r.height, 'class': 'gm-plant gm-st-' + p.statusKey, fill: fill(p, b) };
                    svg('rect', rr, inner);
                    // Crop name in the strip when it fits.
                    var lf = Math.min(fs, S * 0.42), pxLong = part * pxPerFt;
                    if (lf * pxPerFt >= 7 && pxLong >= 28) {
                        var maxChars = Math.max(2, Math.floor(part / (lf * 0.58)));
                        var name = p.crop.length > maxChars ? p.crop.slice(0, maxChars - 1) + '…' : p.crop;
                        var t = long ? svg('text', { x: at + part / 2, y: S / 2, 'class': 'gm-label', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': lf, 'stroke-width': lf * 0.22 }, inner)
                                     : svg('text', { x: S / 2, y: at + part / 2, 'class': 'gm-label', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': lf, 'stroke-width': lf * 0.22 }, inner);
                        t.textContent = name;
                    }
                }
                at += part;
            });
        }
        // Bed name: above an area with beds in it, above each bed in Pictures, in the middle of an empty bed otherwise.
        var nf = Math.min(fs * 0.85, Math.min(b.len, b.wid) * 0.35);
        if (area && fs * pxPerFt >= 7) {
            var ta = svg('text', { x: 0, y: -fs * 0.3, 'class': 'gm-name', 'font-size': fs * 0.9 }, g); ta.textContent = b.name;
        } else if (pics && b.len * pxPerFt >= 34) {
            var pf = fs * 0.8, maxP = Math.max(2, Math.floor(b.len / (pf * 0.56)));
            var tp = svg('text', { x: b.len / 2, y: -pf * 0.35, 'class': 'gm-name gm-name-pic', 'text-anchor': 'middle', 'font-size': pf, 'stroke-width': pf * 0.25 }, g);
            tp.textContent = b.name.length > maxP ? b.name.slice(0, maxP - 1) + '…' : b.name;
        } else if (!pics && !list.length && nf * pxPerFt >= 7 && b.len * pxPerFt >= 30) {
            var maxN = Math.max(2, Math.floor((b.len - nf) / (nf * 0.58)));
            var tn = svg('text', { x: b.len / 2, y: b.wid / 2, 'class': 'gm-name', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': nf }, g);
            tn.textContent = b.name.length > maxN ? b.name.slice(0, maxN - 1) + '…' : b.name;
        }
        // Wood frame (Pictures) or a plain edge; the picked bed gets a red edge either way.
        if (pics && !area) shapeEl(b, { 'class': 'gm-frame', 'stroke-width': Math.max(Math.min(b.len, b.wid) * 0.06, 2.5 / pxPerFt), 'stroke-linejoin': 'round' }, g);
        shapeEl(b, { 'class': 'gm-outline', 'vector-effect': 'non-scaling-stroke' }, g);
        if (editing && b.id === selected && !(kids[b.id] || []).length) {
            var handleSize = Math.max(18 / pxPerFt, Math.min(b.len, b.wid) * 0.16);
            svg('circle', { 'class': 'gm-resize-handle', 'data-resize': 'length', cx: b.len, cy: b.wid / 2, r: handleSize / 2, 'aria-label': 'Resize length' }, g);
            svg('circle', { 'class': 'gm-resize-handle', 'data-resize': 'width', cx: b.len / 2, cy: b.wid, r: handleSize / 2, 'aria-label': 'Resize width' }, g);
        }
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
        if (colorBy === 'picture') {   // each crop on the map with its picture
            var seen = {};
            (D.beds || []).forEach(function (b) { if (b.placed) b.plantings.forEach(function (p) { if (!seen[p.crop]) seen[p.crop] = p; }); });
            var names = Object.keys(seen).sort();
            names.forEach(function (n) { var s = html('span', 'gm-key', null, legendEl); plantIcon(seen[n], s); html('span', '', n, s); });
            if ((D.beds || []).some(function (b) { return b.placed && b.plantings.some(function (p) { return p.statusKey === 'planned'; }); })) html('span', 'gm-key', 'Faded = planned', legendEl);
            if ((D.beds || []).some(function (b) { return b.placed && b.warnings.length; })) html('span', 'gm-key', '⚠ needs a look (tap the bed)', legendEl);
            if (!names.length) html('span', 'gm-key', 'Nothing planted in ' + (D.season === 'Today' ? 'the beds today' : D.season) + '.', legendEl);
            return;
        }
        var items = (D.legend || {})[colorBy] || [];
        items.forEach(function (it) { var s = html('span', 'gm-key', null, legendEl); swatch(it.color, s); html('span', '', it.label, s); });
        if (colorBy === 'status') { var pl = html('span', 'gm-key', null, legendEl); html('span', '', 'Planned = faded with a dashed edge', pl); }
        if ((D.beds || []).some(function (b) { return b.placed && b.warnings.length; })) html('span', 'gm-key', '⚠ needs a look (tap the bed)', legendEl);
        if (!items.length && colorBy === 'bed') html('span', 'gm-key', 'No bed colors picked yet. Open a bed, then Change this bed, to pick one.', legendEl);
        else if (!items.length) html('span', 'gm-key', 'Nothing planted in ' + (D.season === 'Today' ? 'the beds today' : D.season) + '.', legendEl);
    }
    // A small plant picture (or emoji) for the legend and the bed panel.
    function plantIcon(p, parent) {
        var src = p.pic || (p.art && ART[p.art]) || '';
        if (src) { var im = html('img', 'gm-ico', null, parent); im.src = src; im.alt = ''; return im; }
        return html('span', 'gm-ico gm-ico-emoji', p.emoji || '🌱', parent);
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
                if (colorBy === 'picture') plantIcon(p, li); else swatch(fill(p, b), li);
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
        var changedSelection = selected !== id;
        selected = id;
        if (changedSelection && editing && !drag) render();
        if (svgEl) Array.prototype.forEach.call(svgEl.querySelectorAll('.gm-bed'), function (g) { g.classList.toggle('is-selected', +g.getAttribute('data-id') === id); });
        Array.prototype.forEach.call(turnBtns, function (t) { t.disabled = busy || !!drag || !(editing && id); });
        controls();
        if (lengthInput) lengthInput.value = byId[id] ? Number(byId[id].len).toFixed(2).replace(/\.?0+$/, '') : '';
        if (widthInput) widthInput.value = byId[id] ? Number(byId[id].wid).toFixed(2).replace(/\.?0+$/, '') : '';
        if (editing) { var b = byId[id]; var help = editbar && editbar.querySelector('[data-edit-help]'); if (help) help.textContent = b ? b.path + ' selected. Drag to move; use edge handles to resize a bed without child areas. Save layout when finished.' : 'Drag to move, tap to select, then rotate or resize. Save layout when finished.'; }
        else showPanel(byId[id] || null);
    }

    function saveLayout() {
        if (busy || drag || !dirty()) return;
        var changes = (D.beds || []).filter(function (b) { return differs(b, baseline[b.id]); }).map(function (b) {
            return { id: b.id, x_ft: b.x, y_ft: b.y, rotation: b.rot, length_ft: b.len, width_ft: b.wid };
        });
        var body = new URLSearchParams();
        body.set('act','area_layout_save'); body.set('csrf_token',D.csrf); body.set('site_id',D.site);
        body.set('changes',JSON.stringify(changes));
        body.set('revision', revision);
        var savingRevision = editRevision;
        busy = true; controls(); select(selected); say('Saving layout…');
        fetch('areas.php', { method:'POST', body:body, credentials:'same-origin', headers:{ Accept:'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('Save failed.'); return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) throw new Error(j && j.error || 'Save failed.');
                if (editRevision !== savingRevision) {
                    revision = j.revision || revision;
                    say('Layout saved, but newer edits remain unsaved.', true);
                    return;
                }
                (D.beds || []).forEach(function (b) { baseline[b.id] = state(b); });
                revision = j.revision || revision;
                history = []; future = []; say('Layout saved.');
            })
            .catch(function (e) { say(e.message || 'Could not save layout.',true); })
            .finally(function () { busy = false; controls(); select(selected); });
    }
    if (saveButton) saveButton.addEventListener('click',saveLayout);
    // Pointer: tap = pick; in edit mode, drag = move (configurable snap).
    var drag = null, panDrag = null;
    function localPoint(gParent, ev) {
        var m = gParent.getScreenCTM(); if (!m) return null;
        var pt = svgEl.createSVGPoint(); pt.x = ev.clientX; pt.y = ev.clientY;
        return pt.matrixTransform(m.inverse());
    }
    if (stage) {
        stage.addEventListener('pointerdown', function (ev) {
            var g = ev.target.closest && ev.target.closest('.gm-bed');
            if (!g) {
                if (zoomLevel <= 1 || drag || panDrag) return;
                panDrag = { id: ev.pointerId, x: ev.clientX, y: ev.clientY, ox: panX, oy: panY, lastX: panX, lastY: panY };
                try { stage.setPointerCapture(ev.pointerId); } catch (e) { /* older browsers */ }
                ev.preventDefault();
                return;
            }
            if (!editing || busy || drag || panDrag) return;
            var b = byId[+g.getAttribute('data-id')]; if (!b) return;
            var p = localPoint(g.parentNode, ev); if (!p) return;
            var handle = ev.target.getAttribute && ev.target.getAttribute('data-resize');
            if (handle && (kids[b.id] || []).length) return;
            drag = { b: b, g: g, start: p, was: state(b), moved: false, id: ev.pointerId, resize: handle || '' };
            try { stage.setPointerCapture(ev.pointerId); } catch (e) { /* older browsers */ }
            controls();
            ev.preventDefault();
        });
        stage.addEventListener('pointermove', function (ev) {
            if (panDrag && ev.pointerId === panDrag.id && svgEl) {
                var matrix = svgEl.getScreenCTM(), view = svgEl.viewBox.baseVal;
                if (matrix) {
                    var determinant = matrix.a * matrix.d - matrix.b * matrix.c;
                    if (Math.abs(determinant) > 0.000001) {
                        var screenDx = ev.clientX - panDrag.x, screenDy = ev.clientY - panDrag.y;
                        panX = panDrag.ox - (matrix.d * screenDx - matrix.c * screenDy) / determinant;
                        panY = panDrag.oy - (-matrix.b * screenDx + matrix.a * screenDy) / determinant;
                        // Changing viewBox pans without reconstructing every bed and plant.
                        view.x += panX - panDrag.lastX;
                        view.y += panY - panDrag.lastY;
                        panDrag.lastX = panX; panDrag.lastY = panY;
                    }
                }
                return;
            }
            if (!drag || ev.pointerId !== drag.id) return;
            var p = localPoint(drag.g.parentNode, ev); if (!p) return;
            var dx = p.x - drag.start.x, dy = p.y - drag.start.y;
            if (!drag.moved && Math.abs(dx) * pxPerFt < 4 && Math.abs(dy) * pxPerFt < 4) return;
            drag.moved = true;
            if (drag.resize) {
                var angle = drag.was.rot * Math.PI / 180;
                var along = dx * Math.cos(angle) + dy * Math.sin(angle);
                var across = -dx * Math.sin(angle) + dy * Math.cos(angle);
                var size = Math.max(0.01, Math.min(10000, quantize((drag.resize === 'length' ? drag.was.len : drag.was.wid) + (drag.resize === 'length' ? along : across))));
                if (drag.resize === 'length') drag.b.len = size;
                else drag.b.wid = size;
                // Keep the opposite corner fixed in world space when resizing a rotated bed.
                var deltaLen = drag.b.len - drag.was.len, deltaWid = drag.b.wid - drag.was.wid;
                drag.b.x = drag.was.x + (deltaLen * (Math.cos(angle) - 1) - deltaWid * Math.sin(angle)) / 2;
                drag.b.y = drag.was.y + (deltaLen * Math.sin(angle) + deltaWid * (Math.cos(angle) - 1)) / 2;
                // Stretch existing SVG only for the gesture preview; rebuild crop strips on release.
                drag.g.setAttribute('transform', tf(drag.was) + ' scale(' + (drag.b.len / drag.was.len) + ' ' + (drag.b.wid / drag.was.wid) + ')');
                say(drag.b.name + ': ' + drag.b.len + ' × ' + drag.b.wid + ' ft. Release to apply.');
            } else {
                drag.b.x = quantize(drag.was.x + dx); drag.b.y = quantize(drag.was.y + dy);
                drag.g.setAttribute('transform', tf(drag.b));
                say(drag.b.name + ': ' + drag.b.x + ' ft across, ' + drag.b.y + ' ft down');
            }
        });
        var end = function (ev) {
            if (panDrag && ev.pointerId === panDrag.id) { panDrag = null; return; }
            if (!drag || ev.pointerId !== drag.id) return;
            var d = drag; drag = null; controls();
            if (d.moved) { render(); select(d.b.id); record(d.b, d.was); }
            else select(d.b.id);
        };
        stage.addEventListener('pointerup', end);
        function cancelGesture(ev) {
            if (panDrag && ev.pointerId === panDrag.id) {
                panX = panDrag.ox; panY = panDrag.oy; panDrag = null; render(); return;
            }
            if (!drag || ev.pointerId !== drag.id) return;
            var d = drag; drag = null;
            d.b.x = d.was.x; d.b.y = d.was.y; d.b.rot = d.was.rot;
            d.b.len = d.was.len; d.b.wid = d.was.wid;
            render(); select(d.b.id); controls();
            say('Gesture cancelled; no changes applied.');
        }
        stage.addEventListener('pointercancel', cancelGesture);
        stage.addEventListener('lostpointercapture', cancelGesture);
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
    Array.prototype.forEach.call(zoomBtns, function (btn) {
        btn.addEventListener('click', function () {
            if (drag || panDrag) return;
            var action = btn.getAttribute('data-zoom');
            if (action === 'reset') { panX = 0; panY = 0; }
            zoomLevel = action === 'reset' ? 1 : Math.max(0.5, Math.min(4, zoomLevel * (action === 'in' ? 1.25 : 0.8)));
            render();
        });
    });
    if (dimensionButton) dimensionButton.addEventListener('click', function () {
        var b = byId[selected]; if (!b || !editing || busy || drag || (kids[b.id] || []).length) return;
        var length = Number(lengthInput.value), width = Number(widthInput.value);
        if (!Number.isFinite(length) || !Number.isFinite(width) || length <= 0 || width <= 0 || length > 10000 || width > 10000) {
            say('Enter positive bed dimensions of 10,000 ft or less.', true); return;
        }
        var was = state(b);
        b.len = Math.round(length * 100) / 100;
        b.wid = Math.round(width * 100) / 100;
        if (differs(b, was)) { render(); select(b.id); record(b, was); }
    });
    Array.prototype.forEach.call(nudgeBtns, function (t) {
        t.addEventListener('click', function () {
            var b = byId[selected]; if (!b || !editing || busy) return;
            var direction = t.getAttribute('data-nudge');
            var was = state(b), step = snap();
            if (direction === 'left') b.x = quantize(b.x - step);
            if (direction === 'right') b.x = quantize(b.x + step);
            if (direction === 'up') b.y = quantize(b.y - step);
            if (direction === 'down') b.y = quantize(b.y + step);
            if (differs(b, was)) { render(); select(b.id); record(b, was); }
        });
    });
    Array.prototype.forEach.call(turnBtns, function (t) {
        t.addEventListener('click', function () {
            var b = byId[selected]; if (!b || !editing || busy) return;
            var was = { x: b.x, y: b.y, rot: b.rot };
            b.rot = (b.rot + parseInt(t.getAttribute('data-turn'), 10)) % 360;
            render(); record(b, was);
        });
    });
    if (moveBtn) moveBtn.addEventListener('click', function () {
        if (busy || drag || (editing && dirty())) { say('Finish the gesture and save or undo changes before leaving edit mode.', true); return; }
        editing = !editing;
        controls();
        moveBtn.setAttribute('aria-pressed', editing ? 'true' : 'false');
        moveBtn.textContent = editing ? 'Done editing' : 'Edit beds';
        if (editbar) editbar.hidden = !editing;
        if (editing) showPanel(null);
        render(); select(editing ? 0 : selected);
        say(editing ? 'Edit mode: select Save layout to keep changes.' : '');
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
            var b = byId[+btn.getAttribute('data-place')]; if (!b || busy) return;
            if (dirty()) { say('Save your layout changes before adding another bed.', true); return; }
            var e = extent();
            var previous = state(b);
            b.x = 0; b.y = roots.length ? Math.ceil(e.maxY + 2) : 0; b.rot = 0;
            if (b.parent && byId[b.parent] && byId[b.parent].placed) b.y = 0;   // inside its area: start in the corner
            btn.disabled = true;
            var body = new URLSearchParams();
            body.set('act', 'area_move'); body.set('csrf_token', D.csrf);
            body.set('site_id', D.site); body.set('area_id', b.id);
            body.set('x_ft', String(b.x)); body.set('y_ft', String(b.y)); body.set('rotation', '0');
            fetch('areas.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j || !j.ok) throw new Error(j && j.error || 'Could not place bed.');
                    window.location.href = 'areas.php?site=' + D.site + '&tab=map&focus=' + b.id;
                })
                .catch(function (err) { b.x = previous.x; b.y = previous.y; b.rot = previous.rot; say(err.message || 'Could not place bed.', true); btn.disabled = false; });
        });
    });
    var rt = null;
    window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 150); });
    render(); controls();
    if (selected) {
        select(selected);
        var g = svgEl && svgEl.querySelector('.gm-bed[data-id="' + selected + '"]');
        if (g && stage) stage.scrollIntoView({ block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' });
    }
})();
