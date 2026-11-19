<?php
declare(strict_types=1);

// Cron (every 10 minutes): php bin/marketing.php
// Schedules the win-back emails for the chosen customer segment, then sends the marketing emails that are due
// (module marketing_flows). Nothing is sent while the module or a flow is switched off.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\MarketingService;

$scheduled = MarketingService::scanWinBack();
$r = MarketingService::runDue(200);
echo "Win-back scheduled: {$scheduled}. Sent {$r['sent']}, skipped {$r['skipped']}, failed {$r['failed']}, waiting {$r['waiting']}.\n";
