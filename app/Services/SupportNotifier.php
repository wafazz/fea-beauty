<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Emails for support tickets (phase 4). The emails are pointers: they carry the ticket number and, for members, a link
 * that needs sign-in. They never carry the message text, and never the guest's secret link (that only appears in the
 * email sent when the ticket was opened). Mail failures are logged by MailService and never break the action.
 */
final class SupportNotifier
{
    /** Tests can capture what would be sent instead of sending it. Unset in production. */
    public static ?\Closure $capture = null;

    private static function ready(): bool
    {
        return self::$capture !== null || EmailService::ready();
    }

    private static function send(string $to, string $subject, string $text): void
    {
        if (self::$capture !== null) {
            (self::$capture)($to, $subject, $text);
            return;
        }
        MailService::send($to, $subject, $text, 'support');
    }

    /** A staff reply went out: one email to the customer. $ticket comes from SupportInboxService::detail(). */
    public static function customerReplied(array $ticket): void
    {
        $to = (string) ($ticket['customer_email'] ?? '');
        if ($to === '' || !self::ready()) {
            return;
        }
        // A member who is looking at the site already sees the reply live: no email (guests always get one).
        if ((int) ($ticket['user_id'] ?? 0) > 0 && SupportPresence::isOnline((int) $ticket['user_id'])) {
            return;
        }
        $shop = (string) config('app.name');
        $no = (string) $ticket['ticket_no'];
        $hello = trim((string) ($ticket['customer_name'] ?? '')) !== '' ? 'Hello ' . $ticket['customer_name'] . ',' : 'Hello,';
        if ((int) ($ticket['user_id'] ?? 0) > 0) {
            $how = "Sign in to read the reply:\n" . url('/member/support/' . $no);
        } else {
            $how = 'Use the secure link in the first email about this ticket to read the reply.';
        }
        $text = "{$hello}\n\nThe {$shop} team has replied to your support ticket {$no}.\n\n{$how}";
        self::send($to, "Reply to your ticket {$no}", $text);
    }

    /** An urgent ticket was opened or answered by the customer: tell the assignee, or every active admin if unassigned. */
    public static function urgentRaised(array $ticket): void
    {
        if (($ticket['urgency'] ?? '') !== 'urgent' || !self::ready()) {
            return;
        }
        $recipients = [];
        if (!empty($ticket['assignee_id'])) {
            $recipients = Database::query("SELECT email FROM users WHERE id = ? AND status = 'active'", [(int) $ticket['assignee_id']])->fetchAll();
        }
        if ($recipients === []) {
            $recipients = Database::query("SELECT email FROM users WHERE role IN ('admin','superadmin') AND status = 'active'")->fetchAll();
        }
        $no = (string) $ticket['ticket_no'];
        $text = "An urgent support ticket needs attention: {$no}.\n\nOpen the support inbox to read it:\n" . url('/admin/tickets/' . $no);
        foreach ($recipients as $r) {
            self::send((string) $r['email'], "Urgent support ticket {$no}", $text);
        }
    }
}
