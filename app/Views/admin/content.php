<?php
/** Expects $pages, $banners. */
$pageForm = function (?array $p): string {
    $v = fn(string $k, string $d = '') => e((string) old($k, $p[$k] ?? $d));
    $status = (string) old('status', $p['status'] ?? 'draft');
    return '<div class="grid2">'
        . '<label>Title <input name="title" value="' . $v('title') . '" maxlength="160" required></label>'
        . '<label>Address (for example shipping-policy) <input name="slug" value="' . $v('slug') . '" maxlength="120" required placeholder="faq"></label>'
        . '</div>'
        . '<label>Body <textarea name="body" rows="10">' . e((string) old('body', $p['body'] ?? '')) . '</textarea></label>'
        . '<div class="grid2">'
        . '<label>SEO title (optional) <input name="seo_title" value="' . $v('seo_title') . '" maxlength="160"></label>'
        . '<label>SEO description (optional) <input name="seo_description" value="' . $v('seo_description') . '" maxlength="255"></label>'
        . '</div>'
        . '<label>Status <select name="status"><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Draft (hidden)</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>Published</option></select></label>';
};
$bannerForm = function (?array $b, bool $needImage): string {
    $v = fn(string $k, string $d = '') => e((string) old($k, $b[$k] ?? $d));
    $dt = fn(?string $s) => $s ? e(date('Y-m-d\TH:i', strtotime($s))) : '';
    return '<label>Title <input name="title" value="' . $v('title') . '" maxlength="160" required></label>'
        . '<label>Image (JPG, PNG or WebP, 2 MB)' . ($needImage ? '' : ' <small class="muted">(leave empty to keep the current image)</small>') . ' <input type="file" name="image" accept="image/jpeg,image/png,image/webp"' . ($needImage ? ' required' : '') . '></label>'
        . '<div class="grid2">'
        . '<label>Link (optional) <input name="link" value="' . $v('link') . '" maxlength="255" placeholder="/products or https://…"></label>'
        . '<label>Order (lower first) <input name="sort_order" value="' . $v('sort_order', '0') . '" inputmode="numeric" maxlength="9"></label>'
        . '</div>'
        . '<div class="grid2">'
        . '<label>Show from (optional) <input type="datetime-local" name="starts_at" value="' . $dt($b['starts_at'] ?? null) . '"></label>'
        . '<label>Show until (optional) <input type="datetime-local" name="ends_at" value="' . $dt($b['ends_at'] ?? null) . '"></label>'
        . '</div>'
        . '<label class="check"><input type="checkbox" name="active" value="1"' . ((string) ($b['active'] ?? '1') === '1' ? ' checked' : '') . '> Active</label>';
};
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Storefront content</h1>
            <p class="muted">Homepage banners and content pages (FAQ, policies). A page shows only when published; a banner shows only while active and inside its dates.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Homepage banners</h2>
        <p class="muted">Shown in order at the top of the homepage.</p>
        <?php foreach ($banners as $b): ?>
            <details class="msg-card" style="margin:.5rem 0">
                <summary><strong><?= e($b['title']) ?></strong> <?= (int) $b['active'] === 1 ? '' : '<small class="muted">(inactive)</small>' ?></summary>
                <form method="post" action="<?= e(url('/admin/content/banners/' . (int) $b['id'])) ?>" class="card-form wide" enctype="multipart/form-data" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <?= $bannerForm($b, false) ?>
                    <div class="msg-row"><span></span><button class="btn">Save banner</button></div>
                </form>
                <form method="post" action="<?= e(url('/admin/content/banners/' . (int) $b['id'] . '/delete')) ?>" data-confirm="Delete this banner?">
                    <?= csrf_field() ?><button class="s-btn secondary sm" type="submit">Delete banner</button>
                </form>
            </details>
        <?php endforeach; ?>
        <details style="margin-top:1rem">
            <summary><strong>Add a banner</strong></summary>
            <form method="post" action="<?= e(url('/admin/content/banners')) ?>" class="card-form wide" enctype="multipart/form-data" novalidate data-busy-form>
                <?= csrf_field() ?>
                <?= $bannerForm(null, true) ?>
                <div class="msg-row"><span></span><button class="btn">Add banner</button></div>
            </form>
        </details>
    </section>

    <section class="msg-card">
        <h2>Content pages</h2>
        <?php foreach ($pages as $p): ?>
            <details class="msg-card" style="margin:.5rem 0">
                <summary><strong><?= e($p['title']) ?></strong> <small class="muted">/<?= e($p['slug']) ?></small> <?= $p['status'] === 'published' ? '<span class="msg-pill ok">Published</span>' : '<span class="msg-pill">Draft</span>' ?></summary>
                <?php if ($p['status'] === 'published'): ?><p><a href="<?= e(url('/pages/' . $p['slug'])) ?>" target="_blank" rel="noopener">View page</a></p><?php endif; ?>
                <form method="post" action="<?= e(url('/admin/content/pages/' . (int) $p['id'])) ?>" class="card-form wide" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <?= $pageForm($p) ?>
                    <div class="msg-row"><span></span><button class="btn">Save page</button></div>
                </form>
                <form method="post" action="<?= e(url('/admin/content/pages/' . (int) $p['id'] . '/delete')) ?>" data-confirm="Delete this page?">
                    <?= csrf_field() ?><button class="s-btn secondary sm" type="submit">Delete page</button>
                </form>
            </details>
        <?php endforeach; ?>
        <details style="margin-top:1rem">
            <summary><strong>Add a page</strong></summary>
            <form method="post" action="<?= e(url('/admin/content/pages')) ?>" class="card-form wide" novalidate data-busy-form>
                <?= csrf_field() ?>
                <?= $pageForm(null) ?>
                <div class="msg-row"><span></span><button class="btn">Add page</button></div>
            </form>
        </details>
    </section>
</div>
