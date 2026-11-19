<?php
// Multiple warehouses: stock moves between warehouses within the total; an order ships from one warehouse that holds
// all of it (same state first); a cancel puts it back in that warehouse; no warehouse goes negative; the totals add up.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\StockService;
use App\Services\WarehouseService as W;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Tester', 'warehouse-tester@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
DB::query("INSERT INTO warehouses (name, is_default, active, sort_order) VALUES ('Main warehouse', 1, 1, 0)");
$main = (int) DB::connection()->lastInsertId();
DB::query("INSERT INTO warehouses (name, state, active, sort_order) VALUES ('Sabah store', 'Sabah', 1, 1)");
$sabah = (int) DB::connection()->lastInsertId();

Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Warehouse Mug', 'slug' => 'warehouse-mug', 'sku' => 'WH-MUG',
    'category_id' => null, 'brand_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 10, 'status' => 'active']);
$mug = (int) DB::query("SELECT id FROM products WHERE slug = 'warehouse-mug'")->fetchColumn();
$total = fn(): int => (int) DB::query('SELECT stock FROM products WHERE id = ?', [$mug])->fetchColumn();
$sum = fn(): int => $total() === ((int) DB::query('SELECT COALESCE(SUM(qty), 0) FROM warehouse_stock WHERE product_id = ?', [$mug])->fetchColumn() + W::available($main, $mug, 0)) ? $total() : -1;
$order = function (string $state, int $qty) use ($mug): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $cart = (int) DB::connection()->lastInsertId();
    DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty, reserved_until) VALUES (?,?,?,?, NOW() + INTERVAL 15 MINUTE)', [$cart, $mug, null, $qty]);
    $contact = ['name' => 'Buyer', 'email' => 'buyer' . random_int(1, 999999) . '@example.test', 'phone' => '0123456789', 'line1' => '1 Jalan Mawar', 'line2' => '',
        'city' => 'City', 'state' => $state, 'postcode' => '40150', 'notes' => ''];
    return (int) CheckoutService::place($cart, $contact, 'cod', null)['order_id'];
};

// Module off: the shop works as before; orders do not record a warehouse.
$offOrder = $order('Selangor', 1);
check('module off: an order does not record a warehouse, and the total goes down as before', DB::query('SELECT warehouse_id FROM orders WHERE id = ?', [$offOrder])->fetchColumn() === null && $total() === 9);
OrderService::transition($offOrder, 'cancelled', $actor);
check('module off: a cancel puts the stock back', $total() === 10);

Setting::set('module.multi_warehouse', '1');
check('module on: the default warehouse holds everything at first', W::available($main, $mug, 0) === 10 && W::available($sabah, $mug, 0) === 0);

check('a transfer moves stock within the total', W::transfer($main, $sabah, $mug, 0, 4, $actor) === null && $total() === 10
    && W::available($sabah, $mug, 0) === 4 && W::available($main, $mug, 0) === 6);
check('refused: moving more than the source holds', str_contains((string) W::transfer($main, $sabah, $mug, 0, 7, $actor), 'Only 6'));
check('refused: moving to the same warehouse', W::transfer($main, $main, $mug, 0, 1, $actor) !== null);

// An order from Sabah ships from the Sabah store, which holds it.
$a = $order('Sabah', 2);
check('an order from Sabah ships from the Sabah store', (int) DB::query('SELECT warehouse_id FROM orders WHERE id = ?', [$a])->fetchColumn() === $sabah
    && W::available($sabah, $mug, 0) === 2 && $total() === 8 && W::available($main, $mug, 0) === 6);

// An order from Selangor: the Sabah store is not the same state, so the default warehouse ships it.
$b = $order('Selangor', 5);
check('an order from elsewhere ships from the default warehouse', (int) DB::query('SELECT warehouse_id FROM orders WHERE id = ?', [$b])->fetchColumn() === $main
    && $total() === 3 && W::available($main, $mug, 0) === 1 && W::available($sabah, $mug, 0) === 2);

// Nothing holds three more: refused at checkout, with nothing taken.
$refused = false;
try {
    $order('Sabah', 3);
} catch (CheckoutException) {
    $refused = true;
}
check('an order no one warehouse can fill is refused, and nothing is taken', $refused && $total() === 3 && W::available($sabah, $mug, 0) === 2);

// A cancel puts stock back where the order shipped from.
OrderService::transition($a, 'cancelled', $actor);
check('a cancel returns stock to the warehouse it came from', W::available($sabah, $mug, 0) === 4 && $total() === 5 && W::available($main, $mug, 0) === 1);

// No warehouse goes negative: taking more from Sabah than it holds is refused.
$live = $order('Sabah', 1);   // Sabah now holds 3, and the total is 4 (so the total itself would allow 4 more)
$guard = false;
try {
    StockService::delta($mug, null, -4, 'order', $live);   // the total would stay at 0, but Sabah holds only 3
} catch (CheckoutException) {
    $guard = true;
}
check('a warehouse cannot give out more than it holds (the total would allow it)', $guard && W::available($sabah, $mug, 0) === 3 && $total() === 4);
check('totals still add up: the warehouses equal the stock total', $sum() === $total());

// Moving stock back to the default warehouse keeps the total.
check('moving stock back keeps the total', W::transfer($sabah, $main, $mug, 0, 2, $actor) === null && W::available($sabah, $mug, 0) === 1 && $total() === 4 && $sum() === 4);

DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
