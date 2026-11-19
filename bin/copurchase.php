<?php
declare(strict_types=1);

// Cron (daily): php bin/copurchase.php
// Works out which products are bought together in the last 180 days, for the cross-sell suggestions
// (module cross_sell). Replaces the stored pairs in one transaction, so a failed run keeps yesterday's list.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\CrossSellService;

$n = CrossSellService::recompute();
echo "Bought-together pairs saved: {$n}.\n";
