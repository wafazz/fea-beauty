<?php
/** Expects $account (or null). */
$status = $account['status'] ?? null;
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Business account</h1>
            <p class="muted">For businesses that buy regularly. Once approved you can pay by credit terms: pay by the due date, up to your credit limit.</p>
        </div>
    </header>

    <?php if ($account === null): ?>
    <section class="msg-card">
        <h2>Apply</h2>
        <form method="post" action="<?= e(url('/member/wholesale')) ?>" class="card-form" novalidate data-busy-form>
            <?= csrf_field() ?>
            <label>Company name <input name="company" value="<?= e(old('company', '')) ?>" maxlength="160" required><?= field_error('company') ?></label>
            <label>Registration number (optional) <input name="reg_no" value="<?= e(old('reg_no', '')) ?>" maxlength="60"><?= field_error('reg_no') ?></label>
            <div class="msg-row"><span></span><button class="btn">Send application</button></div>
        </form>
    </section>
    <?php else: ?>
    <section class="msg-card">
        <h2><?= e($account['company']) ?></h2>
        <p>
            Status:
            <?php if ($status === 'approved'): ?>
                <span class="msg-pill ok">Approved</span> Credit limit RM<?= e(number_format((float) $account['credit_limit'], 2)) ?>, payment within <?= (int) $account['terms_days'] ?> days.
            <?php elseif ($status === 'suspended'): ?>
                <span class="msg-pill bad">Suspended</span> You cannot place new credit orders. Please contact the shop.
            <?php else: ?>
                <span class="msg-pill">Waiting for review</span> The shop will tell you the credit limit and terms.
            <?php endif; ?>
        </p>
    </section>
    <?php endif; ?>
</div>
