<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\SupportPresence;

/** Check-in from a member's open, visible tab. Used only to skip support emails while the member is looking. */
final class PresenceController extends Controller
{
    public function ping(Request $request): void
    {
        // Only a visible tab counts: the browser sends visible=1 only when the page is in front.
        if ($request->str('visible') === '1') {
            SupportPresence::touch((int) Auth::user()['id']);
        }
        Response::json(['ok' => true]);
    }
}
