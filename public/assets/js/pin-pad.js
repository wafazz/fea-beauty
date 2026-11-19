// Shared iPhone-style 6-digit passcode popup (used by Superadmin > Modules and Superadmin > Store status).
// window.PinPad.open(title, sub, error) resolves with the 6 digits, or null when cancelled.
(function () {
  'use strict';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function open(title, sub, error) {
    return new Promise(function (resolve) {
      var overlay = el('div', 'pin-overlay');
      var panel = el('div', 'pin-panel');
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      panel.setAttribute('aria-label', title);
      panel.appendChild(el('h2', 'pin-title', title));
      panel.appendChild(el('p', 'pin-sub muted', sub));
      var msg = el('p', 'pin-error', error || '');
      panel.appendChild(msg);

      var dots = el('div', 'pin-dots');
      var dotEls = [];
      for (var i = 0; i < 6; i++) {
        var d = el('span', 'pin-dot');
        dotEls.push(d);
        dots.appendChild(d);
      }
      panel.appendChild(dots);

      var value = '';
      var keys = el('div', 'pin-keys');
      var done = false;
      function paint() {
        dotEls.forEach(function (d, i) { d.classList.toggle('on', i < value.length); });
      }
      function finish(result) {
        if (done) return;
        done = true;
        document.removeEventListener('keydown', onKey, true);
        overlay.remove();
        resolve(result);
      }
      function press(digit) {
        if (value.length >= 6) return;
        value += digit;
        paint();
        if (value.length === 6) setTimeout(function () { finish(value); }, 120);
      }
      function key(label, aria, fn, cls) {
        var b = el('button', 'pin-key' + (cls ? ' ' + cls : ''), label);
        b.type = 'button';
        if (aria) b.setAttribute('aria-label', aria);
        b.addEventListener('click', fn);
        keys.appendChild(b);
        return b;
      }
      ['1', '2', '3', '4', '5', '6', '7', '8', '9'].forEach(function (n) {
        key(n, n, function () { press(n); });
      });
      key('', '', null, 'ghost');
      key('0', '0', function () { press('0'); });
      key('⌫', 'Delete', function () { value = value.slice(0, -1); paint(); });
      panel.appendChild(keys);

      var cancel = el('button', 'pin-cancel', 'Cancel');
      cancel.type = 'button';
      cancel.addEventListener('click', function () { finish(null); });
      panel.appendChild(cancel);

      function onKey(e) {
        if (/^[0-9]$/.test(e.key)) { e.preventDefault(); press(e.key); }
        else if (e.key === 'Backspace') { e.preventDefault(); value = value.slice(0, -1); paint(); }
        else if (e.key === 'Escape') { e.preventDefault(); finish(null); }
      }
      document.addEventListener('keydown', onKey, true);

      overlay.appendChild(panel);
      document.body.appendChild(overlay);
      if (error) {
        panel.classList.add('is-error');
        setTimeout(function () { panel.classList.remove('is-error'); }, 450);
      }
      keys.querySelector('.pin-key').focus();
    });
  }

  window.PinPad = { open: open };
})();
