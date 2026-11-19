<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\DashboardService;
use App\Services\Modules;
use App\Services\OverviewService;

final class DashboardController extends Controller
{
    /** The admin front page: the overview when the admin_overview module is on, otherwise the standard dashboard. */
    public function index(Request $request): void
    {
        if (Modules::enabled('admin_overview')) {
            $this->view('admin/overview', [
                'title' => 'Overview',
                'o' => OverviewService::build(),
            ], 'portal');
            return;
        }
        $this->analytics($request);
    }

    /** The standard charts dashboard (always available at /admin/analytics). */
    public function analytics(Request $request): void
    {
        $this->view('admin/dashboard', [
            'title' => 'Dashboard',
            'dash' => DashboardService::admin($this->days($request)),
            'visitors' => self::visitors(),
            'scripts' => ['charts.js', 'dashboard.js', 'visitors.js'],
        ], 'portal');
    }

    /** JSON for the live refresh / range switch. */
    public function data(Request $request): void
    {
        header('Cache-Control: no-store');
        Response::json(DashboardService::admin($this->days($request)));
    }

    private function days(Request $request): int
    {
        return in_array((int) $request->input('range', 30), DashboardService::RANGES, true) ? (int) $request->input('range', 30) : 30;
    }

    /** Live visitor counts for the panel; zeros (never an error) if the visitor tables are not there yet. */
    private static function visitors(): array
    {
        try {
            return \App\Services\VisitorService::cachedStats();
        } catch (\Throwable) {
            return ['online' => 0, 'online_members' => 0, 'online_guests' => 0, 'today' => 0, 'week' => 0, 'month' => 0, 'overall' => 0, 'at' => date('H:i:s')];
        }
    }
}
