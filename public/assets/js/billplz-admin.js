// Billplz settings page: show/hide the secret keys, a notice when the mode changes, and a copy button for the
// callback URL. Everything is progressive: without this script the page still works.
(function () {
  'use strict';

  // Show / hide for password fields (the saved keys are never shown; this only reveals what is typed now).
  document.querySelectorAll('.billplz-page input[type="password"]').forEach(function (input) {
    var wrap = document.createElement('span');
    wrap.className = 'secret-field';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'secret-toggle';
    btn.setAttribute('aria-pressed', 'false');
    btn.textContent = 'Show';
    btn.setAttribute('aria-label', 'Show ' + (input.getAttribute('name') || 'key').replace(/_/g, ' '));
    btn.addEventListener('click', function () {
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.textContent = showing ? 'Show' : 'Hide';
      btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
    });
    wrap.appendChild(btn);
  });

  // Mode notice: keys differ between sandbox and production, so say so as soon as the mode is changed.
  var mode = document.querySelector('[data-mode-select]');
  var notice = document.querySelector('[data-mode-notice]');
  if (mode && notice) {
    var initial = mode.getAttribute('data-initial');
    var sync = function () { notice.hidden = mode.value === initial; };
    mode.addEventListener('change', sync);
    sync();
  }

  // Copy the callback URL.
  var copyBtn = document.querySelector('[data-copy-button]');
  var source = document.querySelector('[data-copy-source]');
  var status = document.querySelector('[data-copy-status]');
  if (copyBtn && source) {
    // execCommand copies synchronously, so the result is known at once (the async clipboard API can stay pending).
    copyBtn.addEventListener('click', function () {
      source.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      if (status) status.textContent = ok ? 'Copied.' : 'Could not copy. Select the address and copy it by hand.';
    });
  }
})();
