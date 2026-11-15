<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Support tickets for members and guests (Docs/support-tickets-plan.md, phase 2). Every read and write checks who the
 * caller is: a member sees only their own tickets; a guest needs the ticket number and the secret link from the email.
 */
final class SupportTicketService
{
    public const SUBJECT_MAX = 160;
    public const REOPEN_DAYS = 7;
    public const URGENCIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    /**
     * Open a ticket with its first message.
     * @param array $in      category_id, urgency, subject, body, order_id?, subscription_id?
     * @param array $files   checked uploads from SupportAttachmentService::collect()
     * @param array|null $member signed-in member (id), or null for a guest
     * @param string|null $guestEmail guest contact (required when $member is null)
     * @return array{ok:bool, error?:string, ticket_no?:string, token?:string, id?:int}
     */
    public static function create(array $in, array $files, ?array $member, ?string $guestEmail): array
    {
        $categoryId = (int) ($in['category_id'] ?? 0);
        if (Database::query('SELECT COUNT(*) FROM support_categories WHERE id = ? AND active = 1', [$categoryId])->fetchColumn() == 0) {
            return ['ok' => false, 'error' => 'Choose a topic for your ticket.'];
        }
        $urgency = (string) ($in['urgency'] ?? '');
        if (!isset(self::URGENCIES[$urgency])) {
            return ['ok' => false, 'error' => 'Choose how urgent this is.'];
        }
        $subject = trim((string) ($in['subject'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX) {
            return ['ok' => false, 'error' => 'Write a short subject (up to ' . self::SUBJECT_MAX . ' characters).'];
        }
        $body = trim((string) ($in['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > SupportService::MAX_CHARS) {
            return ['ok' => false, 'error' => 'Write a message of up to ' . SupportService::MAX_CHARS . ' characters.'];
        }

        $orderId = isset($in['order_id']) && (int) $in['order_id'] > 0 ? (int) $in['order_id'] : null;
        if ($orderId !== null && !self::orderBelongs($orderId, $member, $guestEmail)) {
            return ['ok' => false, 'error' => 'That order cannot be linked to this ticket.'];
        }
        $subscriptionId = null;
        if ($member !== null && isset($in['subscription_id']) && (int) $in['subscription_id'] > 0) {
            $subscriptionId = self::activeSubscriptionOf((int) $in['subscription_id'], (int) $member['id']);
            if ($subscriptionId === null) {
                return ['ok' => false, 'error' => 'Only an active subscription of yours can be linked to this ticket.'];
            }
        }
        if ($member === null && ($guestEmail === null || filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false)) {
            return ['ok' => false, 'error' => 'Enter a valid email address so we can reply.'];
        }

        // Guests get a secret token; only its hash is stored.
        $token = $member === null ? bin2hex(random_bytes(16)) : null;
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $stored = [];
        try {
            Database::query(
                "INSERT INTO support_tickets (user_id, guest_email, category_id, subject, urgency, status, order_id, subscription_id, access_hash, last_message_at, customer_read_at)
                 VALUES (?, ?, ?, ?, ?, 'waiting_staff', ?, ?, ?, NOW(), NOW())",
                [$member['id'] ?? null, $member === null ? strtolower(trim((string) $guestEmail)) : null, $categoryId, $subject, $urgency, $orderId, $subscriptionId, $token === null ? null : hash('sha256', $token)]
            );
            $id = (int) $pdo->lastInsertId();
            $ticketNo = 'TK-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            Database::query('UPDATE support_tickets SET ticket_no = ? WHERE id = ?', [$ticketNo, $id]);
            Database::query("INSERT INTO support_messages (ticket_id, sender, visibility, body) VALUES (?, 'customer', 'public', ?)", [$id, $body]);
            $messageId = (int) $pdo->lastInsertId();
            foreach ($files as $file) {
                $stored[] = SupportAttachmentService::store($id, $messageId, $file);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            foreach ($stored as $name) {
                @unlink(SupportAttachmentService::dir() . '/' . $name);
            }
            throw $e;
        }

        self::emailOpened($ticketNo, $member === null ? strtolower(trim((string) $guestEmail)) : (string) ($member['email'] ?? ''), $token);
        return ['ok' => true, 'ticket_no' => $ticketNo, 'token' => $token, 'id' => $id];
    }

    /** A customer's reply on their own ticket. Returns an error message, or null when posted. */
    public static function reply(array $ticket, string $body, array $files): ?string
    {
        if ($ticket['status'] === 'closed') {
            return 'This ticket is closed. Please open a new ticket.';
        }
        $reopen = false;
        if ($ticket['status'] === 'resolved') {
            $resolvedAt = strtotime((string) $ticket['resolved_at']);
            if ($resolvedAt === false || time() - $resolvedAt > self::REOPEN_DAYS * 86400) {
                return 'This ticket was resolved more than ' . self::REOPEN_DAYS . ' days ago. Please open a new ticket.';
            }
            $reopen = true;
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > SupportService::MAX_CHARS) {
            return 'Write a message of up to ' . SupportService::MAX_CHARS . ' characters.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $stored = [];
        try {
            Database::query("INSERT INTO support_messages (ticket_id, sender, visibility, body) VALUES (?, 'customer', 'public', ?)", [(int) $ticket['id'], $body]);
            $messageId = (int) $pdo->lastInsertId();
            foreach ($files as $file) {
                $stored[] = SupportAttachmentService::store((int) $ticket['id'], $messageId, $file);
            }
            Database::query(
                "UPDATE support_tickets SET status = 'waiting_staff', last_message_at = NOW(), customer_read_at = NOW()" . ($reopen ? ', resolved_at = NULL' : '') . ' WHERE id = ?',
                [(int) $ticket['id']]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            foreach ($stored as $name) {
                @unlink(SupportAttachmentService::dir() . '/' . $name);
            }
            throw $e;
        }
        return null;
    }

    /** Can the customer still reply? Closed tickets no; resolved ones only within REOPEN_DAYS of being resolved. */
    public static function canReply(array $ticket): bool
    {
        if ($ticket['status'] === 'closed') {
            return false;
        }
        if ($ticket['status'] === 'resolved') {
            $resolvedAt = strtotime((string) $ticket['resolved_at']);
            return $resolvedAt !== false && time() - $resolvedAt <= self::REOPEN_DAYS * 86400;
        }
        return true;
    }

    /** The customer marks their ticket resolved. Staff can still reopen it by replying within REOPEN_DAYS. */
    public static function resolve(array $ticket): void
    {
        if (in_array($ticket['status'], ['resolved', 'closed'], true)) {
            return;
        }
        Database::query("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW() WHERE id = ?", [(int) $ticket['id']]);
    }

    public static function markRead(array $ticket): void
    {
        Database::query('UPDATE support_tickets SET customer_read_at = NOW() WHERE id = ?', [(int) $ticket['id']]);
    }

    /** A member's ticket, or null when it is not theirs (the same answer as "not found"). */
    public static function forMember(string $ticketNo, int $userId): ?array
    {
        return self::withDetails("t.ticket_no = ? AND t.user_id = ?", [$ticketNo, $userId]);
    }

    /** A guest's ticket: the number and the secret token from the email must both match. */
    public static function forGuest(string $ticketNo, string $token): ?array
    {
        $row = self::withDetails("t.ticket_no = ? AND t.access_hash IS NOT NULL", [$ticketNo]);
        if ($row === null || $token === '' || !hash_equals((string) $row['access_hash'], hash('sha256', $token))) {
            return null;
        }
        return $row;
    }

    /** @return array<int, array<string, mixed>> the member's tickets, newest activity first */
    public static function listForMember(int $userId): array
    {
        $rows = Database::query(
            "SELECT t.id, t.ticket_no, t.subject, t.status, t.urgency, t.last_message_at, t.customer_read_at, c.name AS category_name, o.order_no
             FROM support_tickets t
             JOIN support_categories c ON c.id = t.category_id
             LEFT JOIN orders o ON o.id = t.order_id
             WHERE t.user_id = ? ORDER BY t.last_message_at DESC, t.id DESC LIMIT 200",
            [$userId]
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['unread'] = $r['customer_read_at'] === null || strtotime((string) $r['customer_read_at']) < strtotime((string) $r['last_message_at']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Public messages on a ticket with an id after $afterId, oldest first, each with its attachments.
     * Internal notes are never returned to customers.
     * @return array<int, array<string, mixed>>
     */
    public static function messagesAfter(int $ticketId, int $afterId = 0, bool $forStaff = false): array
    {
        // Staff also get internal notes and the name of the staff member who wrote each reply. Customers get neither.
        $rows = $forStaff
            ? Database::query(
                "SELECT m.id, m.sender, m.visibility, m.body, m.created_at, u.name AS staff_name FROM support_messages m
                 LEFT JOIN users u ON u.id = m.staff_id
                 WHERE m.ticket_id = ? AND m.id > ? ORDER BY m.id LIMIT 200",
                [$ticketId, $afterId]
            )->fetchAll()
            : Database::query(
                "SELECT m.id, m.sender, m.body, m.created_at FROM support_messages m
                 WHERE m.ticket_id = ? AND m.visibility = 'public' AND m.id > ? ORDER BY m.id LIMIT 200",
                [$ticketId, $afterId]
            )->fetchAll();
        $files = SupportAttachmentService::forMessages(array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['attachments'] = $files[(int) $r['id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    /** Orders a member can link to a ticket (their own). @return array<int, array{id:int, order_no:string, created_at:string}> */
    public static function ordersForMember(int $userId): array
    {
        return Database::query('SELECT id, order_no, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$userId])->fetchAll();
    }

    /** Active subscriptions a member can link to a ticket. Empty when the subscriptions module is off. */
    public static function subscriptionsForMember(int $userId): array
    {
        if (!Modules::enabled('subscriptions')) {
            return [];
        }
        return Database::query("SELECT s.id, p.name AS product_name FROM subscriptions s JOIN products p ON p.id = s.product_id WHERE s.user_id = ? AND s.status = 'active' ORDER BY s.id DESC", [$userId])->fetchAll();
    }

    private static function withDetails(string $where, array $args): ?array
    {
        $row = Database::query(
            "SELECT t.*, c.name AS category_name, o.order_no
             FROM support_tickets t
             JOIN support_categories c ON c.id = t.category_id
             LEFT JOIN orders o ON o.id = t.order_id
             WHERE {$where} LIMIT 1",
            $args
        )->fetch();
        return $row ?: null;
    }

    /** The order must be the member's own, or (for a guest) the order whose email matches the guest contact. */
    private static function orderBelongs(int $orderId, ?array $member, ?string $guestEmail): bool
    {
        if ($member !== null) {
            return Database::query('SELECT COUNT(*) FROM orders WHERE id = ? AND user_id = ?', [$orderId, (int) $member['id']])->fetchColumn() > 0;
        }
        return $guestEmail !== null && Database::query('SELECT COUNT(*) FROM orders WHERE id = ? AND user_id IS NULL AND LOWER(customer_email) = ?', [$orderId, strtolower(trim($guestEmail))])->fetchColumn() > 0;
    }

    private static function activeSubscriptionOf(int $subscriptionId, int $userId): ?int
    {
        if (!Modules::enabled('subscriptions')) {
            return null;
        }
        $id = Database::query("SELECT id FROM subscriptions WHERE id = ? AND user_id = ? AND status = 'active'", [$subscriptionId, $userId])->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * The first email: ticket number and a link. Guests get the secret link; members get a link that needs sign-in.
     * The message text is never put in the email.
     */
    private static function emailOpened(string $ticketNo, string $to, ?string $token): void
    {
        if ($to === '' || !EmailService::ready()) {
            return;
        }
        $shop = (string) config('app.name');
        $link = $token !== null ? url('/support/t/' . $ticketNo . '/' . $token) : url('/member/support/' . $ticketNo);
        $note = $token !== null ? "\n\nKeep this link private. Anyone with it can read this ticket." : '';
        $text = "Thanks for contacting {$shop}. We received your ticket {$ticketNo}.\n\nFollow it and reply here:\n{$link}{$note}";
        MailService::send($to, "We received your ticket {$ticketNo}", $text, 'support');
    }
}
