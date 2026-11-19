<?php
/** Expects $active, $expiresAt (null = unlimited, else "Y-m-d H:i:s"), and $custom (whether this superadmin has set their own PIN). */
$isDate = $expiresAt !== null;
$dateValue = $isDate ? date('Y-m-d\TH:i', strtotime($expiresAt)) : '';
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Store status</h1>
            <p class="muted">Switch the whole store on or off. When it is <strong>inactive</strong>, every page shows an offline notice to shoppers, members, admins and staff. Only the superadmin portal keeps working, so you can switch it back on.</p>
        </div>
    </header>

    <?php if (!$custom): ?>
        <section class="msg-card pin-nudge"><p>You are still using the default PIN, 123456. Set your own on the Security page before you rely on this switch.</p></section>
    <?php endif; ?>

    <section class="msg-card">
        <p class="mod-status <?= $active ? 'is-on' : 'is-off' ?>"><?= $active ? 'Active: the store is open' : 'Inactive: the store is offline' ?></p>
        <?php if ($active): ?>
            <p class="muted"><?= $isDate ? 'Closes automatically on ' . e(date('d M Y, H:i', strtotime($expiresAt))) . '. Then it goes offline by itself.' : 'No end date (unlimited).' ?></p>
        <?php else: ?>
            <p class="muted">Turn it on to open the store. You can choose an end date when you do; unlimited is the default.</p>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/superadmin/system')) ?>" class="card-form mod-form" data-store-form autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
            <?php if (!$active): ?>
                <fieldset class="expiry-fields">
                    <legend>End date</legend>
                    <label><input type="radio" name="expires_mode" value="unlimited" <?= $isDate ? '' : 'checked' ?>> Unlimited</label>
                    <label><input type="radio" name="expires_mode" value="date"> Until a date and time</label>
                    <label>Closes at <input type="datetime-local" name="expires_at" value="" disabled></label>
                </fieldset>
            <?php endif; ?>
            <noscript><label class="mod-pin-fallback">Your PIN <input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required></label></noscript>
            <div class="msg-row">
                <span class="muted">Tap the switch, then enter your PIN.</span>
                <button type="submit" class="mod-switch" role="switch" data-store-switch
                        aria-checked="<?= $active ? 'true' : 'false' ?>"
                        aria-label="Store status: <?= $active ? 'active, switch to inactive' : 'inactive, switch to active' ?>">
                    <span class="mod-track" aria-hidden="true"><span class="mod-knob"></span></span>
                    <span class="mod-switch-text"><?= $active ? 'Set inactive (offline)' : 'Set active (open)' ?></span>
                </button>
            </div>
        </form>
    </section>

    <?php if ($active): ?>
        <section class="msg-card">
            <h2>End date</h2>
            <p class="expiry-now">Current: <strong><?= $isDate ? 'until ' . e(date('d M Y, H:i', strtotime($expiresAt))) : 'Unlimited' ?></strong></p>
            <form method="post" action="<?= e(url('/superadmin/system/expiry')) ?>" class="card-form mod-form" data-store-form autocomplete="off">
                <?= csrf_field() ?>
                <fieldset class="expiry-fields">
                    <legend>When the store closes</legend>
                    <label><input type="radio" name="expires_mode" value="unlimited" <?= $isDate ? '' : 'checked' ?>> Unlimited<?= $isDate ? '' : ' <span class="expiry-current">Current</span>' ?></label>
                    <label><input type="radio" name="expires_mode" value="date" <?= $isDate ? 'checked' : '' ?>> Until a date and time<?= $isDate ? ' <span class="expiry-current">Current</span>' : '' ?></label>
                    <label>Closes at <input type="datetime-local" name="expires_at" value="<?= e($dateValue) ?>"<?= $isDate ? '' : ' disabled' ?>></label>
                </fieldset>
                <noscript><label class="mod-pin-fallback">Your PIN <input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required></label></noscript>
                <div class="msg-row">
                    <span class="muted">The store goes offline by itself at that time.</span>
                    <button type="submit" class="btn" data-expiry-save>Save end date</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>
