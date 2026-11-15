<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use App\Services\SupportAttachmentService;
use App\Services\SupportTaxonomy;
use App\Services\SupportTicketService;

/** A member's own support tickets: list, open a new one, chat, reply, resolve, attachments. */
final class SupportTicketController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('member/support/index', [
            'title' => 'Support',
            'tickets' => SupportTicketService::listForMember($this->me()),
        ], 'portal');
    }

    public function create(Request $request): void
    {
        $this->view('member/support/new', [
            'title' => 'New support ticket',
            'categories' => SupportTaxonomy::activeCategories(),
            'orders' => SupportTicketService::ordersForMember($this->me()),
            'subscriptions' => SupportTicketService::subscriptionsForMember($this->me()),
            'urgencies' => SupportTicketService::URGENCIES,
        ], 'portal');
    }

    public function store(Request $request): void
    {
        if (RateLimiter::tooMany('support_ticket', $request->ip(), 10, 60)) {
            $this->backWithErrors('/member/support/new', ['form' => 'Too many tickets. Please wait a while.'], $request->body);
        }
        RateLimiter::hit('support_ticket', $request->ip());
        try {
            $files = SupportAttachmentService::collect($_FILES['attachments'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $this->backWithErrors('/member/support/new', ['attachments' => $e->getMessage()], $request->body);
        }
        $result = SupportTicketService::create($this->input($request), $files, Auth::user(), null);
        if (!$result['ok']) {
            $this->backWithErrors('/member/support/new', ['form' => $result['error']], $request->body);
        }
        $this->success('/member/support/' . $result['ticket_no'], 'Ticket ' . $result['ticket_no'] . ' opened. We will reply here.');
    }

    public function show(Request $request, string $ticketNo): void
    {
        $ticket = $this->mine($ticketNo);
        SupportTicketService::markRead($ticket);
        header('Cache-Control: no-store');
        $this->view('member/support/show', [
            'title' => 'Ticket ' . $ticket['ticket_no'],
            'ticket' => $ticket,
            'messages' => SupportTicketService::messagesAfter((int) $ticket['id']),
        ], 'portal');
    }

    public function reply(Request $request, string $ticketNo): void
    {
        $ticket = $this->mine($ticketNo);
        $back = '/member/support/' . $ticket['ticket_no'];
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

    public function resolve(Request $request, string $ticketNo): void
    {
        $ticket = $this->mine($ticketNo);
        SupportTicketService::resolve($ticket);
        $this->success('/member/support/' . $ticket['ticket_no'], 'Marked as resolved. You can reopen it by replying within ' . SupportTicketService::REOPEN_DAYS . ' days.');
    }

    /** Live chat: messages after the last one the page has (JSON). */
    public function poll(Request $request, string $ticketNo): void
    {
        $ticket = $this->mine($ticketNo);
        Response::json([
            'status' => $ticket['status'],
            'messages' => SupportTicketService::messagesAfter((int) $ticket['id'], max(0, (int) $request->str('after'))),
        ]);
    }

    public function attachment(Request $request, string $ticketNo, string $id): void
    {
        $ticket = $this->mine($ticketNo);
        $file = SupportAttachmentService::find((int) $ticket['id'], (int) $id) ?? Response::abort(404);
        SupportAttachmentService::serve($file);
    }

    private function me(): int
    {
        return (int) Auth::user()['id'];
    }

    /** The ticket if it belongs to the signed-in member; otherwise a plain 404. */
    private function mine(string $ticketNo): array
    {
        return SupportTicketService::forMember($ticketNo, $this->me()) ?? Response::abort(404);
    }

    private function input(Request $request): array
    {
        return [
            'category_id' => $request->str('category_id'),
            'urgency' => $request->str('urgency'),
            'subject' => $request->str('subject'),
            'body' => $request->str('body'),
            'order_id' => $request->str('order_id'),
            'subscription_id' => $request->str('subscription_id'),
        ];
    }
}
