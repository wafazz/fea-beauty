// Admin > Pickup points: search, expand/collapse, a postcode check as you type, and a live preview of how each point
// appears at checkout. Everything works without this script; the forms post as before.
(function () {
  'use strict';
  var root = document.querySelector('.pp-page');
  if (!root) return;

  // ---- search ---------------------------------------------------------------------------------------------------------
  var filter = root.querySelector('[data-pp-filter]');
  var items = Array.prototype.slice.call(root.querySelectorAll('.cc-item[data-search]'));
  var empty = root.querySelector('[data-pp-empty]');
  function applyFilter() {
    var q = (filter.value || '').trim().toLowerCase();
    var shown = 0;
    items.forEach(function (item) {
      var match = q === '' || item.getAttribute('data-search').indexOf(q) !== -1;
      item.hidden = !match;
      if (match) shown++;
    });
    if (empty) empty.hidden = shown !== 0 || items.length === 0;
  }
  if (filter) {
    filter.addEventListener('input', applyFilter);
    applyFilter();
  }

  // ---- expand / collapse all points (the add form is left alone) ----------------------------------------------------
  root.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-pp-expand]');
    if (!btn) return;
    var open = btn.getAttribute('data-pp-expand') === 'open';
    items.forEach(function (item) { item.open = open; });
  });

  // ---- per form: postcode check, live checkout preview, active label ------------------------------------------------
  Array.prototype.forEach.call(root.querySelectorAll('form'), function (form) {
    var postcode = form.querySelector('[data-pp-postcode]');
    var hint = postcode && postcode.closest('label').querySelector('.pp-hint');
    var name = form.querySelector('[data-pp-name]');
    var city = form.querySelector('[data-pp-city]');
    var state = form.querySelector('[data-pp-state]');
    var preview = form.querySelector('[data-pp-preview]');
    var active = form.querySelector('[data-pp-active]');
    var activeText = form.querySelector('.pp-active-text');

    function paintPostcode() {
      if (!postcode) return;
      postcode.value = postcode.value.replace(/\D/g, '').slice(0, 5); // digits only
      var ok = postcode.value.length === 5;
      postcode.setAttribute('aria-invalid', ok || postcode.value === '' ? 'false' : 'true');
      if (hint) {
        hint.textContent = ok ? 'Looks right' : (postcode.value === '' ? '5 digits' : (5 - postcode.value.length) + ' more digit(s)');
        hint.classList.toggle('pp-hint-ok', ok);
      }
    }
    function paintPreview() {
      if (!preview) return;
      var n = (name && name.value.trim()) || 'Name';
      var c = (city && city.value.trim()) || 'City';
      var s = (state && state.value) || 'State';
      preview.textContent = n + ' · ' + c + ', ' + s;
    }
    function paintActive() {
      if (activeText) activeText.textContent = active.checked ? 'Active: shown at checkout' : 'Hidden from checkout';
    }

    if (postcode) {
      postcode.addEventListener('input', paintPostcode);
      paintPostcode();
    }
    [name, city, state].forEach(function (el) {
      if (el) el.addEventListener('input', paintPreview);
      if (el) el.addEventListener('change', paintPreview);
    });
    if (active) active.addEventListener('change', paintActive);
    paintPreview();
    paintActive();
  });
})();
