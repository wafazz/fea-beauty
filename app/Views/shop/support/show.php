<?php
/** Expects $ticket, $token and $messages. */
$canReply = \App\Services\SupportTicketService::canReply($ticket);
$base = '/support/t/' . $ticket['ticket_no'] . '/' . $token;
$urls = [
    'post' => url($base . '/messages'),
    'resolve' => url($base . '/resolve'),
    'poll' => url($base . '/messages'),
    'attach' => url($base . '/attachments/__ID__'),
];
?>
<div class="narrow-page">
    <?php require BASE_PATH . '/app/Views/support/_chat.php'; ?>
    <p class="muted" style="text-align:center;margin-top:12px">Keep this page's link private. Anyone with it can read this ticket.</p>
</div>
