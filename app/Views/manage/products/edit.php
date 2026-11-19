<?php
$pid = (int) $product['id'];
$formAction = $base . "/products/{$pid}";
?>
<p><a href="<?= e(url($base . '/products')) ?>">← Products</a></p>
<h1><?= e($product['name']) ?></h1>
<?php if (\App\Services\Modules::enabled('cross_sell')): ?>
    <p><a href="<?= e(url($base . '/products/' . (int) $product['id'] . '/related')) ?>">Related products (pin)</a></p>
<?php endif; ?>
<?php require __DIR__ . '/_form.php'; ?>
