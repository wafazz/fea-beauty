<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * The scheduled jobs from the deploy checklist, and when each one next runs. Interval jobs are assumed to run on the
 * clock (every 10 minutes at :00, :10, ...; hourly at the top of the hour). Daily jobs have no fixed time in the code,
 * so they show "daily" and their time is whatever the server cron uses. The worker records each run (cron.worker.last),
 * so the admin can see whether it is still running.
 */
final class CronStatus
{
    public const WORKER_KEY = 'cron.worker.last';
    /** A worker run older than this is reported as stale (it runs every minute). */
    public const STALE_SECONDS = 180;

    /** [script, label, minutes between runs (0 = daily), what it does] */
    public const JOBS = [
        ['worker.php', 'Every minute', 1, 'Sends queued emails and WhatsApp messages'],
        ['abandoned-carts.php', 'Every 10 minutes', 10, 'Abandoned-cart reminders (when that module is on)'],
        ['expire-unpaid.php', 'Hourly', 60, 'Cancels unpaid online-payment orders and restocks them'],
        ['subscriptions.php', 'Hourly', 60, 'Runs due subscription cycles'],
        ['module-billing.php', 'Daily', 0, 'Emails admins before a module renews'],
        ['prune-carts.php', 'Daily', 0, 'Removes stale guest carts'],
        ['retention.php', 'Daily', 0, 'Deletes old logs under the retention rules'],
        ['backup.php', 'Daily', 0, 'Database backup'],
        ['copurchase.php', 'Daily', 0, 'Works out products bought together, for cross-sell suggestions'],
    ];

    /** Last time the worker started, or null if it has never run. */
    public static function workerLastRun(): ?string
    {
        $v = trim((string) Setting::get(self::WORKER_KEY, ''));
        return $v === '' ? null : $v;
    }

    /** Whether the worker has run recently enough. */
    public static function workerHealthy(?int $now = null): bool
    {
        $last = self::workerLastRun();
        if ($last === null) {
            return false;
        }
        return ($now ?? time()) - (int) strtotime($last) <= self::STALE_SECONDS;
    }

    /**
     * Next run of an interval job, as "Y-m-d H:i", from the clock boundary after $now. Null for daily jobs.
     */
    public static function nextRun(int $minutes, int $now): ?string
    {
        if ($minutes <= 0) {
            return null;
        }
        $step = $minutes * 60;
        $next = (int) (floor($now / $step) + 1) * $step;
        return date('Y-m-d H:i', $next);
    }

    /**
     * Rows for the overview: [script, label, what, next run or null]. Next run is "" for daily jobs.
     * @return array<int,array{script:string,label:string,what:string,next:?string}>
     */
    public static function rows(?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach (self::JOBS as [$script, $label, $minutes, $what]) {
            $out[] = ['script' => $script, 'label' => $label, 'what' => $what, 'next' => self::nextRun($minutes, $now)];
        }
        return $out;
    }
}
