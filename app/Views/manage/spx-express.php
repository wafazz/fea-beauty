<?php
$haveCreds = $cfg['complete'];
$chip = $haveCreds ? ['ok', 'Saved'] : ['', 'Not configured'];
$hint = fn(string $f, string $label) => $cfg[$f . '_set']
    ? ($cfg[$f . '_hint'] !== null ? "Saved ({$cfg[$f . '_hint']}). Leave blank to keep it." : 'Saved but unreadable (APP_KEY changed). Enter it again.')
    : 'Not set yet.';
$v = fn(string $f) => old($f, $cfg[$f]);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>SPX Express</h1>
            <p class="muted">Courier account details for SPX Express Malaysia. Both secrets are stored encrypted and are never shown again after saving.</p>
        </div>
        <div class="msg-row">
            <span class="msg-pill <?= e($chip[0]) ?>">Credentials: <?= e($chip[1]) ?></span>
            <span class="msg-pill <?= $cfg['enabled'] ? 'ok' : '' ?>">SPX: <?= $cfg['enabled'] ? 'Enabled' : 'Off' ?></span>
        </div>
    </header>

    <?php if ($cfg['unreadable']): ?><div class="alert bad">A saved secret can't be decrypted (APP_KEY was changed). Enter it again.</div><?php endif; ?>

    <section class="msg-card">
        <h2>API credentials</h2>
        <form method="post" action="<?= e(url($base . '/shipping/spx-express')) ?>" class="card-form wide" autocomplete="off" novalidate data-busy-form>
            <?= csrf_field() ?>
            <fieldset>
                <legend>SPX account</legend>
                <label class="check"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Enable SPX Express (shows "Push to SPX Express" on Processing orders)</label>
                <label>Mode
                    <select name="mode">
                        <?php foreach ($modes as $k => $label): ?><option value="<?= e($k) ?>" <?= $v('mode') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select><?= field_error('mode') ?></label>
                <label>Parcel collection
                    <select name="collect">
                        <?php foreach ($collect as $k => $label): ?><option value="<?= e($k) ?>" <?= $v('collect') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select><?= field_error('collect') ?></label>
                <div class="grid2">
                    <label>App ID <input name="app_id" value="<?= $v('app_id') ?>" maxlength="100" spellcheck="false"><?= field_error('app_id') ?></label>
                    <label>User ID <input name="user_id" value="<?= $v('user_id') ?>" maxlength="20" inputmode="numeric" spellcheck="false"><?= field_error('user_id') ?></label>
                </div>
                <label>App Secret <input type="password" name="app_secret" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($hint('app_secret', 'App Secret')) ?>"><?= field_error('app_secret') ?></label>
                <label>User Secret <input type="password" name="user_secret" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($hint('user_secret', 'User Secret')) ?>"><?= field_error('user_secret') ?></label>
                <small class="muted">SPX issues these on request (there is no self-serve signup). Use sandbox while testing and switch to production only after SPX activates your live account.</small>
            </fieldset>
            <div class="msg-row"><span></span><button class="btn">Save</button></div>
        </form>
    </section>

    <section class="msg-card">
        <h2>Pickup address</h2>
        <p class="muted">SPX uses the sender address saved in <a href="<?= e(url($base . '/shipping/ninjavan')) ?>">NinjaVan settings</a>. Fill it in there before pushing orders.</p>
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
