<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;

/**
 * Multiple warehouses (module multi_warehouse). Product stock totals stay where they were (products.stock and
 * product_variants.stock). The default warehouse holds what is not in another warehouse; every other warehouse keeps
 * an explicit count. An order is shipped from one warehouse, recorded on the order, and a cancel or return puts the
 * stock back there. With the module off, nothing here runs and the shop works as before.
 */
final class WarehouseService
{
    public static function enabled(): bool
    {
        return Modules::enabled('multi_warehouse');
    }

    public static function all(): array
    {
        return Database::query('SELECT * FROM warehouses ORDER BY is_default DESC, sort_order, id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        return Database::query('SELECT * FROM warehouses WHERE id = ?', [$id])->fetch() ?: null;
    }

    public static function defaultId(): ?int
    {
        $id = Database::query('SELECT id FROM warehouses WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Stock of one product (or variant) held in the other, non-default warehouses. */
    public static function heldElsewhere(int $productId, int $variantId): int
    {
        return (int) Database::query(
            'SELECT COALESCE(SUM(s.qty), 0) FROM warehouse_stock s JOIN warehouses w ON w.id = s.warehouse_id
              WHERE w.is_default = 0 AND s.product_id = ? AND s.variant_id = ?',
            [$productId, $variantId]
        )->fetchColumn();
    }

    /** The total stock of a product (or variant), as the shop has always counted it. */
    public static function total(int $productId, int $variantId): int
    {
        if ($variantId > 0) {
            return (int) Database::query('SELECT stock FROM product_variants WHERE id = ? AND product_id = ?', [$variantId, $productId])->fetchColumn();
        }
        return (int) Database::query('SELECT stock FROM products WHERE id = ?', [$productId])->fetchColumn();
    }

    /** How many of a product (or variant) a warehouse can give out now. */
    public static function available(int $warehouseId, int $productId, int $variantId): int
    {
        $w = self::find($warehouseId);
        if ($w === null) {
            return 0;
        }
        if ((int) $w['is_default'] === 1) {
            return max(0, self::total($productId, $variantId) - self::heldElsewhere($productId, $variantId));
        }
        return (int) Database::query('SELECT COALESCE(SUM(qty), 0) FROM warehouse_stock WHERE warehouse_id = ? AND product_id = ? AND variant_id = ?', [$warehouseId, $productId, $variantId])->fetchColumn();
    }

    /**
     * Picks the warehouse to ship an order from: one that holds every physical line. The same state comes first, then the
     * default warehouse, then the others. Returns null when no single warehouse has everything.
     * @param array<int,array{product_id:int, variant_id:?int, qty:int}> $lines
     */
    public static function chooseFor(array $lines, string $state): ?int
    {
        if ($lines === []) {
            return self::defaultId();
        }
        $warehouses = Database::query('SELECT id, is_default, state FROM warehouses WHERE active = 1 ORDER BY (state = ?) DESC, is_default DESC, sort_order, id', [$state])->fetchAll();
        foreach ($warehouses as $w) {
            $ok = true;
            foreach ($lines as $l) {
                if (self::available((int) $w['id'], (int) $l['product_id'], (int) ($l['variant_id'] ?? 0)) < (int) $l['qty']) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return (int) $w['id'];
            }
        }
        return null;
    }

    /**
     * Called by StockService for every stock change. An order-linked change moves the warehouse the order shipped from.
     * Any change must leave the default warehouse with a count that is not negative.
     * @throws \DomainException|CheckoutException when the warehouse cannot give the stock
     */
    public static function onChange(int $productId, int $variantId, int $delta, ?int $orderId): void
    {
        if (!self::enabled()) {
            return;
        }
        $whId = null;
        if ($orderId !== null) {
            $whId = Database::query('SELECT warehouse_id FROM orders WHERE id = ?', [$orderId])->fetchColumn();
            $whId = $whId === false || $whId === null ? null : (int) $whId;
        }
        $w = $whId !== null ? self::find($whId) : null;
        if ($w !== null && (int) $w['is_default'] === 0 && $delta !== 0) {
            // Lock the warehouse's row, then move it. It may not go below zero.
            Database::query('INSERT IGNORE INTO warehouse_stock (warehouse_id, product_id, variant_id, qty) VALUES (?, ?, ?, 0)', [$whId, $productId, $variantId]);
            $row = Database::query('SELECT qty FROM warehouse_stock WHERE warehouse_id = ? AND product_id = ? AND variant_id = ? FOR UPDATE', [$whId, $productId, $variantId])->fetch();
            $now = (int) $row['qty'] + $delta;
            if ($now < 0) {
                throw new CheckoutException('"' . self::itemName($productId, $variantId) . '" is not in stock at ' . $w['name'] . ' any more. Please reduce the quantity or try again later.');
            }
            Database::query('UPDATE warehouse_stock SET qty = ? WHERE warehouse_id = ? AND product_id = ? AND variant_id = ?', [$now, $whId, $productId, $variantId]);
        }
        // The default warehouse is what is left after the others: it must not go negative.
        $total = self::total($productId, $variantId);
        if ($total < self::heldElsewhere($productId, $variantId)) {
            throw new \DomainException('Some of this stock is held at another warehouse. Move it back before you take this much out.');
        }
    }

    private static function itemName(int $productId, int $variantId): string
    {
        $name = (string) Database::query('SELECT name FROM products WHERE id = ?', [$productId])->fetchColumn();
        return $variantId > 0 ? $name . ' · ' . VariantService::label($variantId) : $name;
    }

    /**
     * Moves stock from one warehouse to another, within the totals. Returns an error message, or null.
     */
    public static function transfer(int $fromId, int $toId, int $productId, int $variantId, int $qty, int $actorId): ?string
    {
        if ($fromId === $toId) {
            return 'Choose two different warehouses.';
        }
        $from = self::find($fromId);
        $to = self::find($toId);
        if ($from === null || $to === null || (int) $to['active'] !== 1) {
            return 'Choose warehouses that exist and are active.';
        }
        if ($qty < 1 || $qty > 100000) {
            return 'Enter a quantity from 1 up.';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if (self::available($fromId, $productId, $variantId) < $qty) {
                $pdo->rollBack();
                return 'Only ' . self::available($fromId, $productId, $variantId) . ' in ' . $from['name'] . '.';
            }
            // Out of the source (an explicit count), into the destination (an explicit count). The default warehouse
            // needs no row: its count follows from the totals.
            if ((int) $from['is_default'] === 0) {
                self::addQty($fromId, $productId, $variantId, -$qty);
            }
            if ((int) $to['is_default'] === 0) {
                self::addQty($toId, $productId, $variantId, $qty);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditService::log($actorId, 'warehouse.transfer', "{$qty} from {$from['name']} to {$to['name']} (product {$productId}, variant {$variantId})");
        return null;
    }

    private static function addQty(int $warehouseId, int $productId, int $variantId, int $delta): void
    {
        Database::query('INSERT IGNORE INTO warehouse_stock (warehouse_id, product_id, variant_id, qty) VALUES (?, ?, ?, 0)', [$warehouseId, $productId, $variantId]);
        $row = Database::query('SELECT qty FROM warehouse_stock WHERE warehouse_id = ? AND product_id = ? AND variant_id = ? FOR UPDATE', [$warehouseId, $productId, $variantId])->fetch();
        $now = (int) $row['qty'] + $delta;
        if ($now < 0) {
            throw new \DomainException('Not enough stock to move.');
        }
        Database::query('UPDATE warehouse_stock SET qty = ? WHERE warehouse_id = ? AND product_id = ? AND variant_id = ?', [$now, $warehouseId, $productId, $variantId]);
    }

    /** @return array<string,string> errors */
    public static function validate(array $in): array
    {
        $errors = (new Validator($in, ['name' => 'required|max:120', 'state' => 'max:60', 'postcode' => 'max:10']))->errors();
        if (($in['postcode'] ?? '') !== '' && !preg_match('/^\d{5}$/', (string) $in['postcode'])) {
            $errors['postcode'] = 'Postcode must be 5 digits.';
        }
        return $errors;
    }

    /** Creates a warehouse (id null) or updates one. The default warehouse cannot be switched off. @return array<string,string> */
    public static function save(?int $id, array $in, int $actorId): array
    {
        $errors = self::validate($in);
        $current = $id !== null ? self::find($id) : null;
        if ($id !== null && $current === null) {
            return ['name' => 'That warehouse no longer exists.'];
        }
        $active = ($in['active'] ?? '0') === '1' ? 1 : 0;
        if ($current !== null && (int) $current['is_default'] === 1 && $active === 0) {
            $errors['active'] = 'The default warehouse must stay active.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $state = trim((string) $in['state']) ?: null;
        $postcode = trim((string) $in['postcode']) ?: null;
        if ($id === null) {
            Database::query('INSERT INTO warehouses (name, state, postcode, active, sort_order) VALUES (?, ?, ?, ?, ?)', [trim($in['name']), $state, $postcode, $active, max(0, (int) ($in['sort_order'] ?? 0))]);
            AuditService::log($actorId, 'warehouse.create', 'warehouse: ' . trim($in['name']));
        } else {
            Database::query('UPDATE warehouses SET name = ?, state = ?, postcode = ?, active = ? WHERE id = ?', [trim($in['name']), $state, $postcode, $active, $id]);
            AuditService::log($actorId, 'warehouse.update', 'warehouse ' . $id . ': ' . trim($in['name']));
        }
        return [];
    }
}
