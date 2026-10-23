/* Bat Cave Road Farm — Admin list search + sort (external; no inline script).
 * Enhances any <table data-admin-list> with a live search box and a sort control
 * (A-Z, Z-A, Newest, Oldest). Opt-in per table via attributes:
 *   data-admin-list                 enable
 *   data-list-name-col="N"          column index used for A-Z / Z-A
 *   data-list-date-col="N"          column index used for Newest / Oldest (optional)
 *   data-list-no-sort               search only (no sort control)
 * Rows excluded from filter/sort (always handled safely):
 *   tr[data-list-section]  (group header)   tr[data-list-skip]   tr containing td[colspan] (empty-state)
 * Newest/Oldest falls back to a numeric id (data-id, descendant [data-id], or trailing digits in the row id)
 * when no date column is given. Degrades gracefully and never throws into the page. */
(function () {
  'use strict';

  function txt(el) { return (el && el.textContent || '').trim(); }
  function cellText(row, idx) { var c = row.cells && row.cells[idx]; return c ? txt(c) : ''; }
  function parseWhen(s) { var t = Date.parse(s); return isNaN(t) ? null : t; }

  function rowId(row) {
    if (row.dataset) {
      if (row.dataset.listDate) { var d = Date.parse(row.dataset.listDate); if (!isNaN(d)) return d; }
      if (row.dataset.id) { var n = parseInt(row.dataset.id, 10); if (!isNaN(n)) return n; }
    }
    var inner = row.querySelector('[data-id]');
    if (inner) { var i = parseInt(inner.getAttribute('data-id'), 10); if (!isNaN(i)) return i; }
    var m = (row.id || '').match(/(\d+)\s*$/);
    return m ? parseInt(m[1], 10) : null;
  }

  function isSkip(row) {
    return row.hasAttribute('data-list-skip') ||
           row.hasAttribute('data-list-section') ||
           !!row.querySelector('td[colspan]');
  }

  function enhance(table) {
    var tbody = table.tBodies && table.tBodies[0];
    if (!tbody) return;

    var nameCol = parseInt(table.getAttribute('data-list-name-col'), 10); if (isNaN(nameCol)) nameCol = null;
    var dateCol = parseInt(table.getAttribute('data-list-date-col'), 10); if (isNaN(dateCol)) dateCol = null;
    var noSort = table.hasAttribute('data-list-no-sort');
    var hasSections = !!tbody.querySelector('tr[data-list-section]');

    function allRows()  { return Array.prototype.slice.call(tbody.rows); }
    function dataRows() { return allRows().filter(function (r) { return !isSkip(r); }); }

    var canDate = (dateCol != null) || dataRows().some(function (r) { return rowId(r) != null; });

    // ---- toolbar ----
    var bar = document.createElement('div');
    bar.className = 'flex flex-wrap items-center gap-2 mb-3';

    var search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search\u2026';
    search.setAttribute('aria-label', 'Search list');
    search.className = 'flex-1 min-w-[160px] border border-stone-300 rounded px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-stone-400';
    bar.appendChild(search);

    var sel = null;
    if (!noSort && (nameCol != null || canDate)) {
      sel = document.createElement('select');
      sel.setAttribute('aria-label', 'Sort list');
      sel.className = 'border border-stone-300 rounded px-2 py-1.5 text-xs bg-white text-stone-700 focus:outline-none focus:ring-2 focus:ring-stone-400';
      var opts = [['', 'Sort\u2026']];
      if (nameCol != null) { opts.push(['az', 'A \u2192 Z'], ['za', 'Z \u2192 A']); }
      if (canDate)         { opts.push(['newest', 'Newest first'], ['oldest', 'Oldest first']); }
      opts.forEach(function (o) {
        var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; sel.appendChild(op);
      });
      bar.appendChild(sel);
    }

    var count = document.createElement('span');
    count.className = 'text-[11px] text-stone-400 font-mono ml-auto';
    bar.appendChild(count);

    table.parentNode.insertBefore(bar, table);

    function updateCount() {
      var rs = dataRows();
      var vis = rs.filter(function (r) { return r.style.display !== 'none'; }).length;
      count.textContent = vis + (vis === 1 ? ' item' : ' items') + (vis !== rs.length ? ' (of ' + rs.length + ')' : '');
    }

    function doSearch() {
      var q = search.value.trim().toLowerCase();
      dataRows().forEach(function (r) {
        r.style.display = (!q || r.textContent.toLowerCase().indexOf(q) >= 0) ? '' : 'none';
      });
      if (hasSections) {
        var rows = allRows();
        for (var i = 0; i < rows.length; i++) {
          if (!rows[i].hasAttribute('data-list-section')) continue;
          var anyVisible = false;
          for (var j = i + 1; j < rows.length; j++) {
            if (rows[j].hasAttribute('data-list-section')) break;
            if (isSkip(rows[j])) continue;
            if (rows[j].style.display !== 'none') { anyVisible = true; break; }
          }
          rows[i].style.display = anyVisible ? '' : 'none';
        }
      }
      updateCount();
    }

    function cmp(a, b, mode) {
      if (mode === 'az' || mode === 'za') {
        var c = cellText(a, nameCol).localeCompare(cellText(b, nameCol), undefined, { numeric: true, sensitivity: 'base' });
        return mode === 'az' ? c : -c;
      }
      var av = dateCol != null ? (parseWhen(cellText(a, dateCol)) != null ? parseWhen(cellText(a, dateCol)) : (rowId(a) || 0)) : (rowId(a) || 0);
      var bv = dateCol != null ? (parseWhen(cellText(b, dateCol)) != null ? parseWhen(cellText(b, dateCol)) : (rowId(b) || 0)) : (rowId(b) || 0);
      return mode === 'newest' ? bv - av : av - bv;
    }

    function doSort(mode) {
      if (!mode) return;
      if (hasSections) {
        var rows = allRows(), segs = [], pre = [], cur = null;
        rows.forEach(function (r) {
          if (r.hasAttribute('data-list-section')) { cur = { sec: r, items: [] }; segs.push(cur); }
          else if (cur) { cur.items.push(r); }
          else { pre.push(r); }
        });
        pre.forEach(function (r) { tbody.appendChild(r); });
        segs.forEach(function (seg) {
          var items = seg.items.filter(function (r) { return !isSkip(r); });
          var rest  = seg.items.filter(function (r) { return isSkip(r); });
          items.sort(function (a, b) { return cmp(a, b, mode); });
          tbody.appendChild(seg.sec);
          items.forEach(function (r) { tbody.appendChild(r); });
          rest.forEach(function (r) { tbody.appendChild(r); });
        });
      } else {
        var rs = dataRows(), skips = allRows().filter(isSkip);
        rs.sort(function (a, b) { return cmp(a, b, mode); });
        rs.forEach(function (r) { tbody.appendChild(r); });
        skips.forEach(function (r) { tbody.appendChild(r); });
      }
    }

    search.addEventListener('input', doSearch);
    if (sel) sel.addEventListener('change', function () { doSort(this.value); doSearch(); });
    updateCount();
  }

  // ── Record browser (schema.php): a list of <div class="record-row" data-name data-id> ──
  function enhanceRecords(container) {
    function items() {
      return Array.prototype.filter.call(container.children, function (el) {
        return el.classList && el.classList.contains('record-row');
      });
    }
    if (!items().length) return;

    function idOf(el) { var n = parseInt(el.getAttribute('data-id'), 10); return isNaN(n) ? null : n; }
    function key(el) { return (el.getAttribute('data-name') || el.textContent || '').toLowerCase().trim(); }
    var canDate = items().some(function (el) { return idOf(el) != null; });

    var bar = document.createElement('div');
    bar.className = 'flex flex-wrap items-center gap-2 mb-2';

    var search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search\u2026';
    search.setAttribute('aria-label', 'Search records');
    search.className = 'flex-1 min-w-[120px] border border-stone-300 rounded px-2 py-1.5 text-[11px] focus:outline-none focus:ring-2 focus:ring-stone-400';
    bar.appendChild(search);

    var sel = document.createElement('select');
    sel.setAttribute('aria-label', 'Sort records');
    sel.className = 'border border-stone-300 rounded px-2 py-1.5 text-[11px] bg-white text-stone-700 focus:outline-none focus:ring-2 focus:ring-stone-400';
    var opts = [['', 'Sort\u2026'], ['az', 'A \u2192 Z'], ['za', 'Z \u2192 A']];
    if (canDate) opts.push(['newest', 'Newest first'], ['oldest', 'Oldest first']);
    opts.forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; sel.appendChild(op); });
    bar.appendChild(sel);

    var count = document.createElement('span');
    count.className = 'text-[10px] text-stone-400 font-mono ml-auto';
    bar.appendChild(count);

    container.parentNode.insertBefore(bar, container);

    function updateCount() {
      var all = items(), vis = all.filter(function (el) { return el.style.display !== 'none'; }).length;
      count.textContent = vis + (vis !== all.length ? '/' + all.length : '');
    }
    search.addEventListener('input', function () {
      var q = this.value.toLowerCase().trim();
      items().forEach(function (el) { el.style.display = (!q || key(el).indexOf(q) >= 0) ? '' : 'none'; });
      updateCount();
    });
    sel.addEventListener('change', function () {
      var mode = this.value; if (!mode) return;
      var list = items();
      list.sort(function (a, b) {
        if (mode === 'az' || mode === 'za') {
          var c = key(a).localeCompare(key(b), undefined, { numeric: true, sensitivity: 'base' });
          return mode === 'az' ? c : -c;
        }
        var av = idOf(a) || 0, bv = idOf(b) || 0;
        return mode === 'newest' ? bv - av : av - bv;
      });
      list.forEach(function (el) { container.appendChild(el); });
    });
    updateCount();
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('table[data-admin-list]'), function (t) { try { enhance(t); } catch (e) { /* never break the page */ } });
    Array.prototype.forEach.call(document.querySelectorAll('[data-admin-list-records]'), function (c) { try { enhanceRecords(c); } catch (e) { /* never break the page */ } });
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
