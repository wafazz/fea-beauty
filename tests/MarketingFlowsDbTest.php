<?php
// Marketing flows: welcome and review triggers schedule one email each; a switched-off flow schedules nothing and
// sends nothing; emails wait while email is not set up; unsubscribed people are skipped; win-back goes once per period.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CheckoutService;
use App\Services\CustomerSegmentService as S;
use App\Services\MarketingService as M;
use App\Services\OrderService;
use App\Services\UnsubscribeService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
DB::query("DELETE FROM marketing_sends");
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Tester', 'marketing-tester@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$count = fn(?string $ref = null): int => (int) DB::query($ref === null ? 'SELECT COUNT(*) FROM marketing_sends' : 'SELECT COUNT(*) FROM marketing_sends WHERE ref = ?', $ref === null ? [] : [$ref])->fetchColumn();
$member = function (string $name, string $email): int {
    DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('member', ?, ?, 'x', 'active')", [$name, $email]);
    return (int) DB::connection()->lastInsertId();
};
$flowOn = fn(string $key, array $extra = []) => M::saveFlow($key, $extra + ['subject' => 'Hello {name}', 'body' => 'Body for {name}', 'delay_hours' => '1', 'enabled' => '1', 'segment_id' => '', 'repeat_days' => ''], $actor);

// Module off: nothing is scheduled.
$ann = $member('Ann Welcome', 'ann@example.test');
M::onVerified($ann);
check('module off: no welcome is scheduled', $count() === 0);

Setting::set('module.marketing_flows', '1');
check('module on, welcome flow off: still nothing is scheduled', M::onVerified($ann) === null && $count() === 0);

check('the welcome flow saves', $flowOn('welcome') === []);
M::onVerified($ann);
check('welcome: one email is scheduled, about an hour ahead', $count() === 1 && DB::query('SELECT TIMESTAMPDIFF(MINUTE, NOW(), due_at) FROM marketing_sends')->fetchColumn() >= 55);
M::onVerified($ann);
check('welcome: a second trigger for the same person adds nothing', $count() === 1);

// Email is not set up in the test environment: the email waits, it is not marked failed.
DB::query("UPDATE marketing_sends SET due_at = NOW() - INTERVAL 1 MINUTE");
$r = M::runDue(50);
check('email not set up: the email waits and nothing is marked failed', $r['waiting'] >= 1 && $r['failed'] === 0 && DB::query("SELECT COUNT(*) FROM marketing_sends WHERE status = 'pending'")->fetchColumn() == 1);

// Unsubscribed people are skipped.
$gone = $member('Gone Away', 'gone@example.test');
UnsubscribeService::unsubscribe('gone@example.test');
$flow = (int) DB::query("SELECT id FROM marketing_flows WHERE flow_key = 'welcome'")->fetchColumn();
DB::query("INSERT INTO marketing_sends (flow_id, user_id, ref, due_at) VALUES (?, ?, 'welcome', NOW() - INTERVAL 1 MINUTE)", [$flow, $gone]);
M::runDue(50);
check('an unsubscribed person is skipped', DB::query("SELECT status FROM marketing_sends WHERE user_id = ?", [$gone])->fetchColumn() === 'skipped');

// Switching the flow off stops sending, and the waiting email stays waiting.
M::saveFlow('welcome', ['subject' => 'x', 'body' => 'y', 'delay_hours' => '1', 'enabled' => '0', 'segment_id' => '', 'repeat_days' => ''], $actor);
$r = M::runDue(50);
check('a switched-off flow sends nothing and leaves its emails waiting', $r['sent'] === 0 && $r['waiting'] >= 1);

// Review request after a completed order: members yes, guests no.
$flowOn('review_request', ['delay_hours' => '72']);
Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Marketing Mug', 'slug' => 'marketing-mug', 'sku' => 'MKT-MUG',
    'category_id' => null, 'brand_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 100, 'status' => 'active']);
$mug = (int) DB::query("SELECT id FROM products WHERE slug = 'marketing-mug'")->fetchColumn();
$placeOrder = function (?int $userId) use ($mug): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $cart = (int) DB::connection()->lastInsertId();
    DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty, reserved_until) VALUES (?,?,?,?, NOW() + INTERVAL 15 MINUTE)', [$cart, $mug, null, 1]);
    $contact = ['name' => 'Buyer', 'email' => 'buyer' . random_int(1, 999999) . '@example.test', 'phone' => '0123456789', 'line1' => '1 Jalan Mawar', 'line2' => '',
        'city' => 'Shah Alam', 'state' => 'Selangor', 'postcode' => '40150', 'notes' => ''];
    return (int) CheckoutService::place($cart, $contact, 'cod', $userId)['order_id'];
};
$complete = function (int $orderId) use ($actor): void {
    OrderService::transition($orderId, 'processing', $actor);
    OrderService::transition($orderId, 'shipped', $actor);
    OrderService::transition($orderId, 'completed', $actor, null, false, true);
};
$buyer = $member('Ben Buyer', 'ben@example.test');
$memberOrder = $placeOrder($buyer);
$complete($memberOrder);
check('a completed member order schedules one review request', $count("order:{$memberOrder}") === 1);
$guestOrder = $placeOrder(null);
$complete($guestOrder);
check('a completed guest order schedules nothing', $count("order:{$guestOrder}") === 0);

// Win-back: members of a lapsed segment, once per repeat period.
S::save(null, 'Lapsed Members', ['inactive_days' => '30'], $actor);
$lapsedSegment = (int) DB::query("SELECT id FROM customer_segments WHERE name = 'Lapsed Members'")->fetchColumn();
$flowOn('win_back', ['segment_id' => (string) $lapsedSegment, 'repeat_days' => '90', 'delay_hours' => '0']);
$first = M::scanWinBack();
check('win-back schedules the lapsed members', $first >= 1);
check('win-back: the same period adds no one again', M::scanWinBack() === 0);
$later = time() + 100 * 86400; // a later period: the customers may be contacted again
check('win-back: after the repeat period they can be contacted again', M::scanWinBack($later) >= 1);

// Module off: nothing is scheduled by any trigger.
Setting::set('module.marketing_flows', '0');
check('module off: win-back and review triggers schedule nothing', M::scanWinBack($later + 100 * 86400) === 0 && M::onOrderCompleted($memberOrder) === null);
// Put the flows back to their starting state (all off, no segment).
DB::query("UPDATE marketing_flows SET enabled = 0, segment_id = NULL, repeat_days = NULL");

DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
