<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\SpxExpressSettings;

/** SPX Express courier settings. Mounted in /admin and /staff behind permission:shipping.manage. */
final class SpxExpressController extends ManageController
{
    public function show(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('manage/spx-express', [
            'title' => 'SPX Express',
            'base' => $this->base(),
            'cfg' => SpxExpressSettings::overview(),
            'modes' => SpxExpressSettings::MODES,
            'collect' => SpxExpressSettings::COLLECT,
            'history' => AuditService::recent('spx.'),
        ], 'portal');
    }

    public function update(Request $request): void
    {
        $in = [
            'enabled' => $request->str('enabled') === '1' ? '1' : '0',
            'mode' => $request->str('mode'),
            'collect' => $request->str('collect'),
            'app_id' => trim($request->str('app_id')),
            'user_id' => trim($request->str('user_id')),
            'app_secret' => trim($request->str('app_secret')),
            'user_secret' => trim($request->str('user_secret')),
        ];
        $back = $this->base() . '/shipping/spx-express';
        $errors = SpxExpressSettings::validate($in, SpxExpressSettings::overview());
        if ($errors !== []) {
            unset($in['app_secret'], $in['user_secret']); // never echo secrets back into the session
            $this->backWithErrors($back, $errors, $in);
        }
        $changed = SpxExpressSettings::save($in, (int) Auth::user()['id']);
        $this->success($back, $changed === [] ? 'Nothing changed.' : 'SPX Express settings saved.' . ($in['enabled'] === '1' ? ' The Push to SPX Express button now shows on Processing orders.' : ''));
    }
}
