<?php
/** Expects $accounts, $ageing, $buckets. */
$money = fn(float $v): string => 'RM ' . number_format($v, 2);
$grand = array_sum(array_column($ageing, 'total'));
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Business accounts</h1>
            <p class="muted">Approve applications, set each account's credit limit and terms. To record a payment, open the order and mark it paid; that clears it from the balance.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Accounts</h2>
        <?php if ($accounts === []): ?><p class="muted">No business accounts yet.</p><?php endif; ?>
        <?php foreach ($accounts as $a): ?>
            <details class="msg-card" style="margin:.5rem 0" <?= $a['status'] === 'pending' ? 'open' : '' ?>>
                <summary><strong><?= e($a['company']) ?></strong> <small class="muted"><?= e($a['name']) ?> · <?= e($a['email']) ?></small>
                    <span class="msg-pill <?= $a['status'] === 'approved' ? 'ok' : ($a['status'] === 'suspended' ? 'bad' : '') ?>"><?= e(ucfirst($a['status'])) ?></span></summary>
                <p class="muted">Registration: <?= e((string) ($a['reg_no'] ?? '—')) ?></p>
                <form method="post" action="<?= e(url('/admin/wholesale/' . (int) $a['id'])) ?>" class="card-form" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <label>Status
                        <select name="status">
                            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'suspended' => 'Suspended'] as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="grid2">
                        <label>Credit limit (RM) <input name="credit_limit" value="<?= e(number_format((float) $a['credit_limit'], 2, '.', '')) ?>" inputmode="decimal"></label>
                        <label>Payment terms (days) <input name="terms_days" value="<?= (int) $a['terms_days'] ?>" inputmode="numeric"></label>
                    </div>
                    <div class="msg-row"><span></span><button class="btn">Save account</button></div>
                </form>
            </details>
        <?php endforeach; ?>
    </section>

    <section class="msg-card">
        <h2>Ageing report <small class="muted">(unpaid credit orders, by due date; total RM <?= e(number_format($grand, 2)) ?>)</small></h2>
        <?php if ($ageing === []): ?><p class="muted">Nothing is owed on credit terms.</p><?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Company</th><th>Status</th><th>Limit</th><?php foreach ($buckets as $label): ?><th><?= e($label) ?></th><?php endforeach; ?><th>Total owed</th></tr></thead>
            <tbody>
            <?php foreach ($ageing as $row): ?>
                <tr>
                    <td><?= e($row['company']) ?></td>
                    <td><?= e(ucfirst($row['status'])) ?></td>
                    <td><?= e($money($row['limit'])) ?></td>
                    <?php foreach (array_keys($buckets) as $b): ?><td><?= e($money($row['buckets'][$b])) ?></td><?php endforeach; ?>
                    <td><strong><?= e($money($row['total'])) ?></strong></td>
                </tr>
                <tr><td colspan="<?= 4 + count($buckets) ?>"><small class="muted"><?php foreach ($row['orders'] as $o): ?><?= e($o['order_no']) ?> <?= e($money($o['total'])) ?> due <?= e($o['due_date'] ?? '—') ?> · <?php endforeach; ?></small></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
</div>
