<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OrderService;
use App\Services\RateLimiter;
use App\Services\SupportAttachmentService;
use App\Services\SupportTaxonomy;
use App\Services\SupportTicketService;

/**
 * Guest support tickets. A guest opens a ticket with their email, then follows it through the secret link sent by email.
 * The link carries the ticket number and a token; both must match before anything is shown.
 */
final class SupportTicketController extends Controller
{
    public function create(Request $request): void
    {
        $this->view('shop/support/new', [
            'title' => 'Contact support',
            'categories' => SupportTaxonomy::activeCategories(),
            'urgencies' => SupportTicketService::URGENCIES,
        ]);
    }

    public function store(Request $request): void
    {
        if (RateLimiter::tooMany('support_ticket', $request->ip(), 10, 60)) {
            $this->backWithErrors('/support/new', ['form' => 'Too many tickets. Please wait a while.'], $request->body);
        }
        RateLimiter::hit('support_ticket', $request->ip());

        $orderId = null;
        $orderNo = trim($request->str('order_no'));
        if ($orderNo !== '') {
            $order = OrderService::findByNo($orderNo);
            $token = trim($request->str('track_token'));
            if (!$order || $order['track_hash'] === null || $token === '' || !hash_equals((string) $order['track_hash'], hash('sha256', $token))) {
                $this->backWithErrors('/support/new', ['order_no' => 'That order number and tracking code do not match. Check the order email, or leave both blank.'], $request->body);
            }
            $orderId = (int) $order['id'];
        }

        try {
            $files = SupportAttachmentService::collect($_FILES['attachments'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $this->backWithErrors('/support/new', ['attachments' => $e->getMessage()], $request->body);
        }

        $result = SupportTicketService::create([
            'category_id' => $request->str('category_id'),
            'urgency' => $request->str('urgency'),
            'subject' => $request->str('subject'),
            'body' => $request->str('body'),
            'order_id' => $orderId,
        ], $files, null, $request->str('email'));
        if (!$result['ok']) {
            $this->backWithErrors('/support/new', ['form' => $result['error']], $request->body);
        }
        // The link to follow the ticket is also in the email we just sent.
        $this->success('/support/t/' . $result['ticket_no'] . '/' . $result['token'], 'Ticket ' . $result['ticket_no'] . ' opened. We have emailed you the link to follow it.');
    }

    public function show(Request $request, string $ticketNo, string $token): void
    {
        $ticket = $this->guest($ticketNo, $token);
        SupportTicketService::markRead($ticket);
        header('Cache-Control: no-store');
        $this->view('shop/support/show', [
            'title' => 'Ticket ' . $ticket['ticket_no'],
            'ticket' => $ticket,
            'token' => $token,
            'messages' => SupportTicketService::messagesAfter((int) $ticket['id']),
        ]);
    }

    public function reply(Request $request, string $ticketNo, string $token): void
    {
        $ticket = $this->guest($ticketNo, $token);
        $back = '/support/t/' . $ticket['ticket_no'] . '/' . $token;
        if (RateLimiter::tooMany('support_reply', $request->ip(), 30, 60)) {
            $this->backWithErrors($back, ['form' => 'Too many messages. Please wait a while.']);
        }
        RateLimiter::hit('support_reply', $request->ip());
        try {
            $files = SupportAttachmentService::collect($_FILES['attachments'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $this->backWithErrors($back, ['attachments' => $e->getMessage()]);
        }
        $error = SupportTicketService::reply($ticket, $request->str('body'), $files);
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success($back, 'Message sent.');
    }

    public function resolve(Request $request, string $ticketNo, string $token): void
    {
        $ticket = $this->guest($ticketNo, $token);
        SupportTicketService::resolve($ticket);
        $this->success('/support/t/' . $ticket['ticket_no'] . '/' . $token, 'Marked as resolved. You can reopen it by replying within ' . SupportTicketService::REOPEN_DAYS . ' days.');
    }

    public function poll(Request $request, string $ticketNo, string $token): void
    {
        $ticket = $this->guest($ticketNo, $token);
        Response::json([
            'status' => $ticket['status'],
            'messages' => SupportTicketService::messagesAfter((int) $ticket['id'], max(0, (int) $request->str('after'))),
        ]);
    }

    public function attachment(Request $request, string $ticketNo, string $token, string $id): void
    {
        $ticket = $this->guest($ticketNo, $token);
        $file = SupportAttachmentService::find((int) $ticket['id'], (int) $id) ?? Response::abort(404);
        SupportAttachmentService::serve($file);
    }

    /** The ticket if the number and token both match; otherwise a plain 404. */
    private function guest(string $ticketNo, string $token): array
    {
        return SupportTicketService::forGuest($ticketNo, $token) ?? Response::abort(404);
    }
}
