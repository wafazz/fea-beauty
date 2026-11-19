<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\CustomerSegmentService;
use App\Services\MarketingService;

/** Marketing flows (module marketing_flows). Admin only; routes guarded by the module. */
final class MarketingController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/marketing', [
            'title' => 'Marketing emails',
            'flows' => MarketingService::flows(),
            'stats' => MarketingService::stats(),
            'segments' => CustomerSegmentService::all(),
            'scripts' => ['marketing.js'],
        ], 'portal');
    }

    public function save(Request $request, string $key): void
    {
        $in = [
            'subject' => trim($request->str('subject')),
            'body' => trim($request->str('body')),
            'delay_hours' => trim($request->str('delay_hours', '0')),
            'enabled' => $request->str('enabled') === '1' ? '1' : '0',
            'segment_id' => trim($request->str('segment_id')),
            'repeat_days' => trim($request->str('repeat_days')),
        ];
        $errors = MarketingService::saveFlow($key, $in, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/marketing', $errors, $in);
        }
        $this->success('/admin/marketing', 'Saved. ' . ($in['enabled'] === '1' ? 'This email is on.' : 'This email is off, so nothing is sent.'));
    }
}
