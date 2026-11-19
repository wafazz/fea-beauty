// New-order alerts for admins. Two parts:
//  1. In-browser: checks a small JSON endpoint every few seconds; a new order plays a chime and shows a notification
//     while the admin page is open.
//  2. Web push: subscribes this browser so the alert also arrives when the site is closed (service worker in /sw.js).
// The server still checks everything (login, role, CSRF on the subscribe calls).
(function () {
  'use strict';

  var me = document.currentScript;
  if (!me) return;
  var countsUrl = me.getAttribute('data-counts');
  var subscribeUrl = me.getAttribute('data-subscribe');
  var unsubscribeUrl = me.getAttribute('data-unsubscribe');
  var swUrl = me.getAttribute('data-sw');
  var vapidKey = me.getAttribute('data-vapid') || '';
  if (!countsUrl) return;

  var POLL_MS = 5000;
  var seen = null;                       // newest order id already handled (null until the first answer)
  var dismissedKey = 'order-alerts-dismissed';

  var csrfToken = me.getAttribute('data-token') || '';
  function csrf() {
    return csrfToken;
  }

  // Alert sound: a short two-tone chime made in the browser. Browsers only allow sound after a click or key press.
  var audioCtx = null;
  function unlockAudio() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') audioCtx.resume();
    } catch (e) { audioCtx = null; }
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, unlockAudio, { passive: true });
  });
  function tone(freq, start, length) {
    var osc = audioCtx.createOscillator();
    var gain = audioCtx.createGain();
    osc.type = 'sine';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.0001, audioCtx.currentTime + start);
    gain.gain.exponentialRampToValueAtTime(0.25, audioCtx.currentTime + start + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + start + length);
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start(audioCtx.currentTime + start);
    osc.stop(audioCtx.currentTime + start + length + 0.02);
  }
  function chime() {
    if (!audioCtx || audioCtx.state !== 'running') return;
    tone(880, 0, 0.18);
    tone(1175, 0.2, 0.25);
  }

  function notify(d) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    var n = new Notification(d.latest_title || 'New order', {
      body: 'Open the order to see it.',
      tag: 'order-alert',
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
        if (seen !== null && d.latest_id > seen) {
          chime();
          notify(d);
        }
        seen = d.latest_id;
      })
      .catch(function () { /* offline: try again on the next tick */ });
  }

  // ---- Web push ----

  function b64uToBytes(text) {
    var pad = '='.repeat((4 - (text.length % 4)) % 4);
    var raw = atob(text.replace(/-/g, '+').replace(/_/g, '/') + pad);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  function bytesToB64u(buffer) {
    var bytes = new Uint8Array(buffer);
    var bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function postForm(url, fields) {
    var body = new URLSearchParams(fields);
    body.append('_token', csrf());
    return fetch(url, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: body
    });
  }

  // Registers the service worker and subscribes this browser. Safe to run on every page load (the server upserts).
  function subscribePush() {
    if (!vapidKey || !swUrl || !('serviceWorker' in navigator) || !('PushManager' in window)) return;
    navigator.serviceWorker.register(swUrl).then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        return sub || reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: b64uToBytes(vapidKey)
        });
      });
    }).then(function (sub) {
      if (!sub) return;
      return postForm(subscribeUrl, {
        endpoint: sub.endpoint,
        p256dh: bytesToB64u(sub.getKey('p256dh')),
        auth: bytesToB64u(sub.getKey('auth'))
      });
    }).catch(function () { /* push not available here: in-browser alerts still work */ });
  }

  // A small box asks for permission (browsers only show the prompt after a click).
  function offerAlerts() {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'default' || localStorage.getItem(dismissedKey)) return;
    var box = document.createElement('div');
    box.setAttribute('role', 'region');
    box.setAttribute('aria-label', 'Order alerts');
    box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:50;background:#fff;color:#111827;border:1px solid #d1d5db;border-radius:12px;padding:10px 12px;box-shadow:0 8px 24px rgba(0,0,0,.15);font:14px/1.4 system-ui,sans-serif;display:flex;gap:8px;align-items:center;max-width:calc(100vw - 32px)';
    var text = document.createElement('span');
    text.textContent = 'Get an alert when a new order comes in?';
    var yes = document.createElement('button');
    yes.type = 'button';
    yes.textContent = 'Turn on';
    yes.style.cssText = 'background:#3b5bdb;color:#fff;border:0;border-radius:8px;padding:6px 10px;cursor:pointer';
    var no = document.createElement('button');
    no.type = 'button';
    no.textContent = 'Not now';
    no.style.cssText = 'background:none;border:0;color:#6b7280;cursor:pointer;padding:6px';
    yes.addEventListener('click', function () {
      Notification.requestPermission().then(function (p) {
        box.remove();
        if (p === 'granted') subscribePush();
      });
    });
    no.addEventListener('click', function () {
      localStorage.setItem(dismissedKey, '1');
      box.remove();
    });
    box.appendChild(text);
    box.appendChild(yes);
    box.appendChild(no);
    document.body.appendChild(box);
  }

  if ('Notification' in window && Notification.permission === 'granted') subscribePush();
  offerAlerts();
  poll();
  setInterval(poll, POLL_MS);
})();
