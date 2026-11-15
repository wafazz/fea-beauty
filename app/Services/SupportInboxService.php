<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Staff side of support tickets: the inbox list and every staff action on a ticket (reply, note, assign, status,
 * urgency, labels, bulk). Every change is written to the audit log. Urgency is changed only by admins (D2).
 */
final class SupportInboxService
{
    /** First-reply targets by urgency, in hours (D5). Low has no target. */
    public const SLA_HOURS = ['urgent' => 2, 'high' => 8, 'normal' => 24];
    public const STATUSES = ['open' => 'Open', 'waiting_staff' => 'Waiting for the shop', 'waiting_customer' => 'Waiting for the customer', 'resolved' => 'Resolved', 'closed' => 'Closed'];
    public const MAX_BULK = 100;
    private const ASSIGNABLE_ROLES = ['admin', 'staff', 'superadmin'];

    /**
     * The inbox list. Filters: status ('' = not closed, 'all', or one status), urgency, category, label,
     * assignee ('me', 'none', or a staff id), q (ticket no, subject, customer, order no).
     * Sorted by urgency, then the latest activity.
     * @return array<int, array<string, mixed>>
     */
    public static function list(array $f, int $meId): array
    {
        $where = [];
        $args = [];
        $status = (string) ($f['status'] ?? '');
        if ($status === '') {
            $where[] = "t.status <> 'closed'";
        } elseif ($status !== 'all' && isset(self::STATUSES[$status])) {
            $where[] = 't.status = ?';
            $args[] = $status;
        }
        if (isset(\App\Services\SupportTicketService::URGENCIES[(string) ($f['urgency'] ?? '')])) {
            $where[] = 't.urgency = ?';
            $args[] = (string) $f['urgency'];
        }
        if ((int) ($f['category'] ?? 0) > 0) {
            $where[] = 't.category_id = ?';
            $args[] = (int) $f['category'];
        }
        if ((int) ($f['label'] ?? 0) > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM support_ticket_labels tl WHERE tl.ticket_id = t.id AND tl.label_id = ?)';
            $args[] = (int) $f['label'];
        }
        $assignee = (string) ($f['assignee'] ?? '');
        if ($assignee === 'me') {
            $where[] = 't.assignee_id = ?';
            $args[] = $meId;
        } elseif ($assignee === 'none') {
            $where[] = 't.assignee_id IS NULL';
        } elseif ((int) $assignee > 0) {
            $where[] = 't.assignee_id = ?';
            $args[] = (int) $assignee;
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(t.ticket_no LIKE ? OR t.subject LIKE ? OR t.guest_email LIKE ? OR cu.name LIKE ? OR cu.email LIKE ? OR o.order_no LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like, $like);
        }
        $sql = self::selectSql() . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . " ORDER BY FIELD(t.urgency, 'urgent', 'high', 'normal', 'low'), t.last_message_at DESC, t.id DESC LIMIT 300";
        $rows = Database::query($sql, $args)->fetchAll();
        return self::decorate($rows);
    }

    /** One ticket with everything the staff chat panel needs, or null. */
    public static function detail(string $ticketNo): ?array
    {
        $row = Database::query(self::selectSql() . ' WHERE t.ticket_no = ? LIMIT 1', [$ticketNo])->fetch();
        if (!$row) {
            return null;
        }
        $t = self::decorate([$row])[0];
        $t['labels'] = self::labelsOf((int) $t['id']);
        // How many tickets this customer has opened (a member's tickets, or a guest's by email).
        $t['customer_tickets'] = (int) ($t['user_id'] !== null
            ? Database::query('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?', [(int) $t['user_id']])->fetchColumn()
            : Database::query('SELECT COUNT(*) FROM support_tickets WHERE user_id IS NULL AND guest_email = ?', [(string) $t['guest_email']])->fetchColumn());
        $t['all_labels'] = Database::query('SELECT id, name, colour FROM support_labels WHERE active = 1 ORDER BY name')->fetchAll();
        $t['subscription'] = $t['subscription_id'] !== null
            ? Database::query('SELECT s.id, s.status, s.cadence, p.name AS product_name FROM subscriptions s JOIN products p ON p.id = s.product_id WHERE s.id = ?', [(int) $t['subscription_id']])->fetch() ?: null
            : null;
        $t['order'] = $t['order_id'] !== null
            ? Database::query('SELECT id, order_no, status, payment_status, total FROM orders WHERE id = ?', [(int) $t['order_id']])->fetch() ?: null
            : null;
        return $t;
    }

    /** The newest message a customer has written, for live alerts: its id and ticket number, or null. */
    public static function latestCustomerMessage(): ?array
    {
        $row = Database::query(
            "SELECT m.id, t.ticket_no FROM support_messages m JOIN support_tickets t ON t.id = m.ticket_id
             WHERE m.sender = 'customer' ORDER BY m.id DESC LIMIT 1"
        )->fetch();
        return $row ? ['id' => (int) $row['id'], 'ticket_no' => (string) $row['ticket_no']] : null;
    }

    /** Tickets where the customer wrote last and nobody has answered yet: the sidebar badge. */
    public static function needsReplyCount(): int
    {
        return (int) Database::query("SELECT COUNT(*) FROM support_tickets WHERE status = 'waiting_staff'")->fetchColumn();
    }

    /** Staff opened the ticket: clears its unread mark. */
    public static function markRead(int $ticketId): void
    {
        Database::query('UPDATE support_tickets SET staff_read_at = NOW(3) WHERE id = ?', [$ticketId]);
    }

    /** A public reply to the customer. Returns an error message, or null when posted. */
    public static function reply(array $ticket, int $staffId, string $body, array $files): ?string
    {
        $body = trim($body);
        if (($body === '' && $files === []) || mb_strlen($body) > SupportService::MAX_CHARS) {
            return 'Write a message of up to ' . SupportService::MAX_CHARS . ' characters, or attach a file.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $stored = [];
        try {
            Database::query("INSERT INTO support_messages (ticket_id, sender, staff_id, visibility, body) VALUES (?, 'staff', ?, 'public', ?)", [(int) $ticket['id'], $staffId, $body]);
            $messageId = (int) $pdo->lastInsertId();
            foreach ($files as $file) {
                $stored[] = SupportAttachmentService::store((int) $ticket['id'], $messageId, $file);
            }
            Database::query(
                "UPDATE support_tickets SET status = 'waiting_customer', last_message_at = NOW(3), staff_read_at = NOW(3), resolved_at = NULL, assignee_id = COALESCE(assignee_id, ?) WHERE id = ?",
                [$staffId, (int) $ticket['id']]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            foreach ($stored as $name) {
                @unlink(SupportAttachmentService::dir() . '/' . $name);
            }
            throw $e;
        }
        AuditService::log($staffId, 'support.reply', (string) $ticket['ticket_no']);
        SupportNotifier::customerReplied($ticket); // one email per reply; internal notes send nothing
        return null;
    }

    /** An internal note: staff only, never shown to the customer. Does not change status or the customer's place in the list. */
    public static function note(array $ticket, int $staffId, string $body): ?string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > SupportService::MAX_CHARS) {
            return 'Write a note of up to ' . SupportService::MAX_CHARS . ' characters.';
        }
        Database::query("INSERT INTO support_messages (ticket_id, sender, staff_id, visibility, body) VALUES (?, 'staff', ?, 'internal', ?)", [(int) $ticket['id'], $staffId, $body]);
        Database::query('UPDATE support_tickets SET staff_read_at = NOW(3) WHERE id = ?', [(int) $ticket['id']]);
        AuditService::log($staffId, 'support.note', (string) $ticket['ticket_no']);
        return null;
    }

    /** Assign to an active admin or staff member, or unassign with null. Returns an error or null. */
    public static function assign(array $ticket, ?int $assigneeId, int $actorId): ?string
    {
        if ($assigneeId !== null && !self::assignable($assigneeId)) {
            return 'Choose an active admin or staff member.';
        }
        Database::query('UPDATE support_tickets SET assignee_id = ? WHERE id = ?', [$assigneeId, (int) $ticket['id']]);
        AuditService::log($actorId, 'support.assign', $ticket['ticket_no'] . ' -> ' . ($assigneeId ?? 'none'));
        return null;
    }

    /** Change the status. Resolved records when; any other status clears it. */
    public static function setStatus(array $ticket, string $status, int $actorId): ?string
    {
        if (!isset(self::STATUSES[$status])) {
            return 'Unknown status.';
        }
        if ($status === 'resolved') {
            Database::query("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW(3) WHERE id = ?", [(int) $ticket['id']]);
        } else {
            Database::query('UPDATE support_tickets SET status = ?, resolved_at = NULL WHERE id = ?', [$status, (int) $ticket['id']]);
        }
        AuditService::log($actorId, 'support.status', $ticket['ticket_no'] . ' -> ' . $status);
        return null;
    }

    /** Admins only (D2; the controller checks the role). */
    public static function setUrgency(array $ticket, string $urgency, int $actorId): ?string
    {
        if (!isset(\App\Services\SupportTicketService::URGENCIES[$urgency])) {
            return 'Unknown urgency.';
        }
        Database::query('UPDATE support_tickets SET urgency = ? WHERE id = ?', [$urgency, (int) $ticket['id']]);
        AuditService::log($actorId, 'support.urgency', $ticket['ticket_no'] . ' -> ' . $urgency);
        return null;
    }

    /** Replace the ticket's labels with these (only active labels count). */
    public static function setLabels(array $ticket, array $labelIds, int $actorId): void
    {
        $ids = array_values(array_unique(array_map('intval', $labelIds)));
        $valid = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $valid = array_map('intval', array_column(Database::query("SELECT id FROM support_labels WHERE active = 1 AND id IN ({$in})", $ids)->fetchAll(), 'id'));
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('DELETE FROM support_ticket_labels WHERE ticket_id = ?', [(int) $ticket['id']]);
            foreach ($valid as $labelId) {
                Database::query('INSERT INTO support_ticket_labels (ticket_id, label_id) VALUES (?, ?)', [(int) $ticket['id'], $labelId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditService::log($actorId, 'support.labels', $ticket['ticket_no'] . ' -> ' . count($valid));
    }

    /**
     * Bulk actions on several tickets. $action: assign (value = staff id or 0 for none), status (value = status),
     * label (value = label id, added to each ticket). Returns the number of tickets changed, or an error.
     * @return array{ok:bool, count?:int, error?:string}
     */
    public static function bulk(string $action, array $ticketIds, string $value, int $actorId, bool $isAdmin): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ticketIds), fn($i) => $i > 0)));
        if ($ids === []) {
            return ['ok' => false, 'error' => 'Choose at least one ticket.'];
        }
        if (count($ids) > self::MAX_BULK) {
            return ['ok' => false, 'error' => 'Choose up to ' . self::MAX_BULK . ' tickets at a time.'];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tickets = Database::query("SELECT * FROM support_tickets WHERE id IN ({$in})", $ids)->fetchAll();
        if ($action === 'label') {
            $label = (int) $value;
            if (Database::query('SELECT COUNT(*) FROM support_labels WHERE id = ? AND active = 1', [$label])->fetchColumn() == 0) {
                return ['ok' => false, 'error' => 'Choose an active label.'];
            }
        } elseif ($action === 'assign') {
            $assignee = (int) $value > 0 ? (int) $value : null;
            if ($assignee !== null && !self::assignable($assignee)) {
                return ['ok' => false, 'error' => 'Choose an active admin or staff member.'];
            }
        } elseif ($action === 'status') {
            if (!isset(self::STATUSES[$value])) {
                return ['ok' => false, 'error' => 'Unknown status.'];
            }
        } else {
            return ['ok' => false, 'error' => 'Unknown action.'];
        }
        $done = 0;
        foreach ($tickets as $t) {
            if ($action === 'label') {
                $existing = array_column(self::labelsOf((int) $t['id']), 'id');
                self::setLabels($t, array_merge(array_map('intval', $existing), [(int) $value]), $actorId);
            } elseif ($action === 'assign') {
                self::assign($t, (int) $value > 0 ? (int) $value : null, $actorId);
            } else {
                self::setStatus($t, $value, $actorId);
            }
            $done++;
        }
        return ['ok' => true, 'count' => $done];
    }

    /** Can this user be given a ticket? Active admins and staff only. */
    public static function assignable(int $userId): bool
    {
        $in = implode(',', array_fill(0, count(self::ASSIGNABLE_ROLES), '?'));
        return Database::query("SELECT COUNT(*) FROM users WHERE id = ? AND status = 'active' AND role IN ({$in})", array_merge([$userId], self::ASSIGNABLE_ROLES))->fetchColumn() > 0;
    }

    /** Staff who can be picked as assignee. @return array<int, array{id:int, name:string}> */
    public static function assignees(): array
    {
        $in = implode(',', array_fill(0, count(self::ASSIGNABLE_ROLES), '?'));
        return Database::query("SELECT id, name FROM users WHERE status = 'active' AND role IN ({$in}) ORDER BY name", self::ASSIGNABLE_ROLES)->fetchAll();
    }

    /** @return array<int, array{id:int, name:string, colour:string}> */
    public static function labelsOf(int $ticketId): array
    {
        return Database::query('SELECT l.id, l.name, l.colour FROM support_ticket_labels tl JOIN support_labels l ON l.id = tl.label_id WHERE tl.ticket_id = ? ORDER BY l.name', [$ticketId])->fetchAll();
    }

    private static function selectSql(): string
    {
        return "SELECT t.id, t.ticket_no, t.subject, t.status, t.urgency, t.user_id, t.guest_email, t.order_id, t.subscription_id,
                       t.assignee_id, t.last_message_at, t.staff_read_at, t.resolved_at, t.created_at,
                       c.name AS category_name, o.order_no, cu.name AS customer_name, COALESCE(cu.email, t.guest_email) AS customer_email,
                       cu.phone AS customer_phone, cu.created_at AS customer_since,
                       au.name AS assignee_name,
                       (SELECT MAX(m.created_at) FROM support_messages m WHERE m.ticket_id = t.id AND m.sender = 'customer') AS last_customer_at
                FROM support_tickets t
                JOIN support_categories c ON c.id = t.category_id
                LEFT JOIN orders o ON o.id = t.order_id
                LEFT JOIN users cu ON cu.id = t.user_id
                LEFT JOIN users au ON au.id = t.assignee_id";
    }

    /** Adds the computed fields: unread, overdue (waiting for us past its target), and the labels for the list. */
    private static function decorate(array $rows): array
    {
        $ids = array_map('intval', array_column($rows, 'id'));
        $labels = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::query("SELECT tl.ticket_id, l.id, l.name, l.colour FROM support_ticket_labels tl JOIN support_labels l ON l.id = tl.label_id WHERE tl.ticket_id IN ({$in}) ORDER BY l.name", $ids)->fetchAll() as $l) {
                $labels[(int) $l['ticket_id']][] = ['id' => (int) $l['id'], 'name' => $l['name'], 'colour' => $l['colour']];
            }
        }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['labels'] = $labels[$r['id']] ?? [];
            $r['unread'] = $r['staff_read_at'] === null || ($r['last_customer_at'] !== null && new \DateTimeImmutable((string) $r['last_customer_at']) > new \DateTimeImmutable((string) $r['staff_read_at']));
            $hours = self::SLA_HOURS[$r['urgency']] ?? null;
            $r['overdue'] = $hours !== null && $r['status'] === 'waiting_staff' && $r['last_customer_at'] !== null
                && strtotime((string) $r['last_customer_at']) < time() - $hours * 3600;
        }
        unset($r);
        return $rows;
    }
}
