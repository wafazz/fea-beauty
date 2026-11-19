<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\WholesaleService;

/** A member's business account: apply for credit terms and see the status (module wholesale_accounts). */
final class WholesaleController extends Controller
{
    public function show(Request $request): void
    {
        $this->view('member/wholesale', [
            'title' => 'Business account',
            'account' => WholesaleService::account((int) Auth::user()['id']),
        ], 'portal');
    }

    public function apply(Request $request): void
    {
        $in = ['company' => trim($request->str('company')), 'reg_no' => trim($request->str('reg_no'))];
        $errors = WholesaleService::apply((int) Auth::user()['id'], $in);
        if ($errors !== []) {
            $this->backWithErrors('/member/wholesale', $errors, $in);
        }
        $this->success('/member/wholesale', 'Application sent. The shop will review it and tell you the credit limit and terms.');
    }
}
