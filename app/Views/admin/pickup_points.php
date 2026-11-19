<?php
/** Expects $points (all pickup points), $states. */
$fields = function (?array $p, array $states): string {
    $v = fn(string $k, string $d = '') => e((string) old($k, $p[$k] ?? $d));
    $state = (string) old('state', $p['state'] ?? '');
    $html = '<div class="grid2">'
        . '<label>Name <input name="name" value="' . $v('name') . '" maxlength="120" required></label>'
        . '<label>Opening hours (optional) <input name="opening_hours" value="' . $v('opening_hours') . '" maxlength="160" placeholder="Mon-Sat 10:00-18:00"></label>'
        . '</div>'
        . '<label>Address <input name="line1" value="' . $v('line1') . '" maxlength="255" required></label>'
        . '<div class="grid3">'
        . '<label>City <input name="city" value="' . $v('city') . '" maxlength="100" required></label>'
        . '<label>State <select name="state" required><option value="">Select…</option>';
    foreach ($states as $s) {
        $html .= '<option' . ($state === $s ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    $html .= '</select></label>'
        . '<label>Postcode <input name="postcode" value="' . $v('postcode') . '" inputmode="numeric" maxlength="5" required></label>'
        . '</div>'
        . '<div class="grid2">'
        . '<label>Order (lower first) <input name="sort_order" value="' . $v('sort_order', '0') . '" inputmode="numeric" maxlength="9"></label>'
        . '<label class="check"><input type="checkbox" name="active" value="1"' . ((string) ($p['active'] ?? '1') === '1' ? ' checked' : '') . '> Active (shown at checkout)</label>'
        . '</div>';
    return $html;
};
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Pickup points</h1>
            <p class="muted">Where customers can collect an order instead of having it delivered. A pickup order pays no postage. Only active points show at checkout.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Add a pickup point</h2>
        <form method="post" action="<?= e(url('/admin/pickup-points')) ?>" class="card-form wide" novalidate data-busy-form>
            <?= csrf_field() ?>
            <?= $fields(null, $states) ?>
            <div class="msg-row"><span></span><button class="btn">Add pickup point</button></div>
        </form>
    </section>

    <?php foreach ($points as $p): ?>
    <section class="msg-card">
        <h2><?= e($p['name']) ?> <?= (int) $p['active'] === 1 ? '' : '<small class="muted">(hidden)</small>' ?></h2>
        <form method="post" action="<?= e(url('/admin/pickup-points/' . (int) $p['id'])) ?>" class="card-form wide" novalidate data-busy-form>
            <?= csrf_field() ?>
            <?= $fields($p, $states) ?>
            <div class="msg-row"><span></span><button class="btn">Save</button></div>
        </form>
    </section>
    <?php endforeach; ?>
</div>
