<?php
// Shipping rules: free-shipping threshold (at or above, after discounts), COD surcharge kept, store pickup with no
// postage, pickup orders keep the point's address, and everything behaves as before when the module is off.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\PickupPointService;
use App\Services\ShippingRates as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Tester', 'shipping-tester@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$light = [['weight_kg' => '1.000', 'qty' => 1, 'digital' => false]];

// Module off: the threshold and pickup are ignored; the normal postage applies (zone 1: first kilo RM8.00).
check('module off: normal postage, no free reason', S::quote($light, 10000, 'Selangor', 'bank_transfer')['fee'] === 800 && S::quote($light, 10000, 'Selangor', 'bank_transfer')['free'] === null);
S::saveZone('zone1', ['free_from' => '100.00'], $actor);
check('module off: a threshold is kept but not applied', S::quote($light, 20000, 'Selangor', 'bank_transfer')['fee'] === 800);

// Module on.
Setting::set('module.shipping_rules', '1');
check('module on: an order at the threshold pays no postage', S::quote($light, 10000, 'Selangor', 'bank_transfer')['fee'] === 0);
check('module on: the reason is the threshold', S::quote($light, 10000, 'Selangor', 'bank_transfer')['free'] === 'threshold');
check('module on: one cent under the threshold pays normal postage', S::quote($light, 9999, 'Selangor', 'bank_transfer')['fee'] === 800);
check('module on: a blank threshold clears it', S::saveZone('zone1', ['free_from' => ''], $actor) === [] && S::freeFrom('zone1') === null && S::quote($light, 20000, 'Selangor', 'bank_transfer')['fee'] === 800);
S::saveZone('zone1', ['free_from' => '100.00'], $actor);
check('a threshold that is not an amount is refused', S::saveZone('zone1', ['free_from' => 'abc'], $actor) !== []);

// COD: the surcharge is still added when the postage is free (zone 1 cod_at_or_below 5% of RM100.00 = RM5.00).
$cod = S::quote($light, 10000, 'Selangor', 'cod');
check('COD with a free threshold: the surcharge stays and is the whole fee', $cod['normal'] === 0 && $cod['cod'] === 500 && $cod['fee'] === 500);

// Pickup: no postage, in any zone, for any payment method.
$pickup = S::quote($light, 2000, 'Sabah', 'bank_transfer', true);
check('pickup: no postage', $pickup['fee'] === 0 && $pickup['free'] === 'pickup');
check('pickup: the zone still comes from the point (for COD rates)', $pickup['zone'] === 'zone2');

// Checkout with a pickup point.
Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Pickup Mug', 'slug' => 'pickup-mug', 'sku' => 'PU-MUG',
    'category_id' => null, 'brand_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 50, 'status' => 'active']);
$mug = (int) DB::query("SELECT id FROM products WHERE slug = 'pickup-mug'")->fetchColumn();
DB::query("INSERT INTO pickup_points (name, line1, city, state, postcode, opening_hours, active, sort_order) VALUES ('KL Store', '12 Jalan Bukit Bintang', 'Kuala Lumpur', 'Kuala Lumpur', '55100', 'Mon-Sat 10:00-18:00', 1, 0)");
$point = (int) DB::connection()->lastInsertId();
DB::query("INSERT INTO pickup_points (name, line1, city, state, postcode, active, sort_order) VALUES ('Old Store', 'Closed Road 1', 'Ipoh', 'Perak', '30000', 0, 1)");
$closed = (int) DB::connection()->lastInsertId();

$order = function (array $extra, string $method = 'cod') use ($mug): array {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $cart = (int) DB::connection()->lastInsertId();
    DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty, reserved_until) VALUES (?,?,?,?, NOW() + INTERVAL 15 MINUTE)', [$cart, $mug, null, 1]);
    $contact = array_merge(['name' => 'Buyer', 'email' => 'buyer' . random_int(1, 999999) . '@example.test', 'phone' => '0123456789', 'line1' => '1 Jalan Mawar', 'line2' => '',
        'city' => 'Shah Alam', 'state' => 'Selangor', 'postcode' => '40150', 'notes' => ''], $extra);
    return CheckoutService::place($cart, $contact, $method, null);
};

$p = $order(['fulfilment' => 'pickup', 'pickup_point_id' => (string) $point]);
$row = DB::query('SELECT * FROM orders WHERE id = ?', [(int) $p['order_id']])->fetch();
check('a pickup order records the pickup', $row['fulfilment'] === 'pickup' && (int) $row['pickup_point_id'] === $point && $row['pickup_name'] === 'KL Store');
check('a pickup order uses the point address and charges no postage (only the COD surcharge, if any)', $row['ship_line1'] === '12 Jalan Bukit Bintang' && $row['ship_city'] === 'Kuala Lumpur' && (float) $row['shipping_fee'] === (float) $row['cod_fee'] && (float) $row['cod_fee'] > 0);

$d = $order([]);
check('a delivery order is unchanged', DB::query('SELECT fulfilment FROM orders WHERE id = ?', [(int) $d['order_id']])->fetchColumn() === 'delivery');

$refused = false;
try {
    $order(['fulfilment' => 'pickup', 'pickup_point_id' => (string) $closed]);
} catch (CheckoutException) {
    $refused = true;
}
check('a closed pickup point is refused', $refused);

Setting::set('module.shipping_rules', '0');
$refused = false;
try {
    $order(['fulfilment' => 'pickup', 'pickup_point_id' => (string) $point]);
} catch (CheckoutException) {
    $refused = true;
}
check('pickup is refused when the module is switched off', $refused && PickupPointService::active() === []);

// Leave the switches as a fresh database has them.
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
DB::query("DELETE FROM settings WHERE `key` LIKE 'shipping.zone%.free_from'");
