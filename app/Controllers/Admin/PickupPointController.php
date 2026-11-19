<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\PickupPointService;

/** Store pickup points (module shipping_rules). Admin only; the routes are guarded by module:shipping_rules. */
final class PickupPointController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/pickup_points', [
            'title' => 'Pickup points',
            'points' => PickupPointService::all(),
            'states' => config('states'),
            'scripts' => ['pickup-points.js'],
        ], 'portal');
    }

    public function store(Request $request): void
    {
        $this->save(null, $request);
    }

    public function update(Request $request, string $id): void
    {
        $point = PickupPointService::find((int) $id) ?? \App\Core\Response::abort(404);
        $this->save((int) $point['id'], $request);
    }

    private function save(?int $id, Request $request): void
    {
        $in = [
            'name' => trim($request->str('name')),
            'line1' => trim($request->str('line1')),
            'city' => trim($request->str('city')),
            'state' => trim($request->str('state')),
            'postcode' => trim($request->str('postcode')),
            'opening_hours' => trim($request->str('opening_hours')),
            'sort_order' => trim($request->str('sort_order')),
            'active' => $request->str('active') === '1' ? '1' : '0',
        ];
        $errors = PickupPointService::save($id, $in, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/pickup-points', $errors, $in);
        }
        $this->success('/admin/pickup-points', $id === null ? 'Pickup point added.' : 'Pickup point saved.');
    }
}
