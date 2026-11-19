<?php
// Storefront content: a page shows only when published and its time has passed; the body is cleaned; slugs are
// checked; banners show only while active and inside their dates; links must be on this site or https.
use App\Core\Database as DB;
use App\Models\Setting;
use App\Services\CmsService as C;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Tester', 'cms-tester@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();
$page = fn(array $over = []) => array_merge(['title' => 'FAQ', 'slug' => 'faq', 'body' => '<p>Hello</p>', 'status' => 'published', 'seo_title' => '', 'seo_description' => ''], $over);
$banner = fn(array $over = []) => array_merge(['title' => 'Sale', 'link' => '/products', 'starts_at' => '', 'ends_at' => '', 'sort_order' => '0', 'active' => '1'], $over);

// Module off: nothing shows.
C::savePage(null, $page(), $actor);
check('module off: a published page is not served', C::publishedPage('faq') === null);
DB::query("INSERT INTO cms_banners (title, image, link, active, sort_order) VALUES ('Old', 'banners/old.jpg', NULL, 1, 0)");
check('module off: no banners on the homepage', C::liveBanners() === []);
DB::query("DELETE FROM cms_banners");

Setting::set('module.cms_pages', '1');
check('module on: a published page is served', (C::publishedPage('faq') ?? [])['title'] === 'FAQ');

C::savePage(null, $page(['slug' => 'draft-page', 'title' => 'Draft', 'status' => 'draft']), $actor);
check('a draft page is not served', C::publishedPage('draft-page') === null);

C::savePage(null, $page(['slug' => 'later-page', 'title' => 'Later']), $actor);
DB::query("UPDATE cms_pages SET published_at = ? WHERE slug = 'later-page'", [date('Y-m-d H:i:s', time() + 86400)]);
check('a page with a future publish time is not served yet', C::publishedPage('later-page') === null);

check('refused: a reserved address', C::savePage(null, $page(['slug' => 'admin', 'title' => 'X']), $actor) !== []);
check('refused: a duplicate address', C::savePage(null, $page(['slug' => 'faq', 'title' => 'Again']), $actor) !== []);
check('refused: an address with capitals or spaces', C::savePage(null, $page(['slug' => 'Bad Slug', 'title' => 'X']), $actor) !== []);
check('refused: a title that is missing', C::savePage(null, $page(['slug' => 'no-title', 'title' => '']), $actor) !== []);

C::savePage(null, $page(['slug' => 'clean-me', 'title' => 'Clean', 'body' => '<p>Safe</p><script>alert(1)</script>']), $actor);
$body = (string) DB::query("SELECT body FROM cms_pages WHERE slug = 'clean-me'")->fetchColumn();
check('the page body is cleaned: scripts are removed, text is kept', !str_contains($body, '<script') && str_contains($body, 'Safe'));

// Banners.
$now = date('Y-m-d H:i:s');
$ins = fn(string $title, int $order, int $active, ?string $from, ?string $until) => DB::query(
    'INSERT INTO cms_banners (title, image, link, starts_at, ends_at, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$title, 'banners/' . md5($title) . '.jpg', '/products', $from, $until, $active, $order]
);
$ins('Second', 2, 1, null, null);
$ins('First', 1, 1, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600));
$ins('Ended', 0, 1, null, date('Y-m-d H:i:s', time() - 60));
$ins('Not yet', 0, 1, date('Y-m-d H:i:s', time() + 3600), null);
$ins('Off', 0, 0, null, null);
$titles = array_column(C::liveBanners(), 'title');
check('only active banners inside their dates show, in order', $titles === ['First', 'Second']);

check('a link on this site is allowed', C::safeLink('/products') && C::safeLink('https://example.test/sale'));
check('a link to a script or another site over http is refused', !C::safeLink('javascript:alert(1)') && !C::safeLink('http://example.test') && !C::safeLink('//evil.example'));
check('refused: a banner whose link is a script', isset(C::saveBanner(null, $banner(['link' => 'javascript:alert(1)']), ['name' => 'x.jpg'], $actor)['link']));
check('refused: a new banner with no image', isset(C::saveBanner(null, $banner(), null, $actor)['image']));
check('refused: an end before the start', isset(C::validateBanner($banner(['starts_at' => '2030-02-01 10:00', 'ends_at' => '2030-01-01 10:00']))['ends_at']));

// Leave the switches as a fresh database has them.
DB::query("DELETE FROM settings WHERE `key` LIKE 'module.%'");
