<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\WholesaleService;

/** Business accounts: approve, set the credit limit and terms, and the ageing report (module wholesale_accounts). */
final class WholesaleController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/wholesale', [
            'title' => 'Business accounts',
            'accounts' => WholesaleService::all(),
            'ageing' => WholesaleService::ageing(),
            'buckets' => WholesaleService::BUCKETS,
        ], 'portal');
    }

    public function update(Request $request, string $id): void
    {
        $in = [
            'status' => $request->str('status'),
            'credit_limit' => trim($request->str('credit_limit', '0')),
            'terms_days' => trim($request->str('terms_days', '30')),
        ];
        $errors = WholesaleService::update((int) $id, $in, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/wholesale', $errors, $in);
        }
        $this->success('/admin/wholesale', 'Business account saved.');
    }
}
