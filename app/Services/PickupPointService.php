<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;

/**
 * Store pickup points (module shipping_rules). A customer can choose a point at checkout instead of delivery. The order
 * keeps a copy of the point's address, so it still reads correctly if the point changes later.
 */
final class PickupPointService
{
    /** Active points for the checkout, in the admin's order. */
    public static function active(): array
    {
        if (!Modules::enabled('shipping_rules')) {
            return [];
        }
        return Database::query('SELECT id, name, line1, city, state, postcode, opening_hours FROM pickup_points WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
    }

    /** All points for the admin page, active or not. */
    public static function all(): array
    {
        return Database::query('SELECT * FROM pickup_points ORDER BY sort_order, id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        return Database::query('SELECT * FROM pickup_points WHERE id = ?', [$id])->fetch() ?: null;
    }

    /**
     * The point to pick up from at checkout. Refused unless the module is on and the point is active.
     * @throws CheckoutException
     */
    public static function forCheckout(int $id): array
    {
        if (!Modules::enabled('shipping_rules')) {
            throw new CheckoutException('Pickup is not available right now. Choose delivery instead.');
        }
        $p = self::find($id);
        if ($p === null || (int) $p['active'] !== 1) {
            throw new CheckoutException('Please choose a pickup point.');
        }
        return $p;
    }

    /** @return array<string,string> field errors (empty when valid) */
    public static function validate(array $in): array
    {
        $errors = (new Validator($in, [
            'name' => 'required|max:120',
            'line1' => 'required|max:255',
            'city' => 'required|max:100',
            'postcode' => 'required|max:10',
            'opening_hours' => 'max:160',
            'sort_order' => 'max:9',
        ]))->errors();
        if (!isset($errors['state']) && !in_array($in['state'] ?? '', config('states') ?? [], true)) {
            $errors['state'] = 'Choose a state.';
        }
        if (!isset($errors['postcode']) && !preg_match('/^\d{5}$/', (string) $in['postcode'])) {
            $errors['postcode'] = 'Postcode must be 5 digits.';
        }
        return $errors;
    }

    /** Creates a point (id null) or updates one. @return array<string,string> errors */
    public static function save(?int $id, array $in, int $actorId): array
    {
        $errors = self::validate($in);
        if ($errors !== []) {
            return $errors;
        }
        $row = [
            'name' => $in['name'], 'line1' => $in['line1'], 'city' => $in['city'], 'state' => $in['state'], 'postcode' => $in['postcode'],
            'opening_hours' => trim((string) ($in['opening_hours'] ?? '')) ?: null,
            'active' => ($in['active'] ?? '0') === '1' ? 1 : 0,
            'sort_order' => max(0, (int) ($in['sort_order'] ?? 0)),
        ];
        if ($id === null) {
            Database::query(
                'INSERT INTO pickup_points (name, line1, city, state, postcode, opening_hours, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array_values($row)
            );
            $id = (int) Database::connection()->lastInsertId();
            AuditService::log($actorId, 'pickup.create', 'pickup point ' . $id . ': ' . $row['name']);
        } else {
            Database::query(
                'UPDATE pickup_points SET name = ?, line1 = ?, city = ?, state = ?, postcode = ?, opening_hours = ?, active = ?, sort_order = ? WHERE id = ?',
                [...array_values($row), $id]
            );
            AuditService::log($actorId, 'pickup.update', 'pickup point ' . $id . ': ' . $row['name']);
        }
        return [];
    }
}
