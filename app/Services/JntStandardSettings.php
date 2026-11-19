<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\SecretBox;
use App\Models\Setting;

/**
 * J&T Express Malaysia, standard API: the Order, Cancel Order, Tracking and Consignment Note specs (2023).
 * Kept apart from the Open Platform settings (JntExpressSettings). Stored in settings under jntstd.*; the secrets
 * are encrypted with SecretBox and never rendered back. Nothing calls J&T from here yet.
 * The specs give sandbox URLs only, so production URLs are entered by the admin once J&T confirms them.
 */
final class JntStandardSettings
{
    public const MODES = ['sandbox' => 'Sandbox (testing)', 'production' => 'Production (live)'];

    /** From the J&T specs. Sandbox only: testing is done here before the live account is activated. */
    public const SANDBOX_URLS = [
        'order' => 'https://demostandard.jtexpress.my/blibli/order/createOrder',
        'cancel' => 'https://demostandard.jtexpress.my/blibli/order/cancelOrder',
        'track' => 'https://demostandard.jtexpress.my/common/track/trackings',
        'label' => 'https://demo-ylshopee.jtexpress.my/jandt_report_web/print/facelistAction!print.action',
    ];

    public const URL_LABELS = [
        'order' => 'Order (create)',
        'cancel' => 'Cancel order',
        'track' => 'Tracking',
        'label' => 'Consignment note (label print)',
    ];

    /** Secrets: encrypted, never shown again. */
    public const SECRET_LABELS = [
        'sign_key' => 'Signing key (Order and Cancel "key")',
        'api_key' => 'API key (Order and Cancel "api_key")',
        'password' => 'Account password (Order and Label "password")',
        'track_key' => 'Tracking key (Tracking "data_digest" key)',
    ];
    private const SECRETS = ['sign_key', 'api_key', 'password', 'track_key'];

    private static function k(string $name): string
    {
        return 'jntstd.' . $name;
    }

    public static function mode(): string
    {
        $m = Setting::get(self::k('mode'), 'sandbox');
        return isset(self::MODES[$m]) ? $m : 'sandbox';
    }

    public static function hint(string $secret): string
    {
        return strlen($secret) >= 12 ? '••••' . substr($secret, -4) : '••••••••';
    }

    /** Production URL setting key for one API, e.g. prod_order. */
    private static function prodKey(string $api): string
    {
        return 'prod_' . $api;
    }

    /** Production URLs the admin has entered (empty string = not yet). */
    public static function productionUrls(): array
    {
        $out = [];
        foreach (array_keys(self::URL_LABELS) as $api) {
            $out[$api] = (string) Setting::get(self::k(self::prodKey($api)), '');
        }
        return $out;
    }

    /** Safe-for-display view: secrets leave this method only as a masked hint. */
    public static function overview(): array
    {
        $out = [
            'enabled' => Setting::get(self::k('enabled'), '0') === '1',
            'mode' => self::mode(),
            'username' => (string) Setting::get(self::k('username')),
            'customer_code' => (string) Setting::get(self::k('customer_code')),
            'eccompanyid' => (string) Setting::get(self::k('eccompanyid')),
            'prod' => self::productionUrls(),
        ];
        foreach (self::SECRETS as $s) {
            $stored = (string) Setting::get(self::k($s));
            $plain = $stored === '' ? null : SecretBox::decrypt($stored);
            $out[$s . '_set'] = $stored !== '';
            $out[$s . '_hint'] = $plain !== null ? self::hint($plain) : null;
            $out[$s . '_unreadable'] = $stored !== '' && $plain === null;
        }
        $out['unreadable'] = $out['sign_key_unreadable'] || $out['api_key_unreadable'] || $out['password_unreadable'] || $out['track_key_unreadable'];
        $out['production_ready'] = !in_array('', $out['prod'], true);
        // The Order, Cancel and Label calls need these. Tracking needs eccompanyid and track_key as well (see tracking_ready).
        $out['complete'] = $out['username'] !== '' && $out['customer_code'] !== ''
            && $out['sign_key_set'] && $out['api_key_set'] && $out['password_set'] && !$out['unreadable'];
        $out['tracking_ready'] = $out['eccompanyid'] !== '' && $out['track_key_set'] && !$out['track_key_unreadable'];
        return $out;
    }

    /**
     * The URLs a call would use in the current mode, or null while production is not ready.
     * @return array<string,string>|null
     */
    public static function endpoints(): ?array
    {
        if (self::mode() === 'sandbox') {
            return self::SANDBOX_URLS;
        }
        $prod = self::productionUrls();
        return in_array('', $prod, true) ? null : $prod;
    }

    /** @param array<string,string> $in @param array<string,mixed> $current @return array<string,string> errors */
    public static function validate(array $in, array $current): array
    {
        $errors = [];
        if (!isset(self::MODES[$in['mode']])) {
            $errors['mode'] = 'Choose sandbox or production.';
        }
        if (!preg_match('/^[A-Za-z0-9_\-.]{1,100}$/', $in['username'])) {
            $errors['username'] = 'Username must be 1-100 letters, numbers, "-", "_" or ".".';
        }
        if ($in['customer_code'] === '' || mb_strlen($in['customer_code']) > 100) {
            $errors['customer_code'] = 'Customer code is required (at most 100 characters).';
        }
        if ($in['eccompanyid'] !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,20}$/', $in['eccompanyid'])) {
            $errors['eccompanyid'] = 'Tracking company ID is letters and numbers only (at most 20).';
        }
        foreach (self::URL_LABELS as $api => $label) {
            $v = $in['prod_' . $api];
            if ($v !== '' && !preg_match('#^https://[^\s]{4,290}$#', $v)) {
                $errors['prod_' . $api] = $label . ' must be a full https:// address.';
            }
        }
        foreach (self::SECRETS as $s) {
            $v = $in[$s];
            if ($v === '') {
                if (!$current[$s . '_set'] || $current[$s . '_unreadable']) {
                    $errors[$s] = self::SECRET_LABELS[$s] . ' is required.';
                }
            } elseif (!preg_match('/^[\x21-\x7E]{4,200}$/', $v)) {
                $errors[$s] = self::SECRET_LABELS[$s] . ' looks wrong (4-200 visible characters, no spaces).';
            }
        }
        // A live switch needs the live addresses first.
        $afterSave = $in['prod_order'] !== '' && $in['prod_cancel'] !== '' && $in['prod_track'] !== '' && $in['prod_label'] !== '';
        if ($in['enabled'] === '1' && $in['mode'] === 'production' && !$afterSave) {
            $errors['mode'] = 'Enter all four production URLs before switching to production.';
        }
        return $errors;
    }

    /** @return string[] changed field names (secret values are never logged) */
    public static function save(array $in, int $actorId): array
    {
        $before = self::overview();
        $changed = [];
        foreach (['mode', 'username', 'customer_code', 'eccompanyid'] as $f) {
            if ($in[$f] !== (string) $before[$f]) {
                Setting::set(self::k($f), $in[$f]);
                $changed[] = $f;
            }
        }
        foreach (array_keys(self::URL_LABELS) as $api) {
            $key = 'prod_' . $api;
            if ($in[$key] !== $before['prod'][$api]) {
                Setting::set(self::k(self::prodKey($api)), $in[$key]);
                $changed[] = $key;
            }
        }
        $on = ($in['enabled'] ?? '0') === '1' ? '1' : '0';
        if ($on !== ($before['enabled'] ? '1' : '0')) {
            Setting::set(self::k('enabled'), $on);
            $changed[] = 'enabled';
        }
        foreach (self::SECRETS as $s) {
            if ($in[$s] !== '') {
                Setting::set(self::k($s), SecretBox::encrypt($in[$s]));
                $changed[] = $s;
            }
        }
        if ($changed !== []) {
            AuditService::log($actorId, 'jntstd.update', 'Changed: ' . implode(', ', $changed) . ' (secret values are never logged)');
        }
        return $changed;
    }
}
