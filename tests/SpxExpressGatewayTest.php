<?php
use App\Services\Shipping\SpxExpressGateway as G;

// check-sign test vector from the SPX integration notes (docs/spx-api in laraditz/courier-spx-express).
$body = '{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}';
check('check-sign matches the SPX test vector', G::sign('100000', 'H25HY53GO4BA2GQ', $body, '1677918414', '926611981') === '97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d');
check('check-sign changes with the body (signed bytes matter)', G::sign('100000', 'H25HY53GO4BA2GQ', $body . ' ', '1677918414', '926611981') !== '97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d');

check('phone: Malaysian digits only, 0 or 60 prefix', G::phone('012-345 6789') === '0123456789' && G::phone('+60 12-345 6789') === '60123456789' && G::phone('601234567890') === '601234567890');
check('phone: foreign or junk numbers rejected', G::phone('+65 9123 4567') === null && G::phone('abc') === null && G::phone('') === null);

$order = ['order_no' => 'ORD-ABC1234567', 'customer_name' => 'Aina', 'customer_phone' => '012-3456789', 'ship_line1' => '1 Jalan Satu', 'ship_line2' => null, 'ship_city' => 'Petaling Jaya', 'ship_state' => 'Selangor', 'ship_postcode' => '47800', 'total' => '123.50', 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'notes' => 'Leave at guard house'];
$items = [
    ['product_name' => 'Widget', 'variant_label' => 'Red / M', 'qty' => 2, 'unit_price' => '40.00', 'weight_kg' => '0.300', 'length_cm' => '20', 'width_cm' => '15', 'height_cm' => '10'],
    ['product_name' => 'Gadget', 'variant_label' => null, 'qty' => 1, 'unit_price' => '43.50', 'weight_kg' => '0.500', 'length_cm' => '30', 'width_cm' => '12', 'height_cm' => '8'],
];
$sender = ['sender_name' => 'Lure Shop', 'sender_phone' => '012-1112222', 'sender_address1' => '9 Jalan Kilang', 'sender_address2' => '', 'sender_postcode' => '50000', 'sender_city' => 'Kuala Lumpur', 'sender_state' => 'Kuala Lumpur'];

$pickup = ['pickup_time' => 1700000000, 'pickup_time_range_id' => 3];
$r = G::buildOrder($order, $items, $sender, 'pickup', $pickup);
$b = $r['order'] ?? [];
check('payload builds for a COD pickup order', isset($r['order']) && !isset($r['error']));
check('payload: order id is the order number, service type 1', ($b['order_id'] ?? '') === 'ORD-ABC1234567' && ($b['base_info']['service_type'] ?? 0) === 1);
check('payload: sender and receiver addresses are folded into SPX fields', ($b['sender_info']['sender_detail_address'] ?? '') === '9 Jalan Kilang' && ($b['sender_info']['sender_post_code'] ?? '') === '50000' && ($b['deliver_info']['deliver_city'] ?? '') === 'Petaling Jaya' && ($b['deliver_info']['deliver_phone'] ?? '') === '0123456789');
check('payload: parcel weight sums qty x kg; dimensions are the largest per side', ($b['parcel_info']['parcel_weight'] ?? 0) === 1.1 && ($b['parcel_info']['parcel_length'] ?? 0) === 30 && ($b['parcel_info']['parcel_width'] ?? 0) === 15 && ($b['parcel_info']['parcel_height'] ?? 0) === 10);
check('payload: item quantity and declared value (sum of unit price x qty)', ($b['parcel_info']['parcel_item_quantity'] ?? 0) === 3 && ($b['parcel_info']['parcel_value'] ?? 0) === 123.5);
check('payload: COD unpaid -> cod_collection 1 with the order total', ($b['fulfillment_info']['cod_collection'] ?? 0) === 1 && ($b['fulfillment_info']['cod_amount'] ?? 0) === 123.5);
check('payload: pickup collect type carries the slot ids', ($b['fulfillment_info']['collect_type'] ?? 0) === 1 && ($b['fulfillment_info']['pickup_time'] ?? 0) === 1700000000 && ($b['fulfillment_info']['pickup_time_range_id'] ?? 0) === 3);
check('payload: note becomes the delivery instruction', ($b['deliver_info']['deliver_instruction'] ?? '') === 'Leave at guard house');

$drop = G::buildOrder(['payment_method' => 'billplz', 'payment_status' => 'paid', 'notes' => ''] + $order, $items, $sender, 'dropoff', null)['order'] ?? [];
check('payload: paid drop-off -> collect type 2, no COD, no pickup, no note', ($drop['fulfillment_info']['collect_type'] ?? 0) === 2 && !isset($drop['fulfillment_info']['cod_amount']) && !isset($drop['fulfillment_info']['pickup_time']) && !isset($drop['deliver_info']['deliver_instruction']));

$light = [['product_name' => 'Tiny', 'variant_label' => null, 'qty' => 1, 'unit_price' => '5', 'weight_kg' => '0.01', 'length_cm' => '5', 'width_cm' => '5', 'height_cm' => '5']];
check('payload: weight is raised above SPX minimum (strictly over 0.1 kg)', (G::buildOrder($order, $light, $sender, 'dropoff', null)['order']['parcel_info']['parcel_weight'] ?? 0) === 0.11);
check('payload: product without dimensions is refused, not sent half-formed', isset(G::buildOrder($order, [['product_name' => 'X', 'variant_label' => null, 'qty' => 1, 'unit_price' => '5', 'weight_kg' => '0.5', 'length_cm' => null, 'width_cm' => '5', 'height_cm' => '5']], $sender, 'dropoff', null)['error']));
check('payload: over 30 kg is refused', isset(G::buildOrder($order, [['product_name' => 'Heavy', 'variant_label' => null, 'qty' => 1, 'unit_price' => '5', 'weight_kg' => '31', 'length_cm' => '5', 'width_cm' => '5', 'height_cm' => '5']], $sender, 'dropoff', null)['error']));
check('payload: a bad customer phone is refused with a clear message', str_contains(G::buildOrder(['customer_phone' => '+65 9123 4567'] + $order, $items, $sender, 'dropoff', null)['error'] ?? '', 'phone'));
check('payload encodes to valid JSON', is_array(json_decode(json_encode($b), true)));
