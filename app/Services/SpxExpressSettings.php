<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\SecretBox;
use App\Models\Setting;

/**
 * SPX Express (Malaysia) courier credentials. Plain fields live in settings; the App Secret and the
 * User Secret are encrypted with SecretBox and never rendered back. Both pairs are issued by SPX
 * (there is no self-serve signup): App ID + App Secret sign each request, User ID + User Secret are
 * the sender account that bookings are billed to.
 */
final class SpxExpressSettings
{
    public const MODES = ['sandbox' => 'Sandbox (testing)', 'production' => 'Production (live)'];
    /** dropoff = you take parcels to an SPX point; pickup = an SPX rider collects from the pickup address. */
    public const COLLECT = ['pickup' => 'SPX collects from my pickup address', 'dropoff' => 'I drop off at an SPX point'];
    private const SECRETS = ['app_secret', 'user_secret'];

    private static function k(string $name): string
    {
        return 'spx.' . $name;
    }

    public static function mode(): string
    {
        $m = Setting::get(self::k('mode'), 'sandbox');
        return isset(self::MODES[$m]) ? $m : 'sandbox';
    }

    public static function collect(): string
    {
        $c = Setting::get(self::k('collect'), 'pickup');
        return isset(self::COLLECT[$c]) ? $c : 'pickup';
    }

    public static function hint(string $secret): string
    {
        return strlen($secret) >= 12 ? '••••' . substr($secret, -4) : '••••••••';
    }

    /** Safe-for-display view: secrets leave this method only as a masked hint. */
    public static function overview(): array
    {
        $out = [
            'enabled' => Setting::get(self::k('enabled'), '0') === '1',
            'mode' => self::mode(),
            'collect' => self::collect(),
            'app_id' => Setting::get(self::k('app_id')),
            'user_id' => Setting::get(self::k('user_id')),
        ];
        foreach (self::SECRETS as $s) {
            $stored = Setting::get(self::k($s));
            $plain = $stored === '' ? null : SecretBox::decrypt($stored);
            $out[$s . '_set'] = $stored !== '';
            $out[$s . '_hint'] = $plain !== null ? self::hint($plain) : null;
            $out[$s . '_unreadable'] = $stored !== '' && $plain === null;
        }
        $out['unreadable'] = $out['app_secret_unreadable'] || $out['user_secret_unreadable'];
        $out['complete'] = $out['app_id'] !== '' && $out['user_id'] !== '' && $out['app_secret_set'] && $out['user_secret_set'] && !$out['unreadable'];
        return $out;
    }

    /** True when the admin switched SPX on and the credentials are complete: shows the Push button and allows pushes. */
    public static function enabled(): bool
    {
        $o = self::overview();
        return $o['enabled'] && $o['complete'];
    }

    /** Decrypted credentials for server-side use; null until everything needed is saved and readable. */
    public static function credentials(): ?array
    {
        $o = self::overview();
        if (!$o['complete']) {
            return null;
        }
        $secrets = [];
        foreach (self::SECRETS as $s) {
            $secrets[$s] = SecretBox::decrypt(Setting::get(self::k($s)));
        }
        return [
            'mode' => $o['mode'],
            'collect' => $o['collect'],
            'app_id' => $o['app_id'],
            'user_id' => (int) $o['user_id'],
        ] + $secrets;
    }

    /** @param array<string,string> $in @return array<string,string> errors */
    public static function validate(array $in, array $current): array
    {
        $errors = [];
        if (!isset(self::MODES[$in['mode']])) {
            $errors['mode'] = 'Choose sandbox or production.';
        }
        if (!isset(self::COLLECT[$in['collect']])) {
            $errors['collect'] = 'Choose how parcels reach SPX.';
        }
        if (!preg_match('/^[A-Za-z0-9_\-.]{1,100}$/', $in['app_id'])) {
            $errors['app_id'] = 'App ID must be 1-100 letters, numbers, "-", "_" or ".".';
        }
        if (!preg_match('/^[0-9]{1,20}$/', $in['user_id'])) {
            $errors['user_id'] = 'User ID is the number SPX issued for your sender account (digits only).';
        }
        $secretLabels = ['app_secret' => 'App Secret', 'user_secret' => 'User Secret'];
        foreach (self::SECRETS as $s) {
            $v = $in[$s];
            if ($v === '') {
                if (!$current[$s . '_set'] || $current[$s . '_unreadable']) {
                    $errors[$s] = $secretLabels[$s] . ' is required.';
                }
            } elseif (!preg_match('/^[\x21-\x7E]{4,200}$/', $v)) {
                $errors[$s] = $secretLabels[$s] . ' looks wrong (4-200 visible characters, no spaces).';
            }
        }
        return $errors;
    }

    /** @return string[] changed field names (secret values are never logged) */
    public static function save(array $in, int $actorId): array
    {
        $before = self::overview();
        $changed = [];
        foreach (['mode', 'collect', 'app_id', 'user_id'] as $f) {
            if ($in[$f] !== (string) ($before[$f] ?? '')) {
                Setting::set(self::k($f), $in[$f]);
                $changed[] = $f;
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
            AuditService::log($actorId, 'spx.update', 'Changed: ' . implode(', ', $changed) . ' (secret values are never logged)');
        }
        return $changed;
    }
}
