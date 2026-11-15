<?php
/** Expects $ticket and $messages. */
$canReply = \App\Services\SupportTicketService::canReply($ticket);
$base = '/member/support/' . $ticket['ticket_no'];
$urls = [
    'post' => url($base . '/messages'),
    'resolve' => url($base . '/resolve'),
    'poll' => url($base . '/messages'),
    'attach' => url($base . '/attachments/__ID__'),
];
?>
<div class="msg-page">
    <p><a href="<?= e(url('/member/support')) ?>">← All tickets</a></p>
    <?php require BASE_PATH . '/app/Views/support/_chat.php'; ?>
</div>
