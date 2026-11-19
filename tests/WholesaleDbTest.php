<?php
// Wholesale accounts: applications and approval; credit orders only for approved accounts; the credit limit holds across
// several orders; suspended accounts keep their unpaid orders on the ageing report; payment clears the balance.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\WholesaleService as W;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Tester', 'wholesale-tester@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$member = function (string $name, string $email): int {
    DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', ?, ?, 'x', 'active')", [$name, $email]);
    return (int) DB::connection()->lastInsertId();
};
Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Wholesale Mug', 'slug' => 'wholesale-mug', 'sku' => 'WHS-MUG',
    'category_id' => null, 'brand_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 500, 'status' => 'active']);
$mug = (int) DB::query("SELECT id FROM products WHERE slug = 'wholesale-mug'")->fetchColumn();
// One credit order of one mug: RM20.00 + RM8.00 postage (zone 1 first kilo) = RM28.00.
$creditOrder = function (int $userId) use ($mug): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $cart = (int) DB::connection()->lastInsertId();
    DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty, reserved_until) VALUES (?,?,?,?, NOW() + INTERVAL 15 MINUTE)', [$cart, $mug, null, 1]);
    $contact = ['name' => 'Buyer', 'email' => 'buyer' . random_int(1, 999999) . '@example.test', 'phone' => '0123456789', 'line1' => '1 Jalan Mawar', 'line2' => '',
        'city' => 'Shah Alam', 'state' => 'Selangor', 'postcode' => '40150', 'notes' => ''];
    return (int) CheckoutService::place($cart, $contact, 'credit_terms', $userId)['order_id'];
};

$biz = $member('Biz Owner', 'biz@example.test');
$other = $member('Plain Buyer', 'plain@example.test');

// Module off: nothing is offered.
check('module off: credit terms are not approved for anyone', W::approvedFor($biz) === null);

Setting::set('module.wholesale_accounts', '1');
check('applying gives a pending account', W::apply($biz, ['company' => 'Biz Sdn Bhd', 'reg_no' => '202601012345']) === [] && W::account($biz)['status'] === 'pending');
check('a pending account is not approved yet', W::approvedFor($biz) === null);
check('refused: a second application', isset(W::apply($biz, ['company' => 'Again', 'reg_no' => ''])['company']));
check('refused: a company with no name', isset(W::validateApplication(['company' => '', 'reg_no' => ''])['company']));

check('an unapproved member cannot place a credit order', (function () use ($other, $creditOrder): bool {
    try {
        $creditOrder($other);
        return false;
    } catch (CheckoutException) {
        return true;
    }
})());

$acct = W::account($biz);
check('the admin approves with a limit of RM100 and 30 days', W::update((int) $acct['id'], ['status' => 'approved', 'credit_limit' => '100.00', 'terms_days' => '30'], $actor) === []);
check('an approved account is recognised', W::approvedFor($biz) !== null);
check('refused: a limit that is not an amount', isset(W::update((int) $acct['id'], ['status' => 'approved', 'credit_limit' => 'abc', 'terms_days' => '30'], $actor)['credit_limit']));

// The limit holds across several orders: three orders of RM28 (RM84) fit, a fourth (RM112) does not.
$o1 = $creditOrder($biz);
$o2 = $creditOrder($biz);
$o3 = $creditOrder($biz);
check('three credit orders within the limit are placed', W::openBalance($biz) === 84.0);
$due = DB::query('SELECT due_date FROM orders WHERE id = ?', [$o1])->fetchColumn();
check('a credit order is due in the account terms (30 days)', $due === date('Y-m-d', strtotime('+30 days')) && DB::query('SELECT payment_status FROM orders WHERE id = ?', [$o1])->fetchColumn() === 'unpaid');
$refused = false;
try {
    $creditOrder($biz);
} catch (CheckoutException) {
    $refused = true;
}
check('a fourth order that goes over the limit is refused', $refused && W::openBalance($biz) === 84.0);

// Payment clears the balance; a cancelled order does not count.
check('recording a payment marks the order paid', OrderService::markPaid($o1, $actor) === null);
check('after the payment the balance drops by that order', W::openBalance($biz) === 56.0);
OrderService::transition($o2, 'cancelled', $actor);
check('a cancelled credit order is not in the balance', W::openBalance($biz) === 28.0);

// Ageing: move the last open order's due date into each bucket, then check the report adds up.
check('buckets: not yet due, then 1-30, 31-60 and over 60 days', W::bucket('2030-01-01', '2026-01-01') === 'current' && W::bucket('2026-01-01', '2026-01-11') === 'd1_30'
    && W::bucket('2026-01-01', '2026-02-15') === 'd31_60' && W::bucket('2026-01-01', '2026-06-01') === 'd61');
DB::query('UPDATE orders SET due_date = ? WHERE id = ?', [date('Y-m-d', strtotime('-45 days')), $o3]);
$report = W::ageing();
$row = array_values(array_filter($report, fn($r) => (int) $r['account_id'] === (int) $acct['id']))[0] ?? null;
check('the ageing report puts the overdue order in the 31-60 day bucket', $row !== null && $row['buckets']['d31_60'] === 28.0 && $row['total'] === 28.0);
check('the report total equals the unpaid credit orders', round(array_sum(array_column($report, 'total')), 2) === round((float) DB::query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_method = 'credit_terms' AND payment_status = 'unpaid' AND status NOT IN ('cancelled','returned')")->fetchColumn(), 2));

// Suspended: no new credit orders, but the unpaid order stays on the report.
W::update((int) $acct['id'], ['status' => 'suspended', 'credit_limit' => '100.00', 'terms_days' => '30'], $actor);
check('a suspended account gets no new credit orders', W::approvedFor($biz) === null);
$refused = false;
try {
    $creditOrder($biz);
} catch (CheckoutException) {
    $refused = true;
}
check('refused: a credit order from a suspended account', $refused);
check('a suspended account still appears on the ageing report', count(array_filter(W::ageing(), fn($r) => (int) $r['account_id'] === (int) $acct['id'])) === 1);

// The payment list builds with the credit method in it, and the general checkout list leaves it out.
check('the payment methods build, credit terms is known but not in the general list', \App\Services\Payment\Payments::label('credit_terms') === 'Credit terms (pay by due date)' && !isset(\App\Services\Payment\Payments::checkoutMethods()['credit_terms']));

// Module off: no credit at all.
Setting::set('module.wholesale_accounts', '0');
check('module off: even an approved account gets no credit', W::approvedFor($biz) === null);

DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
