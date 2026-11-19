<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;

/**
 * Marketing flows (module marketing_flows). A trigger schedules one email per customer, once: marketing_sends has a
 * unique (flow, customer, ref), so repeating a trigger changes nothing. bin/marketing.php sends the due emails. A flow
 * that is switched off sends nothing, and its waiting emails stay waiting until it is switched on again.
 */
final class MarketingService
{
    public const FLOWS = ['welcome', 'review_request', 'win_back'];

    public static function enabled(): bool
    {
        return Modules::enabled('marketing_flows');
    }

    public static function flows(): array
    {
        return Database::query('SELECT * FROM marketing_flows ORDER BY id')->fetchAll();
    }

    public static function flow(string $key): ?array
    {
        return Database::query('SELECT * FROM marketing_flows WHERE flow_key = ?', [$key])->fetch() ?: null;
    }

    /** Counts per status, for the admin page. */
    public static function stats(): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (Database::query('SELECT status, COUNT(*) AS n FROM marketing_sends GROUP BY status')->fetchAll() as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<string,string> errors (empty when valid) */
    public static function validateFlow(array $in): array
    {
        $errors = (new Validator($in, ['subject' => 'required|max:190', 'body' => 'required|max:4000']))->errors();
        if (!ctype_digit((string) $in['delay_hours']) || (int) $in['delay_hours'] > 720) {
            $errors['delay_hours'] = 'Enter a delay from 0 to 720 hours.';
        }
        if ($in['repeat_days'] !== '' && (!ctype_digit((string) $in['repeat_days']) || (int) $in['repeat_days'] < 1 || (int) $in['repeat_days'] > 365)) {
            $errors['repeat_days'] = 'Enter how many days before a customer can get it again (1 to 365).';
        }
        if ($in['segment_id'] !== '' && CustomerSegmentService::find((int) $in['segment_id']) === null) {
            $errors['segment_id'] = 'Choose a segment that exists.';
        }
        return $errors;
    }

    /** Saves a flow's settings. @return array<string,string> errors */
    public static function saveFlow(string $key, array $in, int $actorId): array
    {
        if (self::flow($key) === null) {
            return ['flow' => 'Unknown flow.'];
        }
        $errors = self::validateFlow($in);
        if ($errors !== []) {
            return $errors;
        }
        Database::query(
            'UPDATE marketing_flows SET subject = ?, body = ?, delay_hours = ?, enabled = ?, segment_id = ?, repeat_days = ? WHERE flow_key = ?',
            [$in['subject'], $in['body'], (int) $in['delay_hours'], ($in['enabled'] ?? '0') === '1' ? 1 : 0,
             $in['segment_id'] !== '' ? (int) $in['segment_id'] : null, $in['repeat_days'] !== '' ? (int) $in['repeat_days'] : null, $key]
        );
        AuditService::log($actorId, 'marketing.flow', "flow {$key}: " . (($in['enabled'] ?? '0') === '1' ? 'on' : 'off'));
        return [];
    }

    /**
     * Schedules one email for a customer. Returns false when it was already scheduled (or the flow is off).
     */
    public static function schedule(string $key, int $userId, string $ref, ?int $delayHours = null): bool
    {
        $f = self::flow($key);
        if ($f === null || (int) $f['enabled'] !== 1) {
            return false;
        }
        $hours = $delayHours ?? (int) $f['delay_hours'];
        $stmt = Database::query(
            'INSERT IGNORE INTO marketing_sends (flow_id, user_id, ref, due_at) VALUES (?, ?, ?, ?)',
            [(int) $f['id'], $userId, $ref, date('Y-m-d H:i:s', time() + $hours * 3600)]
        );
        return $stmt->rowCount() === 1;
    }

    /** A member has confirmed their email address. */
    public static function onVerified(int $userId): void
    {
        if (!self::enabled()) {
            return;
        }
        self::schedule('welcome', $userId, 'welcome');
    }

    /** An order is completed: the member who placed it is asked for a review. Guest orders are not. */
    public static function onOrderCompleted(int $orderId): void
    {
        if (!self::enabled()) {
            return;
        }
        $userId = Database::query('SELECT user_id FROM orders WHERE id = ?', [$orderId])->fetchColumn();
        if ($userId !== false && $userId !== null) {
            self::schedule('review_request', (int) $userId, 'order:' . $orderId);
        }
    }

    /**
     * Win-back: the members of the flow's segment, scheduled once per repeat period. Returns how many were scheduled.
     */
    public static function scanWinBack(?int $now = null): int
    {
        $f = self::flow('win_back');
        if (!self::enabled() || $f === null || (int) $f['enabled'] !== 1 || $f['segment_id'] === null) {
            return 0;
        }
        $segment = CustomerSegmentService::find((int) $f['segment_id']);
        if ($segment === null) {
            return 0;
        }
        $repeat = max(1, (int) ($f['repeat_days'] ?? 90));
        $bucket = intdiv($now ?? time(), $repeat * 86400); // one period per repeat window
        $n = 0;
        foreach (CustomerSegmentService::members($segment['rules']) as $m) {
            if (self::schedule('win_back', (int) $m['id'], 'period:' . $bucket, 0)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Sends the emails that are due (up to $limit). Each email is checked again before it goes: the flow is on, the
     * account is still an active member, the address has not unsubscribed. Returns the counts.
     * @return array{sent:int, skipped:int, failed:int, waiting:int}
     */
    public static function runDue(int $limit = 200): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0];
        $rows = Database::query(
            "SELECT s.id, s.user_id, s.ref, f.flow_key, f.subject, f.body, f.enabled
               FROM marketing_sends s JOIN marketing_flows f ON f.id = s.flow_id
              WHERE s.status = 'pending' AND s.due_at <= NOW() ORDER BY s.due_at, s.id LIMIT " . max(1, $limit)
        )->fetchAll();
        $unsubscribed = UnsubscribeService::set();
        foreach ($rows as $r) {
            if ((int) $r['enabled'] !== 1) {
                $out['waiting']++; // the flow is off: nothing is sent, and the email waits
                continue;
            }
            $user = Database::query('SELECT name, email, role, status FROM users WHERE id = ?', [(int) $r['user_id']])->fetch();
            $email = trim((string) ($user['email'] ?? ''));
            if ($user === false || $user === null || $user['role'] !== 'member' || $user['status'] !== 'active') {
                self::finish((int) $r['id'], 'skipped', 'account is not an active member');
                $out['skipped']++;
                continue;
            }
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($unsubscribed[strtolower($email)])) {
                self::finish((int) $r['id'], 'skipped', 'not an address, or unsubscribed');
                $out['skipped']++;
                continue;
            }
            $orderId = str_starts_with((string) $r['ref'], 'order:') ? (int) substr((string) $r['ref'], 6) : null;
            $vars = [
                'name' => (string) $user['name'],
                'shop' => (string) config('app.name'),
                'shop_url' => url('/'),
                'review_url' => url('/member/orders'),
                'order_no' => $orderId !== null ? (string) Database::query('SELECT order_no FROM orders WHERE id = ?', [$orderId])->fetchColumn() : '',
            ];
            $res = MailService::send($email, EmailService::render((string) $r['subject'], $vars), EmailService::render((string) $r['body'], $vars), 'marketing', $orderId);
            if ($res['ok']) {
                self::finish((int) $r['id'], 'sent', null);
                $out['sent']++;
            } elseif (($res['message'] ?? '') === 'Email is not set up.') {
                $out['waiting']++; // email is not switched on: keep it waiting, do not mark it failed
            } else {
                self::finish((int) $r['id'], 'failed', mb_substr((string) ($res['message'] ?? 'send failed'), 0, 190));
                $out['failed']++;
            }
        }
        return $out;
    }

    private static function finish(int $sendId, string $status, ?string $note): void
    {
        Database::query('UPDATE marketing_sends SET status = ?, sent_at = ?, note = ? WHERE id = ?', [
            $status, $status === 'sent' ? date('Y-m-d H:i:s') : null, $note, $sendId,
        ]);
    }
}
