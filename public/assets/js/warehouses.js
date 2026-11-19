// Admin > Warehouses: search and a low-stock filter for the stock table, and a move-stock form that checks the quantity
// against what the source warehouse holds, previews the result and swaps from/to. The server still checks everything.
// Without this script the page works as before.
(function () {
  'use strict';
  var root = document.querySelector('.wh-page');
  if (!root) return;

  // ---- stock table: search and low-stock filter -----------------------------------------------------------------------
  var filter = root.querySelector('[data-wh-filter]');
  var rows = Array.prototype.slice.call(root.querySelectorAll('[data-wh-row]'));
  var empty = root.querySelector('[data-wh-empty]');
  var lowOnly = false;
  function applyRows() {
    var q = filter ? (filter.value || '').trim().toLowerCase() : '';
    var shown = 0;
    rows.forEach(function (row) {
      var match = (q === '' || row.getAttribute('data-search').indexOf(q) !== -1)
        && (!lowOnly || row.classList.contains('is-low'));
      row.hidden = !match;
      if (match) shown++;
    });
    if (empty) empty.hidden = shown !== 0 || rows.length === 0;
    Array.prototype.forEach.call(root.querySelectorAll('[data-wh-low]'), function (b) { b.setAttribute('aria-pressed', lowOnly ? 'true' : 'false'); });
  }
  if (filter) filter.addEventListener('input', applyRows);
  root.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-wh-low]')) { lowOnly = true; applyRows(); }
    if (e.target.closest && e.target.closest('[data-wh-all]')) { lowOnly = false; if (filter) filter.value = ''; applyRows(); }
  });
  applyRows();

  // ---- move stock form -------------------------------------------------------------------------------------------------
  var form = root.querySelector('[data-wh-transfer]');
  if (!form) return;
  var item = form.querySelector('[data-wh-item]');
  var from = form.querySelector('[data-wh-from]');
  var to = form.querySelector('[data-wh-to]');
  var qty = form.querySelector('[data-wh-qty]');
  var hint = form.querySelector('.wh-hint');
  var preview = form.querySelector('[data-wh-preview]');
  var submit = form.querySelector('[data-wh-submit]');

  function counts() {
    var opt = item.options[item.selectedIndex];
    try { return JSON.parse((opt && opt.getAttribute('data-counts')) || '{}'); } catch (e) { return {}; }
  }
  function nameOf(select) {
    var opt = select.options[select.selectedIndex];
    return opt ? opt.textContent : '';
  }
  function total() {
    var opt = item.options[item.selectedIndex];
    var m = opt ? /total (\d+)/.exec(opt.textContent) : null;
    return m ? Number(m[1]) : 0;
  }

  // "All" button: fills the quantity with everything the source holds.
  var all = document.createElement('button');
  all.type = 'button';
  all.className = 's-btn secondary sm wh-all';
  all.textContent = 'All';
  all.setAttribute('aria-label', 'Move everything that is available');
  qty.insertAdjacentElement('afterend', all);
  all.addEventListener('click', function () {
    var avail = counts()[from.value];
    qty.value = avail ? String(avail) : '';
    check();
  });

  // Swap button: exchanges From and To.
  var swap = document.createElement('button');
  swap.type = 'button';
  swap.className = 's-btn secondary sm wh-swap';
  swap.textContent = '⇄ Swap';
  swap.setAttribute('aria-label', 'Swap from and to');
  to.parentNode.insertAdjacentElement('afterend', swap);
  swap.addEventListener('click', function () {
    var a = from.value;
    from.value = to.value;
    to.value = a;
    check();
  });

  function check() {
    var n = Number(qty.value);
    var have = counts()[from.value];
    have = typeof have === 'number' ? have : 0;
    var problem = '';
    if (from.value === to.value) problem = 'Choose two different warehouses.';
    else if (!/^\d+$/.test(qty.value) || n < 1) problem = qty.value === '' ? 'Enter how many to move.' : 'Enter a whole number, 1 or more.';
    else if (n > have) problem = 'Only ' + have + ' available at ' + nameOf(from) + '.';
    qty.max = String(have);
    qty.setAttribute('aria-invalid', problem && qty.value !== '' ? 'true' : 'false');
    if (hint) hint.textContent = problem || (have ? 'Up to ' + have + ' available at ' + nameOf(from) + '.' : 'Nothing is available at ' + nameOf(from) + '.');
    if (submit) submit.disabled = !!problem;
    if (preview) {
      if (problem || !n) {
        preview.innerHTML = '<span class="muted">' + (problem ? problem.replace(/</g, '&lt;') : 'Pick an item and a quantity to see the result.') + '</span>';
      } else {
        var c = counts();
        var fromNow = (c[from.value] || 0) - n;
        var toNow = (c[to.value] || 0) + n;
        preview.innerHTML = '<strong>Move ' + n + '</strong> from ' + escape(nameOf(from)) + ' to ' + escape(nameOf(to)) + '.<br>'
          + '<span class="muted">' + escape(nameOf(from)) + ': ' + (c[from.value] || 0) + ' → ' + fromNow
          + ' · ' + escape(nameOf(to)) + ': ' + (c[to.value] || 0) + ' → ' + toNow + ' · Total stays ' + total() + '.</span>';
      }
    }
  }
  function escape(s) { return String(s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); }

  qty.addEventListener('input', function () { qty.value = qty.value.replace(/\D/g, '').slice(0, 6); check(); });
  [item, from, to].forEach(function (el) { el.addEventListener('change', check); });

  // Item picker: a searchable Select2 list when the library is loaded (the plain select works without it).
  // Select2 fires its change through jQuery, so the item also listens with jQuery.
  if (window.jQuery && jQuery.fn.select2) {
    jQuery(item).select2({ width: '100%', placeholder: 'Search an item…' }).on('change', check);
  }
  form.addEventListener('submit', function (e) { check(); if (submit && submit.disabled) e.preventDefault(); });
  check();
})();
