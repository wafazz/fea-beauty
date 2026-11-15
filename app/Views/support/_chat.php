<?php
/**
 * Chat view for one ticket, for the customer (member and guest) and for staff. Expects:
 *   $ticket      row from the ticket services (ticket_no, subject, status, urgency, category_name, order_no, subscription_id)
 *   $messages    from SupportTicketService::messagesAfter() (staff: with $staffView = true, includes internal notes)
 *   $urls        post, resolve, poll, attach (attach contains __ID__). Staff also: note.
 *   $canReply    bool (customer). Staff always can reply.
 *   $staffView   bool, default false
 */
$staffView = $staffView ?? false;
$statusLabels = ['open' => 'Open', 'waiting_staff' => 'Waiting for the shop', 'waiting_customer' => 'Waiting for you', 'resolved' => 'Resolved', 'closed' => 'Closed'];
$urgencyLabels = \App\Services\SupportTicketService::URGENCIES;
$lastId = $messages === [] ? 0 : (int) end($messages)['id'];
$resolved = in_array($ticket['status'], ['resolved', 'closed'], true);
$showReply = $staffView ? true : $canReply;
?>
<style>
  .sc { --sc-bg: #f0f2f5; --sc-me: #d9fdd3; --sc-shop: #ffffff; --sc-int: #fff4c2; --sc-ink: #111b21; --sc-muted: #667781; --sc-line: #d1d7db; display: flex; flex-direction: column; min-height: 70vh; max-width: 760px; margin: 0 auto; background: var(--sc-bg); border: 1px solid var(--sc-line); border-radius: 12px; overflow: hidden; color: var(--sc-ink); }
  @media (prefers-color-scheme: dark) { .sc { --sc-bg: #0b141a; --sc-me: #005c4b; --sc-shop: #202c33; --sc-int: #4a4120; --sc-ink: #e9edef; --sc-muted: #8696a0; --sc-line: #2a3942; } }
  .sc.sc-wide { max-width: none; min-height: 75vh; }
  .sc-head { padding: 12px 16px; background: var(--sc-shop); border-bottom: 1px solid var(--sc-line); }
  .sc-head strong { font-size: 1rem; }
  .sc-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
  .sc-pill { font-size: .75rem; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--sc-line); color: var(--sc-muted); }
  .sc-u-urgent { border-color: #d92d20; color: #d92d20; }
  .sc-u-high { border-color: #dc6803; color: #dc6803; }
  .sc-list { flex: 1; overflow-y: auto; padding: 14px 12px; display: flex; flex-direction: column; gap: 6px; max-height: 60vh; }
  .sc-day { align-self: center; font-size: .75rem; color: var(--sc-muted); background: var(--sc-shop); padding: 3px 10px; border-radius: 8px; margin: 6px 0; }
  .sc-row { display: flex; }
  .sc-row.me { justify-content: flex-end; }
  .sc-row.shop { justify-content: flex-start; }
  .sc-row.int { justify-content: center; }
  .sc-bubble { max-width: 78%; padding: 6px 9px 4px; border-radius: 8px; background: var(--sc-shop); box-shadow: 0 1px 0 rgba(0,0,0,.08); }
  .sc-row.me .sc-bubble { background: var(--sc-me); }
  .sc-row.int .sc-bubble { background: var(--sc-int); max-width: 88%; font-size: .9rem; border: 1px dashed var(--sc-line); }
  .sc-who { font-size: .72rem; color: var(--sc-muted); margin-bottom: 2px; border: 0; padding: 0; }
  .sc-text { white-space: normal; word-wrap: break-word; overflow-wrap: anywhere; font-size: .95rem; line-height: 1.4; }
  .sc-time { font-size: .68rem; color: var(--sc-muted); text-align: right; margin-top: 2px; }
  .sc-img { display: block; max-width: 220px; max-height: 220px; border-radius: 6px; margin-bottom: 4px; }
  .sc-file { display: block; font-size: .9rem; margin-bottom: 4px; color: inherit; }
  .sc-composer { border-top: 1px solid var(--sc-line); background: var(--sc-shop); padding: 10px; display: grid; gap: 8px; }
  .sc-composer textarea { width: 100%; min-height: 56px; resize: vertical; border-radius: 8px; border: 1px solid var(--sc-line); padding: 8px; font: inherit; background: transparent; color: inherit; }
  .sc-composer .sc-row-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
  .sc-composer input[type=file] { font-size: .8rem; color: var(--sc-muted); max-width: 100%; }
  .sc-note { padding: 10px 14px; font-size: .85rem; color: var(--sc-muted); background: var(--sc-shop); border-top: 1px solid var(--sc-line); }
  .sc-resolve { background: none; border: 1px solid var(--sc-line); border-radius: 8px; padding: 6px 10px; color: inherit; cursor: pointer; }
  @media (max-width: 640px) { .sc-list { max-height: 55vh; } .sc-bubble { max-width: 88%; } }
</style>

<section class="sc<?= $staffView ? ' sc-wide' : '' ?>" data-chat data-staff="<?= $staffView ? '1' : '0' ?>" data-last-id="<?= $lastId ?>" data-poll="<?= e($urls['poll']) ?>" data-attach="<?= e($urls['attach']) ?>">
    <header class="sc-head">
        <div><strong><?= e($ticket['ticket_no']) ?></strong> · <?= e($ticket['subject']) ?></div>
        <div class="sc-meta">
            <span class="sc-pill"><?= e($ticket['category_name']) ?></span>
            <span class="sc-pill sc-u-<?= e($ticket['urgency']) ?>"><?= e($urgencyLabels[$ticket['urgency']] ?? $ticket['urgency']) ?></span>
            <span class="sc-pill" data-status><?= e($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span>
            <?php if (!empty($ticket['order_no'])): ?><span class="sc-pill">Order <?= e($ticket['order_no']) ?></span><?php endif; ?>
            <?php if (!empty($ticket['subscription_id'])): ?><span class="sc-pill">Subscription #<?= (int) $ticket['subscription_id'] ?></span><?php endif; ?>
        </div>
    </header>

    <div class="sc-list" data-chat-list>
        <?php $day = ''; foreach ($messages as $m): $d = date('j M Y', strtotime((string) $m['created_at'])); ?>
            <?php
            $isInternal = ($m['visibility'] ?? 'public') === 'internal';
            $mine = $staffView ? ($m['sender'] === 'staff' && !$isInternal) : ($m['sender'] === 'customer');
            $cls = $isInternal ? 'int' : ($mine ? 'me' : 'shop');
            if ($isInternal) {
                $who = 'Internal note · ' . ($m['staff_name'] ?? 'Staff');
            } elseif ($staffView) {
                $who = $m['sender'] === 'customer' ? 'Customer' : ($m['staff_name'] ?? 'Shop team');
            } else {
                $who = $m['sender'] === 'customer' ? '' : 'Shop';
            }
            ?>
            <?php if ($d !== $day): $day = $d; ?><div class="sc-day"><?= e($d) ?></div><?php endif; ?>
            <div class="sc-row <?= $cls ?>" data-msg="<?= (int) $m['id'] ?>">
                <div class="sc-bubble">
                    <?php if (!$mine && $who !== ''): ?><div class="sc-pill sc-who"><?= e($who) ?></div><?php endif; ?>
                    <?php foreach (($m['attachments'] ?? []) as $a): $url = str_replace('__ID__', (string) $a['id'], $urls['attach']); ?>
                        <?php if (str_starts_with((string) $a['mime'], 'image/')): ?>
                            <a href="<?= e($url) ?>" target="_blank" rel="noopener"><img class="sc-img" src="<?= e($url) ?>" alt="<?= e($a['original_name']) ?>"></a>
                        <?php else: ?>
                            <a class="sc-file" href="<?= e($url) ?>">Attachment: <?= e($a['original_name']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ((string) $m['body'] !== ''): ?><div class="sc-text"><?= nl2br(e((string) $m['body'])) ?></div><?php endif; ?>
                    <div class="sc-time"><?= e(date('H:i', strtotime((string) $m['created_at']))) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($showReply): ?>
    <form method="post" action="<?= e($urls['post']) ?>" enctype="multipart/form-data" class="sc-composer">
        <?= csrf_field() ?>
        <?= field_error('form') ?>
        <?= field_error('attachments') ?>
        <textarea name="body" maxlength="2000" placeholder="<?= $staffView ? 'Reply to the customer, or write an internal note' : 'Type a message' ?>" aria-label="Message"><?= e(old('body')) ?></textarea>
        <div class="sc-row-actions">
            <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" aria-label="Attach files (up to 3, 5 MB each)">
            <span style="display:flex;gap:8px">
                <?php if ($staffView): ?>
                <button class="sc-resolve" type="submit" formaction="<?= e($urls['note']) ?>" title="Only staff can see this">Internal note</button>
                <?php endif; ?>
                <button class="btn" type="submit">Send</button>
            </span>
        </div>
    </form>
    <?php else: ?>
    <p class="sc-note">This ticket is closed. Please open a new ticket if you need more help.</p>
    <?php endif; ?>

    <?php if (!$staffView && !$resolved && $canReply): ?>
    <form method="post" action="<?= e($urls['resolve']) ?>" class="sc-note" style="display:flex;justify-content:flex-end">
        <?= csrf_field() ?>
        <button class="sc-resolve" type="submit">Mark as resolved</button>
    </form>
    <?php endif; ?>
</section>

<script>
(function () {
  'use strict';
  var root = document.querySelector('[data-chat]');
  if (!root) return;
  var STAFF = root.getAttribute('data-staff') === '1';
  var list = root.querySelector('[data-chat-list]');
  var status = root.querySelector('[data-status]');
  var last = parseInt(root.getAttribute('data-last-id') || '0', 10);
  var pollUrl = root.getAttribute('data-poll');
  var attachUrl = root.getAttribute('data-attach');
  list.scrollTop = list.scrollHeight;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text; // text only: never HTML from the server
    return n;
  }

  function bubble(m) {
    var internal = m.visibility === 'internal';
    var mine = STAFF ? (m.sender === 'staff' && !internal) : m.sender === 'customer';
    var cls = internal ? 'int' : (mine ? 'me' : 'shop');
    var who = internal ? 'Internal note · ' + (m.staff_name || 'Staff')
      : (STAFF ? (m.sender === 'customer' ? 'Customer' : (m.staff_name || 'Shop team'))
        : (m.sender === 'customer' ? '' : 'Shop'));
    var row = el('div', 'sc-row ' + cls);
    row.setAttribute('data-msg', m.id);
    var b = el('div', 'sc-bubble');
    if (!mine && who) b.appendChild(el('div', 'sc-pill sc-who', who));
    (m.attachments || []).forEach(function (a) {
      var url = attachUrl.replace('__ID__', String(a.id));
      if (String(a.mime).indexOf('image/') === 0) {
        var link = el('a'); link.href = url; link.target = '_blank'; link.rel = 'noopener';
        var img = el('img', 'sc-img'); img.src = url; img.alt = a.original_name; link.appendChild(img);
        b.appendChild(link);
      } else {
        var f = el('a', 'sc-file', 'Attachment: ' + a.original_name); f.href = url; b.appendChild(f);
      }
    });
    if (m.body) b.appendChild(el('div', 'sc-text', m.body));
    b.appendChild(el('div', 'sc-time', String(m.created_at).substring(11, 16)));
    row.appendChild(b);
    return row;
  }

  function poll() {
    if (document.hidden) return;
    fetch(pollUrl + (pollUrl.indexOf('?') === -1 ? '?' : '&') + 'after=' + last, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (!d) return;
      if (d.messages && d.messages.length) {
        d.messages.forEach(function (m) {
          if (document.querySelector('[data-msg="' + m.id + '"]')) return;
          list.appendChild(bubble(m));
          last = Math.max(last, parseInt(m.id, 10));
        });
        list.scrollTop = list.scrollHeight;
      }
    }).catch(function () { /* offline: try again on the next tick */ });
  }

  setInterval(poll, 8000);

  var box = root.querySelector('textarea[name="body"]');
  if (box) {
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.form.submit(); }
    });
  }
})();
</script>
