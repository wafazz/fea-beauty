<?php
/** Expects $points (all pickup points), $states. */
$fields = function (?array $p, array $states): string {
    $v = fn(string $k, string $d = '') => e((string) old($k, $p[$k] ?? $d));
    $state = (string) old('state', $p['state'] ?? '');
    $active = (string) ($p['active'] ?? '1') === '1';
    $html = '<div class="cc-form">'
        . '<label>Name <input name="name" value="' . $v('name') . '" maxlength="120" required data-pp-name></label>'
        . '<label>Opening hours (optional) <input name="opening_hours" value="' . $v('opening_hours') . '" maxlength="160" placeholder="Mon-Sat 10:00-18:00" data-pp-hours></label>'
        . '<label class="cc-wide">Address <input name="line1" value="' . $v('line1') . '" maxlength="255" required data-pp-line></label>'
        . '<label>City <input name="city" value="' . $v('city') . '" maxlength="100" required data-pp-city></label>'
        . '<label>State <select name="state" required data-pp-state><option value="">Select…</option>';
    foreach ($states as $s) {
        $html .= '<option' . ($state === $s ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    $html .= '</select></label>'
        . '<label>Postcode <input name="postcode" value="' . $v('postcode') . '" inputmode="numeric" maxlength="5" required data-pp-postcode><small class="muted pp-hint" aria-live="polite">5 digits</small></label>'
        . '<label>Order (lower first) <input name="sort_order" value="' . $v('sort_order', '0') . '" inputmode="numeric" maxlength="9"></label>'
        . '<label class="check cc-wide"><input type="checkbox" name="active" value="1"' . ($active ? ' checked' : '') . ' data-pp-active> <span class="pp-active-text">' . ($active ? 'Active: shown at checkout' : 'Hidden from checkout') . '</span></label>'
        . '<div class="pp-preview cc-wide" aria-live="polite"><span class="muted">How it looks at checkout:</span> <strong data-pp-preview>' . e(($p['name'] ?? 'Name') . ' · ' . ($p['city'] ?? 'City') . ', ' . ($p['state'] ?? 'State')) . '</strong></div>'
        . '</div>';
    return $html;
};
?>
<div class="msg-page cc-page pp-page">
    <header class="msg-head">
        <div>
            <h1>Pickup points</h1>
            <p class="muted">Where customers can collect an order instead of having it delivered. A pickup order pays no postage. Only active points show at checkout.</p>
        </div>
    </header>

    <div class="cc-toolbar">
        <label class="cc-search">
            <span class="sr-only">Search pickup points</span>
            <input type="search" placeholder="Search by name, city or state…" data-pp-filter autocomplete="off">
        </label>
        <div class="cc-tabs" role="group" aria-label="Show">
            <button type="button" class="s-btn secondary sm" data-pp-expand="open">Expand all</button>
            <button type="button" class="s-btn secondary sm" data-pp-expand="close">Collapse all</button>
        </div>
    </div>

    <section class="msg-card">
        <details class="cc-item cc-new" data-pp-new>
            <summary><strong>+ Add a pickup point</strong></summary>
            <form method="post" action="<?= e(url('/admin/pickup-points')) ?>" novalidate data-busy-form>
                <?= csrf_field() ?>
                <?= $fields(null, $states) ?>
                <div class="cc-actions"><button class="btn">Add pickup point</button></div>
            </form>
        </details>
    </section>

    <section class="msg-card">
        <h2>Points <small class="muted">(<?= count($points) ?>)</small></h2>
        <div class="cc-list">
        <?php foreach ($points as $p): ?>
            <details class="cc-item" data-search="<?= e(strtolower($p['name'] . ' ' . $p['city'] . ' ' . $p['state'] . ' ' . $p['postcode'])) ?>">
                <summary>
                    <span class="cc-title"><strong><?= e($p['name']) ?></strong>
                        <small class="muted"><?= e($p['line1']) ?>, <?= e($p['city']) ?>, <?= e($p['state']) ?> <?= e($p['postcode']) ?></small></span>
                    <span class="msg-pill <?= (int) $p['active'] === 1 ? 'ok' : '' ?>"><?= (int) $p['active'] === 1 ? 'Active' : 'Hidden' ?></span>
                </summary>
                <?php if (!empty($p['opening_hours'])): ?><p class="muted">Hours: <?= e($p['opening_hours']) ?></p><?php endif; ?>
                <form method="post" action="<?= e(url('/admin/pickup-points/' . (int) $p['id'])) ?>" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <?= $fields($p, $states) ?>
                    <div class="cc-actions"><button class="btn">Save</button></div>
                </form>
            </details>
        <?php endforeach; ?>
        </div>
        <p class="muted cc-nomatch" data-pp-empty hidden>Nothing matches that search.</p>
    </section>
</div>
