// Support alerts in the portal: a live count on the Support menu item, and a browser notification when a new
// message arrives while the page is open. Checks a small JSON endpoint every few seconds; no page reload.
(function () {
  'use strict';

  var me = document.currentScript;
  if (!me) return;
  var countsUrl = me.getAttribute('data-counts');
  var linkPath = me.getAttribute('data-link');
  if (!countsUrl || !linkPath) return;

  var POLL_MS = 5000;
  var seen = null;          // newest message id already handled (null until the first answer)
  var dismissedKey = 'support-alerts-dismissed';

  function menuLink() {
    var links = document.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      var href = links[i].getAttribute('href') || '';
      if (href === linkPath || href.slice(-linkPath.length) === linkPath) return links[i];
    }
    return null;
  }

  function setBadge(count) {
    var link = menuLink();
    if (!link) return;
    var pill = link.querySelector('.pill');
    if (!(count > 0)) {
      if (pill) pill.remove();
      return;
    }
    if (!pill) {
      pill = document.createElement('span');
      pill.className = 'pill';
      link.appendChild(pill);
    }
    pill.textContent = count > 99 ? '99+' : String(count);
    pill.setAttribute('aria-label', count + ' waiting');
  }

  function notify(d) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    var n = new Notification(d.latest_title || 'Support', {
      body: 'Open the support page to read it.',
      tag: 'support-alert',
      renotify: true
    });
    n.onclick = function () {
      window.focus();
      if (d.latest_url) window.location.href = d.latest_url;
      n.close();
    };
  }

  function poll() {
    fetch(countsUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        setBadge(d.count);
        if (seen !== null && d.latest_id > seen) notify(d);
        seen = d.latest_id;
      })
      .catch(function () { /* offline: try again on the next tick */ });
  }

  // A small button asks for permission (browsers only allow the prompt after a click).
  function offerAlerts() {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'default' || localStorage.getItem(dismissedKey)) return;
    var box = document.createElement('div');
    box.setAttribute('role', 'region');
    box.setAttribute('aria-label', 'Support alerts');
    box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:50;background:#fff;color:#111827;border:1px solid #d1d5db;border-radius:12px;padding:10px 12px;box-shadow:0 8px 24px rgba(0,0,0,.15);font:14px/1.4 system-ui,sans-serif;display:flex;gap:8px;align-items:center;max-width:calc(100vw - 32px)';
    var text = document.createElement('span');
    text.textContent = 'Get a notice when a support message arrives?';
    var yes = document.createElement('button');
    yes.type = 'button';
    yes.textContent = 'Turn on';
    yes.style.cssText = 'min-height:36px;padding:6px 12px;border-radius:8px;border:0;background:#2563eb;color:#fff;cursor:pointer;font:inherit';
    var no = document.createElement('button');
    no.type = 'button';
    no.textContent = 'Not now';
    no.style.cssText = 'min-height:36px;padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;background:none;color:inherit;cursor:pointer;font:inherit';
    function done() { box.remove(); }
    yes.addEventListener('click', function () {
      Notification.requestPermission().then(function () { done(); });
    });
    no.addEventListener('click', function () {
      try { localStorage.setItem(dismissedKey, '1'); } catch (e) { /* private mode: just hide it */ }
      done();
    });
    box.appendChild(text);
    box.appendChild(yes);
    box.appendChild(no);
    document.body.appendChild(box);
  }

  poll();
  setInterval(poll, POLL_MS);
  offerAlerts();
})();
