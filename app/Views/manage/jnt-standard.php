<?php
$chip = $cfg['complete'] ? ['ok', 'Saved'] : ['', 'Not configured'];
$hintFor = function (string $s) use ($cfg): string {
    if (!$cfg[$s . '_set']) {
        return 'Not set yet.';
    }
    return $cfg[$s . '_hint'] !== null ? "Saved ({$cfg[$s . '_hint']}). Leave blank to keep it." : 'Saved but unreadable (APP_KEY changed). Enter it again.';
};
$v = fn(string $f) => old($f, $cfg[$f]);
$vp = fn(string $api) => old('prod_' . $api, $cfg['prod'][$api]);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>J&amp;T Standard API</h1>
            <p class="muted">Account details for the J&amp;T standard API (Order, Cancel Order, Tracking and Consignment Note). The secrets are stored encrypted and are never shown again after saving. This is separate from the J&amp;T Express (Open Platform) settings.</p>
        </div>
        <div class="msg-row">
            <span class="msg-pill <?= e($chip[0]) ?>">Credentials: <?= e($chip[1]) ?></span>
            <span class="msg-pill <?= $cfg['enabled'] ? 'ok' : '' ?>">J&amp;T Standard: <?= $cfg['enabled'] ? 'Enabled' : 'Off' ?></span>
            <span class="msg-pill"><?= $cfg['mode'] === 'sandbox' ? 'Mode: Sandbox' : 'Mode: Production' ?></span>
        </div>
    </header>

    <?php if ($cfg['unreadable']): ?><div class="alert bad">A saved secret can't be decrypted (APP_KEY was changed). Enter it again.</div><?php endif; ?>

    <section class="msg-card">
        <h2>Account</h2>
        <form method="post" action="<?= e(url($base . '/shipping/jnt-standard')) ?>" class="card-form wide" autocomplete="off" novalidate data-busy-form>
            <?= csrf_field() ?>
            <fieldset>
                <legend>J&amp;T account</legend>
                <label class="check"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Enable J&amp;T Standard API</label>
                <label>Mode
                    <select name="mode">
                        <?php foreach ($modes as $k => $label): ?><option value="<?= e($k) ?>" <?= $v('mode') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select><?= field_error('mode') ?></label>
                <div class="grid2">
                    <label>Username <input name="username" value="<?= $v('username') ?>" maxlength="100" spellcheck="false"><?= field_error('username') ?></label>
                    <label>Customer code (cuscode) <input name="customer_code" value="<?= $v('customer_code') ?>" maxlength="100" spellcheck="false"><?= field_error('customer_code') ?></label>
                </div>
                <label>Tracking company ID (eccompanyid) <input name="eccompanyid" value="<?= $v('eccompanyid') ?>" maxlength="20" spellcheck="false"><?= field_error('eccompanyid') ?></label>
            </fieldset>

            <fieldset>
                <legend>Secrets</legend>
                <label><?= e($secretLabels['sign_key']) ?> <input type="password" name="sign_key" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($hintFor('sign_key')) ?>"><?= field_error('sign_key') ?></label>
                <label><?= e($secretLabels['api_key']) ?> <input type="password" name="api_key" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($hintFor('api_key')) ?>"><?= field_error('api_key') ?></label>
                <label><?= e($secretLabels['password']) ?> <input type="password" name="password" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($hintFor('password')) ?>"><?= field_error('password') ?></label>
                <label><?= e($secretLabels['track_key']) ?> <input type="password" name="track_key" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($hintFor('track_key')) ?>"><?= field_error('track_key') ?></label>
                <small class="muted">These come from the J&amp;T documents and your account manager. Use the sandbox keys while testing.</small>
            </fieldset>

            <fieldset>
                <legend>URLs</legend>
                <p class="muted">Sandbox URLs come from the J&amp;T documents. Production URLs are not in those documents; enter them when J&amp;T confirms them for your account.</p>
                <?php foreach ($urlLabels as $api => $label): ?>
                    <label><?= e($label) ?> (production)
                        <input name="prod_<?= e($api) ?>" value="<?= $vp($api) ?>" maxlength="300" spellcheck="false" placeholder="https://">
                        <?= field_error('prod_' . $api) ?>
                    </label>
                    <small class="muted">Sandbox: <?= e($sandbox[$api]) ?></small>
                <?php endforeach; ?>
            </fieldset>

            <div class="msg-row"><span></span><button class="btn">Save</button></div>
        </form>
    </section>

    <?php if ($history !== []): ?>
    <section class="msg-card">
        <h2>Recent changes</h2>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr><td><small><?= e($h['created_at']) ?></small></td><td><?= e($h['actor'] ?? 'removed user') ?></td><td><?= e($h['detail'] ?? $h['action']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>
</div>
