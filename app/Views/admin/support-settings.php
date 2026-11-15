<?php
/** Expects $categories (id, name, slug, position, active) and $labels (id, name, colour, active). */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Support categories and labels</h1>
            <p class="muted">Categories are the topic a customer picks when opening a ticket (one per ticket). Labels are for your team's own tracking (many per ticket). Inactive items stay on existing tickets but cannot be picked for new ones.</p>
        </div>
        <a class="s-btn secondary" href="<?= e(url('/admin/tickets')) ?>">Back to support inbox</a>
    </header>

    <section class="msg-card">
        <h2>Categories</h2>
        <?= field_error('category') ?>
        <?php foreach ($categories as $c): ?>
        <form method="post" action="<?= e(url('/admin/support-settings/categories/' . (int) $c['id'])) ?>" class="card-form inline-row">
            <?= csrf_field() ?>
            <label>Name <input name="name" value="<?= e($c['name']) ?>" maxlength="80" required></label>
            <label>Position <input name="position" inputmode="numeric" value="<?= (int) $c['position'] ?>" style="width:5rem"></label>
            <label class="check"><input type="checkbox" name="active" value="1" <?= (int) $c['active'] === 1 ? 'checked' : '' ?>> Active</label>
            <button class="btn secondary" type="submit">Save</button>
        </form>
        <?php endforeach; ?>
        <form method="post" action="<?= e(url('/admin/support-settings/categories')) ?>" class="card-form inline-row">
            <?= csrf_field() ?>
            <label>New category <input name="name" maxlength="80" placeholder="e.g. Pre-order" required></label>
            <label>Position <input name="position" inputmode="numeric" value="0" style="width:5rem"></label>
            <label class="check"><input type="checkbox" name="active" value="1" checked> Active</label>
            <button class="btn" type="submit">Add category</button>
        </form>
    </section>

    <section class="msg-card">
        <h2>Labels</h2>
        <?= field_error('label') ?>
        <?php foreach ($labels as $l): ?>
        <form method="post" action="<?= e(url('/admin/support-settings/labels/' . (int) $l['id'])) ?>" class="card-form inline-row">
            <?= csrf_field() ?>
            <label>Name <input name="name" value="<?= e($l['name']) ?>" maxlength="40" required></label>
            <label>Colour <input type="color" name="colour" value="<?= e($l['colour']) ?>"></label>
            <label class="check"><input type="checkbox" name="active" value="1" <?= (int) $l['active'] === 1 ? 'checked' : '' ?>> Active</label>
            <button class="btn secondary" type="submit">Save</button>
        </form>
        <?php endforeach; ?>
        <form method="post" action="<?= e(url('/admin/support-settings/labels')) ?>" class="card-form inline-row">
            <?= csrf_field() ?>
            <label>New label <input name="name" maxlength="40" placeholder="e.g. VIP" required></label>
            <label>Colour <input type="color" name="colour" value="#64748b"></label>
            <label class="check"><input type="checkbox" name="active" value="1" checked> Active</label>
            <button class="btn" type="submit">Add label</button>
        </form>
    </section>
</div>
