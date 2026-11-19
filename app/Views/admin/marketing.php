<?php
/** Expects $flows, $stats, $segments. */
$placeholders = ['name' => 'Aisyah', 'shop' => config('app.name'), 'shop_url' => url('/'), 'order_no' => 'ORD-SAMPLE01', 'review_url' => url('/member/orders')];
$chips = ['{name}', '{shop}', '{shop_url}', '{order_no}', '{review_url}'];
$stat = [['sent', 'Sent', 'ok'], ['pending', 'Waiting', ''], ['skipped', 'Skipped', ''], ['failed', 'Failed', 'bad']];
?>
<div class="msg-page cc-page mk-page">
    <header class="msg-head">
        <div>
            <h1>Marketing emails</h1>
            <p class="muted">Each email is sent once per customer. A flow does nothing until you switch it on.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Sending so far</h2>
        <div class="mk-stats">
            <?php foreach ($stat as [$key, $label, $tone]): ?>
                <div class="mk-stat <?= $tone !== '' ? 'is-' . e($tone) : '' ?>">
                    <span class="mk-stat-num"><?= (int) $stats[$key] ?></span>
                    <span class="mk-stat-label"><?= e($label) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted">Waiting emails are sent by the every-10-minutes cron job (<code>bin/marketing.php</code>) once the flow is on and email is set up.</p>
    </section>

    <?php foreach ($flows as $f):
        $isWin = $f['flow_key'] === 'win_back';
        $delay = (int) $f['delay_hours'];
        $when = $delay === 0 ? 'Right away' : ($delay < 24 ? 'After ' . $delay . ' hour' . ($delay === 1 ? '' : 's') : 'After ' . round($delay / 24, 1) . ' days');
    ?>
    <section class="msg-card mk-flow" data-mk-flow data-mk-key="<?= e($f['flow_key']) ?>">
        <header class="mk-flow-head">
            <div>
                <h2><?= e($f['name']) ?></h2>
                <p class="muted mk-when"><?= e($when) ?><?= $isWin ? ' · to a customer segment' : '' ?></p>
            </div>
            <label class="mk-switch">
                <input type="checkbox" name="enabled" value="1" form="mk-form-<?= e($f['flow_key']) ?>" <?= (int) $f['enabled'] === 1 ? 'checked' : '' ?> data-mk-enabled>
                <span class="mk-switch-track" aria-hidden="true"></span>
                <span class="mk-switch-text"><?= (int) $f['enabled'] === 1 ? 'On' : 'Off' ?></span>
            </label>
        </header>

        <form id="mk-form-<?= e($f['flow_key']) ?>" method="post" action="<?= e(url('/admin/marketing/' . $f['flow_key'])) ?>" class="mk-form" novalidate data-busy-form>
            <?= csrf_field() ?>
            <div class="mk-grid">
                <label>Send after (hours)
                    <input name="delay_hours" value="<?= e(old('delay_hours', (string) $f['delay_hours'])) ?>" inputmode="numeric" maxlength="3" data-mk-delay>
                    <?= field_error('delay_hours') ?>
                </label>
                <?php if ($isWin): ?>
                <label>Customer segment
                    <select name="segment_id" data-mk-segment>
                        <option value="">Choose a segment…</option>
                        <?php foreach ($segments as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" data-count="<?= (int) $s['members'] ?>" <?= (string) $f['segment_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= (int) $s['members'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted" data-mk-audience aria-live="polite"></small>
                    <?= field_error('segment_id') ?>
                </label>
                <label>Send the same customer this again after (days)
                    <input name="repeat_days" value="<?= e(old('repeat_days', (string) ($f['repeat_days'] ?? ''))) ?>" inputmode="numeric" maxlength="3">
                    <?= field_error('repeat_days') ?>
                </label>
                <?php endif; ?>
            </div>

            <label class="cc-wide">Subject
                <input name="subject" value="<?= e(old('subject', $f['subject'])) ?>" maxlength="190" required data-mk-subject>
                <small class="muted mk-count" data-mk-count-for="subject"></small>
                <?= field_error('subject') ?>
            </label>

            <div class="mk-chips" role="group" aria-label="Insert a placeholder">
                <span class="muted">Insert:</span>
                <?php foreach ($chips as $c): ?><button type="button" class="mk-chip" data-mk-chip="<?= e($c) ?>"><?= e($c) ?></button><?php endforeach; ?>
            </div>
            <label class="cc-wide">Message
                <textarea name="body" rows="7" maxlength="4000" required data-mk-body><?= e(old('body', $f['body'])) ?></textarea>
                <small class="muted mk-count" data-mk-count-for="body"></small>
                <?= field_error('body') ?>
            </label>

            <div class="mk-preview" aria-live="polite">
                <p class="mk-preview-title muted">Preview (sample values)</p>
                <p class="mk-preview-subject"><strong data-mk-prev-subject></strong></p>
                <p class="mk-preview-body" data-mk-prev-body></p>
            </div>

            <div class="mk-actions">
                <span class="muted mk-dirty" data-mk-dirty hidden>Unsaved changes</span>
                <button class="btn" data-mk-save disabled>Save</button>
            </div>
        </form>
        <script type="application/json" data-mk-sample><?= json_encode($placeholders, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </section>
    <?php endforeach; ?>
</div>
