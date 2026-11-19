// Superadmin > Store status: the switch and the end-date form open the same iPhone-style PIN popup as Modules (pin-pad.js).
// Going offline asks first. A date is checked before the PIN is asked for. The server checks everything again.
(function () {
  'use strict';
  if (!window.PinPad) return;
  var forms = document.querySelectorAll('[data-store-form]');
  if (!forms.length) return;
  var busy = false;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function localNow() {
    var d = new Date();
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function confirmOffline() {
    var text = 'Shoppers, admins and staff will see the offline page until you switch it back on. Only the superadmin portal keeps working.';
    if (window.Confirm) return window.Confirm.ask({ title: 'Take the store offline?', text: text, button: 'Set offline', danger: true });
    return Promise.resolve(window.confirm('Take the store offline? ' + text));
  }

  /** The end date, if one is chosen, must be filled in and in the future. Returns an error message, or '' when fine. */
  function checkDate(form) {
    var mode = form.querySelector('input[name=expires_mode]:checked');
    if (!mode || mode.value !== 'date') return '';
    var at = form.querySelector('input[name=expires_at]');
    if (!at || !at.value) return 'Choose the date and time the store closes, or choose Unlimited.';
    if (at.value < localNow()) return 'The end time must be in the future.';
    return '';
  }

  function send(form, pin) {
    var fd = new FormData(form);
    fd.set('pin', pin);
    return fetch(form.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (r.status === 401 || d.login) { location.reload(); return { ok: false }; }
        if (r.ok && d.ok === true) return { ok: true, message: d.message };
        return { ok: false, pinWrong: !!d.pin, message: d.message || 'Could not save. Try again.' };
      });
    }, function () {
      return { ok: false, message: 'Network problem. Check your connection and try again.' };
    });
  }

  /** Asks for the PIN and retries after a wrong one, until it is right, locked, or cancelled. */
  function askPin(form, sub, error) {
    return window.PinPad.open('Enter your PIN', sub, error).then(function (pin) {
      if (pin === null) return null;
      return send(form, pin).then(function (res) {
        if (res.ok) return res;
        if (res.pinWrong) return askPin(form, sub, 'Wrong PIN. Try again.');
        if (window.Portal) window.Portal.toast(res.message, 'error');
        return null;
      });
    });
  }

  Array.prototype.forEach.call(forms, function (form) {
    var at = form.querySelector('input[name=expires_at]');
    if (at) at.min = localNow();

    // The date and time is only usable when "Until a date and time" is chosen.
    function syncDate() {
      if (!at) return;
      var mode = form.querySelector('input[name=expires_mode]:checked');
      at.disabled = !mode || mode.value !== 'date';
    }
    Array.prototype.forEach.call(form.querySelectorAll('input[name=expires_mode]'), function (radio) {
      radio.addEventListener('change', syncDate);
    });
    syncDate();

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;
      var dateError = checkDate(form);
      if (dateError) {
        if (window.Portal) window.Portal.toast(dateError, 'error');
        return;
      }
      var turningOff = form.elements.active && form.elements.active.value === '0';
      var go = function () {
        busy = true;
        var sub = turningOff ? 'Take the store offline'
          : form.elements.active ? 'Open the store' : 'Save the end date';
        askPin(form, sub, '').then(function (res) {
          busy = false;
          if (!res) return;
          if (window.Portal) window.Portal.toast(res.message, 'success');
          setTimeout(function () { location.reload(); }, 600);
        });
      };
      if (turningOff) {
        confirmOffline().then(function (ok) { if (ok) go(); });
      } else {
        go();
      }
    });
  });
})();
