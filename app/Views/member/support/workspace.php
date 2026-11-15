<?php
/** Expects $tickets (listForMember rows), $active (ticket row or null) and $messages (for $active). */
use App\Services\SupportTicketService;

$statusLabels = ['open' => 'Open', 'waiting_staff' => 'Waiting for the shop', 'waiting_customer' => 'Waiting for you', 'resolved' => 'Resolved', 'closed' => 'Closed'];
$unread = count(array_filter($tickets, fn($t) => $t['unread']));
$waiting = count(array_filter($tickets, fn($t) => $t['status'] === 'waiting_customer'));
$resolvedCount = count(array_filter($tickets, fn($t) => $t['status'] === 'resolved'));

if ($active) {
    $ticket = $active;
    $canReply = SupportTicketService::canReply($active);
    $base = '/member/support/' . $active['ticket_no'];
    $urls = [
        'post' => url($base . '/messages'),
        'resolve' => url($base . '/resolve'),
        'poll' => url($base . '/messages'),
        'attach' => url($base . '/attachments/__ID__'),
    ];
}
?>
<link rel="stylesheet" href="<?= e(asset('css/support-workspace.css')) ?>">
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Support</h1>
            <p class="muted">Your tickets with the shop. Pick one to read the conversation and reply.</p>
        </div>
        <a class="s-btn" href="<?= e(url('/member/support/new')) ?>">New ticket</a>
    </header>

    <div class="ws" data-ws="1" data-active="<?= $active ? '1' : '0' ?>">
        <aside class="ws-list" aria-label="Your tickets">
            <div class="ws-tools">
                <div class="ws-search">
                    <input type="search" placeholder="Search by subject or ticket number" aria-label="Search your tickets" data-ws-search>
                </div>
                <div class="ws-chips" role="group" aria-label="Filter tickets">
                    <button type="button" class="ws-chip" data-ws-filter="all" aria-pressed="true">All <span class="ws-count"><?= count($tickets) ?></span></button>
                    <button type="button" class="ws-chip" data-ws-filter="unread" aria-pressed="false">New replies <span class="ws-count"><?= $unread ?></span></button>
                    <button type="button" class="ws-chip" data-ws-filter="waiting" aria-pressed="false">Waiting for you <span class="ws-count"><?= $waiting ?></span></button>
                    <button type="button" class="ws-chip" data-ws-filter="resolved" aria-pressed="false">Resolved <span class="ws-count"><?= $resolvedCount ?></span></button>
                </div>
            </div>
            <div class="ws-items" data-ws-items>
                <?php foreach ($tickets as $t):
                    $flags = [];
                    if ($t['unread']) $flags[] = 'unread';
                    if ($t['status'] === 'waiting_customer') $flags[] = 'waiting';
                    if ($t['status'] === 'resolved') $flags[] = 'resolved';
                    $isActive = $active && $active['ticket_no'] === $t['ticket_no'];
                ?>
                <a class="ws-row<?= $isActive ? ' is-active' : '' ?>" href="<?= e(url('/member/support/' . $t['ticket_no'])) ?>" data-ws-row data-flags="<?= e(implode(' ', $flags)) ?>"<?= $isActive ? ' aria-current="true"' : '' ?>>
                    <div class="ws-row-top">
                        <strong><?php if ($t['unread']): ?><span class="ws-dot" aria-label="New reply"></span><?php endif; ?><?= e($t['subject']) ?></strong>
                        <span class="ws-time"><?= e(date('j M', strtotime((string) $t['last_message_at']))) ?></span>
                    </div>
                    <div class="ws-row-sub"><?= e($t['ticket_no']) ?> · <?= e($t['category_name']) ?><?php if (!empty($t['order_no'])): ?> · <?= e($t['order_no']) ?><?php endif; ?></div>
                    <div class="ws-row-sub"><?= e($statusLabels[$t['status']] ?? $t['status']) ?> · <?= e(SupportTicketService::URGENCIES[$t['urgency']] ?? $t['urgency']) ?></div>
                </a>
                <?php endforeach; ?>
                <?php if ($tickets === []): ?>
                    <p class="ws-none">You have no tickets yet. Open one if you need help with an order, a payment or anything else.</p>
                <?php endif; ?>
            </div>
            <p class="ws-none" data-ws-none hidden>No tickets match. Try another filter or search.</p>
        </aside>

        <section class="ws-main" aria-live="polite" aria-label="Conversation">
            <?php if ($active): ?>
                <div class="ws-main-inner">
                    <a class="ws-back" href="<?= e(url('/member/support')) ?>">← All tickets</a>
                    <?php require BASE_PATH . '/app/Views/support/_chat.php'; ?>
                </div>
            <?php else: ?>
                <div class="ws-empty">
                    <h2>Choose a ticket</h2>
                    <p>Pick a ticket on the left to read the conversation and reply here.</p>
                    <a class="s-btn secondary" href="<?= e(url('/member/support/new')) ?>">Open a new ticket</a>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
<script src="<?= e(asset('js/support-workspace.js')) ?>" defer></script>
