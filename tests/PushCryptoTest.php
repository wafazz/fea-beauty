<?php
// Web push crypto (no database): the payload encrypts so a browser can decrypt it (RFC 8291 / RFC 8188), and the
// VAPID JWT signature verifies against the public key in .env (RFC 8292). Runs without a DB.
use App\Services\PushService;

$ref = new ReflectionClass(PushService::class);
$encrypt = $ref->getMethod('encrypt');
$encrypt->setAccessible(true);
$jwt = $ref->getMethod('jwt');
$jwt->setAccessible(true);
$spki = $ref->getMethod('spki');
$spki->setAccessible(true);

$b64d = fn(string $t): string => (string) base64_decode(strtr($t, '-_', '+/') . str_repeat('=', (4 - strlen($t) % 4) % 4), true);

if (!PushService::ready()) {
    echo "  skip  (VAPID keys not set in .env)\n";
} else {
    // A stand-in browser: its own P-256 key pair and 16-byte auth secret.
    $client = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 384]);
    $cd = openssl_pkey_get_details($client);
    $uaPublic = "\x04" . str_pad($cd['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($cd['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $authSecret = random_bytes(16);
    $message = '{"title":"New order TEST","body":"Total RM 1.00","url":"http://localhost/admin/orders/1"}';

    $body = $encrypt->invoke(null, $message, $uaPublic, $authSecret);
    check('record size is 4096', unpack('N', substr($body, 16, 4))[1] === 4096);
    check('key id is the 65-byte sender key', ord($body[20]) === 65);

    // Decrypt the way a browser does.
    $salt = substr($body, 0, 16);
    $asPublic = substr($body, 21, 65);
    $rest = substr($body, 86);
    $tag = substr($rest, -16);
    $cipher = substr($rest, 0, -16);
    $shared = openssl_pkey_derive(openssl_pkey_get_public($spki->invoke(null, $asPublic)), $client, 32);
    $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    check('browser can decrypt the payload', $plain !== false && rtrim((string) $plain, "\x02") === $message);

    // VAPID JWT: the ES256 signature verifies with the public key, and the claims are the ones we set.
    [$h, $p, $s] = explode('.', $jwt->invoke(null, 'https://push.example'));
    $raw = $b64d($s);
    $int = function (string $x): string {
        $x = ltrim($x, "\0");
        return (ord($x[0] ?? "\0") & 0x80 ? "\0" : '') . $x;
    };
    $r = $int(substr($raw, 0, 32));
    $sv = $int(substr($raw, 32, 32));
    $der = "\x30" . chr(4 + strlen($r) + strlen($sv)) . "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($sv)) . $sv;
    $pub = $spki->invoke(null, $b64d((string) env('VAPID_PUBLIC_KEY', '')));
    check('VAPID JWT signature verifies', openssl_verify($h . '.' . $p, $der, $pub, OPENSSL_ALGO_SHA256) === 1);
    $claims = json_decode($b64d($p), true);
    check('VAPID JWT audience is the push origin', ($claims['aud'] ?? '') === 'https://push.example');
    check('VAPID JWT expires within 12 hours', ($claims['exp'] ?? 0) > time() && ($claims['exp'] ?? 0) <= time() + 12 * 3600);
}

// Endpoint allowlist: only big browser push services, https, default port, no credentials.
check('allows Chrome push endpoint', PushService::allowedEndpoint('https://fcm.googleapis.com/fcm/send/abc'));
check('allows Firefox push endpoint', PushService::allowedEndpoint('https://updates.push.services.mozilla.com/wpush/v2/abc'));
check('allows Windows push endpoint', PushService::allowedEndpoint('https://wns2-pn1p.notify.windows.com/w/?token=abc'));
check('rejects http endpoint', !PushService::allowedEndpoint('http://fcm.googleapis.com/fcm/send/abc'));
check('rejects arbitrary host', !PushService::allowedEndpoint('https://internal.example.com/x'));
check('rejects non-default port', !PushService::allowedEndpoint('https://fcm.googleapis.com:8443/fcm/send/abc'));
check('rejects lookalike host', !PushService::allowedEndpoint('https://fcm.googleapis.com.evil.example/x'));
