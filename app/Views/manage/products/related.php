<?php
/** Expects $product, $pinned, $q, $results, $max, $base. */
$pid = (int) $product['id'];
$at = fn(string $path) => e(url($base . '/products/' . $pid . '/related' . $path));
$n = count($pinned);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Related products</h1>
            <p class="muted"><?= e($product['name']) ?><?= $product['sku'] ? ' · ' . e($product['sku']) : '' ?>. Pinned products show first on this product's page and in the cart. Products bought together fill any space left.</p>
        </div>
        <div class="msg-row">
            <a class="s-btn secondary" href="<?= e(url($base . '/products/' . $pid . '/edit')) ?>">Back to product</a>
        </div>
    </header>

    <section class="msg-card">
        <h2>Pinned (<?= $n ?> of <?= (int) $max ?>)</h2>
        <?php if ($pinned === []): ?>
            <p class="muted">Nothing pinned yet. Search below to add products.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="msg-table">
                <thead><tr><th>#</th><th>Product</th><th>Status</th><th>Order</th></tr></thead>
                <tbody>
                <?php foreach ($pinned as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($r['name']) ?><?= $r['sku'] ? ' <small class="muted">· ' . e($r['sku']) . '</small>' : '' ?></td>
                        <td><?= ($r['deleted_at'] === null && $r['status'] === 'active') ? 'Active' : '<span class="msg-pill bad">Not active, hidden</span>' ?></td>
                        <td>
                            <form method="post" action="<?= $at('/move') ?>" class="msg-row" style="display:inline-flex;gap:.3rem">
                                <?= csrf_field() ?><input type="hidden" name="related_id" value="<?= (int) $r['id'] ?>">
                                <button name="dir" value="-1" class="s-btn secondary sm" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move up">▲</button>
                                <button name="dir" value="1" class="s-btn secondary sm" <?= $i === $n - 1 ? 'disabled' : '' ?> aria-label="Move down">▼</button>
                            </form>
                            <form method="post" action="<?= $at('/remove') ?>" style="display:inline">
                                <?= csrf_field() ?><input type="hidden" name="related_id" value="<?= (int) $r['id'] ?>">
                                <button class="s-btn secondary sm" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="msg-card">
        <h2>Add a product</h2>
        <?php if ($n >= (int) $max): ?>
            <p class="muted">This product already has the maximum of <?= (int) $max ?> pinned products. Remove one to add another.</p>
        <?php else: ?>
            <form method="get" action="<?= e(url($base . '/products/' . $pid . '/related')) ?>" class="msg-row">
                <input name="q" value="<?= e($q) ?>" placeholder="Name or SKU" maxlength="100" aria-label="Search products">
                <button class="s-btn secondary" type="submit">Search</button>
            </form>
            <?php if ($q !== '' && $results === []): ?>
                <p class="muted">No active product matches "<?= e($q) ?>".</p>
            <?php endif; ?>
            <?php if ($results !== []): ?>
                <div class="table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Product</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr>
                            <td><?= e($r['name']) ?><?= $r['sku'] ? ' <small class="muted">· ' . e($r['sku']) . '</small>' : '' ?></td>
                            <td>
                                <form method="post" action="<?= $at('/add') ?>">
                                    <?= csrf_field() ?><input type="hidden" name="related_id" value="<?= (int) $r['id'] ?>">
                                    <button class="s-btn sm" type="submit">Pin</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
