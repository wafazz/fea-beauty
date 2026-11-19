<?php
/** Expects $flows, $stats, $segments. */
$placeholders = '{name}, {shop}, {shop_url}, {order_no}, {review_url}';
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Marketing emails</h1>
            <p class="muted">Each email is sent once per customer. A flow does nothing until you switch it on. Placeholders you can use: <?= e($placeholders) ?>.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Sending so far</h2>
        <p>
            <span class="msg-pill ok">Sent: <?= (int) $stats['sent'] ?></span>
            <span class="msg-pill">Waiting: <?= (int) $stats['pending'] ?></span>
            <span class="msg-pill">Skipped: <?= (int) $stats['skipped'] ?></span>
            <span class="msg-pill <?= $stats['failed'] > 0 ? 'bad' : '' ?>">Failed: <?= (int) $stats['failed'] ?></span>
        </p>
        <p class="muted">Waiting emails are sent by the every-10-minutes cron job (<code>bin/marketing.php</code>) once the flow is on and email is set up.</p>
    </section>

    <?php foreach ($flows as $f): ?>
    <section class="msg-card">
        <h2><?= e($f['name']) ?> <?= (int) $f['enabled'] === 1 ? '<span class="msg-pill ok">On</span>' : '<span class="msg-pill">Off</span>' ?></h2>
        <form method="post" action="<?= e(url('/admin/marketing/' . $f['flow_key'])) ?>" class="card-form wide" novalidate data-busy-form>
            <?= csrf_field() ?>
            <label class="check"><input type="checkbox" name="enabled" value="1" <?= (int) $f['enabled'] === 1 ? 'checked' : '' ?>> Switch this email on</label>
            <div class="grid2">
                <label>Send after (hours)
                    <input name="delay_hours" value="<?= e(old('delay_hours', (string) $f['delay_hours'])) ?>" inputmode="numeric" maxlength="3">
                    <?= field_error('delay_hours') ?>
                </label>
                <?php if ($f['flow_key'] === 'win_back'): ?>
                <label>Customer segment
                    <select name="segment_id">
                        <option value="">Choose a segment…</option>
                        <?php foreach ($segments as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= (string) $f['segment_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= (int) $s['members'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('segment_id') ?>
                </label>
                <?php endif; ?>
            </div>
            <?php if ($f['flow_key'] === 'win_back'): ?>
                <label>Send the same customer this again after (days)
                    <input name="repeat_days" value="<?= e(old('repeat_days', (string) ($f['repeat_days'] ?? ''))) ?>" inputmode="numeric" maxlength="3">
                    <?= field_error('repeat_days') ?>
                </label>
            <?php endif; ?>
            <label>Subject <input name="subject" value="<?= e(old('subject', $f['subject'])) ?>" maxlength="190" required><?= field_error('subject') ?></label>
            <label>Message <textarea name="body" rows="6" maxlength="4000" required><?= e(old('body', $f['body'])) ?></textarea><?= field_error('body') ?></label>
            <div class="msg-row"><span></span><button class="btn">Save</button></div>
        </form>
    </section>
    <?php endforeach; ?>
</div>
