<?php
declare(strict_types=1);

// Usage: php bin/vapid-keys.php >> .env   (run once per site; keep VAPID_PRIVATE_KEY secret)
// Prints the three web push settings. Changing the keys later logs out every subscribed browser.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\PushService;

$keys = PushService::generateKeys();
echo 'VAPID_PUBLIC_KEY=' . $keys['public'] . "\n";
echo 'VAPID_PRIVATE_KEY=' . $keys['private'] . "\n";
echo "VAPID_SUBJECT=mailto:admin@example.com\n";
