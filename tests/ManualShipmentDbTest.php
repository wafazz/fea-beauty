<?php
// Couriers the seller books outside the system: Processing only, one active shipment per order, safe links only. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Services\ManualShipmentService as M;
use App\Services\ShipmentService as S;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Manual Admin', 'manual@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();

$mk = function (string $status): int {
    DB::query("INSERT INTO orders (order_no, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, ship_country, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?, 'Ann', 'ann@example.test', '0123456789', '1 St', 'KL', 'Selangor', '47300', 'MY', 90, 0, 90, 'bank_transfer', 'paid', ?)", ['M-' . bin2hex(random_bytes(3)), $status]);
    return (int) DB::connection()->lastInsertId();
};
$good = ['courier' => 'poslaju', 'tracking_number' => 'PL123456789MY', 'tracking_url' => 'https://www.pos.com.my/track?id=PL123456789MY'];

check('courier names: four couriers, unknown codes fall back to NinjaVan', M::courierName('poslaju') === 'PosLaju' && M::courierName('spx') === 'SPX Express' && M::courierName('jnt') === 'J&T Express' && M::courierName('weird') === 'NinjaVan');

$p = $mk('processing');
$r = M::create($p, $good, null, $actor);
check('a Processing order can take a manual courier', $r['ok'] === true && $r['tracking'] === 'PL123456789MY');
$ship = S::active($p);
check('the booking is stored as manual, with the tracking link and no courier API fields', $ship !== null && $ship['source'] === 'manual' && $ship['carrier'] === 'poslaju' && $ship['tracking_url'] === $good['tracking_url'] && $ship['weight_kg'] === null && $ship['service_level'] === null);
check('the order event records the manual booking', (int) DB::query("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event = 'shipment'", [$p])->fetchColumn() === 1);

$dup = M::create($mk('processing'), $good, null, $actor);
check('a tracking number already on another shipment is refused', $dup['ok'] === false && isset($dup['fields']['tracking_number']));

$again = M::create($p, ['courier' => 'jnt', 'tracking_number' => 'JT0000001'], null, $actor);
check('a second active shipment on the same order is refused', $again['ok'] === false && str_contains((string) $again['error'], 'active shipment'));

$shipped = M::create($mk('shipped'), ['courier' => 'spx', 'tracking_number' => 'SPXMY00001'], null, $actor);
check('only Processing orders take a manual courier', $shipped['ok'] === false);

$pending = M::create($mk('pending'), ['courier' => 'spx', 'tracking_number' => 'SPXMY00002'], null, $actor);
check('a Pending order is refused too', $pending['ok'] === false);

$q = $mk('processing');
$bad = M::create($q, ['courier' => 'dhl', 'tracking_number' => 'X', 'tracking_url' => 'javascript:alert(1)'], null, $actor);
check('bad courier, short tracking number and a javascript: link are all refused', $bad['ok'] === false && isset($bad['fields']['courier'], $bad['fields']['tracking_number'], $bad['fields']['tracking_url']));
check('nothing was booked for the refused order', S::active($q) === null);

$spaces = M::create($q, ['courier' => 'spx', 'tracking_number' => 'SPX 123 456'], null, $actor);
check('tracking numbers with spaces are refused', $spaces['ok'] === false && isset($spaces['fields']['tracking_number']));

$fake = M::create($q, ['courier' => 'spx', 'tracking_number' => 'SPXMY00003'], ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__, 'name' => 'awb.pdf', 'size' => 10], $actor);
check('an AWB that was not uploaded over HTTP is refused', $fake['ok'] === false && isset($fake['fields']['awb']));

$cancelled = M::cancel($p, $actor);
check('cancelling a manual booking clears the active shipment', $cancelled === true && S::active($p) === null);
$rebook = M::create($p, ['courier' => 'ninjavan', 'tracking_number' => 'NV00000001'], null, $actor);
check('after cancelling, a new courier can be booked on the same order', $rebook['ok'] === true);

$api = $mk('processing');
DB::query("INSERT INTO shipments (order_id, carrier, tracking_number, service_level, weight_kg, delivery_date, delivery_timeslot, active) VALUES (?, 'ninjavan', 'NVAPI00001', 'Standard', 1, '2026-12-01', 'AM', 1)", [$api]);
check('an API booking is never cancelled by the manual path', M::cancel($api, $actor) === false && S::active($api) !== null);
check('an AWB cannot be attached to an API booking', M::attachAwb($api, null, $actor) !== null);
