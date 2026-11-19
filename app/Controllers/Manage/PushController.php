<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\PushService;

/** Browser push subscriptions for the admin who is signed in (admin and superadmin only; see routes). */
final class PushController extends ManageController
{
    public function subscribe(Request $request): void
    {
        $endpoint = trim($request->str('endpoint'));
        $p256dh = trim($request->str('p256dh'));
        $auth = trim($request->str('auth'));
        if (!PushService::ready()) {
            Response::json(['ok' => false, 'error' => 'Push is not set up on this server.'], 503);
        }
        if (strlen($endpoint) > 1000 || !PushService::allowedEndpoint($endpoint)
            || !preg_match('/^[A-Za-z0-9_-]{60,120}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{16,32}$/', $auth)) {
            Response::json(['ok' => false, 'error' => 'Invalid subscription.'], 422);
        }
        Database::query(
            'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth_secret, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth_secret = VALUES(auth_secret)',
            [
                (int) Auth::user()['id'], hash('sha256', $endpoint), $endpoint, $p256dh, $auth,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
        Response::json(['ok' => true]);
    }

    public function unsubscribe(Request $request): void
    {
        $endpoint = trim($request->str('endpoint'));
        Database::query(
            'DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?',
            [hash('sha256', $endpoint), (int) Auth::user()['id']]
        );
        Response::json(['ok' => true]);
    }
}
