<?php
/** Expects $categories, $orders, $subscriptions (active only), $urgencies. */
use App\Services\SupportInboxService;
use App\Services\SupportAttachmentService;
use App\Services\SupportTicketService;

$selCat = (int) old('category_id', '0');
$selUrg = (string) old('urgency', 'normal');
$selOrder = (int) old('order_id', '0');
$selSub = (int) old('subscription_id', '0');
$subjectMax = SupportTicketService::SUBJECT_MAX;
$bodyMax = \App\Services\SupportService::MAX_CHARS;
$fileMax = SupportAttachmentService::MAX_BYTES;
$filesMax = SupportAttachmentService::MAX_PER_MESSAGE;
$targets = [
    'low' => 'No set target',
    'normal' => 'We aim to reply within ' . SupportInboxService::SLA_HOURS['normal'] . ' hours',
    'high' => 'We aim to reply within ' . SupportInboxService::SLA_HOURS['high'] . ' hours',
    'urgent' => 'We aim to reply within ' . SupportInboxService::SLA_HOURS['urgent'] . ' hours',
];
$hints = [
    'low' => 'A question, no rush',
    'normal' => 'Standard request',
    'high' => 'Affects an order or payment soon',
    'urgent' => 'Needs an answer today',
];
?>
<style>
  .sn { max-width: 760px; margin: 0 auto; display: grid; gap: 14px; padding-bottom: 96px; }
  .sn-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: end; gap: 10px; }
  .sn-head h1 { margin: 0; }
  .sn-card { background: var(--card, #fff); border: 1px solid var(--line, #d1d7db); border-radius: 14px; padding: 16px; display: grid; gap: 12px; }
  .sn-card h2 { margin: 0; font-size: 1rem; display: flex; align-items: center; gap: 8px; }
  .sn-step { width: 24px; height: 24px; border-radius: 50%; display: inline-grid; place-items: center; background: var(--accent, #2563eb); color: #fff; font-size: .8rem; flex: none; }
  .sn-muted { color: var(--muted, #667781); font-size: .85rem; margin: 0; }
  .sn-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px; }
  .sn-opt { position: relative; border: 1px solid var(--line, #d1d7db); border-radius: 12px; padding: 10px 12px; cursor: pointer; display: grid; gap: 2px; transition: border-color .15s, box-shadow .15s, background .15s; }
  .sn-opt input { position: absolute; opacity: 0; pointer-events: none; }
  .sn-opt strong { font-size: .95rem; }
  .sn-opt small { color: var(--muted, #667781); font-size: .78rem; }
  .sn-opt:hover { border-color: var(--accent, #2563eb); }
  .sn-opt:has(input:checked) { border-color: var(--accent, #2563eb); box-shadow: 0 0 0 2px rgba(37, 99, 235, .25); background: rgba(37, 99, 235, .06); }
  .sn-opt:has(input:focus-visible) { outline: 2px solid var(--accent, #2563eb); outline-offset: 2px; }
  .sn-field { display: grid; gap: 6px; }
  .sn-field label, .sn-field .sn-label { font-weight: 600; font-size: .9rem; }
  .sn-field input, .sn-field select, .sn-field textarea { width: 100%; font: inherit; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line, #d1d7db); background: transparent; color: inherit; box-sizing: border-box; }
  .sn-field textarea { min-height: 120px; resize: vertical; }
  .sn-field input:focus, .sn-field select:focus, .sn-field textarea:focus { outline: 2px solid var(--accent, #2563eb); outline-offset: 1px; }
  .sn-field.is-invalid input, .sn-field.is-invalid select, .sn-field.is-invalid textarea { border-color: #d92d20; }
  .sn-err { color: #d92d20; font-size: .85rem; min-height: 0; }
  .sn-err:empty { display: none; }
  .sn-counter { font-size: .78rem; color: var(--muted, #667781); text-align: right; }
  .sn-counter.near { color: #dc6803; }
  .sn-counter.over { color: #d92d20; font-weight: 600; }
  .sn-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .sn-drop { border: 2px dashed var(--line, #d1d7db); border-radius: 14px; padding: 18px; text-align: center; cursor: pointer; display: grid; gap: 4px; transition: border-color .15s, background .15s; }
  .sn-drop.drag { border-color: var(--accent, #2563eb); background: rgba(37, 99, 235, .06); }
  .sn-drop input { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
  .sn-drop:focus-within { outline: 2px solid var(--accent, #2563eb); outline-offset: 2px; }
  .sn-chips { display: grid; gap: 6px; }
  .sn-chip { display: flex; align-items: center; justify-content: space-between; gap: 10px; border: 1px solid var(--line, #d1d7db); border-radius: 10px; padding: 8px 10px; font-size: .9rem; min-width: 0; }
  .sn-chip span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
  .sn-chip button { border: 0; background: none; color: #d92d20; cursor: pointer; font: inherit; padding: 4px 6px; flex: none; }
  .sn-bar { position: sticky; bottom: 0; background: var(--card, #fff); border-top: 1px solid var(--line, #d1d7db); padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; gap: 10px; margin: 0 -16px -16px; border-radius: 0 0 14px 14px; }
  .sn-bar .sn-muted { margin: 0; }
  .sn-submit { min-width: 160px; }
  .sn-submit[disabled] { opacity: .6; cursor: progress; }
  .sn-alert { border: 1px solid #d92d20; color: #d92d20; border-radius: 10px; padding: 10px 12px; font-size: .9rem; }
  @media (max-width: 640px) {
    .sn-row2 { grid-template-columns: 1fr; }
    .sn-bar { flex-direction: column; align-items: stretch; }
    .sn-submit { width: 100%; }
  }
  @media (prefers-reduced-motion: reduce) { .sn * { transition: none !important; } }
</style>

<div class="msg-page">
<form method="post" action="<?= e(url('/member/support')) ?>" enctype="multipart/form-data" class="sn" id="sn-form" novalidate>
    <?= csrf_field() ?>

    <div class="sn-head">
        <div>
            <h1>New support ticket</h1>
            <p class="sn-muted">Tell us what happened. We reply in your account and by email.</p>
        </div>
        <a class="s-btn secondary" href="<?= e(url('/member/support')) ?>">Back to tickets</a>
    </div>

    <?php if (field_error("form") !== ""): ?>
        <div class="sn-alert" role="alert"><?= field_error("form") ?></div>
    <?php endif; ?>

    <section class="sn-card" aria-labelledby="sn-s1">
        <h2 id="sn-s1"><span class="sn-step">1</span> What is it about?</h2>
        <div class="sn-field<?= field_error('category_id') ? ' is-invalid' : '' ?>" data-field="category_id">
            <label for="sn-category">Topic</label>
            <select name="category_id" id="sn-category" required>
                <option value="">Choose a topic…</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $selCat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="sn-err" data-err-for="category_id"><?= field_error('category_id') ?></div>
        </div>
    </section>

    <section class="sn-card" aria-labelledby="sn-s2">
        <h2 id="sn-s2"><span class="sn-step">2</span> How urgent is it?</h2>
        <div class="sn-options" role="radiogroup" aria-label="Urgency">
            <?php foreach ($urgencies as $key => $label): ?>
            <label class="sn-opt">
                <input type="radio" name="urgency" value="<?= e($key) ?>" <?= $selUrg === $key ? 'checked' : '' ?>>
                <strong><?= e($label) ?></strong>
                <small><?= e($hints[$key] ?? '') ?></small>
                <small><?= e($targets[$key] ?? '') ?></small>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="sn-err" data-err-for="urgency"><?= field_error('urgency') ?></div>
    </section>

    <section class="sn-card" aria-labelledby="sn-s3">
        <h2 id="sn-s3"><span class="sn-step">3</span> Tell us more</h2>
        <div class="sn-field<?= field_error('subject') ? ' is-invalid' : '' ?>" data-field="subject">
            <label for="sn-subject">Subject</label>
            <input name="subject" id="sn-subject" maxlength="<?= $subjectMax ?>" value="<?= old('subject') ?>" required autocomplete="off" placeholder="For example: parcel not received">
            <div class="sn-counter" data-count-for="subject" aria-live="polite"></div>
            <div class="sn-err" data-err-for="subject"><?= field_error('subject') ?></div>
        </div>
        <div class="sn-field<?= field_error('body') ? ' is-invalid' : '' ?>" data-field="body">
            <label for="sn-body">Message</label>
            <textarea name="body" id="sn-body" maxlength="<?= $bodyMax ?>" required placeholder="Include dates, order details or what you have already tried."><?= old('body') ?></textarea>
            <div class="sn-counter" data-count-for="body" aria-live="polite"></div>
            <div class="sn-err" data-err-for="body"><?= field_error('body') ?></div>
        </div>
    </section>

    <section class="sn-card" aria-labelledby="sn-s4">
        <h2 id="sn-s4"><span class="sn-step">4</span> Link it to an order (optional)</h2>
        <div class="sn-row2">
            <div class="sn-field">
                <label for="sn-order">Order</label>
                <select name="order_id" id="sn-order">
                    <option value="0">No order</option>
                    <?php foreach ($orders as $o): ?>
                        <option value="<?= (int) $o['id'] ?>" <?= $selOrder === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['order_no']) ?> · <?= e(date('j M Y', strtotime((string) $o['created_at']))) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="sn-err" data-err-for="order_id"><?= field_error('order_id') ?></div>
            </div>
            <?php if ($subscriptions !== []): ?>
            <div class="sn-field">
                <label for="sn-sub">Subscription (active only)</label>
                <select name="subscription_id" id="sn-sub">
                    <option value="0">No subscription</option>
                    <?php foreach ($subscriptions as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $selSub === (int) $s['id'] ? 'selected' : '' ?>>#<?= (int) $s['id'] ?> · <?= e($s['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="sn-err" data-err-for="subscription_id"><?= field_error('subscription_id') ?></div>
            </div>
            <?php endif; ?>
        </div>
        <p class="sn-muted" id="sn-link-note" aria-live="polite"></p>
    </section>

    <section class="sn-card" aria-labelledby="sn-s5">
        <h2 id="sn-s5"><span class="sn-step">5</span> Attachments (optional)</h2>
        <label class="sn-drop" id="sn-drop" for="sn-files">
            <strong>Tap to add photos or PDFs</strong>
            <span class="sn-muted">Up to <?= $filesMax ?> files, <?= (int) ($fileMax / 1048576) ?> MB each. JPG, PNG, WebP or PDF.</span>
            <input type="file" name="attachments[]" id="sn-files" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
        </label>
        <div class="sn-chips" id="sn-chips" aria-live="polite"></div>
        <div class="sn-err" data-err-for="attachments"><?= field_error('attachments') ?></div>
    </section>

    <div class="sn-bar">
        <p class="sn-muted" id="sn-summary">Fill in the topic, urgency, subject and message.</p>
        <button class="btn sn-submit" type="submit" id="sn-submit">Open ticket</button>
    </div>
</form>
</div>

<script>
(function () {
  'use strict';
  var form = document.getElementById('sn-form');
  if (!form) return;
  var MAX_FILES = <?= (int) $filesMax ?>;
  var MAX_BYTES = <?= (int) $fileMax ?>;
  var TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
  var SUBJECT_MAX = <?= (int) $subjectMax ?>;
  var BODY_MAX = <?= (int) $bodyMax ?>;
  var LINK_NOTES = {
    '0': '',
    order: 'Linked to this order: the team can see its status and payment.',
    sub: 'Linked to this subscription: only active subscriptions can be chosen.'
  };
  var files = document.getElementById('sn-files');
  var chips = document.getElementById('sn-chips');
  var drop = document.getElementById('sn-drop');
  var submit = document.getElementById('sn-submit');
  var summary = document.getElementById('sn-summary');
  var linkNote = document.getElementById('sn-link-note');
  var picked = []; // File objects, kept in sync with the input
  var touched = {}; // a field shows its error only after the user has been in it, or tried to send
  var submitted = false;

  function $(sel, root) { return (root || form).querySelector(sel); }
  function field(name) { return form.querySelector('[data-field="' + name + '"]'); }
  function setError(name, msg) {
    var f = field(name); var err = form.querySelector('[data-err-for="' + name + '"]');
    if (f) f.classList.toggle('is-invalid', !!msg);
    if (err) err.textContent = msg || '';
  }
  function fmtBytes(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  function counter(name, max) {
    var input = form.querySelector('[name="' + name + '"]');
    var out = form.querySelector('[data-count-for="' + name + '"]');
    function paint() {
      var n = input.value.length;
      out.textContent = n + ' / ' + max;
      out.classList.toggle('near', n > max * 0.9 && n <= max);
      out.classList.toggle('over', n > max);
    }
    input.addEventListener('input', function () { touched[name] = true; paint(); validateField(name); refresh(); });
    paint();
  }

  function validateField(name) {
    var input = form.querySelector('[name="' + name + '"]');
    var v = (input.value || '').trim();
    var msg = '';
    if (name === 'category_id' && !input.value) msg = 'Choose a topic.';
    if (name === 'subject') {
      if (v === '') msg = 'Write a short subject.';
      else if (input.value.length > SUBJECT_MAX) msg = 'Keep the subject under ' + SUBJECT_MAX + ' characters.';
    }
    if (name === 'body') {
      if (v === '') msg = 'Write a message so we know what to help with.';
      else if (input.value.length > BODY_MAX) msg = 'Keep the message under ' + BODY_MAX + ' characters.';
    }
    // Before the user has touched the field, leave any server-side error text in place.
    if ((name === 'category_id' || name === 'subject' || name === 'body') && (touched[name] || submitted)) setError(name, msg);
    return msg === '';
  }

  function validFiles() {
    var problems = [];
    if (picked.length > MAX_FILES) problems.push('Attach up to ' + MAX_FILES + ' files.');
    picked.forEach(function (f) {
      if (f.size > MAX_BYTES) problems.push('"' + f.name + '" is larger than ' + Math.round(MAX_BYTES / 1048576) + ' MB.');
      if (TYPES.indexOf(f.type) === -1 && !/\.(jpe?g|png|webp|pdf)$/i.test(f.name)) problems.push('"' + f.name + '" is not a JPG, PNG, WebP or PDF.');
    });
    return problems;
  }

  function renderChips() {
    chips.innerHTML = '';
    picked.forEach(function (f, i) {
      var row = document.createElement('div'); row.className = 'sn-chip';
      var name = document.createElement('span'); name.textContent = f.name + ' (' + fmtBytes(f.size) + ')';
      var rm = document.createElement('button'); rm.type = 'button'; rm.textContent = 'Remove'; rm.setAttribute('aria-label', 'Remove ' + f.name);
      rm.addEventListener('click', function () { removeFile(i); });
      row.appendChild(name); row.appendChild(rm); chips.appendChild(row);
    });
    var problems = validFiles();
    setError('attachments', problems[0] || '');
    drop.classList.toggle('is-invalid', problems.length > 0);
  }

  function syncInput() {
    // Keep the real input in step with the chips, so the form sends exactly what is shown.
    var dt = new DataTransfer();
    picked.forEach(function (f) { dt.items.add(f); });
    files.files = dt.files;
  }

  function addFiles(list) {
    Array.prototype.forEach.call(list, function (f) { picked.push(f); });
    syncInput(); renderChips(); refresh();
  }

  function removeFile(i) {
    picked.splice(i, 1); syncInput(); renderChips(); refresh();
  }

  files.addEventListener('change', function () {
    // Files chosen through the picker replace the list shown, so the chips always match the input.
    picked = Array.prototype.slice.call(files.files);
    renderChips(); refresh();
  });

  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
  });

  var linkSelect = $('#sn-order');
  var subSelect = $('#sn-sub');
  function updateLink() {
    var orderOn = linkSelect && linkSelect.value !== '0';
    var subOn = subSelect && subSelect.value !== '0';
    if (linkNote) linkNote.textContent = subOn ? LINK_NOTES.sub : (orderOn ? LINK_NOTES.order : '');
  }
  if (linkSelect) linkSelect.addEventListener('change', updateLink);
  if (subSelect) subSelect.addEventListener('change', updateLink);
  updateLink();

  function refresh() {
    var ok = true;
    ok = validateField('category_id') && ok;
    ok = validateField('subject') && ok;
    ok = validateField('body') && ok;
    var hasUrgency = !!form.querySelector('[name="urgency"]:checked');
    if (!hasUrgency && (submitted || touched.urgency)) setError('urgency', 'Choose how urgent this is.');
    if (hasUrgency) setError('urgency', '');
    ok = hasUrgency && ok;
    ok = validFiles().length === 0 && ok;
    var missing = [];
    if (!form.querySelector('[name="category_id"]').value) missing.push('topic');
    if (!hasUrgency) missing.push('urgency');
    if (!form.querySelector('[name="subject"]').value.trim()) missing.push('subject');
    if (!form.querySelector('[name="body"]').value.trim()) missing.push('message');
    summary.textContent = ok ? 'Ready to send. We will email you when we reply.' : 'Still needed: ' + (missing.join(', ') || 'fix the highlighted fields') + '.';
    submit.disabled = false; // errors are shown on submit; the button stays usable
    return ok;
  }

  form.addEventListener('change', function (e) {
    if (e.target && e.target.name) touched[e.target.name] = true;
    refresh();
  });
  form.addEventListener('input', refresh);
  // Leaving a field counts as having touched it, so its error can show.
  form.addEventListener('focusout', function (e) {
    if (e.target && e.target.name) touched[e.target.name] = true;
    refresh();
  });
  counter('subject', SUBJECT_MAX);
  counter('body', BODY_MAX);

  form.addEventListener('submit', function (e) {
    submitted = true;
    if (!refresh()) {
      e.preventDefault();
      var first = form.querySelector('.is-invalid input, .is-invalid select, .is-invalid textarea') || form.querySelector('[name="urgency"]');
      if (first) first.focus();
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Sending…';
  });

  refresh();
})();
</script>
