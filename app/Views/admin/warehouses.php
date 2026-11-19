<?php
/** Expects $warehouses, $rows (product_id, variant_id, name, sku, label, total, counts by warehouse id), $states. */
$default = null;
foreach ($warehouses as $w) {
    if ((int) $w['is_default'] === 1) { $default = $w; }
}
$wForm = function (?array $w) use ($states): string {
    $state = (string) old('state', $w['state'] ?? '');
    $html = '<div class="cc-form">'
        . '<label>Name <input name="name" value="' . e((string) old('name', $w['name'] ?? '')) . '" maxlength="120" required></label>'
        . '<label>State <select name="state"><option value="">—</option>';
    foreach ($states as $s) {
        $html .= '<option' . ($state === $s ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    $html .= '</select></label>'
        . '<label>Postcode <input name="postcode" value="' . e((string) old('postcode', $w['postcode'] ?? '')) . '" inputmode="numeric" maxlength="5"></label>'
        . '<label class="check cc-wide"><input type="checkbox" name="active" value="1"' . ((string) ($w['active'] ?? '1') === '1' ? ' checked' : '') . '> Active (can give out stock)</label>'
        . '</div>';
    return $html;
};
// Stock that is low anywhere (5 or fewer in any warehouse), for the highlight.
$lowAt = 5;
?>
<div class="msg-page cc-page wh-page">
    <header class="msg-head">
        <div>
            <h1>Warehouses</h1>
            <p class="muted">The <strong><?= e($default['name'] ?? 'default warehouse') ?></strong> holds every item not kept in another warehouse. An order ships from one warehouse that holds all of it, and the warehouse is recorded on the order.</p>
        </div>
    </header>

    <div class="cc-toolbar">
        <label class="cc-search">
            <span class="sr-only">Search stock</span>
            <input type="search" placeholder="Search products, SKUs or variations…" data-wh-filter autocomplete="off">
        </label>
        <div class="cc-tabs" role="group" aria-label="Show">
            <button type="button" class="s-btn secondary sm" data-wh-low>Low stock only</button>
            <button type="button" class="s-btn secondary sm" data-wh-all>Show all</button>
        </div>
    </div>

    <section class="msg-card">
        <h2>Warehouses</h2>
        <div class="cc-list">
        <?php foreach ($warehouses as $w): ?>
            <details class="cc-item" data-wh-open>
                <summary>
                    <span class="cc-title"><strong><?= e($w['name']) ?></strong>
                        <small class="muted"><?= e(trim(($w['state'] ?? '') . ' ' . ($w['postcode'] ?? ''))) ?: 'No address set' ?></small></span>
                    <?= (int) $w['is_default'] === 1 ? '<span class="msg-pill ok">Default</span>' : '' ?>
                    <?= (int) $w['active'] === 1 ? '' : '<span class="msg-pill bad">Inactive</span>' ?>
                </summary>
                <form method="post" action="<?= e(url('/admin/warehouses/' . (int) $w['id'])) ?>" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <?= $wForm($w) ?>
                    <div class="cc-actions"><button class="btn">Save warehouse</button></div>
                </form>
            </details>
        <?php endforeach; ?>
        <details class="cc-item cc-new" data-wh-open>
            <summary><strong>+ Add a warehouse</strong></summary>
            <form method="post" action="<?= e(url('/admin/warehouses')) ?>" novalidate data-busy-form>
                <?= csrf_field() ?>
                <?= $wForm(null) ?>
                <div class="cc-actions"><button class="btn">Add warehouse</button></div>
            </form>
        </details>
        </div>
    </section>

    <section class="msg-card wh-move" data-wh-move>
        <h2>Move stock</h2>
        <form method="post" action="<?= e(url('/admin/warehouses/transfer')) ?>" class="cc-form" novalidate data-busy-form data-wh-transfer>
            <?= csrf_field() ?>
            <label class="cc-wide">Item
                <select name="item" data-wh-item>
                    <?php foreach ($rows as $r): ?>
                        <option value="<?= (int) $r['product_id'] ?>:<?= (int) $r['variant_id'] ?>" data-counts="<?= e(json_encode($r['counts'], JSON_THROW_ON_ERROR)) ?>"><?= e($r['name'] . ($r['label'] !== '' ? ' · ' . $r['label'] : '')) ?> (total <?= (int) $r['total'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>From
                <select name="from" data-wh-from><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label>To
                <select name="to" data-wh-to><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>"<?= count($warehouses) > 1 && (int) $w['id'] === (int) $warehouses[1]['id'] ? ' selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="cc-wide">Quantity
                <input name="qty" inputmode="numeric" maxlength="6" required data-wh-qty>
                <small class="muted wh-hint" aria-live="polite"></small>
            </label>
            <div class="wh-preview cc-wide" aria-live="polite" data-wh-preview><span class="muted">Pick an item and a quantity to see the result.</span></div>
            <div class="cc-actions cc-wide"><span class="muted">The total stays the same; only where it is kept changes.</span><button class="btn" data-wh-submit>Move stock</button></div>
        </form>
    </section>

    <section class="msg-card">
        <h2>Stock by warehouse <small class="muted">(<?= count($rows) ?> items)</small></h2>
        <?php if ($rows === []): ?><p class="muted">No active products yet.</p><?php else: ?>
        <div class="wh-table" data-wh-table>
            <div class="wh-row wh-head" role="row">
                <span role="columnheader">Item</span>
                <span role="columnheader">Total</span>
                <?php foreach ($warehouses as $w): ?><span role="columnheader"><?= e($w['name']) ?></span><?php endforeach; ?>
            </div>
            <?php foreach ($rows as $r):
                $low = $r['total'] <= $lowAt || min(array_values($r['counts']) ?: [0]) <= $lowAt;
            ?>
                <div class="wh-row<?= $low ? ' is-low' : '' ?>" role="row" data-wh-row data-search="<?= e(strtolower($r['name'] . ' ' . $r['sku'] . ' ' . $r['label'])) ?>">
                    <span role="cell" data-label="Item"><strong><?= e($r['name']) ?></strong><?= $r['label'] !== '' ? ' <small class="muted">· ' . e($r['label']) . '</small>' : '' ?><?= $low ? ' <span class="msg-pill bad">Low</span>' : '' ?></span>
                    <span role="cell" data-label="Total"><strong><?= (int) $r['total'] ?></strong></span>
                    <?php foreach ($warehouses as $w): ?>
                        <span role="cell" data-label="<?= e($w['name']) ?>"><?= (int) ($r['counts'][(int) $w['id']] ?? 0) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted cc-nomatch" data-wh-empty hidden>Nothing matches that search.</p>
        <?php endif; ?>
    </section>
</div>
