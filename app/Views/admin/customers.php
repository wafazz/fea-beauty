<?php
/** Expects $segments, $selected (or null), $members, $tags (closure: id => tags). */
$summary = function (array $r): string {
    $parts = [];
    if (isset($r['min_orders'])) $parts[] = 'at least ' . (int) $r['min_orders'] . ' order(s)';
    if (isset($r['min_spent'])) $parts[] = 'spent at least RM' . number_format($r['min_spent'] / 100, 2);
    if (isset($r['within_days'])) $parts[] = 'in the last ' . (int) $r['within_days'] . ' days';
    if (isset($r['inactive_days'])) $parts[] = 'no order for ' . (int) $r['inactive_days'] . ' days (or never ordered)';
    if (isset($r['tag'])) $parts[] = 'tagged "' . $r['tag'] . '"';
    return implode(', ', $parts);
};
$segForm = function (?array $s): string {
    $r = $s['rules'] ?? [];
    $v = fn(string $k, string $d = '') => e((string) old($k, $d));
    $spent = isset($r['min_spent']) ? number_format($r['min_spent'] / 100, 2, '.', '') : '';
    return '<div class="grid2">'
        . '<label>Name <input name="name" value="' . e((string) old('name', $s['name'] ?? '')) . '" maxlength="120" required></label>'
        . '<label>Tag (optional) <input name="tag" value="' . $v('tag', (string) ($r['tag'] ?? '')) . '" maxlength="30" placeholder="vip"></label>'
        . '</div>'
        . '<div class="grid2">'
        . '<label>Minimum orders <input name="min_orders" value="' . $v('min_orders', (string) ($r['min_orders'] ?? '')) . '" inputmode="numeric"></label>'
        . '<label>Minimum spent (RM) <input name="min_spent" value="' . $v('min_spent', $spent) . '" inputmode="decimal" placeholder="300.00"></label>'
        . '</div>'
        . '<div class="grid2">'
        . '<label>Time window (days, for orders and spend) <input name="within_days" value="' . $v('within_days', (string) ($r['within_days'] ?? '')) . '" inputmode="numeric" placeholder="90"></label>'
        . '<label>No order for (days) <input name="inactive_days" value="' . $v('inactive_days', (string) ($r['inactive_days'] ?? '')) . '" inputmode="numeric" placeholder="60"></label>'
        . '</div>';
};
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Customers and segments</h1>
            <p class="muted">Segments are worked out from orders and tags each time. Cancelled orders are not counted. The CSV export contains customer contact details: keep it private.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Segments</h2>
        <?php if ($segments === []): ?><p class="muted">No segments yet.</p><?php endif; ?>
        <?php foreach ($segments as $s): ?>
            <details class="msg-card" style="margin:.5rem 0">
                <summary><strong><?= e($s['name']) ?></strong> <small class="muted"><?= (int) $s['members'] ?> customer(s) · <?= e($summary($s['rules'])) ?></small></summary>
                <p>
                    <a href="<?= e(url('/admin/customers?segment=' . (int) $s['id'])) ?>">Show these customers</a> ·
                    <a href="<?= e(url('/admin/customers/segments/' . (int) $s['id'] . '/export.csv')) ?>">Download CSV</a>
                </p>
                <form method="post" action="<?= e(url('/admin/customers/segments/' . (int) $s['id'])) ?>" class="card-form wide" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <?= $segForm($s) ?>
                    <div class="msg-row"><span></span><button class="btn">Save segment</button></div>
                </form>
                <form method="post" action="<?= e(url('/admin/customers/segments/' . (int) $s['id'] . '/delete')) ?>" data-confirm="Delete this segment? Customers are not affected.">
                    <?= csrf_field() ?><button class="s-btn secondary sm" type="submit">Delete segment</button>
                </form>
            </details>
        <?php endforeach; ?>
        <details style="margin-top:1rem">
            <summary><strong>Add a segment</strong></summary>
            <form method="post" action="<?= e(url('/admin/customers/segments')) ?>" class="card-form wide" novalidate data-busy-form>
                <?= csrf_field() ?>
                <?= $segForm(null) ?>
                <div class="msg-row"><span></span><button class="btn">Add segment</button></div>
            </form>
        </details>
    </section>

    <section class="msg-card">
        <h2><?= $selected !== null ? 'Customers in "' . e($selected['name']) . '"' : 'All customers' ?>
            <small class="muted">(up to 200 shown)</small></h2>
        <?php if ($selected !== null): ?><p><a href="<?= e(url('/admin/customers')) ?>">Show all customers</a></p><?php endif; ?>
        <?php if ($members === []): ?><p class="muted">No customers match.</p><?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Orders</th><th>Spent</th><th>Last order</th><th>Tags</th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['name']) ?></td>
                    <td><?= e($m['email']) ?></td>
                    <td><?= e((string) $m['phone']) ?></td>
                    <td><?= (int) $m['orders'] ?></td>
                    <td><?= e(money((float) $m['spent'])) ?></td>
                    <td><small><?= e((string) ($m['last_order'] ?? '—')) ?></small></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/customers/' . (int) $m['id'] . '/tags')) ?>" class="msg-row">
                            <?= csrf_field() ?>
                            <input type="hidden" name="segment" value="<?= $selected !== null ? (int) $selected['id'] : '' ?>">
                            <input name="tags" value="<?= e(implode(', ', $tags((int) $m['id']))) ?>" maxlength="300" placeholder="vip, wholesale" aria-label="Tags for <?= e($m['name']) ?>">
                            <button class="s-btn secondary sm" type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
</div>
