<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Web push: a browser notification to admins even when the admin site is closed.
 *
 * Plain PHP, no Composer: RFC 8291 (payload encryption, aes128gcm) and RFC 8292 (VAPID: an ES256-signed JWT).
 * The keys live in .env. Make them once with `php bin/vapid-keys.php`; keep VAPID_PRIVATE_KEY secret.
 * A push is queued after an order is saved (job `push.order`), so a slow push service never delays checkout.
 * A failed push is logged and not retried, so the admins who did get it are not sent it twice.
 */
final class PushService
{
    /** Tests can capture the message instead of sending it. Unset in production. */
    public static ?\Closure $capture = null;

    public static function publicKey(): ?string
    {
        $key = (string) env('VAPID_PUBLIC_KEY', '');
        return $key !== '' ? $key : null;
    }

    public static function ready(): bool
    {
        return self::publicKey() !== null
            && (string) env('VAPID_PRIVATE_KEY', '') !== ''
            && function_exists('openssl_pkey_derive');
    }

    /** A new key pair as base64url: public = 65-byte uncompressed point, private = 32-byte scalar. */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 384]);
        $details = openssl_pkey_get_details($key);
        $public = self::point($details['ec']['x'], $details['ec']['y']);
        return [
            'public' => self::b64u($public),
            'private' => self::b64u(str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /** Called by the queue worker. Sends the new-order alert to every active admin with a subscribed browser. */
    public static function notifyNewOrder(int $orderId): bool
    {
        $order = Database::query('SELECT id, order_no, total FROM orders WHERE id = ?', [$orderId])->fetch();
        if (!$order || (!self::ready() && self::$capture === null)) {
            return true;
        }
        $subs = Database::query(
            "SELECT s.id, s.endpoint, s.p256dh, s.auth_secret
               FROM push_subscriptions s
               JOIN users u ON u.id = s.user_id
              WHERE u.role IN ('admin', 'superadmin') AND u.status = 'active'"
        )->fetchAll();
        $payload = [
            'title' => 'New order ' . $order['order_no'],
            'body' => 'Total RM ' . number_format((float) $order['total'], 2) . '. Tap to open.',
            'url' => url('/admin/orders/' . $order['id']),
        ];
        foreach ($subs as $sub) {
            try {
                self::send($sub, $payload);
            } catch (\Throwable $e) {
                self::log('push to subscription ' . $sub['id'] . ' failed: ' . $e->getMessage());
            }
        }
        return true;
    }

    /**
     * Only the big browser push services are accepted, so an admin cannot point the server at an arbitrary URL.
     * HTTPS, default port, and a known host: Google (Chrome, Edge, Android), Mozilla (Firefox), Apple (Safari), Windows.
     */
    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com'], true)) {
            return true;
        }
        return (bool) preg_match('/\.notify\.windows\.com$/', $host);
    }

    /** Send one message. A subscription the browser has dropped (404/410) is removed. */
    public static function send(array $sub, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (self::$capture !== null) {
            (self::$capture)($sub['endpoint'], $payload);
            return;
        }
        if (!self::allowedEndpoint((string) $sub['endpoint'])) {
            self::log('subscription ' . $sub['id'] . ' has a disallowed endpoint; not sent');
            return;
        }
        $body = self::encrypt($json, self::b64uDecode((string) $sub['p256dh']), self::b64uDecode((string) $sub['auth_secret']));
        $status = self::post((string) $sub['endpoint'], $body);
        if ($status === 404 || $status === 410) {
            Database::query('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
            return;
        }
        if ($status < 200 || $status >= 300) {
            self::log('push service answered ' . $status . ' for subscription ' . $sub['id']);
        }
    }

    /**
     * RFC 8291: ECDH with a fresh key pair for each message, HKDF to a content key and nonce, then AES-128-GCM.
     * Body layout (RFC 8188): salt(16) | record size(4) | key id length(1) | sender public key(65) | ciphertext | tag(16).
     */
    private static function encrypt(string $plain, string $uaPublic, string $authSecret): string
    {
        $as = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 384]);
        $asDetails = openssl_pkey_get_details($as);
        $asPublic = self::point($asDetails['ec']['x'], $asDetails['ec']['y']);

        $uaKey = openssl_pkey_get_public(self::spki($uaPublic));
        $shared = openssl_pkey_derive($uaKey, $as, 32);
        if ($shared === false || strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new \RuntimeException('invalid push subscription keys');
        }

        $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
        $keyInfo = "WebPush: info\0" . $uaPublic . $asPublic;
        $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);

        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $cipher = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('payload encryption failed');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    private static function post(string $endpoint, string $body): int
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: 3600',
                'Urgency: normal',
                'Authorization: vapid t=' . self::jwt($audience) . ', k=' . self::publicKey(),
            ],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status;
    }

    /** RFC 8292: ES256 JWT for the push service's origin. */
    private static function jwt(string $audience): string
    {
        $header = self::b64u((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u((string) json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => (string) env('VAPID_SUBJECT', 'mailto:admin@example.com'),
        ]));
        $input = $header . '.' . $claims;
        $key = openssl_pkey_get_private(self::privatePem(self::b64uDecode((string) env('VAPID_PRIVATE_KEY', ''))));
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID signing failed: check VAPID_PRIVATE_KEY');
        }
        return $input . '.' . self::b64u(self::derToRaw($der));
    }

    /** SEC1 EC private key from the raw 32-byte scalar (public part omitted; OpenSSL accepts that). */
    private static function privatePem(string $rawD): string
    {
        $der = hex2bin('30310201010420') . $rawD . hex2bin('a00a06082a8648ce3d030107');
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /** SubjectPublicKeyInfo PEM from a 65-byte uncompressed P-256 point. */
    private static function spki(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** OpenSSL's DER signature (SEQUENCE of two INTEGERs) to the raw r || s form JWT needs: 32 bytes each. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    private static function point(string $x, string $y): string
    {
        return "\x04" . str_pad($x, 32, "\0", STR_PAD_LEFT) . str_pad($y, 32, "\0", STR_PAD_LEFT);
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }

    private static function log(string $message): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . '] push: ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }
}
