<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * Cross-sell suggestions (module cross_sell). For a product: the products an admin pinned to it, in order, then the
 * products most often bought with it in the last 180 days. Only active, in-stock products are shown; out-of-stock
 * ones are hidden, not removed. A product never suggests itself. bin/copurchase.php refreshes the bought-together
 * pairs once a day, so a page view only reads tables.
 */
final class CrossSellService
{
    public const SHOW = 4;          // cards on a product page, and in the cart
    public const MAX_PINS = 12;     // pinned products per product
    public const KEEP_AUTO = 12;    // bought-together products kept per product
    public const WINDOW_DAYS = 180; // how far back orders count
    public const MIN_ORDERS = 2;    // a pair must have been bought together in at least this many orders

    /** Product ids to suggest for one product: pinned first (in order), then bought together (most first). */
    public static function candidateIds(int $pid): array
    {
        $pinned = Database::query('SELECT related_id FROM product_related WHERE product_id = ? ORDER BY sort_order, related_id', [$pid])->fetchAll(\PDO::FETCH_COLUMN);
        $auto = Database::query('SELECT related_id FROM product_copurchase WHERE product_id = ? ORDER BY orders_together DESC, related_id', [$pid])->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_unique(array_map('intval', [...$pinned, ...$auto])));
    }

    /**
     * Sellable product cards for these ids, in the order given, at most $limit. One query for all of them.
     * @return array<int,array> rows in the same shape as the shop's product lists
     */
    public static function cards(array $ids, int $limit): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), fn(int $i) => $i > 0));
        if ($ids === [] || $limit < 1) {
            return [];
        }
        $rows = Product::publicList('', null, 'newest', 1, count($ids), null, ['ids' => $ids, 'instock' => true])['rows'];
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    /** Suggestions for a product page: never the product itself. */
    public static function forProduct(int $pid, int $limit = self::SHOW): array
    {
        $ids = array_values(array_filter(self::candidateIds($pid), fn(int $i) => $i !== $pid));
        return self::cards($ids, $limit);
    }

    /** Suggestions for the cart: from the products in it (the first five), leaving out what is already in it. */
    public static function forCart(array $cartProductIds, int $limit = self::SHOW): array
    {
        $inCart = array_values(array_unique(array_map('intval', $cartProductIds)));
        $ids = [];
        foreach (array_slice($inCart, 0, 5) as $pid) {
            foreach (self::candidateIds($pid) as $id) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_filter(array_unique($ids), fn(int $i) => !in_array($i, $inCart, true)));
        return self::cards($ids, $limit);
    }

    // ---- admin: pinned products ------------------------------------------------------------

    /** The pinned products of a product, in order, with what the admin needs to recognise them. */
    public static function pinned(int $pid): array
    {
        return Database::query(
            'SELECT p.id, p.name, p.sku, p.status, p.deleted_at FROM product_related r JOIN products p ON p.id = r.related_id
              WHERE r.product_id = ? ORDER BY r.sort_order, r.related_id',
            [$pid]
        )->fetchAll();
    }

    /**
     * Replaces a product's pinned list with $ids, in this order. Only active, not deleted products; never itself;
     * at most MAX_PINS. @return string[] errors (nothing is saved when there are any)
     */
    public static function savePins(int $pid, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, fn(int $i) => $i > 0));
        if (in_array($pid, $ids, true)) {
            return ['A product cannot be suggested with itself.'];
        }
        if (count($ids) > self::MAX_PINS) {
            return ['Pin at most ' . self::MAX_PINS . ' products to one product.'];
        }
        // Only products being added now must be active. A pinned product that has since gone inactive can still be removed.
        $existing = array_map('intval', Database::query('SELECT related_id FROM product_related WHERE product_id = ?', [$pid])->fetchAll(\PDO::FETCH_COLUMN));
        $new = array_values(array_diff($ids, $existing));
        if ($new !== []) {
            $found = Database::query(
                'SELECT id FROM products WHERE deleted_at IS NULL AND status = ? AND id IN (' . implode(',', array_fill(0, count($new), '?')) . ')',
                ['active', ...$new]
            )->fetchAll(\PDO::FETCH_COLUMN);
            if (count($found) !== count($new)) {
                return ['Some products are not active. Only active products can be pinned.'];
            }
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('DELETE FROM product_related WHERE product_id = ?', [$pid]);
            foreach ($ids as $pos => $rid) {
                Database::query('INSERT INTO product_related (product_id, related_id, sort_order) VALUES (?, ?, ?)', [$pid, $rid, $pos]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [];
    }

    /** Active products whose name or SKU match $q, not this product and not already pinned. */
    public static function search(int $pid, string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        return Database::query(
            "SELECT p.id, p.name, p.sku FROM products p
              WHERE p.deleted_at IS NULL AND p.status = 'active' AND p.id <> ?
                AND (p.name LIKE ? OR p.sku LIKE ?)
                AND p.id NOT IN (SELECT related_id FROM product_related WHERE product_id = ?)
              ORDER BY p.name LIMIT " . (int) $limit,
            [$pid, $like, $like, $pid]
        )->fetchAll();
    }

    // ---- the daily job -------------------------------------------------------------------------

    /**
     * Works out which products are bought together and replaces product_copurchase with the result. A pair counts when
     * both products were in at least MIN_ORDERS orders in the window; cancelled orders do not count. Returns the number
     * of rows written.
     */
    public static function recompute(?int $now = null): int
    {
        $since = date('Y-m-d H:i:s', ($now ?? time()) - self::WINDOW_DAYS * 86400);
        $pairs = Database::query(
            "SELECT a.product_id AS pid, b.product_id AS other, COUNT(DISTINCT a.order_id) AS n
               FROM order_items a
               JOIN order_items b ON b.order_id = a.order_id AND b.product_id <> a.product_id
               JOIN orders o ON o.id = a.order_id
              WHERE a.product_id IS NOT NULL AND b.product_id IS NOT NULL
                AND o.status <> 'cancelled' AND o.created_at >= ?
              GROUP BY a.product_id, b.product_id
             HAVING n >= ?",
            [$since, self::MIN_ORDERS]
        )->fetchAll();

        // Keep the strongest KEEP_AUTO pairs for each product.
        $byProduct = [];
        foreach ($pairs as $r) {
            $byProduct[(int) $r['pid']][] = ['other' => (int) $r['other'], 'n' => (int) $r['n']];
        }
        $keep = [];
        foreach ($byProduct as $pid => $rows) {
            usort($rows, fn(array $x, array $y) => $y['n'] <=> $x['n'] ?: $x['other'] <=> $y['other']);
            foreach (array_slice($rows, 0, self::KEEP_AUTO) as $r) {
                $keep[] = [$pid, $r['other'], $r['n']];
            }
        }

        $stamp = date('Y-m-d H:i:s', $now ?? time());
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('DELETE FROM product_copurchase');
            foreach ($keep as [$pid, $other, $n]) {
                Database::query('INSERT INTO product_copurchase (product_id, related_id, orders_together, computed_at) VALUES (?, ?, ?, ?)', [$pid, $other, $n, $stamp]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return count($keep);
    }
}
