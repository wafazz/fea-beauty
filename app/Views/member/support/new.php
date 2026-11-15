<?php
/** Expects $categories, $orders, $subscriptions (active only), $urgencies. */
$selCat = (int) old('category_id', '0');
$selUrg = (string) old('urgency', 'normal');
$selOrder = (int) old('order_id', '0');
$selSub = (int) old('subscription_id', '0');
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>New support ticket</h1>
            <p class="muted">Tell us what is wrong and how urgent it is. Link an order or subscription if it is about one. You can attach up to 3 photos or PDFs (5 MB each).</p>
        </div>
        <a class="s-btn secondary" href="<?= e(url('/member/support')) ?>">Back to tickets</a>
    </header>

    <form method="post" action="<?= e(url('/member/support')) ?>" enctype="multipart/form-data" class="msg-card card-form" novalidate>
        <?= csrf_field() ?>
        <?= field_error('form') ?>

        <label>Topic
            <select name="category_id" required>
                <option value="">Choose a topic…</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $selCat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error('category_id') ?>
        </label>

        <fieldset>
            <legend>How urgent is it?</legend>
            <?php foreach ($urgencies as $key => $label): ?>
                <label class="radio"><input type="radio" name="urgency" value="<?= e($key) ?>" <?= $selUrg === $key ? 'checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
            <?= field_error('urgency') ?>
        </fieldset>

        <label>Subject
            <input name="subject" maxlength="160" value="<?= e(old('subject')) ?>" required>
            <?= field_error('subject') ?>
        </label>

        <label>Message
            <textarea name="body" rows="5" maxlength="2000" required><?= e(old('body')) ?></textarea>
            <?= field_error('body') ?>
        </label>

        <label>Order (optional)
            <select name="order_id">
                <option value="0">No order</option>
                <?php foreach ($orders as $o): ?>
                    <option value="<?= (int) $o['id'] ?>" <?= $selOrder === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['order_no']) ?> · <?= e(date('j M Y', strtotime((string) $o['created_at']))) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error('order_id') ?>
        </label>

        <?php if ($subscriptions !== []): ?>
        <label>Subscription (optional, active only)
            <select name="subscription_id">
                <option value="0">No subscription</option>
                <?php foreach ($subscriptions as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $selSub === (int) $s['id'] ? 'selected' : '' ?>>#<?= (int) $s['id'] ?> · <?= e($s['product_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error('subscription_id') ?>
        </label>
        <?php endif; ?>

        <label>Attachments (optional)
            <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
            <small class="muted">JPG, PNG, WebP or PDF. Up to 3 files, 5 MB each.</small>
            <?= field_error('attachments') ?>
        </label>

        <div class="msg-row"><span></span><button class="btn" type="submit">Open ticket</button></div>
    </form>
</div>
