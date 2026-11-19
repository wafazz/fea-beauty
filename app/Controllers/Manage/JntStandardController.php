<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\JntStandardSettings;

/** J&T Express standard API settings (the 2023 specs). Mounted in /admin and /staff behind permission:shipping.manage. */
final class JntStandardController extends ManageController
{
    public function show(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('manage/jnt-standard', [
            'title' => 'J&T Standard API',
            'base' => $this->base(),
            'cfg' => JntStandardSettings::overview(),
            'modes' => JntStandardSettings::MODES,
            'sandbox' => JntStandardSettings::SANDBOX_URLS,
            'urlLabels' => JntStandardSettings::URL_LABELS,
            'secretLabels' => JntStandardSettings::SECRET_LABELS,
            'history' => AuditService::recent('jntstd.'),
        ], 'portal');
    }

    public function update(Request $request): void
    {
        $in = [
            'enabled' => $request->str('enabled') === '1' ? '1' : '0',
            'mode' => $request->str('mode'),
            'username' => trim($request->str('username')),
            'customer_code' => trim($request->str('customer_code')),
            'eccompanyid' => trim($request->str('eccompanyid')),
            'prod_order' => trim($request->str('prod_order')),
            'prod_cancel' => trim($request->str('prod_cancel')),
            'prod_track' => trim($request->str('prod_track')),
            'prod_label' => trim($request->str('prod_label')),
            'sign_key' => trim($request->str('sign_key')),
            'api_key' => trim($request->str('api_key')),
            'password' => trim($request->str('password')),
            'track_key' => trim($request->str('track_key')),
        ];
        $back = $this->base() . '/shipping/jnt-standard';
        $errors = JntStandardSettings::validate($in, JntStandardSettings::overview());
        if ($errors !== []) {
            foreach (['sign_key', 'api_key', 'password', 'track_key'] as $secret) {
                unset($in[$secret]); // never echo secrets back into the session
            }
            $this->backWithErrors($back, $errors, $in);
        }
        $changed = JntStandardSettings::save($in, (int) Auth::user()['id']);
        $this->success($back, $changed === [] ? 'Nothing changed.' : 'J&T Standard API settings saved.');
    }
}
