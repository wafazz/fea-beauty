// Support workspace: filter chips, search and a quiet list refresh for the ticket list beside the chat.
// Rows carry data-flags (space-separated) such as "unread waiting urgent"; a chip shows rows that have its flag.
// The list is refreshed by fetching the same page and swapping only the rows, so the chat is never touched.
(function () {
  'use strict';

  var REFRESH_MS = 20000;

  function setup(root) {
    var items = root.querySelector('[data-ws-items]');
    if (!items) return;
    var search = root.querySelector('[data-ws-search]');
    var chips = root.querySelectorAll('[data-ws-filter]');
    var none = root.querySelector('[data-ws-none]');
    var filter = 'all';

    function matches(row, q) {
      var flags = (row.getAttribute('data-flags') || '').split(' ');
      var okFlag = filter === 'all' || flags.indexOf(filter) !== -1;
      var okText = q === '' || row.textContent.toLowerCase().indexOf(q) !== -1;
      return okFlag && okText;
    }

    function apply() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var shown = 0;
      items.querySelectorAll('[data-ws-row]').forEach(function (row) {
        var ok = matches(row, q);
        row.hidden = !ok;
        if (ok) shown++;
      });
      if (none) none.hidden = shown !== 0;
    }

    function refreshCounts() {
      chips.forEach(function (chip) {
        var key = chip.getAttribute('data-ws-filter');
        var count = key === 'all'
          ? items.querySelectorAll('[data-ws-row]').length
          : items.querySelectorAll('[data-ws-row][data-flags~="' + key + '"]').length;
        var badge = chip.querySelector('.ws-count');
        if (badge) badge.textContent = String(count);
      });
    }

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        filter = chip.getAttribute('data-ws-filter');
        chips.forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
        apply();
      });
    });
    if (search) search.addEventListener('input', apply);

    function busy() {
      // Do not swap rows while the user is picking tickets in bulk, or typing a search.
      if (document.hidden) return true;
      if (root.querySelector('input[type="checkbox"]:checked')) return true;
      return search && document.activeElement === search;
    }

    function refresh() {
      if (busy()) return;
      var top = items.scrollTop;
      fetch(window.location.href, { headers: { 'Accept': 'text/html' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) {
          if (!html) return;
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var fresh = doc.querySelector('[data-ws] [data-ws-items]');
          if (!fresh) return;
          items.innerHTML = fresh.innerHTML;
          items.scrollTop = top;
          apply();
          refreshCounts();
        })
        .catch(function () { /* offline: keep what is shown */ });
    }

    refreshCounts();
    apply();
    setInterval(refresh, REFRESH_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
  }

  document.querySelectorAll('[data-ws]').forEach(setup);
})();
