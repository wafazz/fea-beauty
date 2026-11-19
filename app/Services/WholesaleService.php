<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;

/**
 * Wholesale accounts (module wholesale_accounts). A business member applies, an admin approves it and sets a credit
 * limit and payment terms. Only an approved account can pay on credit terms, and its unpaid credit orders may not go
 * over the limit. Suspended accounts keep their unpaid orders on the ageing report.
 */
final class WholesaleService
{
    public const METHOD = 'credit_terms';
    public const BUCKETS = ['current' => 'Not yet due', 'd1_30' => '1-30 days overdue', 'd31_60' => '31-60 days overdue', 'd61' => 'Over 60 days overdue'];

    public static function enabled(): bool
    {
        return Modules::enabled('wholesale_accounts');
    }

    public static function account(int $userId): ?array
    {
        return Database::query('SELECT * FROM b2b_accounts WHERE user_id = ?', [$userId])->fetch() ?: null;
    }

    /** The account of an approved member who may pay on credit now. Null for everyone else (and while the module is off). */
    public static function approvedFor(?int $userId): ?array
    {
        if ($userId === null || !self::enabled()) {
            return null;
        }
        $a = self::account($userId);
        return $a !== null && $a['status'] === 'approved' ? $a : null;
    }

    /** Whether the signed-in shopper may pick credit terms at checkout. */
    public static function offeredNow(): bool
    {
        $me = \App\Core\Auth::user();
        return $me !== null && $me['role'] === 'member' && self::approvedFor((int) $me['id']) !== null;
    }

    /** Unpaid credit orders of a member (not cancelled or returned): the balance. */
    public static function openBalance(int $userId): float
    {
        return (float) Database::query(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE user_id = ? AND payment_method = ? AND payment_status = 'unpaid' AND status NOT IN ('cancelled', 'returned')",
            [$userId, self::METHOD]
        )->fetchColumn();
    }

    /** @return array<string,string> errors */
    public static function validateApplication(array $in): array
    {
        return (new Validator($in, ['company' => 'required|max:160', 'reg_no' => 'max:60']))->errors();
    }

    /** A member applies. A rejected or suspended application cannot be sent again from here. @return array<string,string> */
    public static function apply(int $userId, array $in): array
    {
        $errors = self::validateApplication($in);
        $existing = self::account($userId);
        if ($existing !== null) {
            return ['company' => $existing['status'] === 'suspended' ? 'Your business account is suspended. Contact the shop.' : 'You have already applied.'];
        }
        if ($errors !== []) {
            return $errors;
        }
        Database::query('INSERT INTO b2b_accounts (user_id, company, reg_no) VALUES (?, ?, ?)', [$userId, trim($in['company']), trim((string) ($in['reg_no'] ?? '')) ?: null]);
        AuditService::log($userId, 'wholesale.apply', 'business account applied: ' . trim($in['company']));
        return [];
    }

    /** An admin sets the status, limit and terms. @return array<string,string> errors */
    public static function update(int $accountId, array $in, int $actorId): array
    {
        $a = Database::query('SELECT * FROM b2b_accounts WHERE id = ?', [$accountId])->fetch();
        if (!$a) {
            return ['status' => 'Account not found.'];
        }
        if (!in_array($in['status'], ['pending', 'approved', 'suspended'], true)) {
            return ['status' => 'Choose a status.'];
        }
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $in['credit_limit'])) {
            return ['credit_limit' => 'Enter a credit limit such as 5000.00 (0 means no credit).'];
        }
        if (!ctype_digit((string) $in['terms_days']) || (int) $in['terms_days'] < 1 || (int) $in['terms_days'] > 120) {
            return ['terms_days' => 'Payment terms are 1 to 120 days.'];
        }
        Database::query('UPDATE b2b_accounts SET status = ?, credit_limit = ?, terms_days = ? WHERE id = ?', [$in['status'], $in['credit_limit'], (int) $in['terms_days'], $accountId]);
        AuditService::log($actorId, 'wholesale.update', "account {$accountId}: {$in['status']}, limit RM{$in['credit_limit']}, {$in['terms_days']} days");
        return [];
    }

    /**
     * Places a credit order's check inside the caller's transaction: locks the account, then checks the balance plus
     * this order against the limit. Throws CheckoutException when it is not allowed.
     */
    public static function reserveCredit(int $userId, float $orderTotal): array
    {
        $a = Database::query('SELECT * FROM b2b_accounts WHERE user_id = ? FOR UPDATE', [$userId])->fetch();
        if (!$a || $a['status'] !== 'approved' || !self::enabled()) {
            throw new CheckoutException('Credit terms are not available on your account. Choose another payment method.');
        }
        $after = self::openBalance($userId) + $orderTotal;
        if ($after > (float) $a['credit_limit'] + 0.00001) {
            throw new CheckoutException('This order would go over your credit limit of RM' . number_format((float) $a['credit_limit'], 2) . '. Choose another payment method, or ask the shop to raise your limit.');
        }
        return $a;
    }

    /**
     * The ageing report: every account with unpaid credit orders (suspended ones too), with the amount in each bucket,
     * measured from each order's due date.
     */
    public static function ageing(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $rows = Database::query(
            "SELECT a.id AS account_id, a.company, a.status, a.credit_limit, o.id AS order_id, o.order_no, o.total, o.due_date
               FROM orders o JOIN b2b_accounts a ON a.user_id = o.user_id
              WHERE o.payment_method = ? AND o.payment_status = 'unpaid' AND o.status NOT IN ('cancelled', 'returned')
              ORDER BY a.company, o.due_date, o.id",
            [self::METHOD]
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['account_id'];
            $out[$id] ??= ['account_id' => $id, 'company' => $r['company'], 'status' => $r['status'], 'limit' => (float) $r['credit_limit'],
                'total' => 0.0, 'buckets' => array_fill_keys(array_keys(self::BUCKETS), 0.0), 'orders' => []];
            $bucket = self::bucket((string) $r['due_date'], $today);
            $amount = (float) $r['total'];
            $out[$id]['buckets'][$bucket] += $amount;
            $out[$id]['total'] += $amount;
            $out[$id]['orders'][] = ['order_no' => $r['order_no'], 'total' => $amount, 'due_date' => $r['due_date'], 'bucket' => $bucket];
        }
        return array_values($out);
    }

    /** Which ageing bucket an order's due date falls in on $today. */
    public static function bucket(string $dueDate, string $today): string
    {
        if ($dueDate === '' || $dueDate >= $today) {
            return 'current';
        }
        $days = (int) floor((strtotime($today) - strtotime($dueDate)) / 86400);
        return match (true) {
            $days <= 30 => 'd1_30',
            $days <= 60 => 'd31_60',
            default => 'd61',
        };
    }

    /** Every application and account, for the admin page. */
    public static function all(): array
    {
        return Database::query(
            'SELECT a.*, u.name, u.email FROM b2b_accounts a JOIN users u ON u.id = a.user_id ORDER BY FIELD(a.status, \'pending\', \'approved\', \'suspended\'), a.id DESC'
        )->fetchAll();
    }
}
