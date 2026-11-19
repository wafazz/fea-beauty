<?php
/** Expects $warehouses, $rows (product_id, variant_id, name, sku, label, total, counts by warehouse id), $states. */
$default = null;
foreach ($warehouses as $w) {
    if ((int) $w['is_default'] === 1) { $default = $w; }
}
$wForm = function (?array $w) use ($states): string {
    $state = (string) old('state', $w['state'] ?? '');
    $html = '<div class="grid3">'
        . '<label>Name <input name="name" value="' . e((string) old('name', $w['name'] ?? '')) . '" maxlength="120" required></label>'
        . '<label>State <select name="state"><option value="">—</option>';
    foreach ($states as $s) {
        $html .= '<option' . ($state === $s ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    $html .= '</select></label>'
        . '<label>Postcode <input name="postcode" value="' . e((string) old('postcode', $w['postcode'] ?? '')) . '" inputmode="numeric" maxlength="5"></label>'
        . '</div>'
        . '<label class="check"><input type="checkbox" name="active" value="1"' . ((string) ($w['active'] ?? '1') === '1' ? ' checked' : '') . '> Active (can give out stock)</label>';
    return $html;
};
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Warehouses</h1>
            <p class="muted">The <strong><?= e($default['name'] ?? 'default warehouse') ?></strong> holds every item not kept in another warehouse. An order ships from one warehouse that holds all of it, and the warehouse is recorded on the order.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Warehouses</h2>
        <?php foreach ($warehouses as $w): ?>
            <details class="msg-card" style="margin:.5rem 0">
                <summary><strong><?= e($w['name']) ?></strong> <?= (int) $w['is_default'] === 1 ? '<span class="msg-pill ok">Default</span>' : '' ?> <?= (int) $w['active'] === 1 ? '' : '<small class="muted">(inactive)</small>' ?></summary>
                <form method="post" action="<?= e(url('/admin/warehouses/' . (int) $w['id'])) ?>" class="card-form wide" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <?= $wForm($w) ?>
                    <div class="msg-row"><span></span><button class="btn">Save warehouse</button></div>
                </form>
            </details>
        <?php endforeach; ?>
        <details style="margin-top:1rem">
            <summary><strong>Add a warehouse</strong></summary>
            <form method="post" action="<?= e(url('/admin/warehouses')) ?>" class="card-form wide" novalidate data-busy-form>
                <?= csrf_field() ?>
                <?= $wForm(null) ?>
                <div class="msg-row"><span></span><button class="btn">Add warehouse</button></div>
            </form>
        </details>
    </section>

    <section class="msg-card">
        <h2>Move stock</h2>
        <form method="post" action="<?= e(url('/admin/warehouses/transfer')) ?>" class="card-form wide" novalidate data-busy-form>
            <?= csrf_field() ?>
            <label>Item
                <select name="item">
                    <?php foreach ($rows as $r): ?>
                        <option value="<?= (int) $r['product_id'] ?>:<?= (int) $r['variant_id'] ?>"><?= e($r['name'] . ($r['label'] !== '' ? ' · ' . $r['label'] : '')) ?> (total <?= (int) $r['total'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="grid3">
                <label>From
                    <select name="from"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select>
                </label>
                <label>To
                    <select name="to"><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select>
                </label>
                <label>Quantity <input name="qty" inputmode="numeric" maxlength="6" required></label>
            </div>
            <div class="msg-row"><span class="muted">The total stays the same; only where it is kept changes.</span><button class="btn">Move stock</button></div>
        </form>
    </section>

    <section class="msg-card">
        <h2>Stock by warehouse</h2>
        <?php if ($rows === []): ?><p class="muted">No active products yet.</p><?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Item</th><th>Total</th><?php foreach ($warehouses as $w): ?><th><?= e($w['name']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['name']) ?><?= $r['label'] !== '' ? ' <small class="muted">· ' . e($r['label']) . '</small>' : '' ?></td>
                    <td><strong><?= (int) $r['total'] ?></strong></td>
                    <?php foreach ($warehouses as $w): ?><td><?= (int) ($r['counts'][(int) $w['id']] ?? 0) ?></td><?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
</div>
