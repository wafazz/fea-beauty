<?php
/** Expects $o from OverviewService::build(). */
$delta = $o['sales'] - $o['yesterday_sales'];
$deltaText = $o['yesterday_sales'] > 0
    ? ($delta >= 0 ? '+' : '') . number_format($delta / $o['yesterday_sales'] * 100, 0) . '% vs yesterday'
    : ($o['sales'] > 0 ? 'no sales yesterday' : 'no sales yet yesterday');
$low = $o['low_stock'];
$workerText = $o['worker_last'] === null
    ? 'The cron worker has never run. Check the every-minute cron job on the server.'
    : ($o['worker_healthy'] ? 'Last run ' . $o['worker_last'] . '.' : 'Last run ' . $o['worker_last'] . ' is stale. The every-minute cron job may have stopped.');
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Overview</h1>
            <p class="muted">Today at a glance. <a href="<?= e(url('/admin/analytics')) ?>">Open the full dashboard with charts</a>.</p>
        </div>
    </header>

    <?php if (!$o['worker_healthy']): ?>
        <div class="alert bad"><?= e($workerText) ?></div>
    <?php endif; ?>

    <section class="msg-card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem">
        <div>
            <h2>Today's sales</h2>
            <p style="font-size:1.6rem;font-weight:700;margin:.2rem 0"><?= e(money($o['sales'])) ?></p>
            <p class="muted"><?= (int) $o['orders'] ?> order<?= $o['orders'] === 1 ? '' : 's' ?> · <?= e($deltaText) ?></p>
        </div>
        <div>
            <h2>Orders waiting</h2>
            <p><a href="<?= e(url('/admin/orders?status=pending')) ?>"><?= (int) $o['new_orders'] ?> new to process</a></p>
            <p><a href="<?= e(url('/admin/orders?status=processing')) ?>"><?= (int) $o['ready_to_ship'] ?> ready to ship</a></p>
            <p><a href="<?= e(url('/admin/orders?status=pending')) ?>"><?= (int) $o['awaiting_payment'] ?> waiting for payment</a></p>
        </div>
        <div>
            <h2>Low stock</h2>
            <p style="font-size:1.6rem;font-weight:700;margin:.2rem 0"><?= (int) $low['count'] ?></p>
            <p class="muted">items at 5 or fewer</p>
            <?php if ($low['rows'] !== []): ?>
                <ul style="padding-left:1.1rem;margin:.4rem 0">
                    <?php foreach ($low['rows'] as $row): ?>
                        <li><a href="<?= e(url($row['href'])) ?>"><?= e($row['label']) ?></a> <small class="muted">(<?= (int) $row['stock'] ?> left)</small></li>
                    <?php endforeach; ?>
                </ul>
                <p><a href="<?= e(url('/admin/stock?level=low')) ?>">See all low stock</a></p>
            <?php endif; ?>
        </div>
    </section>

    <section class="msg-card">
        <h2>Cron jobs</h2>
        <p class="muted"><?= e($workerText) ?></p>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Job</th><th>Runs</th><th>Next run</th><th>What it does</th></tr></thead>
            <tbody>
            <?php foreach ($o['jobs'] as $j): ?>
                <tr>
                    <td><code><?= e($j['script']) ?></code></td>
                    <td><?= e($j['label']) ?></td>
                    <td><?= $j['next'] !== null ? e($j['next']) : '<span class="muted">daily (time set on the server)</span>' ?></td>
                    <td><?= e($j['what']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="muted"><small>Interval jobs are shown at their next clock time (for example, the 10-minute job at :00, :10, :20).</small></p>
    </section>
</div>
