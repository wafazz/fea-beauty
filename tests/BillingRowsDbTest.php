<?php
// The admin billing page's rows, built in one batch (ModuleBillingService::billingRows), must equal the rows the page
// built one module at a time before (copied below as the reference). Covers prices, own and global discounts, an
// expired discount, paid-up and lifetime subscriptions, open invoices, and module switches.
use App\Core\Database as DB;
use App\Services\ModuleBillingService as B;
use App\Services\ModulePricing as P;
use App\Services\Modules;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query('DELETE FROM module_invoices');
DB::query('DELETE FROM module_subscriptions');
DB::query('DELETE FROM module_discounts');
DB::query('DELETE FROM module_prices');
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");

// Prices: flash sales on monthly and yearly; vouchers one-time only; newsletter has no price (coming soon).
DB::query("INSERT INTO module_prices (module_key, period, amount) VALUES ('flash_sales', 'monthly', 50), ('flash_sales', 'yearly', 500), ('vouchers', 'one_time', 99)");
// Discounts: flash sales has its own 10% (active); vouchers falls back to a global RM80 fixed price, after its own expired.
$future = date('Y-m-d H:i:s', time() + 86400 * 5);
$past = date('Y-m-d H:i:s', time() - 86400);
DB::query("INSERT INTO module_discounts (scope, kind, value, ends_at) VALUES ('flash_sales', 'percent', 10, ?), ('global', 'fixed', 80, ?), ('vouchers', 'percent', 50, ?)", [$future, $future, $past]);
// Subscriptions: flash sales paid up for a month; vouchers lifetime.
DB::query("INSERT INTO module_subscriptions (module_key, period, paid_until) VALUES ('flash_sales', 'monthly', ?), ('vouchers', 'one_time', '9999-12-31')", [date('Y-m-d', time() + 20 * 86400)]);
// Invoices: flash sales has an older paid-up-review invoice and a newer unpaid one (the newer one is open).
DB::query("INSERT INTO module_invoices (module_key, period, amount, period_start, period_end, status) VALUES ('flash_sales', 'monthly', 50, ?, ?, 'pending_review')", [date('Y-m-d'), date('Y-m-d', time() + 30 * 86400)]);
DB::query("INSERT INTO module_invoices (module_key, period, amount, period_start, period_end, status) VALUES ('flash_sales', 'yearly', 500, ?, ?, 'unpaid')", [date('Y-m-d'), date('Y-m-d', time() + 365 * 86400)]);
// Switches: newsletter is off by default but switched on; flash sales switched off.
DB::query("INSERT INTO settings (`key`, `value`) VALUES ('module.newsletter', '1'), ('module.flash_sales', '0')");

// Reference: the page's previous per-module loop, unchanged.
$reference = function (): array {
    $rows = [];
    foreach (Modules::LIST as $key => [$label]) {
        $plans = P::plans($key);
        if ($plans === []) {
            $rows[] = ['key' => $key, 'label' => $label, 'help' => Modules::LIST[$key][1], 'coming_soon' => true, 'quotes' => [], 'until' => null, 'subscribed' => null, 'covered' => false, 'open' => null, 'switched_on' => Modules::switchedOn($key)];
            continue;
        }
        $quotes = [];
        foreach (array_keys($plans) as $period) {
            $quotes[$period] = P::quote($key, $period);
        }
        $open = DB::query("SELECT * FROM module_invoices WHERE module_key = ? AND status IN ('unpaid','pending_review') ORDER BY id DESC LIMIT 1", [$key])->fetch() ?: null;
        $rows[] = [
            'key' => $key, 'label' => $label, 'quotes' => $quotes,
            'until' => B::paidUntil($key), 'subscribed' => B::subscribedPeriod($key),
            'covered' => B::covered($key), 'open' => $open,
            'switched_on' => Modules::switchedOn($key),
            'coming_soon' => false,
        ];
    }
    return $rows;
};

$batch = B::billingRows();
$old = $reference();
check('same number of module rows', count($batch) === count($old) && count($batch) === count(Modules::LIST));
check('batch rows equal the per-module rows (prices, discounts, subscriptions, invoices, switches)', $batch == $old);

$byKey = array_column($batch, null, 'key');
check('a module with its own discount uses it', ($byKey['flash_sales']['quotes']['monthly']['scope'] ?? null) === 'flash_sales');
check('an expired own discount falls back to the global one', ($byKey['vouchers']['quotes']['one_time']['scope'] ?? null) === 'global');
check('the open invoice is the newest unfinished one', ($byKey['flash_sales']['open']['period'] ?? null) === 'yearly');
check('no price means coming soon', ($byKey['newsletter']['coming_soon'] ?? false) === true);
check('a lifetime subscription is covered', ($byKey['vouchers']['covered'] ?? false) === true);
check('the switch state is read per module', ($byKey['newsletter']['switched_on'] ?? false) === true && ($byKey['flash_sales']['switched_on'] ?? true) === false);

// Leave the module tables as a fresh database has them, so later tests see the default switches.
DB::query('DELETE FROM module_invoices');
DB::query('DELETE FROM module_subscriptions');
DB::query('DELETE FROM module_discounts');
DB::query('DELETE FROM module_prices');
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
