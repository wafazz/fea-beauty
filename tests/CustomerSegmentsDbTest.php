<?php
// Customer segments: rules (orders, spend, time window, inactivity, tags) select the right customers; cancelled orders
// do not count; tags are checked; the CSV export escapes spreadsheet formulas and is logged.
use App\Core\Database as DB;
use App\Models\Product;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\CheckoutService;
use App\Services\CustomerSegmentService as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Tester', 'segments-tester@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
Setting::set('module.customer_segments', '1');

Product::insert(['weight_kg' => '1.000', 'width_cm' => '10', 'length_cm' => '10', 'height_cm' => '10', 'name' => 'Segment Mug', 'slug' => 'segment-mug', 'sku' => 'SEG-MUG',
    'category_id' => null, 'brand_id' => null, 'base_price' => '5', 'company_base_price' => '10', 'retail_price' => '20.00', 'discount_price' => null, 'stock' => 500, 'status' => 'active']);
$mug = (int) DB::query("SELECT id FROM products WHERE slug = 'segment-mug'")->fetchColumn();
$member = function (string $name, string $email): int {
    DB::query("INSERT INTO users (role, name, email, phone, password_hash, status) VALUES ('member', ?, ?, '0123456789', 'x', 'active')", [$name, $email]);
    return (int) DB::connection()->lastInsertId();
};
// One order of one mug for a member; returns the order id.
$order = function (int $userId) use ($mug): int {
    DB::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', random_bytes(8))]);
    $cart = (int) DB::connection()->lastInsertId();
    DB::query('INSERT INTO cart_items (cart_id, product_id, variant_id, qty, reserved_until) VALUES (?,?,?,?, NOW() + INTERVAL 15 MINUTE)', [$cart, $mug, null, 1]);
    $contact = ['name' => 'Buyer', 'email' => "buyer{$userId}@example.test", 'phone' => '0123456789', 'line1' => '1 Jalan Mawar', 'line2' => '',
        'city' => 'Shah Alam', 'state' => 'Selangor', 'postcode' => '40150', 'notes' => ''];
    return (int) CheckoutService::place($cart, $contact, 'cod', $userId)['order_id'];
};

$a = $member('Aisyah Vip', 'aisyah@example.test');     // three recent orders
$b = $member('Ben Lapsed', 'ben@example.test');        // one order, 60 days ago
$c = $member('Cat Never', 'cat@example.test');         // never ordered
$d = $member('Dan Cancelled', 'dan@example.test');     // two orders, both cancelled
$order($a); $order($a); $order($a);
$oldOrder = $order($b);
DB::query('UPDATE orders SET created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60 * 86400), $oldOrder]);
foreach ([$order($d), $order($d)] as $cancelled) {
    DB::query("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$cancelled]);
}
$ids = fn(array $rows): array => array_map(fn($r) => (int) $r['id'], $rows);

check('at least N orders: only the customer with three orders', $ids(S::members(['min_orders' => 2])) === [$a]);
check('spend in a time window: spend over RM50 in 90 days', $ids(S::members(['min_spent' => 5000, 'within_days' => 90])) === [$a]);
check('no order for 30 days: the lapsed, the never-ordered and the cancelled-only', $ids(S::members(['inactive_days' => 30])) === [$b, $c, $d]);
check('a cancelled order is not counted in orders', $ids(S::members(['min_orders' => 1])) === [$a, $b]);

check('tags are saved in lower case', S::setTags($a, 'VIP, Wholesale', $actor) === [] && S::tags($a) === ['vip', 'wholesale']);
check('tag rule: only the tagged customer', $ids(S::members(['tag' => 'vip'])) === [$a]);
check('a tag for nobody gives nobody', S::members(['tag' => 'nobody']) === []);
check('count matches the members', S::count(['inactive_days' => 30]) === 3);

check('refused: more than ten tags', isset(S::setTags($b, 'a1,a2,a3,a4,a5,a6,a7,a8,a9,a10,a11', $actor)['tags']));
check('refused: a tag with a space', isset(S::setTags($b, 'bad tag', $actor)['tags']));

// Rules from the form.
check('refused: no rule (would be everyone)', isset(S::parseRules([])['errors']['rules']));
check('refused: a negative count', isset(S::parseRules(['min_orders' => '-1'])['errors']['min_orders']));
check('refused: a window with nothing to measure', isset(S::parseRules(['within_days' => '30'])['errors']['within_days']));
check('an amount is stored in cents', S::parseRules(['min_spent' => '300.50'])['rules'] === ['min_spent' => 30050]);
check('a rule set with a tag and an order count', S::parseRules(['min_orders' => '2', 'tag' => 'VIP'])['rules'] === ['min_orders' => 2, 'tag' => 'vip']);

// Save, then duplicate name is refused.
check('a segment is saved', S::save(null, 'Loyal', ['min_orders' => '2'], $actor) === []);
$loyal = (int) DB::query("SELECT id FROM customer_segments WHERE name = 'Loyal'")->fetchColumn();
check('refused: a second segment with the same name', isset(S::save(null, 'Loyal', ['min_orders' => '1'], $actor)['name']));
check('the saved segment finds its members', $ids(S::members(S::find($loyal)['rules'])) === [$a]);

// CSV export: a name that starts with = is escaped, and the export is logged.
DB::query("UPDATE users SET name = '=HYPERLINK(\"x\")' WHERE id = ?", [$c]);
$csv = S::exportCsv($loyal, $actor);
check('the export has a header and the matching rows', is_string($csv) && str_starts_with($csv, 'name,email,phone,orders,total_spent,last_order') && substr_count($csv, "\n") === 2);
S::save(null, 'Lapsed', ['inactive_days' => '30'], $actor);
$lapsed = (int) DB::query("SELECT id FROM customer_segments WHERE name = 'Lapsed'")->fetchColumn();
$csvLapsed = S::exportCsv($lapsed, $actor);
check('a spreadsheet formula in a name is escaped with an apostrophe', is_string($csvLapsed) && str_contains($csvLapsed, "'=HYPERLINK") && S::csvCell('=1+1') === "'=1+1" && S::csvCell('Ali') === 'Ali');
check('the export is written to the audit log', count(AuditService::recent('segment.export', 5)) >= 1);
check('an export of a missing segment is nothing', S::exportCsv(999999, $actor) === null);

DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
