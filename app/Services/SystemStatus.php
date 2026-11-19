<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Request;
use App\Models\Setting;

/**
 * The whole-site switch (Superadmin > Store status). Active = the store runs as normal.
 * Inactive = every page is offline (503) for everyone except a logged-in superadmin and the login and webhook URLs.
 * An active store can have an end time (system.expires_at, "Y-m-d H:i:s"). When that time passes the store switches
 * itself off and the end time is cleared. No end time means unlimited. Defaults to active and unlimited.
 */
final class SystemStatus
{
    public const KEY = 'system.active';
    public const KEY_EXPIRES = 'system.expires_at';

    /** Before the database exists (fresh install) there is no setting yet, so the site counts as active. */
    public static function isActive(): bool
    {
        try {
            if (Setting::get(self::KEY, '1') !== '1') {
                return false;
            }
            $until = self::expiresAt();
            if ($until !== null && $until <= date('Y-m-d H:i:s')) {
                self::set(false); // time is up: switch off, and forget the end time so the next switch-on starts fresh
                return false;
            }
            return true;
        } catch (\Throwable) {
            return true;
        }
    }

    /** The end time ("Y-m-d H:i:s"), or null when the store runs unlimited. */
    public static function expiresAt(): ?string
    {
        $value = trim((string) Setting::get(self::KEY_EXPIRES, ''));
        return $value === '' ? null : $value;
    }

    /** Switch on or off. An end time only applies when switching on; switching off clears it. */
    public static function set(bool $active, ?string $expiresAt = null): void
    {
        Setting::set(self::KEY, $active ? '1' : '0');
        Setting::set(self::KEY_EXPIRES, $active && $expiresAt !== null ? $expiresAt : '');
    }

    /**
     * While inactive, a logged-in superadmin can use the whole site (storefront, admin and superadmin portals), and
     * everyone else is refused. The public login, logout and webhook URLs stay open so that nobody is stranded.
     * Public assets are served by the web server and the offline page is styled inline, so they need no exception.
     */
    public static function allows(Request $request): bool
    {
        $user = Auth::user();
        if ($user !== null && ($user['role'] ?? null) === 'superadmin') {
            return true;
        }
        // Every /superadmin/* route still needs a superadmin login (its own route group), so it is always reachable here.
        // Payment and shipping webhooks are server-to-server and signature-checked. Refusing them would lose
        // payment confirmations while the store is offline, so they stay open.
        return str_starts_with($request->path, '/superadmin/')
            || in_array($request->path, ['/logout', '/payments/billplz/callback', '/billing/billplz/callback', '/shipping/ninjavan/webhook'], true);
    }
}
