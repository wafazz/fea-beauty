<?php
/** Expects $pages, $banners. */
$pageForm = function (?array $p): string {
    $v = fn(string $k, string $d = '') => e((string) old($k, $p[$k] ?? $d));
    $status = (string) old('status', $p['status'] ?? 'draft');
    $body = (string) old('body', $p['body'] ?? '');
    return '<div class="cc-form">'
        . '<label>Title <input name="title" value="' . $v('title') . '" maxlength="160" required data-slug-source></label>'
        . '<label>Address <input name="slug" value="' . $v('slug') . '" maxlength="120" required placeholder="shipping-policy" data-slug-target' . ($p !== null ? ' data-slug-locked' : '') . '><small class="muted">Lower-case letters, numbers and dashes. Filled in from the title.</small></label>'
        . '<label class="cc-wide">Body <textarea name="body" rows="8" maxlength="60000" data-counter>' . e($body) . '</textarea><small class="muted cc-count"></small></label>'
        . '<label>SEO title (optional) <input name="seo_title" value="' . $v('seo_title') . '" maxlength="160" data-counter></label>'
        . '<label>SEO description (optional) <input name="seo_description" value="' . $v('seo_description') . '" maxlength="255" data-counter><small class="muted cc-count"></small></label>'
        . '<label>Status <select name="status"><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Draft (hidden)</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>Published</option></select></label>'
        . '</div>';
};
$bannerForm = function (?array $b, bool $needImage): string {
    $v = fn(string $k, string $d = '') => e((string) old($k, $b[$k] ?? $d));
    $dt = fn(?string $s) => $s ? e(date('Y-m-d\TH:i', strtotime($s))) : '';
    return '<div class="cc-form">'
        . '<label>Title <input name="title" value="' . $v('title') . '" maxlength="160" required></label>'
        . '<label class="cc-file">Image (JPG, PNG or WebP, 2 MB)' . ($needImage ? '' : ' <small class="muted">(leave empty to keep the current image)</small>')
        . ' <input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-preview' . ($needImage ? ' required' : '') . '>'
        . '<small class="muted cc-hint">Best size: <strong>1920 × 640 px</strong> (3:1 ratio). Keep the text and logo away from the edges, because phones trim the sides. Save as WebP or JPG, ideally under 500 KB.</small>'
        . '<img class="cc-preview" alt="" hidden></label>'
        . '<label>Link (optional) <input name="link" value="' . $v('link') . '" maxlength="255" placeholder="/products or https://…"></label>'
        . '<label>Order (lower first) <input name="sort_order" value="' . $v('sort_order', '0') . '" inputmode="numeric" maxlength="9"></label>'
        . '<label>Show from (optional) <input type="datetime-local" name="starts_at" value="' . $dt($b['starts_at'] ?? null) . '"></label>'
        . '<label>Show until (optional) <input type="datetime-local" name="ends_at" value="' . $dt($b['ends_at'] ?? null) . '"></label>'
        . '<label class="check cc-wide"><input type="checkbox" name="active" value="1"' . ((string) ($b['active'] ?? '1') === '1' ? ' checked' : '') . '> Active</label>'
        . '</div>';
};
?>
<div class="msg-page cc-page">
    <header class="msg-head">
        <div>
            <h1>Storefront content</h1>
            <p class="muted">Homepage banners and content pages (FAQ, policies). A page shows only when published; a banner shows only while active and inside its dates.</p>
        </div>
    </header>

    <div class="cc-toolbar">
        <label class="cc-search">
            <span class="sr-only">Search content</span>
            <input type="search" placeholder="Search banners and pages…" data-content-filter autocomplete="off">
        </label>
        <div class="cc-tabs" role="group" aria-label="Show">
            <button type="button" class="s-btn secondary sm" data-expand="open">Expand all</button>
            <button type="button" class="s-btn secondary sm" data-expand="close">Collapse all</button>
        </div>
    </div>

    <section class="msg-card" id="cc-banners">
        <h2>Homepage banners <small class="muted">(<?= count($banners) ?>)</small></h2>
        <p class="muted">Shown in order at the top of the homepage.</p>
        <div class="cc-list">
        <?php foreach ($banners as $b): ?>
            <details class="cc-item" data-search="<?= e(strtolower($b['title'] . ' banner')) ?>">
                <summary>
                    <img class="cc-thumb" src="<?= e(url($b['image'])) ?>" alt="" loading="lazy">
                    <span class="cc-title"><strong><?= e($b['title']) ?></strong>
                        <small class="muted"><?= $b['link'] ? e($b['link']) : 'No link' ?></small></span>
                    <span class="msg-pill <?= (int) $b['active'] === 1 ? 'ok' : '' ?>"><?= (int) $b['active'] === 1 ? 'Active' : 'Off' ?></span>
                </summary>
                <form method="post" action="<?= e(url('/admin/content/banners/' . (int) $b['id'])) ?>" enctype="multipart/form-data" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <?= $bannerForm($b, false) ?>
                    <div class="msg-row cc-actions"><button class="btn">Save banner</button></div>
                </form>
                <form method="post" action="<?= e(url('/admin/content/banners/' . (int) $b['id'] . '/delete')) ?>" class="cc-actions"
                      data-swal-confirm="Delete this banner?" data-swal-text="The image is removed too. This cannot be undone." data-swal-button="Delete" data-swal-danger="1">
                    <?= csrf_field() ?><button class="s-btn secondary sm" type="submit">Delete banner</button>
                </form>
            </details>
        <?php endforeach; ?>
        </div>
        <details class="cc-item cc-new">
            <summary><strong>+ Add a banner</strong></summary>
            <form method="post" action="<?= e(url('/admin/content/banners')) ?>" enctype="multipart/form-data" novalidate data-busy-form>
                <?= csrf_field() ?>
                <?= $bannerForm(null, true) ?>
                <div class="msg-row cc-actions"><button class="btn">Add banner</button></div>
            </form>
        </details>
    </section>

    <section class="msg-card" id="cc-pages">
        <h2>Content pages <small class="muted">(<?= count($pages) ?>)</small></h2>
        <div class="cc-list">
        <?php foreach ($pages as $p): ?>
            <details class="cc-item" data-search="<?= e(strtolower($p['title'] . ' ' . $p['slug'] . ' page')) ?>">
                <summary>
                    <span class="cc-title"><strong><?= e($p['title']) ?></strong>
                        <small class="muted">/pages/<?= e($p['slug']) ?></small></span>
                    <span class="msg-pill <?= $p['status'] === 'published' ? 'ok' : '' ?>"><?= $p['status'] === 'published' ? 'Published' : 'Draft' ?></span>
                </summary>
                <?php if ($p['status'] === 'published'): ?><p><a href="<?= e(url('/pages/' . $p['slug'])) ?>" target="_blank" rel="noopener">View page</a></p><?php endif; ?>
                <form method="post" action="<?= e(url('/admin/content/pages/' . (int) $p['id'])) ?>" novalidate data-busy-form>
                    <?= csrf_field() ?>
                    <?= $pageForm($p) ?>
                    <div class="msg-row cc-actions"><button class="btn">Save page</button></div>
                </form>
                <form method="post" action="<?= e(url('/admin/content/pages/' . (int) $p['id'] . '/delete')) ?>" class="cc-actions"
                      data-swal-confirm="Delete this page?" data-swal-text="Shoppers will no longer see it. This cannot be undone." data-swal-button="Delete" data-swal-danger="1">
                    <?= csrf_field() ?><button class="s-btn secondary sm" type="submit">Delete page</button>
                </form>
            </details>
        <?php endforeach; ?>
        </div>
        <details class="cc-item cc-new">
            <summary><strong>+ Add a page</strong></summary>
            <form method="post" action="<?= e(url('/admin/content/pages')) ?>" novalidate data-busy-form>
                <?= csrf_field() ?>
                <?= $pageForm(null) ?>
                <div class="msg-row cc-actions"><button class="btn">Add page</button></div>
            </form>
        </details>
    </section>
    <p class="muted cc-nomatch" data-content-empty hidden>Nothing matches that search.</p>
</div>
