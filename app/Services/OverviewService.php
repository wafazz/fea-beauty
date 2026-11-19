<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The admin front page (module admin_overview): today's sales, orders waiting, low stock and the cron jobs.
 * Same definitions as the standard dashboard (DashboardService), so the numbers agree.
 */
final class OverviewService
{
    public static function build(): array
    {
        $today = self::sales('CURDATE()');
        $yesterday = self::sales('CURDATE() - INTERVAL 1 DAY');

        $newOrders = (int) Database::query("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND (payment_method = 'cod' OR payment_status = 'paid')")->fetchColumn();
        $readyToShip = (int) Database::query("SELECT COUNT(*) FROM orders o WHERE o.status = 'processing' AND (o.payment_method = 'cod' OR o.payment_status = 'paid') AND NOT EXISTS (SELECT 1 FROM shipments s WHERE s.order_id = o.id AND s.active = 1)")->fetchColumn();
        $awaitingPayment = (int) Database::query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing') AND payment_method <> 'cod' AND payment_status = 'unpaid'")->fetchColumn();

        $now = time();
        return [
            'sales' => $today['sales'],
            'orders' => $today['orders'],
            'yesterday_sales' => $yesterday['sales'],
            'yesterday_orders' => $yesterday['orders'],
            'new_orders' => $newOrders,
            'ready_to_ship' => $readyToShip,
            'awaiting_payment' => $awaitingPayment,
            'low_stock' => DashboardService::lowStock('/admin'),
            'worker_last' => CronStatus::workerLastRun(),
            'worker_healthy' => CronStatus::workerHealthy($now),
            'jobs' => CronStatus::rows($now),
        ];
    }

    /** Sales and order count for one day (not cancelled orders). $day is a SQL date expression. */
    private static function sales(string $day): array
    {
        $r = Database::query("SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM orders WHERE status <> 'cancelled' AND DATE(created_at) = {$day}")->fetch();
        return ['orders' => (int) $r['n'], 'sales' => (float) $r['s']];
    }
}
