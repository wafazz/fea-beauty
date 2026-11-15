<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\SupportAttachmentService;
use App\Services\SupportInboxService;
use App\Services\SupportTaxonomy;
use App\Services\SupportTicketService;

/** Support inbox for admins and staff: list, chat, reply, internal note, assign, status, urgency, labels, bulk. */
final class SupportInboxController extends Controller
{
    public function index(Request $request): void
    {
        $this->render(null, $request);
    }

    public function show(Request $request, string $ticketNo): void
    {
        $ticket = SupportInboxService::detail($ticketNo) ?? Response::abort(404);
        SupportInboxService::markRead((int) $ticket['id']);
        header('Cache-Control: no-store');
        $this->render($ticket, $request);
    }

    public function reply(Request $request, string $ticketNo): void
    {
        $ticket = $this->ticket($ticketNo);
        $back = $this->base() . '/tickets/' . $ticket['ticket_no'];
        try {
            $files = SupportAttachmentService::collect($_FILES['attachments'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $this->backWithErrors($back, ['attachments' => $e->getMessage()]);
        }
        try {
            $error = SupportInboxService::reply($ticket, $this->me(), $request->str('body'), $files);
        } catch (\RuntimeException $e) {
            $this->backWithErrors($back, ['form' => SupportAttachmentService::saveFailed($e)]);
        }
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success($back, 'Reply sent to the customer.');
    }

    public function note(Request $request, string $ticketNo): void
    {
        $ticket = $this->ticket($ticketNo);
        $back = $this->base() . '/tickets/' . $ticket['ticket_no'];
        if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null) && array_filter((array) $_FILES['attachments']['error'], fn($e) => (int) $e !== UPLOAD_ERR_NO_FILE) !== []) {
            $this->backWithErrors($back, ['form' => 'Attachments go with a reply to the customer, not with an internal note.']);
        }
        $error = SupportInboxService::note($ticket, $this->me(), $request->str('body'));
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success($back, 'Internal note saved. The customer cannot see it.');
    }

    public function assign(Request $request, string $ticketNo): void
    {
        $ticket = $this->ticket($ticketNo);
        $back = $this->base() . '/tickets/' . $ticket['ticket_no'];
        $raw = (int) $request->str('assignee');
        $error = SupportInboxService::assign($ticket, $raw > 0 ? $raw : null, $this->me());
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success($back, 'Assignee saved.');
    }

    public function status(Request $request, string $ticketNo): void
    {
        $ticket = $this->ticket($ticketNo);
        $back = $this->base() . '/tickets/' . $ticket['ticket_no'];
        $error = SupportInboxService::setStatus($ticket, $request->str('status'), $this->me());
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success($back, 'Status saved.');
    }

    /** Admins and superadmins only (D2). Staff never see the control, and the server refuses them too. */
    public function urgency(Request $request, string $ticketNo): void
    {
        if (!$this->isAdmin()) {
            Response::abort(403);
        }
        $ticket = $this->ticket($ticketNo);
        $back = $this->base() . '/tickets/' . $ticket['ticket_no'];
        $error = SupportInboxService::setUrgency($ticket, $request->str('urgency'), $this->me());
        if ($error !== null) {
            $this->backWithErrors($back, ['form' => $error]);
        }
        $this->success($back, 'Urgency saved.');
    }

    public function labels(Request $request, string $ticketNo): void
    {
        $ticket = $this->ticket($ticketNo);
        $back = $this->base() . '/tickets/' . $ticket['ticket_no'];
        SupportInboxService::setLabels($ticket, (array) $request->input('label_ids', []), $this->me());
        $this->success($back, 'Labels saved.');
    }

    public function bulk(Request $request): void
    {
        $back = $this->base() . '/tickets';
        // One choice from the bulk menu, e.g. "assign:3", "status:resolved" or "label:2".
        [$action, $value] = array_pad(explode(':', $request->str('choice'), 2), 2, '');
        $r = SupportInboxService::bulk(
            $action,
            (array) $request->input('ids', []),
            $value,
            $this->me(),
            $this->isAdmin()
        );
        if (!$r['ok']) {
            $this->backWithErrors($back, ['form' => $r['error']]);
        }
        $this->success($back, $r['count'] . ' ticket(s) updated.');
    }

    /** Live chat for staff: messages after the last one the page has, internal notes included (JSON). */
    public function poll(Request $request, string $ticketNo): void
    {
        $ticket = $this->ticket($ticketNo);
        $messages = SupportTicketService::messagesAfter((int) $ticket['id'], max(0, (int) $request->str('after')), true);
        if ($messages !== []) {
            SupportInboxService::markRead((int) $ticket['id']); // staff is looking at the chat
        }
        Response::json(['status' => $ticket['status'], 'messages' => $messages]);
    }

    public function attachment(Request $request, string $ticketNo, string $id): void
    {
        $ticket = $this->ticket($ticketNo);
        $file = SupportAttachmentService::find((int) $ticket['id'], (int) $id) ?? Response::abort(404);
        SupportAttachmentService::serve($file, $request->str('view') === '1');
    }

    private function render(?array $active, Request $request): void
    {
        $filters = [
            'status' => $request->str('status'),
            'urgency' => $request->str('urgency'),
            'category' => $request->str('category'),
            'label' => $request->str('label'),
            'assignee' => $request->str('assignee'),
            'q' => $request->str('q'),
        ];
        $base = $this->base();
        $this->view('manage/support/inbox', [
            'title' => 'Support inbox',
            'base' => $base,
            'filters' => $filters,
            'tickets' => SupportInboxService::list($filters, $this->me()),
            'active' => $active,
            'isAdmin' => $this->isAdmin(),
            'meId' => $this->me(),
            'urgencies' => SupportTicketService::URGENCIES,
            'statuses' => SupportInboxService::STATUSES,
            'categories' => SupportTaxonomy::categories(),
            'labels' => SupportTaxonomy::labels(),
            'assignees' => SupportInboxService::assignees(),
            'slaHours' => SupportInboxService::SLA_HOURS,
        ], 'portal');
    }

    private function ticket(string $ticketNo): array
    {
        return SupportInboxService::detail($ticketNo) ?? Response::abort(404);
    }

    private function me(): int
    {
        return (int) Auth::user()['id'];
    }

    private function isAdmin(): bool
    {
        return in_array(Auth::user()['role'] ?? '', ['admin', 'superadmin'], true);
    }

    /** Where the inbox lives for the signed-in role. */
    private function base(): string
    {
        return (Auth::user()['role'] ?? '') === 'staff' ? '/staff' : '/admin';
    }
}
