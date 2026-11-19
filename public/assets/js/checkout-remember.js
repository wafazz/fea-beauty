// Guest checkout: remember the buyer's contact details and up to 5 delivery addresses in THIS browser, only when the
// guest ticks the box. Nothing is saved until they tick it, and nothing is sent to the server: the details stay in
// localStorage on the device. Payment details are never stored. The server still validates everything on submit.
(function () {
  'use strict';

  var KEY = 'checkout-saved-v2';
  var OLD_KEY = 'checkout-details';      // first version: one flat set of details, converted once
  var MAX = 5;
  var CONTACT = ['name', 'email', 'phone'];
  var ADDRESS = ['line1', 'line2', 'city', 'state', 'postcode'];

  var form = document.querySelector('form.co-form');
  var box = document.querySelector('[data-remember-box]');
  if (!form || !box) return;

  var tick = document.getElementById('remember-details');
  var forget = document.getElementById('remember-forget');
  var labelWrap = document.getElementById('remember-label-wrap');
  var labelInput = document.getElementById('remember-label');
  var line1Field = field('line1');
  var addressStart = line1Field ? line1Field.closest('label') : null;

  var store = null;          // { contact: {name, email, phone}, addresses: [{id, label, used, line1, ...}] }
  var selectedId = null;     // the saved address currently picked in the chooser, if any
  var picker = null;         // wrapper for the chooser and its "remove" button

  function field(name) {
    return form.querySelector('[name="' + name + '"]');
  }

  function clean(value, max) {
    return String(value == null ? '' : value).trim().slice(0, max || 200);
  }

  function pick(keys) {
    var out = {};
    keys.forEach(function (name) {
      var el = field(name);
      out[name] = el ? clean(el.value) : '';
    });
    return out;
  }

  function newId() {
    return Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  }

  function defaultLabel(addr) {
    return clean(addr.line1, 30) || 'Address';
  }

  function sameAddress(a, b) {
    return ADDRESS.every(function (name) {
      return clean(a[name]).toLowerCase() === clean(b[name]).toLowerCase();
    });
  }

  function normalise(raw) {
    var out = { contact: {}, addresses: [] };
    if (raw && raw.contact && typeof raw.contact === 'object') out.contact = copy(raw.contact, CONTACT);
    if (raw && Array.isArray(raw.addresses)) {
      raw.addresses.forEach(function (e) {
        if (!e || typeof e !== 'object' || out.addresses.length >= MAX) return;
        var entry = copy(e, ADDRESS);
        entry.id = clean(e.id, 40) || newId();
        entry.label = clean(e.label, 30) || defaultLabel(entry);
        entry.used = Number(e.used) || 0;
        out.addresses.push(entry);
      });
    }
    return out;
  }

  function copy(src, keys) {
    var out = {};
    keys.forEach(function (name) { out[name] = clean(src[name]); });
    return out;
  }

  function load() {
    try {
      var raw = localStorage.getItem(KEY);
      if (raw) return normalise(JSON.parse(raw));
      var old = localStorage.getItem(OLD_KEY);
      if (old) {
        var o = JSON.parse(old);
        var converted = { contact: copy(o || {}, CONTACT), addresses: [] };
        if (o && clean(o.line1)) {
          var entry = copy(o, ADDRESS);
          entry.id = newId();
          entry.label = defaultLabel(entry);
          entry.used = Date.now();
          converted.addresses.push(entry);
        }
        save(converted);
        localStorage.removeItem(OLD_KEY);
        return converted;
      }
    } catch (e) { /* unreadable storage: treat as nothing saved */ }
    return null;
  }

  function save(data) {
    try { localStorage.setItem(KEY, JSON.stringify(data)); } catch (e) { /* storage full or blocked: nothing to do */ }
  }

  function clearAll() {
    store = null;
    selectedId = null;
    try { localStorage.removeItem(KEY); } catch (e) { /* nothing to do */ }
  }

  function sorted() {
    return store.addresses.slice().sort(function (a, b) { return b.used - a.used; });
  }

  function fillAddress(entry, overwrite) {
    ADDRESS.forEach(function (name) {
      var el = field(name);
      if (el && (overwrite || !el.value)) el.value = entry[name] || '';
    });
  }

  // Chooser: a dropdown of saved addresses above the address fields, plus a button to remove the one picked.
  function buildPicker() {
    if (picker) picker.remove();
    picker = null;
    if (!store || store.addresses.length === 0 || !addressStart) return;

    picker = document.createElement('div');
    picker.className = 'saved-address-pick';

    var label = document.createElement('label');
    label.textContent = 'Use a saved address ';
    var select = document.createElement('select');
    select.id = 'saved-address';
    var none = document.createElement('option');
    none.value = '';
    none.textContent = 'New address';
    select.appendChild(none);
    sorted().forEach(function (e) {
      var opt = document.createElement('option');
      opt.value = e.id;
      opt.textContent = e.label + ' · ' + e.line1 + ', ' + e.city;
      if (e.id === selectedId) opt.selected = true;
      select.appendChild(opt);
    });
    label.appendChild(select);
    picker.appendChild(label);

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'link-btn';
    remove.textContent = 'Remove this address';
    remove.hidden = !selectedId;
    picker.appendChild(remove);

    select.addEventListener('change', function () {
      selectedId = select.value || null;
      var entry = selectedId ? find(selectedId) : null;
      if (entry) {
        fillAddress(entry, true);
        labelInput.value = entry.label;
      } else {
        labelInput.value = '';
      }
      remove.hidden = !selectedId;
    });

    remove.addEventListener('click', function () {
      store.addresses = store.addresses.filter(function (e) { return e.id !== selectedId; });
      save(store);
      selectedId = null;
      labelInput.value = '';
      buildPicker();
    });

    addressStart.parentNode.insertBefore(picker, addressStart);
  }

  function find(id) {
    for (var i = 0; i < store.addresses.length; i++) {
      if (store.addresses[i].id === id) return store.addresses[i];
    }
    return null;
  }

  function showLabelBox(show) {
    labelWrap.hidden = !show;
  }

  // ---- start up ----
  store = load();
  if (store) {
    // Prefill only empty fields, so typed values or a validation error sent back are never overwritten.
    CONTACT.forEach(function (name) {
      var el = field(name);
      if (el && !el.value && store.contact[name]) el.value = store.contact[name];
    });
    var newest = sorted()[0];
    if (newest) {
      selectedId = newest.id;
      fillAddress(newest, false);
      labelInput.value = newest.label;
    }
    tick.checked = true;
    forget.hidden = false;
    buildPicker();
  }
  showLabelBox(tick.checked);

  tick.addEventListener('change', function () { showLabelBox(tick.checked); });

  forget.addEventListener('click', function () {
    clearAll();
    tick.checked = false;
    forget.hidden = true;
    showLabelBox(false);
    buildPicker();
  });

  // ---- on submit ----
  form.addEventListener('submit', function () {
    if (!tick.checked) {
      clearAll();          // unticked: remove anything saved earlier
      return;
    }
    var contact = pick(CONTACT);
    var addr = pick(ADDRESS);
    store = store || { contact: {}, addresses: [] };
    store.contact = contact;

    if (!addr.line1 || !addr.city || !addr.postcode) {
      save(store);         // incomplete address: keep the contact, the server will ask for the rest
      return;
    }

    var now = Date.now();
    var typedLabel = clean(labelInput.value, 30);
    // A small edit (city, unit, state) to the picked address updates it. Changing the street or postcode means a new
    // address, so the old one is kept.
    var target = selectedId ? find(selectedId) : null;
    if (target && (clean(target.line1).toLowerCase() !== addr.line1.toLowerCase() || clean(target.postcode) !== addr.postcode)) {
      target = null;
    }
    if (!target) {
      for (var i = 0; i < store.addresses.length; i++) {
        if (sameAddress(store.addresses[i], addr)) { target = store.addresses[i]; break; }
      }
    }

    if (target) {
      // An address already saved (or the one picked, now edited): update it in place.
      ADDRESS.forEach(function (name) { target[name] = addr[name]; });
      target.label = typedLabel || target.label || defaultLabel(addr);
      target.used = now;
    } else {
      if (store.addresses.length >= MAX) {
        // Replace the least recently used address so the list stays at MAX.
        var oldest = store.addresses.reduce(function (a, b) { return b.used < a.used ? b : a; });
        store.addresses = store.addresses.filter(function (e) { return e !== oldest; });
      }
      var entry = copy(addr, ADDRESS);
      entry.id = newId();
      entry.label = typedLabel || defaultLabel(addr);
      entry.used = now;
      store.addresses.push(entry);
    }
    save(store);
  });
})();
