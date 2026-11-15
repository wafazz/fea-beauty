<?php
/** Expects $categories and $urgencies. */
$selCat = (int) old('category_id', '0');
$selUrg = (string) old('urgency', 'normal');
?>
<div class="narrow-page">
    <div class="page-head center">
        <h1>Contact support</h1>
        <p class="muted">Tell us what is wrong. We will reply to your email, and you can follow the ticket through the link we send you.</p>
    </div>

    <form method="post" action="<?= e(url('/support')) ?>" enctype="multipart/form-data" class="co-card track-form" novalidate>
        <?= csrf_field() ?>
        <?= field_error('form') ?>

        <label>Your email
            <input type="email" name="email" value="<?= old('email') ?>" autocomplete="email" maxlength="190" required>
            <?= field_error('email') ?>
        </label>

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
            <input name="subject" maxlength="160" value="<?= old('subject') ?>" required>
            <?= field_error('subject') ?>
        </label>

        <label>Message
            <textarea name="body" rows="5" maxlength="2000" required><?= old('body') ?></textarea>
            <?= field_error('body') ?>
        </label>

        <p class="muted">About an order? Add its number and tracking code from your order email (both, or leave both blank).</p>
        <label>Order number (optional)
            <input name="order_no" value="<?= old('order_no') ?>" placeholder="ORD-XXXXXXXXXX" autocomplete="off" maxlength="40">
        </label>
        <label>Tracking code (optional)
            <input name="track_token" value="" autocomplete="off" maxlength="200">
            <?= field_error('order_no') ?>
        </label>

        <label>Attachments (optional)
            <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
            <small class="muted">JPG, PNG, WebP or PDF. Up to 3 files, 5 MB each.</small>
            <?= field_error('attachments') ?>
        </label>

        <button class="s-btn lg block" type="submit">Send</button>
    </form>
</div>
