<?php
declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use App\Services\AuditService;
use App\Services\NinjaVanSettings;
use App\Services\OrderService;
use App\Services\ShipmentService;
use App\Services\SpxExpressSettings;

/**
 * SPX Express Malaysia (SPX Open Service API): create a booking for a Processing order, cancel it,
 * and fetch its waybill (AWB) link.
 *
 * Every call is a POST to {host}/open/api/v1/{path} with a JSON body that carries user_id and
 * user_secret. Headers: app-id, timestamp (seconds), random-num, and check-sign, which is
 * hex(HMAC-SHA256(app_secret, "{app_id}_{timestamp}_{random_num}_{body}")). The body bytes that
 * are signed must be the bytes sent, so the payload is encoded once and reused.
 *
 * Batch endpoints answer ret_code 0 even when the order failed; the reason is in data.fail_list.
 * Field names follow the community SPX driver (laraditz/courier-spx-express) and SPX's public
 * notes. Not yet verified against a live SPX account: test in sandbox first.
 */
final class SpxExpressGateway
{
    private const HOST = ['sandbox' => 'https://test-stable.spx.com.my', 'production' => 'https://spx.com.my'];
    private const TIMEOUT = 30;
    private const SERVICE_TYPE = 1;      // Malaysia offers one service: Standard
    private const COLLECT_PICKUP = 1;    // SPX rider collects from the sender address
    private const COLLECT_DROPOFF = 2;   // sender takes the parcel to an SPX point
    private const MAX_ORDER_ID = 31;     // SPX caps order_id length
    private const MIN_KG = 0.11;         // SPX Malaysia: parcel_weight must be strictly over 0.1 kg
    private const MAX_KG = 30.0;
    private const MAX_SIDE_CM = 125;

    // ---- pure builders ---------------------------------------------------------------------

    /** check-sign for a request: HMAC-SHA256 keyed with the app secret, lowercase hex. */
    public static function sign(string $appId, string $appSecret, string $payload, string $timestamp, string $randomNum): string
    {
        return hash_hmac('sha256', "{$appId}_{$timestamp}_{$randomNum}_{$payload}", $appSecret);
    }

    /** Whole ringgit/sen string, 2 decimals. */
    private static function money(float $v): float
    {
        return round($v, 2);
    }

    /** SPX takes Malaysian numbers as digits only (0… or 60…). Null when nothing usable is left. */
    public static function phone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '60') && strlen($digits) >= 10 && strlen($digits) <= 12) {
            return $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) >= 9 && strlen($digits) <= 11) {
            return $digits;
        }
        return null;
    }

    /**
     * The batch_create_order body for one order. $items rows need product_name, variant_label, qty,
     * unit_price, weight_kg, length_cm, width_cm, height_cm. $pickup is {pickup_time, pickup_time_range_id}
     * when SPX collects, or null for a drop-off.
     *
     * @return array{order?:array,error?:string}
     */
    public static function buildOrder(array $order, array $items, array $sender, string $collect, ?array $pickup, int $serviceType = self::SERVICE_TYPE): array
    {
        $kg = 0.0;
        $qty = 0;
        $value = 0.0;
        $names = [];
        $len = $wid = $hei = 0;
        foreach ($items as $it) {
            $w = (float) ($it['weight_kg'] ?? 0);
            $q = (int) $it['qty'];
            $dims = [(int) round((float) ($it['length_cm'] ?? 0)), (int) round((float) ($it['width_cm'] ?? 0)), (int) round((float) ($it['height_cm'] ?? 0))];
            if ($w <= 0 || min($dims) <= 0) {
                return ['error' => 'Every product needs a weight and a length, width and height before it can be sent to SPX. Check the product details.'];
            }
            $kg += $w * $q;
            $qty += $q;
            $value += (float) $it['unit_price'] * $q;
            $len = max($len, $dims[0]);
            $wid = max($wid, $dims[1]);
            $hei = max($hei, $dims[2]);
            $names[] = $it['product_name'] . ($it['variant_label'] ? ' (' . $it['variant_label'] . ')' : '');
        }
        if ($qty === 0 || $value <= 0) {
            return ['error' => 'This order has no items, so there is nothing to send to SPX.'];
        }
        $kg = max(self::MIN_KG, round($kg, 2));
        if ($kg > self::MAX_KG) {
            return ['error' => 'SPX accepts parcels up to ' . self::MAX_KG . ' kg. This order weighs ' . number_format($kg, 2) . ' kg.'];
        }
        if (max($len, $wid, $hei) > self::MAX_SIDE_CM) {
            return ['error' => 'SPX accepts sides up to ' . self::MAX_SIDE_CM . ' cm. Check the product dimensions.'];
        }
        $senderPhone = self::phone((string) $sender['sender_phone']);
        $buyerPhone = self::phone((string) $order['customer_phone']);
        if ($senderPhone === null || $buyerPhone === null) {
            return ['error' => 'SPX needs a Malaysian phone number (for example 012-3456789) for the sender and the customer. Check both.'];
        }

        $cod = $order['payment_method'] === 'cod' && $order['payment_status'] === 'unpaid';
        $fulfillment = [
            'payment_role' => 1, // sender pays; the only value SPX accepts in Malaysia
            'cod_collection' => $cod ? 1 : 0,
            'collect_type' => $collect === 'pickup' ? self::COLLECT_PICKUP : self::COLLECT_DROPOFF,
        ];
        if ($cod) {
            $fulfillment['cod_amount'] = self::money((float) $order['total']);
        }
        if ($collect === 'pickup') {
            $fulfillment += $pickup ?? [];
        }

        $body = [
            'order_id' => (string) $order['order_no'],
            'base_info' => ['service_type' => $serviceType],
            'sender_info' => self::party('sender', [
                'name' => (string) $sender['sender_name'],
                'phone' => $senderPhone,
                'street' => trim($sender['sender_address1'] . ' ' . ($sender['sender_address2'] ?? '')),
                'state' => (string) $sender['sender_state'],
                'city' => (string) $sender['sender_city'],
                'postcode' => (string) $sender['sender_postcode'],
            ]),
            'fulfillment_info' => $fulfillment,
            'deliver_info' => self::party('deliver', [
                'name' => (string) $order['customer_name'],
                'phone' => $buyerPhone,
                'street' => trim($order['ship_line1'] . ' ' . ($order['ship_line2'] ?? '')),
                'state' => (string) $order['ship_state'],
                'city' => (string) $order['ship_city'],
                'postcode' => (string) $order['ship_postcode'],
            ]),
            'parcel_info' => [
                'parcel_weight' => $kg,
                'parcel_length' => $len,
                'parcel_width' => $wid,
                'parcel_height' => $hei,
                'parcel_item_name' => mb_substr(implode(', ', $names), 0, 100),
                'parcel_item_quantity' => $qty,
                'parcel_value' => self::money($value),
            ],
        ];
        if (trim((string) $order['notes']) !== '') {
            $body['deliver_info']['deliver_instruction'] = mb_substr(trim((string) $order['notes']), 0, 200);
        }
        return ['order' => $body];
    }

    /** SPX's {sender|deliver}_* address fields. Street lines are folded into detail_address. */
    private static function party(string $prefix, array $a): array
    {
        return [
            "{$prefix}_name" => mb_substr($a['name'], 0, 100),
            "{$prefix}_phone" => $a['phone'],
            "{$prefix}_detail_address" => mb_substr($a['street'], 0, 200),
            "{$prefix}_state" => mb_substr($a['state'], 0, 60),
            "{$prefix}_city" => mb_substr($a['city'], 0, 60),
            "{$prefix}_post_code" => $a['postcode'],
        ];
    }

    // ---- availability ----------------------------------------------------------------------

    /** Why an order can't be pushed to SPX right now, or null when it can. */
    public static function blocker(array $order): ?string
    {
        $o = SpxExpressSettings::overview();
        $sender = NinjaVanSettings::sender();
        return match (true) {
            !$o['enabled'] => 'SPX Express is not turned on. Enable it in API Management.',
            !$o['complete'] => 'SPX Express is not fully configured. Save the App ID, User ID and secrets first.',
            $sender === null => 'The pickup address is missing. Fill it in under API Management → NinjaVan.',
            $order['ship_country'] !== 'MY' => 'SPX pushes here are for Malaysia addresses only.',
            $order['status'] !== 'processing' => 'Move the order to Processing before pushing it.',
            $order['payment_method'] !== 'cod' && $order['payment_status'] !== 'paid' => 'Payment has not been received for this order yet.',
            ShipmentService::active((int) $order['id']) !== null => 'This order already has an active shipment.',
            default => null,
        };
    }

    // ---- create ----------------------------------------------------------------------------

    /** @return array{ok:bool,error?:string,tracking?:string} */
    public static function create(int $orderId, int $actorId): array
    {
        $creds = SpxExpressSettings::credentials();
        $sender = NinjaVanSettings::sender();
        if ($creds === null || $sender === null) {
            return ['ok' => false, 'error' => 'SPX Express is not fully configured.'];
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->fetch();
            if (!$order || ($why = self::blocker($order)) !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => $order ? $why : 'Order not found.'];
            }
            if (strlen((string) $order['order_no']) > self::MAX_ORDER_ID) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => "Order number {$order['order_no']} is too long for SPX (max " . self::MAX_ORDER_ID . ' characters).'];
            }
            $items = Database::query(
                'SELECT oi.*, p.weight_kg, p.length_cm, p.width_cm, p.height_cm FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id',
                [$orderId]
            )->fetchAll();
            if ($items === []) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'This order has no items, so there is nothing to send to SPX.'];
            }

            $pickup = null;
            if ($creds['collect'] === 'pickup') {
                $pickup = self::pickupSlot($creds);
                if ($pickup === null) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'SPX has no pickup slot available for your account right now. Try again later, or set collection to drop-off in API Management.'];
                }
            }

            $built = self::buildOrder($order, $items, $sender, $creds['collect'], $pickup);
            if (isset($built['error'])) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => $built['error']];
            }
            $body = $built['order'];

            $r = self::post($creds, 'order/batch_create_order', ['orders' => [$body]]);
            $created = $r['json']['data']['orders'][0] ?? null;
            $tn = is_array($created) ? (string) ($created['tracking_no'] ?? '') : '';
            if ($r['http'] !== 200 || ($r['json']['ret_code'] ?? -1) !== 0 || $tn === '') {
                $pdo->rollBack();
                $why = self::failReason($r['json'], (string) $order['order_no']);
                self::log("create failed for {$order['order_no']}: HTTP {$r['http']} {$why}");
                return ['ok' => false, 'error' => 'SPX could not create the order' . ($why !== '' ? ": {$why}" : " (HTTP {$r['http']}).")];
            }

            $weightKg = $body['parcel_info']['parcel_weight'];
            $cod = $body['fulfillment_info']['cod_amount'] ?? null;
            try {
                Database::query(
                    'INSERT INTO shipments (order_id, carrier, tracking_number, service_level, weight_kg, cod_amount, pickup_required, status, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$orderId, 'spx', $tn, null, $weightKg, $cod === null ? null : number_format((float) $cod, 2, '.', ''), $pickup !== null ? 1 : 0, 'Created', $actorId]
                );
            } catch (\Throwable $e) {
                self::log("CREATED at SPX but NOT saved locally: order {$order['order_no']} tracking {$tn}: " . $e->getMessage());
                throw $e;
            }
            OrderService::note($orderId, 'shipment_created', "SPX Express shipment {$tn} ({$weightKg} kg)");
            $pdo->commit();
            AuditService::log($actorId, 'spx.shipment', "Created {$tn} for {$order['order_no']}");
            return ['ok' => true, 'tracking' => $tn];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if (!$e instanceof \RuntimeException) {
                throw $e;
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The first pickup day and slot SPX offers this account, as pickup_time / pickup_time_range_id.
     * Null when SPX offers none (sandbox accounts often have no slots).
     *
     * @return array{pickup_time:int,pickup_time_range_id:int}|null
     */
    private static function pickupSlot(array $creds): ?array
    {
        $r = self::post($creds, 'order/get_pickup_time', ['service_type' => self::SERVICE_TYPE]);
        foreach (($r['json']['data'] ?? []) as $day) {
            if (!is_array($day)) {
                continue;
            }
            foreach (($day['slots'] ?? []) as $slot) {
                if (is_array($slot) && isset($day['pickup_time'], $slot['pickup_time_range_id'])) {
                    return [
                        'pickup_time' => (int) $day['pickup_time'],
                        'pickup_time_range_id' => (int) $slot['pickup_time_range_id'],
                    ];
                }
            }
        }
        return null;
    }

    // ---- cancel and waybill ----------------------------------------------------------------

    /** Cancel the active SPX booking of an order. @return array{ok:bool,error?:string} */
    public static function cancel(int $orderId, int $actorId): array
    {
        $s = ShipmentService::active($orderId);
        if ($s === null || $s['carrier'] !== 'spx') {
            return ['ok' => false, 'error' => 'There is no active SPX shipment to cancel.'];
        }
        $creds = SpxExpressSettings::credentials();
        if ($creds === null) {
            return ['ok' => false, 'error' => 'SPX Express is not fully configured.'];
        }
        $r = self::post($creds, 'order/batch_cancel_order', ['tracking_no_list' => [$s['tracking_number']]]);
        $cancelled = in_array($s['tracking_number'], $r['json']['data']['tracking_no_list'] ?? [], true);
        if ($r['http'] !== 200 || ($r['json']['ret_code'] ?? -1) !== 0 || !$cancelled) {
            $why = self::failReason($r['json'], (string) $s['tracking_number']);
            self::log("cancel failed for {$s['tracking_number']}: HTTP {$r['http']} {$why}");
            return ['ok' => false, 'error' => 'SPX could not cancel it' . ($why !== '' ? ": {$why}" : " (HTTP {$r['http']}).") . ' Only parcels still waiting for pickup can be cancelled.'];
        }
        Database::query("UPDATE shipments SET active = NULL, cancelled_at = NOW(), status = 'Cancelled' WHERE id = ? AND active = 1", [(int) $s['id']]);
        OrderService::note($orderId, 'shipment_cancelled', "SPX Express shipment {$s['tracking_number']} cancelled");
        AuditService::log($actorId, 'spx.shipment', "Cancelled {$s['tracking_number']}");
        return ['ok' => true];
    }

    /**
     * A fresh waybill (AWB) link for an active SPX booking. SPX links expire after 30 minutes or
     * ten opens, so the link is fetched on each request and never stored. @return array{ok:bool,url?:string,error?:string}
     */
    public static function waybillUrl(array $shipment): array
    {
        $creds = SpxExpressSettings::credentials();
        if ($creds === null) {
            return ['ok' => false, 'error' => 'SPX Express is not fully configured.'];
        }
        $r = self::post($creds, 'order/batch_get_shipping_label', ['tracking_no_list' => [$shipment['tracking_number']]]);
        $link = (string) ($r['json']['data']['awb_link'] ?? '');
        if ($r['http'] !== 200 || ($r['json']['ret_code'] ?? -1) !== 0 || $link === '') {
            $why = self::failReason($r['json'], (string) $shipment['tracking_number']);
            self::log("label failed for {$shipment['tracking_number']}: HTTP {$r['http']} {$why}");
            return ['ok' => false, 'error' => 'SPX could not produce the waybill' . ($why !== '' ? ": {$why}" : '.')];
        }
        return ['ok' => true, 'url' => $link];
    }

    // ---- transport -------------------------------------------------------------------------

    /**
     * One signed POST. The body carries user_id and user_secret; the JSON encoded here is the exact
     * string that is signed and sent. @return array{http:int,json:?array}
     */
    private static function post(array $creds, string $path, array $body): array
    {
        $full = ['user_id' => $creds['user_id'], 'user_secret' => $creds['user_secret']] + $body;
        $payload = json_encode($full, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $rand = (string) random_int(0, PHP_INT_MAX);
        $ch = curl_init(self::HOST[$creds['mode']] . '/open/api/v1/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'app-id: ' . $creds['app_id'],
                'check-sign: ' . self::sign($creds['app_id'], $creds['app_secret'], $payload, $ts, $rand),
                'timestamp: ' . $ts,
                'random-num: ' . $rand,
            ],
        ]);
        $raw = curl_exec($ch);
        $http = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return ['http' => $http, 'json' => is_array($json) ? $json : null];
    }

    /** Readable reason from an SPX envelope: the top-level message, or the first per-order fail_list entry. */
    private static function failReason(?array $json, string $ref): string
    {
        if (!is_array($json)) {
            return '';
        }
        if (($json['ret_code'] ?? 0) !== 0) {
            return trim((string) ($json['message'] ?? '')) . ' (ret_code ' . ($json['ret_code'] ?? 'unknown') . ')';
        }
        foreach (($json['data']['fail_list'] ?? []) as $f) {
            if (is_array($f)) {
                return trim((string) ($f['message'] ?? 'no reason given')) . ' (ret_code ' . ($f['ret_code'] ?? 'unknown') . ') for ' . $ref;
            }
        }
        return '';
    }

    private static function log(string $msg): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . '] spx: ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}
