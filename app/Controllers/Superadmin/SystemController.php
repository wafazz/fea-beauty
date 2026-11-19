<?php
declare(strict_types=1);

namespace App\Controllers\Superadmin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\SuperadminPin;
use App\Services\SystemStatus;

/** Superadmin only (route group auth:superadmin). Switches the whole store active or inactive, with an optional end time. */
final class SystemController extends Controller
{
    public function show(Request $request): void
    {
        $this->view('superadmin/system', [
            'title' => 'Store status',
            'active' => SystemStatus::isActive(),
            'expiresAt' => SystemStatus::expiresAt(),
            'custom' => SuperadminPin::isCustom((int) Auth::user()['id']),
            'scripts' => ['pin-pad.js', 'system.js'],
        ], 'portal');
    }

    /**
     * Switch on or off. Switching on takes the chosen end time (unlimited by default). Switching off clears it.
     * The PIN is checked here, as on the module switches.
     */
    public function toggle(Request $request): void
    {
        $actor = (int) Auth::user()['id'];
        $this->requirePin($request, $actor, '/superadmin/system');

        if ($request->str('active') !== '1') {
            SystemStatus::set(false);
            AuditService::log($actor, 'system.inactive', 'store');
            $this->done('The store is now offline. Only the superadmin portal works until you switch it back on.', '/superadmin/system');
        }

        $until = $this->endTime($request, '/superadmin/system');
        SystemStatus::set(true, $until);
        AuditService::log($actor, 'system.active', 'store until ' . ($until ?? 'unlimited'));
        $this->done('The store is active' . ($until !== null ? ' until ' . $this->label($until) . '.' : ', with no end date.') . ' Everyone can use it.', '/superadmin/system');
    }

    /** Changes the end time while the store is already active. Unlimited, or a date and time. */
    public function expiry(Request $request): void
    {
        $actor = (int) Auth::user()['id'];
        $this->requirePin($request, $actor, '/superadmin/system');

        if (!SystemStatus::isActive()) {
            $this->fail('Switch the store on before setting an end date.', '/superadmin/system');
        }
        $until = $this->endTime($request, '/superadmin/system');
        SystemStatus::set(true, $until);
        AuditService::log($actor, 'system.expiry', 'store until ' . ($until ?? 'unlimited'));
        $this->done('End date saved: ' . ($until !== null ? 'the store closes on ' . $this->label($until) . '.' : 'unlimited.'), '/superadmin/system');
    }

    /** Stops the request unless the PIN is right. Wrong or locked PINs answer JSON for the popup, or go back with a message. */
    private function requirePin(Request $request, int $actor, string $back): void
    {
        $check = SuperadminPin::verify($actor, $request->str('pin'));
        if ($check['ok']) {
            return;
        }
        $locked = $check['error'] === 'locked';
        $msg = $locked ? 'Too many wrong PINs. Try again in 15 minutes.' : 'Wrong PIN. Try again.';
        if (Request::wantsJson()) {
            Response::json(['ok' => false, 'pin' => true, 'locked' => $locked, 'message' => $msg], 403);
        }
        $this->backWithErrors($back, ['form' => $msg]);
    }

    /** The end time from the form: null for unlimited, otherwise "Y-m-d H:i:s" in the future. Stops with a message if invalid. */
    private function endTime(Request $request, string $back): ?string
    {
        if ($request->str('expires_mode') !== 'date') {
            return null;
        }
        $raw = trim($request->str('expires_at'));
        if ($raw === '') {
            $this->fail('Choose the date and time the store closes, or choose Unlimited.', $back);
        }
        try {
            $when = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            $this->fail('That date and time is not valid.', $back);
        }
        if ($when->format('Y-m-d H:i:s') <= date('Y-m-d H:i:s')) {
            $this->fail('The end time must be in the future.', $back);
        }
        return $when->format('Y-m-d H:i:s');
    }

    private function label(string $dateTime): string
    {
        return date('d M Y, H:i', strtotime($dateTime));
    }

    private function done(string $message, string $back): never
    {
        if (Request::wantsJson()) {
            Response::json(['ok' => true, 'message' => $message]);
        }
        $this->success($back, $message);
    }

    private function fail(string $message, string $back): never
    {
        if (Request::wantsJson()) {
            Response::json(['ok' => false, 'message' => $message], 422);
        }
        $this->backWithErrors($back, ['form' => $message]);
    }
}
