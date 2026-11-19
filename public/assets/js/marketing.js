// Admin > Marketing emails: a live preview with sample values, placeholder buttons, character counts, a plain-words
// delay, the audience size for a segment, an on/off switch, and Save only when something has changed.
// Without this script the forms work as before.
(function () {
  'use strict';
  var root = document.querySelector('.mk-page');
  if (!root) return;

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }
  // Replaces {placeholders} with the sample values; unknown ones are left as they are.
  function fill(text, sample) {
    return String(text || '').replace(/\{(\w+)\}/g, function (m, k) {
      return Object.prototype.hasOwnProperty.call(sample, k) ? sample[k] : m;
    });
  }
  function delayText(hours) {
    var h = parseInt(hours, 10);
    if (isNaN(h) || h < 0) return 'Set a delay in hours';
    if (h === 0) return 'Right away';
    if (h < 24) return 'After ' + h + ' hour' + (h === 1 ? '' : 's');
    var d = Math.round((h / 24) * 10) / 10;
    return 'After ' + d + ' day' + (d === 1 ? '' : 's');
  }

  Array.prototype.forEach.call(root.querySelectorAll('[data-mk-flow]'), function (flow) {
    var form = flow.querySelector('form');
    var sample = {};
    try { sample = JSON.parse(flow.querySelector('[data-mk-sample]').textContent || '{}'); } catch (e) { sample = {}; }
    var subject = form.querySelector('[data-mk-subject]');
    var body = form.querySelector('[data-mk-body]');
    var delay = form.querySelector('[data-mk-delay]');
    var segment = form.querySelector('[data-mk-segment]');
    var audience = form.querySelector('[data-mk-audience]');
    var save = form.querySelector('[data-mk-save]');
    var dirtyNote = form.querySelector('[data-mk-dirty]');
    var when = flow.querySelector('.mk-when');
    var toggle = flow.querySelector('[data-mk-enabled]');
    var toggleText = flow.querySelector('.mk-switch-text');
    var lastFocus = body;

    // ---- preview and counts ------------------------------------------------------------------------------------------
    function counts() {
      [subject, body].forEach(function (field) {
        if (!field) return;
        var out = form.querySelector('[data-mk-count-for="' + field.name + '"]');
        var max = Number(field.getAttribute('maxlength') || 0);
        if (out) out.textContent = field.value.length + (max ? ' / ' + max : '') + ' characters';
      });
    }
    function preview() {
      var ps = flow.querySelector('[data-mk-prev-subject]');
      var pb = flow.querySelector('[data-mk-prev-body]');
      if (ps && subject) ps.textContent = fill(subject.value, sample) || '(no subject)';
      if (pb && body) {
        // Line breaks are shown as breaks; the text itself is escaped.
        pb.innerHTML = escapeHtml(fill(body.value, sample)).replace(/\n/g, '<br>') || '<span class="muted">(empty message)</span>';
      }
    }

    // ---- unsaved changes -------------------------------------------------------------------------------------------------
    function snapshot() {
      var parts = [];
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name) return;
        parts.push(el.name + '=' + (el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value));
      });
      return parts.join('&');
    }
    var initial = snapshot();
    function markDirty() {
      var dirty = snapshot() !== initial;
      if (save) save.disabled = !dirty;
      if (dirtyNote) dirtyNote.hidden = !dirty;
      flow.classList.toggle('is-dirty', dirty);
    }

    // ---- placeholder buttons insert at the cursor of the field last used --------------------------------------------
    flow.addEventListener('click', function (e) {
      var chip = e.target.closest && e.target.closest('[data-mk-chip]');
      if (!chip) return;
      var field = lastFocus || body;
      var token = chip.getAttribute('data-mk-chip');
      var start = field.selectionStart != null ? field.selectionStart : field.value.length;
      var end = field.selectionEnd != null ? field.selectionEnd : start;
      field.value = field.value.slice(0, start) + token + field.value.slice(end);
      var pos = start + token.length;
      field.focus();
      if (field.setSelectionRange) field.setSelectionRange(pos, pos);
      field.dispatchEvent(new Event('input', { bubbles: true }));
    });
    [subject, body].forEach(function (field) {
      if (!field) return;
      field.addEventListener('focus', function () { lastFocus = field; });
      field.addEventListener('input', function () { counts(); preview(); markDirty(); });
    });

    // ---- delay in plain words and the segment audience ------------------------------------------------------------------
    if (delay) {
      delay.addEventListener('input', function () {
        delay.value = delay.value.replace(/\D/g, '').slice(0, 3);
        if (when) when.textContent = delayText(delay.value) + (segment ? ' · to a customer segment' : '');
        markDirty();
      });
    }
    function paintAudience() {
      if (!segment || !audience) return;
      var opt = segment.options[segment.selectedIndex];
      var n = opt && opt.getAttribute('data-count');
      audience.textContent = n === null || segment.value === '' ? 'Choose a segment to see who it reaches.' : 'Reaches about ' + n + ' customer' + (n === '1' ? '' : 's') + ' right now.';
    }
    if (segment) {
      segment.addEventListener('change', function () { paintAudience(); markDirty(); });
      paintAudience();
    }
    var repeat = form.querySelector('input[name=repeat_days]');
    if (repeat) repeat.addEventListener('input', markDirty);

    // ---- on / off switch -----------------------------------------------------------------------------------------------
    if (toggle) {
      toggle.addEventListener('change', function () {
        if (toggleText) toggleText.textContent = toggle.checked ? 'On' : 'Off';
        markDirty();
      });
    }

    // Warn before leaving the page with unsaved edits.
    form.addEventListener('submit', function () { initial = snapshot(); markDirty(); });
    window.addEventListener('beforeunload', function (e) {
      if (flow.classList.contains('is-dirty')) { e.preventDefault(); e.returnValue = ''; }
    });

    counts();
    preview();
    markDirty();
  });
})();
