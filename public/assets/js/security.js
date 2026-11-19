// Superadmin > Security: "Change PIN" asks with the iPhone-style popup (pin-pad.js). The current PIN is checked on the
// server before the new PIN is asked for, and a wrong one asks again. The server checks everything again on submit.
// Without JavaScript the fields show.
(function () {
  'use strict';
  var form = document.querySelector('[data-pin-change-form]');
  var button = document.querySelector('[data-pin-change]');
  if (!form || !button || !window.PinPad) return;
  var checkUrl = form.dataset.checkUrl;

  function toast(message, kind) {
    if (window.Portal) window.Portal.toast(message, kind);
  }

  /** Sends the current PIN to the server. Resolves { ok, wrong, locked }. */
  function checkCurrent(current) {
    var fd = new FormData(form);
    fd.set('current', current);
    return fetch(checkUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (r.status === 401 || d.login) { location.reload(); return { ok: false, locked: true }; }
        if (d.ok === true) return { ok: true };
        return { ok: false, wrong: !!(d.message && !d.locked), locked: !!d.locked, message: d.message };
      });
    }, function () {
      return { ok: false, message: 'Network problem. Check your connection and try again.' };
    });
  }

  /** Current PIN, checked on the server; asks again after a wrong one. Resolves the PIN, or null when cancelled or locked. */
  function askCurrent(error) {
    return window.PinPad.open('Current PIN', 'Enter your current 6-digit PIN', error).then(function (current) {
      if (current === null) return null;
      return checkCurrent(current).then(function (res) {
        if (res.ok) return current;
        if (res.locked) { toast(res.message || 'Too many wrong PINs. Try again in 15 minutes.', 'error'); return null; }
        if (res.wrong) return askCurrent('Wrong PIN. Try again.');
        toast(res.message || 'Could not check the PIN. Try again.', 'error');
        return null;
      });
    });
  }

  /** New PIN, then its repeat. Restarts on a mismatch. Resolves { pin, confirm } or null. */
  function askNew(error) {
    return window.PinPad.open('New PIN', 'Enter a new 6-digit PIN', error).then(function (pin) {
      if (pin === null) return null;
      return window.PinPad.open('Confirm new PIN', 'Enter the new PIN again', '').then(function (confirm) {
        if (confirm === null) return null;
        if (pin !== confirm) return askNew('The PINs did not match. Start again.');
        return { pin: pin, confirm: confirm };
      });
    });
  }

  function addField(name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }

  button.addEventListener('click', function () {
    askCurrent('').then(function (current) {
      if (current === null) return null;
      return askNew('').then(function (res) {
        if (!res) return;
        addField('current', current);
        addField('pin', res.pin);
        addField('confirm', res.confirm);
        form.submit();
      });
    });
  });
})();
