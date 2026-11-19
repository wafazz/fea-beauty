<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Customer segments (module customer_segments): saved rules that group customers by what they buy, plus manual tags.
 * Orders are counted without cancelled ones. A segment is never copied: its members are worked out from the orders
 * and tags each time. Customer contact details leave the system only through the CSV export, which is logged.
 */
final class CustomerSegmentService
{
    public const RULE_KEYS = ['min_orders', 'min_spent', 'within_days', 'inactive_days', 'tag'];

    public static function enabled(): bool
    {
        return Modules::enabled('customer_segments');
    }

    /**
     * Checks a rule set from a form. Unknown keys are dropped; an empty rule set is refused (it would match everyone).
     * @return array{rules: array, errors: array<string,string>}
     */
    public static function parseRules(array $in): array
    {
        $rules = [];
        $errors = [];
        foreach (['min_orders' => [0, 100000], 'within_days' => [1, 3650], 'inactive_days' => [1, 3650]] as $k => [$lo, $hi]) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                continue;
            }
            if (!ctype_digit($v) || (int) $v < $lo || (int) $v > $hi) {
                $errors[$k] = "Enter a whole number from {$lo} to {$hi}.";
            } else {
                $rules[$k] = (int) $v;
            }
        }
        $spent = trim((string) ($in['min_spent'] ?? ''));
        if ($spent !== '') {
            if (!PricingService::isMoney($spent)) {
                $errors['min_spent'] = 'Enter an amount such as 300.00.';
            } else {
                $rules['min_spent'] = PricingService::cents($spent);
            }
        }
        $tag = strtolower(trim((string) ($in['tag'] ?? '')));
        if ($tag !== '') {
            if (!self::validTag($tag)) {
                $errors['tag'] = 'A tag is 1-30 letters, numbers or dashes.';
            } else {
                $rules['tag'] = $tag;
            }
        }
        if ($rules === [] && $errors === []) {
            $errors['rules'] = 'Set at least one rule, otherwise the segment would be every customer.';
        }
        if (isset($rules['within_days']) && !isset($rules['min_orders']) && !isset($rules['min_spent'])) {
            $errors['within_days'] = 'A time window needs a minimum order count or a minimum spend.';
        }
        return ['rules' => $rules, 'errors' => $errors];
    }

    public static function validTag(string $tag): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $tag) === 1;
    }

    /** Segments with their current member count. */
    public static function all(): array
    {
        $out = [];
        foreach (Database::query('SELECT * FROM customer_segments ORDER BY name')->fetchAll() as $s) {
            $s['rules'] = json_decode((string) $s['rules'], true) ?: [];
            $s['members'] = self::count($s['rules']);
            $out[] = $s;
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        $s = Database::query('SELECT * FROM customer_segments WHERE id = ?', [$id])->fetch();
        if (!$s) {
            return null;
        }
        $s['rules'] = json_decode((string) $s['rules'], true) ?: [];
        return $s;
    }

    /** Creates a segment (id null) or updates one. @return array<string,string> errors */
    public static function save(?int $id, string $name, array $in, int $actorId): array
    {
        $name = trim($name);
        $parsed = self::parseRules($in);
        $errors = $parsed['errors'];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the segment a name (at most 120 characters).';
        } elseif (Database::query('SELECT COUNT(*) FROM customer_segments WHERE name = ? AND id <> ?', [$name, $id ?? 0])->fetchColumn() > 0) {
            $errors['name'] = 'Another segment has that name.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $json = json_encode($parsed['rules'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($id === null) {
            Database::query('INSERT INTO customer_segments (name, rules, created_by) VALUES (?, ?, ?)', [$name, $json, $actorId]);
            AuditService::log($actorId, 'segment.create', 'segment: ' . $name);
        } else {
            Database::query('UPDATE customer_segments SET name = ?, rules = ? WHERE id = ?', [$name, $json, $id]);
            AuditService::log($actorId, 'segment.update', 'segment ' . $id . ': ' . $name);
        }
        return [];
    }

    public static function delete(int $id, int $actorId): void
    {
        $s = self::find($id);
        if ($s !== null) {
            Database::query('DELETE FROM customer_segments WHERE id = ?', [$id]);
            AuditService::log($actorId, 'segment.delete', 'segment ' . $id . ': ' . $s['name']);
        }
    }

    /** The members of a rule set: id, name, email, phone, orders, spent, last order. */
    public static function members(array $rules, ?int $limit = null): array
    {
        [$sql, $args] = self::sql($rules, false);
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }
        return Database::query($sql, $args)->fetchAll();
    }

    public static function count(array $rules): int
    {
        [$sql, $args] = self::sql($rules, true);
        return (int) Database::query($sql, $args)->fetchColumn();
    }

    /**
     * The SQL for a rule set. Counts use only orders in the window; "last order" uses all orders, so an inactive rule
     * means the same thing whatever window is set.
     * @return array{0:string,1:array}
     */
    private static function sql(array $r, bool $countOnly): array
    {
        $args = [];
        $window = "SELECT user_id, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS spent FROM orders WHERE status <> 'cancelled'";
        if (isset($r['within_days'])) {
            $window .= ' AND created_at >= ?';
            $args[] = date('Y-m-d H:i:s', time() - (int) $r['within_days'] * 86400);
        }
        $window .= ' GROUP BY user_id';

        $where = ["u.role = 'member'"];
        $cond = [];
        if (isset($r['min_orders'])) {
            $cond[] = 'COALESCE(m.orders, 0) >= ?';
            $args[] = (int) $r['min_orders'];
        }
        if (isset($r['min_spent'])) {
            $cond[] = 'COALESCE(m.spent, 0) >= ?';
            $args[] = PricingService::format((int) $r['min_spent']);
        }
        if (isset($r['inactive_days'])) {
            $cond[] = '(l.last_order IS NULL OR l.last_order < ?)';
            $args[] = date('Y-m-d H:i:s', time() - (int) $r['inactive_days'] * 86400);
        }
        if (isset($r['tag'])) {
            $cond[] = 'EXISTS (SELECT 1 FROM customer_tags t WHERE t.user_id = u.id AND t.tag = ?)';
            $args[] = (string) $r['tag'];
        }
        $where = array_merge($where, $cond);

        $select = $countOnly
            ? 'COUNT(*)'
            : 'u.id, u.name, u.email, u.phone, COALESCE(m.orders, 0) AS orders, COALESCE(m.spent, 0) AS spent, l.last_order';
        $sql = "SELECT {$select} FROM users u
                LEFT JOIN ({$window}) m ON m.user_id = u.id
                LEFT JOIN (SELECT user_id, MAX(created_at) AS last_order FROM orders WHERE status <> 'cancelled' GROUP BY user_id) l ON l.user_id = u.id
                WHERE " . implode(' AND ', $where)
            . ($countOnly ? '' : ' ORDER BY spent DESC, u.id');
        return [$sql, $args];
    }

    // ---- tags ------------------------------------------------------------------------------------

    /** Tags of a customer, in alphabetical order. */
    public static function tags(int $userId): array
    {
        return Database::query('SELECT tag FROM customer_tags WHERE user_id = ? ORDER BY tag', [$userId])->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Replaces a customer's tags with the comma-separated list. @return array<string,string> errors
     */
    public static function setTags(int $userId, string $list, int $actorId): array
    {
        $tags = array_values(array_unique(array_filter(array_map(fn($t) => strtolower(trim($t)), explode(',', $list)), fn($t) => $t !== '')));
        if (count($tags) > 10) {
            return ['tags' => 'Use at most 10 tags per customer.'];
        }
        foreach ($tags as $t) {
            if (!self::validTag($t)) {
                return ['tags' => "\"{$t}\" is not a valid tag. Use 1-30 letters, numbers or dashes."];
            }
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('DELETE FROM customer_tags WHERE user_id = ?', [$userId]);
            foreach ($tags as $t) {
                Database::query('INSERT INTO customer_tags (user_id, tag) VALUES (?, ?)', [$userId, $t]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditService::log($actorId, 'customer.tags', 'customer ' . $userId . ': ' . ($tags === [] ? 'no tags' : implode(', ', $tags)));
        return [];
    }

    // ---- export ----------------------------------------------------------------------------------

    /** Spreadsheets run a cell that starts with = + - @ as a formula, so such values get a leading apostrophe. */
    public static function csvCell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        return $s !== '' && strpos('=+-@', $s[0]) !== false ? "'" . $s : $s;
    }

    /**
     * The members of a segment as CSV (name, email, phone, orders, total spent, last order). The export is logged.
     * @return string|null null when the segment does not exist
     */
    public static function exportCsv(int $segmentId, int $actorId): ?string
    {
        $s = self::find($segmentId);
        if ($s === null) {
            return null;
        }
        $rows = self::members($s['rules']);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['name', 'email', 'phone', 'orders', 'total_spent', 'last_order']);
        foreach ($rows as $r) {
            fputcsv($out, [self::csvCell($r['name']), self::csvCell($r['email']), self::csvCell($r['phone']), (int) $r['orders'], number_format((float) $r['spent'], 2, '.', ''), (string) ($r['last_order'] ?? '')]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        AuditService::log($actorId, 'segment.export', 'segment ' . $segmentId . ': ' . count($rows) . ' customer(s) exported');
        return $csv;
    }
}
