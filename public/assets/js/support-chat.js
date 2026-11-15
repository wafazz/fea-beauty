// Support chat: send replies and notes without reloading the page, and show new messages as they arrive.
// The server still checks everything (CSRF, ownership, sizes, types). Messages are added as text, never as HTML.
(function () {
  'use strict';

  // Attachment viewer: one popup shared by the chat. Images show as images; PDFs show in the popup.
  // A download button keeps the file one click away. (Ctrl/Cmd-click still opens a new tab.)
  var viewer = null;
  function openViewer(url, name, isImage) {
    if (!viewer) {
      viewer = document.createElement('dialog');
      viewer.className = 'ws-viewer';
      viewer.setAttribute('aria-label', 'Attachment');
      var head = document.createElement('div');
      head.className = 'ws-viewer-head';
      var title = document.createElement('strong');
      title.setAttribute('data-viewer-title', '');
      var actions = document.createElement('div');
      actions.className = 'ws-viewer-actions';
      var dl = document.createElement('a');
      dl.textContent = 'Download';
      dl.setAttribute('data-viewer-download', '');
      dl.setAttribute('download', '');
      var close = document.createElement('button');
      close.type = 'button';
      close.textContent = 'Close';
      close.addEventListener('click', function () { viewer.close(); });
      actions.appendChild(dl);
      actions.appendChild(close);
      head.appendChild(title);
      head.appendChild(actions);
      var body = document.createElement('div');
      body.className = 'ws-dialog-body';
      body.setAttribute('data-viewer-body', '');
      viewer.appendChild(head);
      viewer.appendChild(body);
      viewer.addEventListener('close', function () { body.innerHTML = ''; }); // stops a PDF still loading
      document.body.appendChild(viewer);
    }
    var sep = url.indexOf('?') === -1 ? '?' : '&';
    viewer.querySelector('[data-viewer-title]').textContent = name;
    viewer.querySelector('[data-viewer-download]').setAttribute('href', url);
    var body = viewer.querySelector('[data-viewer-body]');
    body.innerHTML = '';
    var stage = document.createElement('div');
    stage.className = 'ws-viewer-stage';
    if (isImage) {
      var img = document.createElement('img');
      img.src = url + sep + 'view=1';
      img.alt = name;
      stage.appendChild(img);
    } else {
      var frame = document.createElement('iframe');
      frame.src = url + sep + 'view=1';
      frame.title = name;
      stage.appendChild(frame);
    }
    body.appendChild(stage);
    viewer.showModal();
  }

  // Popups: a button with data-details-open opens the dialog with that id; a click on the dark backdrop closes it.
  document.addEventListener('click', function (e) {
    var att = e.target.closest ? e.target.closest('[data-attach-view]') : null;
    if (att && !(e.ctrlKey || e.metaKey || e.shiftKey)) {
      e.preventDefault();
      openViewer(att.getAttribute('href'), att.getAttribute('data-attach-view'), !!att.querySelector('img'));
      return;
    }
    var open = e.target.closest ? e.target.closest('[data-details-open]') : null;
    if (open) {
      var dlg = document.getElementById(open.getAttribute('data-details-open'));
      if (dlg && dlg.showModal) dlg.showModal();
      return;
    }
    if (e.target.tagName === 'DIALOG' && (e.target.classList.contains('ws-dialog') || e.target.classList.contains('ws-viewer'))) e.target.close();
  });

  var root = document.querySelector('[data-chat]');
  if (!root) return;

  var POLL_MS = 3000;          // new messages appear within about 3 seconds
  var MAX_FILES = 3;
  var MAX_BYTES = 5 * 1024 * 1024;
  var TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

  var list = root.querySelector('[data-chat-list]');
  var statusEl = root.querySelector('[data-status]');
  var liveEl = root.querySelector('[data-live]');
  var pollUrl = root.getAttribute('data-poll');
  var attachUrl = root.getAttribute('data-attach');
  var lastId = parseInt(root.getAttribute('data-last-id') || '0', 10);
  var labels = {};
  try { labels = JSON.parse(root.getAttribute('data-labels') || '{}'); } catch (e) { labels = {}; }

  var composer = root.querySelector('[data-composer]');
  var resolveForm = root.querySelector('[data-resolve]');
  var body = composer ? composer.querySelector('[data-body]') : null;
  var files = composer ? composer.querySelector('[data-files]') : null;
  var fileList = composer ? composer.querySelector('[data-file-list]') : null;
  var feedback = composer ? composer.querySelector('[data-feedback]') : null;
  var sendBtn = composer ? composer.querySelector('[data-send]') : null;
  var sending = false;
  var polling = false;
  var jump = null;
  var feedbackTimer = null;

  list.scrollTop = list.scrollHeight;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function fmtBytes(n) {
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  }

  function bottomNear() {
    return list.scrollHeight - list.scrollTop - list.clientHeight < 120;
  }

  function bubble(m, attachBase) {
    var internal = m.visibility === 'internal';
    var staffView = root.getAttribute('data-staff') === '1';
    var mine = staffView ? (m.sender === 'staff' && !internal) : m.sender === 'customer';
    var cls = internal ? 'int' : (mine ? 'me' : 'shop');
    var who = internal ? 'Internal note · ' + (m.staff_name || 'Staff')
      : (staffView ? (m.sender === 'customer' ? 'Customer' : (m.staff_name || 'Shop team'))
        : (m.sender === 'customer' ? '' : 'Shop'));
    var row = el('div', 'sc-row ' + cls);
    row.setAttribute('data-msg', String(m.id));
    var b = el('div', 'sc-bubble');
    if (!mine && who) b.appendChild(el('div', 'sc-pill sc-who', who));
    (m.attachments || []).forEach(function (a) {
      var url = attachBase.replace('__ID__', String(a.id));
      if (String(a.mime).indexOf('image/') === 0) {
        var link = el('a'); link.href = url; link.setAttribute('data-attach-view', a.original_name);
        var img = el('img', 'sc-img'); img.src = url; img.alt = a.original_name; link.appendChild(img);
        b.appendChild(link);
      } else {
        var isPdf = a.mime === 'application/pdf';
        var card = el('a', 'sc-file-card'); card.href = url; card.title = 'Tap to preview';
        card.setAttribute('data-attach-view', a.original_name);
        var ico = el('span', 'sc-file-ico', isPdf ? 'PDF' : 'FILE'); ico.setAttribute('aria-hidden', 'true');
        var meta = el('span', 'sc-file-meta');
        meta.appendChild(el('strong', null, a.original_name));
        var size = Number(a.size || 0);
        var sizeText = size >= 1048576 ? (size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(size / 1024)) + ' KB';
        meta.appendChild(el('small', null, (isPdf ? 'PDF' : 'File') + ' · ' + sizeText + ' · Tap to preview'));
        card.appendChild(ico); card.appendChild(meta); b.appendChild(card);
      }
    });
    if (m.body) b.appendChild(el('div', 'sc-text', m.body));
    b.appendChild(el('div', 'sc-time', String(m.created_at).substring(11, 16)));
    row.appendChild(b);
    return row;
  }

  function showJump(n) {
    if (!jump) {
      jump = el('button', 'sc-resolve', '');
      jump.type = 'button';
      jump.style.cssText = 'align-self:center;margin:6px auto;position:relative;z-index:1;background:var(--sc-shop, #fff)';
      jump.addEventListener('click', function () { list.scrollTop = list.scrollHeight; jump.hidden = true; });
      list.parentNode.insertBefore(jump, list.nextSibling);
    }
    jump.textContent = (n === 1 ? 'New message' : n + ' new messages') + ' ↓';
    jump.hidden = false;
  }

  list.addEventListener('scroll', function () {
    if (jump && bottomNear()) jump.hidden = true;
  });

  function appendMessages(messages) {
    var stick = bottomNear();
    var attachBase = attachUrl;
    var added = 0;
    messages.forEach(function (m) {
      var id = parseInt(m.id, 10);
      if (!id || id <= lastId || list.querySelector('[data-msg="' + id + '"]')) return;
      list.appendChild(bubble(m, attachBase));
      lastId = Math.max(lastId, id);
      added++;
    });
    if (!added) return;
    if (stick) list.scrollTop = list.scrollHeight; else showJump(added);
    // Tells the ticket list beside the chat to refresh (unread dots, order of tickets).
    document.dispatchEvent(new CustomEvent('support:new-messages', { detail: { count: added } }));
  }

  function setLive(on) {
    if (!liveEl) return;
    liveEl.classList.toggle('is-off', !on);
    liveEl.textContent = on ? 'Live' : 'Reconnecting…';
  }

  function setStatus(status) {
    if (!statusEl || !status || !labels[status]) return;
    if (statusEl.textContent !== labels[status]) statusEl.textContent = labels[status];
  }

  function poll() {
    if (document.hidden || polling || !pollUrl) return Promise.resolve();
    polling = true;
    var sep = pollUrl.indexOf('?') === -1 ? '?' : '&';
    return fetch(pollUrl + sep + 'after=' + lastId, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('poll'); return r.json(); })
      .then(function (d) {
        setLive(true);
        if (d.messages && d.messages.length) appendMessages(d.messages);
        if (d.status) setStatus(d.status);
      })
      .catch(function () { setLive(false); })
      .then(function () { polling = false; });
  }

  function setFeedback(text, isError) {
    if (!feedback) return;
    clearTimeout(feedbackTimer);
    feedback.textContent = text;
    feedback.className = 'sc-feedback ' + (isError ? 'is-error' : 'is-ok');
    if (!isError) feedbackTimer = setTimeout(function () { feedback.textContent = ''; }, 2500);
  }

  function renderFiles() {
    if (!files || !fileList) return null;
    fileList.innerHTML = '';
    var picked = Array.prototype.slice.call(files.files || []);
    picked.forEach(function (f) {
      fileList.appendChild(el('span', 'sc-pill', f.name + ' (' + fmtBytes(f.size) + ')'));
    });
    if (picked.length > MAX_FILES) return 'Attach up to ' + MAX_FILES + ' files.';
    for (var i = 0; i < picked.length; i++) {
      var f = picked[i];
      if (f.size > MAX_BYTES) return '"' + f.name + '" is larger than 5 MB.';
      if (TYPES.indexOf(f.type) === -1 && !/\.(jpe?g|png|webp|pdf)$/i.test(f.name)) return '"' + f.name + '" is not a JPG, PNG, WebP or PDF.';
    }
    return null;
  }

  // The server sometimes answers with an error page instead of a message (for example when the web server refuses
  // an upload that is too big). Say what most likely happened, so the customer knows what to do.
  function failureText(status, hadFiles) {
    if (status === 413) {
      return 'The server refused the upload because it is too big. Try smaller photos (about 1 MB each), or ask the shop to raise the upload limit.';
    }
    if (status === 419) {
      return hadFiles
        ? 'The send failed. The files may be too big for the server, or your session expired. Try smaller files, or reload the page.'
        : 'Your session has expired. Reload the page and try again.';
    }
    if (status >= 500) return 'The server had a problem. Please try again in a moment.';
    if (status === 0) return 'No connection. Your message is still in the box; try again.';
    return 'Could not send (error ' + status + '). Please try again.';
  }

  function clearFiles() {
    if (files) files.value = '';
    if (fileList) fileList.innerHTML = '';
  }

  function send(form, action) {
    if (sending || !form) return;
    var text = body ? body.value.trim() : '';
    var count = files && files.files ? files.files.length : 0;
    if (!text && count === 0) {
      setFeedback('Write a message or attach a file first.', true);
      if (body) body.focus();
      return;
    }
    var problem = renderFiles();
    if (problem) { setFeedback(problem, true); return; }

    sending = true;
    if (sendBtn) sendBtn.disabled = true;
    setFeedback('Sending…', false);
    fetch(action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (j) { return { ok: r.ok, status: r.status, json: j }; });
    }).then(function (res) {
      if (res.json && res.json.ok) {
        if (body) body.value = '';
        clearFiles();
        setFeedback(res.json.message || 'Sent.', false);
        return poll(); // shows our own message straight away
      }
      var msg = (res.json && res.json.message) || failureText(res.status, count > 0);
      setFeedback(msg, true);
    }).catch(function () {
      setFeedback('No connection. Your message is still in the box; try again.', true);
    }).then(function () {
      sending = false;
      if (sendBtn) sendBtn.disabled = false;
    });
  }

  if (composer) {
    composer.addEventListener('submit', function (e) {
      e.preventDefault();
      var submitter = e.submitter;
      var action = submitter && submitter.getAttribute('formaction') ? submitter.getAttribute('formaction') : composer.getAttribute('action');
      send(composer, action);
    });
    if (files) files.addEventListener('change', function () {
      var problem = renderFiles();
      setFeedback(problem || '', !!problem);
    });
    if (body) body.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        composer.requestSubmit ? composer.requestSubmit() : composer.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
      }
    });
  }

  if (resolveForm) {
    resolveForm.addEventListener('submit', function (e) {
      e.preventDefault();
      fetch(resolveForm.getAttribute('action'), {
        method: 'POST',
        body: new FormData(resolveForm),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      }).then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (j) {
          if (j && j.ok) {
            setStatus('resolved');
            resolveForm.remove();
            setFeedback(j.message || 'Marked as resolved.', false);
            document.dispatchEvent(new CustomEvent('support:new-messages', { detail: { count: 0 } }));
          } else {
            setFeedback((j && j.message) || 'Could not update the ticket. Try again.', true);
          }
        })
        .catch(function () { setFeedback('No connection. Try again.', true); });
    });
  }

  setInterval(poll, POLL_MS);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
