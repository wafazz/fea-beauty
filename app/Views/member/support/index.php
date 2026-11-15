<?php
/** Expects $tickets (rows from SupportTicketService::listForMember). */
$statusLabels = ['open' => 'Open', 'waiting_staff' => 'Waiting for the shop', 'waiting_customer' => 'Waiting for you', 'resolved' => 'Resolved', 'closed' => 'Closed'];
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Support</h1>
            <p class="muted">Your tickets with the shop. Each one keeps its own conversation, with attachments if you need them.</p>
        </div>
        <a class="s-btn" href="<?= e(url('/member/support/new')) ?>">New ticket</a>
    </header>

    <section class="msg-card">
        <?php if ($tickets === []): ?>
            <p class="muted">You have no tickets yet. Open one if you need help with an order, a payment or anything else.</p>
        <?php else: ?>
            <ul style="list-style:none;padding:0;margin:0;display:grid;gap:8px">
                <?php foreach ($tickets as $t): ?>
                <li>
                    <a href="<?= e(url('/member/support/' . $t['ticket_no'])) ?>" style="display:block;padding:12px;border:1px solid var(--line, #d1d7db);border-radius:10px;text-decoration:none;color:inherit">
                        <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                            <strong><?= $t['unread'] ? '● ' : '' ?><?= e($t['subject']) ?></strong>
                            <span class="muted"><?= e($t['ticket_no']) ?></span>
                        </div>
                        <div class="muted" style="font-size:.85rem;margin-top:4px">
                            <?= e($t['category_name']) ?> · <?= e(\App\Services\SupportTicketService::URGENCIES[$t['urgency']] ?? $t['urgency']) ?>
                            <?php if (!empty($t['order_no'])): ?> · Order <?= e($t['order_no']) ?><?php endif; ?>
                            · <?= e($statusLabels[$t['status']] ?? $t['status']) ?>
                            · <?= e(date('j M Y, H:i', strtotime((string) $t['last_message_at']))) ?>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
