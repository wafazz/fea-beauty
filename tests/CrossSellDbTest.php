<?php
// Cross-sell in the database: pinned order, sellable products only, never the product itself, bought-together pairs
// (two orders minimum, cancelled orders ignored), saving pins, and the cart.
use App\Core\Database as DB;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\CrossSellService as X;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();

$make = function (string $name, int $stock = 50, string $status = 'active'): int {
    return Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10',
        'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'sku' => 'XS-' . strtoupper(substr(md5($name), 0, 6)),
        'category_id' => null, 'brand_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00',
        'discount_price' => null, 'stock' => $stock, 'status' => $status]);
};
$member = function (string $name, string $email): int {
    DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', ?, ?, 'x', 'active')", [$name, $email]);
    return (int) DB::connection()->lastInsertId();
};
// One order with these products (qty 1 each), then its status set (cancelled or otherwise).
$order = function (int $userId, array $productIds, string $status = 'pending'): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $cart = (int) DB::connection()->lastInsertId();
    foreach ($productIds as $pid) {
        DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty, reserved_until) VALUES (?,?,?,?, NOW() + INTERVAL 15 MINUTE)', [$cart, $pid, null, 1]);
    }
    $contact = ['name' => 'Buyer', 'email' => "buyer{$userId}" . random_int(1, 999999) . '@example.test', 'phone' => '0123456789', 'line1' => '1 Jalan Mawar',
        'line2' => '', 'city' => 'Shah Alam', 'state' => 'Selangor', 'postcode' => '40150', 'notes' => ''];
    $o = CheckoutService::place($cart, $contact, 'cod', $userId);
    DB::query('UPDATE orders SET status = ? WHERE id = ?', [$status, (int) $o['order_id']]);
    return (int) $o['order_id'];
};
$ids = fn(array $cards): array => array_map(fn(array $r) => (int) $r['id'], $cards);

$a = $make('Cross A');
$b = $make('Cross B');
$c = $make('Cross C');
$d = $make('Cross D', 0);       // sold out
$e = $make('Cross E');          // bought with A in two orders
$f = $make('Cross F');          // bought with A in one order, and in one cancelled order
$g = $make('Cross G', 50, 'draft'); // not active

// Pins for A: C, D (sold out), B, then G (not active; pinned directly, as an old pin would be).
check('pinning saves in order', X::savePins($a, [$c, $d, $b]) === []);
DB::query('INSERT INTO product_related (product_id, related_id, sort_order) VALUES (?,?,?)', [$a, $g, 99]);
check('sold-out and inactive pins are hidden, the rest keep their order', $ids(X::forProduct($a)) === [$c, $b]);

check('a product never suggests itself', !in_array($a, $ids(X::forProduct($a)), true));
check('refused: a product pinned to itself', X::savePins($a, [$a]) !== []);
$h = $make('Cross H', 50, 'draft');
check('refused: a new pin that is not active', X::savePins($a, [$c, $h]) !== []);
check('refused: more than the maximum', X::savePins($a, array_map(fn($i) => $make("Many {$i}"), range(1, X::MAX_PINS + 1))) !== []);
check('a pin that went inactive can still be removed', X::savePins($a, [$c, $d, $b]) === []);

// Bought together: A with E in two orders, A with F in one order and in one cancelled order.
$member1 = $member('Nur Aisyah Binti Ali', 'nur@example.test');
$member2 = $member('Ben Tan', 'ben@example.test');
$order($member1, [$a, $e]);
$order($member2, [$a, $e]);
$order($member1, [$a, $f]);
$order($member2, [$a, $f], 'cancelled');

$saved = X::recompute();
check('a pair bought together in two orders is saved', $saved >= 1);
check('a pair in only one order is not suggested', !in_array($f, X::candidateIds($a), true));
$after = $ids(X::forProduct($a));
check('pinned first, then bought together', $after === [$c, $b, $e]);
check('cancelled orders do not count', !in_array($f, $after, true));

// Cart: suggestions leave out what is already in the cart.
$cartIds = $ids(X::forCart([$a]));
check('cart suggestions never include the product in the cart', !in_array($a, $cartIds, true) && in_array($e, $cartIds, true));
check('cart suggestions leave out other items already in the cart', !in_array($e, $ids(X::forCart([$a, $e])), true));
