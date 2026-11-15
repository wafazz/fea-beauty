<?php
/**
 * Chat view for one ticket, for the customer (member and guest) and for staff. Expects:
 *   $ticket      row from the ticket services (ticket_no, subject, status, urgency, category_name, order_no, subscription_id)
 *   $messages    from SupportTicketService::messagesAfter() (staff: with $staffView = true, includes internal notes)
 *   $urls        post, resolve, poll, attach (attach contains __ID__). Staff also: note.
 *   $canReply    bool (customer). Staff always can reply.
 *   $staffView   bool, default false
 * Sending and live updates are handled by public/assets/js/support-chat.js (no page reload).
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
  .sc-list { flex: 1; overflow-y: auto; padding: 14px 12px; display: flex; flex-direction: column; gap: 6px; max-height: 60vh; overscroll-behavior: contain; }
  .sc-day { align-self: center; font-size: .75rem; color: var(--sc-muted); background: var(--sc-shop); padding: 3px 10px; border-radius: 8px; margin: 6px 0; }
  .sc-row { display: flex; animation: sc-in .25s ease-out; }
  @keyframes sc-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
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
  /* A file in a message: a card with its type, name and size. Tap to preview in the popup. */
  .sc-file-card { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; padding: 8px; border-radius: 10px; border: 1px solid var(--sc-line); background: rgba(127, 127, 127, .06); color: inherit; text-decoration: none; min-width: 200px; max-width: 320px; }
  .sc-file-card:hover { border-color: #2563eb; }
  .sc-file-ico { flex: none; width: 40px; height: 48px; border-radius: 6px; display: grid; place-items: center; background: #dc2626; color: #fff; font-size: .72rem; font-weight: 700; letter-spacing: .03em; }
  .sc-file-meta { display: grid; gap: 2px; min-width: 0; }
  .sc-file-meta strong { font-size: .88rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .sc-file-meta small { font-size: .74rem; color: var(--sc-muted); }
  .sc-composer { border-top: 1px solid var(--sc-line); background: var(--sc-shop); padding: 10px; display: grid; gap: 8px; }
  .sc-composer textarea { width: 100%; min-height: 56px; resize: vertical; border-radius: 8px; border: 1px solid var(--sc-line); padding: 8px; font: inherit; background: transparent; color: inherit; box-sizing: border-box; }
  .sc-composer .sc-row-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
  .sc-composer input[type=file] { font-size: .8rem; color: var(--sc-muted); max-width: 100%; }
  .sc-files { display: flex; flex-wrap: wrap; gap: 6px; font-size: .8rem; color: var(--sc-muted); }
  .sc-files:empty { display: none; }
  .sc-feedback { font-size: .85rem; min-height: 0; }
  .sc-feedback:empty { display: none; }
  .sc-feedback.is-error { color: #d92d20; }
  .sc-feedback.is-ok { color: var(--sc-muted); }
  .sc-note { padding: 10px 14px; font-size: .85rem; color: var(--sc-muted); background: var(--sc-shop); border-top: 1px solid var(--sc-line); }
  .sc-resolve { background: none; border: 1px solid var(--sc-line); border-radius: 8px; padding: 6px 10px; color: inherit; cursor: pointer; min-height: 36px; }
  .sc-send[disabled] { opacity: .6; cursor: progress; }
  /* Attachment viewer popup: images and PDFs open here instead of a new tab. */
  .ws-viewer { border: 0; border-radius: 16px; padding: 0; background: #fff; color: #111827; box-shadow: 0 20px 60px rgba(0, 0, 0, .35); width: min(960px, calc(100vw - 24px)); height: min(88vh, 900px); max-height: none; }
  /* Flex only while open: a closed dialog must stay hidden (display set here would show it at the bottom of the page). */
  .ws-viewer[open] { display: flex; flex-direction: column; }
  .ws-viewer::backdrop { background: rgba(15, 23, 42, .6); }
  .ws-viewer-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid #e5e7eb; }
  .ws-viewer-head strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; font-size: .95rem; }
  /* The popup scrolls: a tall photo can be read from top to bottom; a PDF scrolls inside its own frame. */
  .ws-viewer .ws-dialog-body { flex: 1; min-height: 0; max-height: none; padding: 0; overflow: auto; -webkit-overflow-scrolling: touch; display: block; background: #111827; overscroll-behavior: contain; }
  .ws-viewer-stage { min-width: 0; background: #111827; }
  .ws-viewer-stage img { display: block; width: auto; max-width: 100%; height: auto; margin: 0 auto; }
  .ws-viewer-stage iframe { display: block; width: 100%; height: calc(min(88vh, 900px) - 58px); border: 0; background: #fff; }
  @media (max-width: 640px) { .ws-viewer { width: calc(100vw - 16px); height: 92vh; } .ws-viewer-stage iframe { height: calc(92vh - 58px); } }
  .ws-viewer-actions { display: flex; gap: 8px; align-items: center; }
  .ws-viewer-actions a, .ws-viewer-actions button { min-height: 36px; padding: 6px 12px; border-radius: 10px; border: 1px solid #d1d5db; background: #fff; color: #111827; text-decoration: none; font: inherit; font-size: .85rem; cursor: pointer; }
  .sc-live { display: inline-flex; align-items: center; gap: 6px; font-size: .75rem; color: var(--sc-muted); }
  .sc-live::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: #12b76a; }
  .sc-live.is-off::before { background: #98a2b3; }
  @media (max-width: 640px) { .sc-list { max-height: 55vh; } .sc-bubble { max-width: 88%; } }
  @media (prefers-reduced-motion: reduce) { .sc-row { animation: none; } }
</style>

<section class="sc<?= $staffView ? ' sc-wide' : '' ?>" data-chat data-staff="<?= $staffView ? '1' : '0' ?>" data-last-id="<?= $lastId ?>" data-poll="<?= e($urls['poll']) ?>" data-attach="<?= e($urls['attach']) ?>" data-labels="<?= e(json_encode($statusLabels, JSON_UNESCAPED_UNICODE)) ?>">
    <header class="sc-head">
        <div><strong><?= e($ticket['ticket_no']) ?></strong> · <?= e($ticket['subject']) ?></div>
        <div class="sc-meta">
            <span class="sc-pill"><?= e($ticket['category_name']) ?></span>
            <span class="sc-pill sc-u-<?= e($ticket['urgency']) ?>"><?= e($urgencyLabels[$ticket['urgency']] ?? $ticket['urgency']) ?></span>
            <span class="sc-pill" data-status><?= e($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span>
            <?php if (!empty($ticket['order_no'])): ?><span class="sc-pill">Order <?= e($ticket['order_no']) ?></span><?php endif; ?>
            <?php if (!empty($ticket['subscription_id'])): ?><span class="sc-pill">Subscription #<?= (int) $ticket['subscription_id'] ?></span><?php endif; ?>
            <span class="sc-live" data-live title="New replies appear here as they arrive">Live</span>
            <?php if ($staffView): ?>
            <button type="button" class="ws-open" data-details-open="ticket-details" aria-haspopup="dialog">
                Customer: <?= e($ticket['customer_name'] ?: ($ticket['customer_email'] ?: 'Guest')) ?> · Details
            </button>
            <?php endif; ?>
        </div>
    </header>

    <div class="sc-list" data-chat-list aria-live="polite">
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
                            <a href="<?= e($url) ?>" data-attach-view="<?= e($a['original_name']) ?>"><img class="sc-img" src="<?= e($url) ?>" alt="<?= e($a['original_name']) ?>"></a>
                        <?php else: ?>
                            <?php $isPdf = $a['mime'] === 'application/pdf'; $bytes = (int) ($a['size'] ?? 0); ?>
                            <a class="sc-file-card" href="<?= e($url) ?>" data-attach-view="<?= e($a['original_name']) ?>" title="Tap to preview">
                                <span class="sc-file-ico" aria-hidden="true"><?= $isPdf ? 'PDF' : 'FILE' ?></span>
                                <span class="sc-file-meta">
                                    <strong><?= e($a['original_name']) ?></strong>
                                    <small><?= $isPdf ? 'PDF' : 'File' ?> · <?= $bytes >= 1048576 ? e(number_format($bytes / 1048576, 1)) . ' MB' : e((string) max(1, (int) round($bytes / 1024))) . ' KB' ?> · Tap to preview</small>
                                </span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ((string) $m['body'] !== ''): ?><div class="sc-text"><?= nl2br(e((string) $m['body'])) ?></div><?php endif; ?>
                    <div class="sc-time"><?= e(date('H:i', strtotime((string) $m['created_at']))) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($showReply): ?>
    <form method="post" action="<?= e($urls['post']) ?>" enctype="multipart/form-data" class="sc-composer" data-composer data-reply-url="<?= e($urls['post']) ?>">
        <?= csrf_field() ?>
        <?= field_error('form') ?>
        <?= field_error('attachments') ?>
        <textarea name="body" maxlength="2000" placeholder="<?= $staffView ? 'Reply to the customer, or write an internal note' : 'Type a message' ?>" aria-label="Message" data-body><?= old('body') ?></textarea>
        <div class="sc-files" data-file-list aria-live="polite"></div>
        <div class="sc-row-actions">
            <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" aria-label="Attach files (up to 3, 5 MB each)" data-files>
            <span style="display:flex;gap:8px;align-items:center">
                <?php if ($staffView): ?>
                <button class="sc-resolve" type="submit" formaction="<?= e($urls['note']) ?>" data-note title="Only staff can see this">Internal note</button>
                <?php endif; ?>
                <button class="btn sc-send" type="submit" data-send>Send</button>
            </span>
        </div>
        <div class="sc-feedback" data-feedback role="status"></div>
    </form>
    <?php else: ?>
    <p class="sc-note">This ticket is closed. Please open a new ticket if you need more help.</p>
    <?php endif; ?>

    <?php if (!$staffView && !$resolved && $canReply): ?>
    <form method="post" action="<?= e($urls['resolve']) ?>" class="sc-note" style="display:flex;justify-content:flex-end" data-resolve>
        <?= csrf_field() ?>
        <button class="sc-resolve" type="submit">Mark as resolved</button>
    </form>
    <?php endif; ?>
</section>
<script src="<?= e(asset('js/support-chat.js')) ?>" defer></script>
