<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use App\Models\Setting;

/**
 * Storefront content (module cms_pages): content pages such as FAQ and policies, and homepage banners.
 * Page bodies are cleaned with RichText on save and again on display. Pages and banners go live only when published
 * and inside their dates, so a draft or a banner outside its dates never reaches a shopper.
 */
final class CmsService
{
    /** Slugs a page may not take, because the storefront already uses them. */
    public const RESERVED = ['admin', 'staff', 'superadmin', 'member', 'login', 'logout', 'register', 'checkout', 'cart', 'products',
        'track', 'order', 'orders', 'search', 'pages', 'health', 'install', 'payments', 'shipping', 'api', 'assets', 'uploads', 'live', 'r', 'wishlist'];

    public static function enabled(): bool
    {
        return Modules::enabled('cms_pages');
    }

    // ---- pages -----------------------------------------------------------------------------------

    /** Every page for the admin, newest first. */
    public static function pages(): array
    {
        return Database::query('SELECT * FROM cms_pages ORDER BY id DESC')->fetchAll();
    }

    public static function page(int $id): ?array
    {
        return Database::query('SELECT * FROM cms_pages WHERE id = ?', [$id])->fetch() ?: null;
    }

    /** A page for a shopper: only when it is published and its publish time has passed. */
    public static function publishedPage(string $slug): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $p = Database::query(
            "SELECT * FROM cms_pages WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= ?)",
            [$slug, date('Y-m-d H:i:s')]
        )->fetch();
        return $p ?: null;
    }

    /** @return array<string,string> errors (empty when valid) */
    public static function validatePage(array $in, ?int $id): array
    {
        $errors = (new Validator($in, [
            'title' => 'required|max:160',
            'seo_title' => 'max:160',
            'seo_description' => 'max:255',
        ]))->errors();
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $in['slug']) || strlen((string) $in['slug']) > 120) {
            $errors['slug'] = 'Address must use lower-case letters, numbers and dashes only (for example shipping-policy).';
        } elseif (in_array($in['slug'], self::RESERVED, true)) {
            $errors['slug'] = 'That address is used by the shop. Choose another.';
        } elseif (Database::query('SELECT COUNT(*) FROM cms_pages WHERE slug = ? AND id <> ?', [$in['slug'], $id ?? 0])->fetchColumn() > 0) {
            $errors['slug'] = 'Another page already uses that address.';
        }
        if (!in_array($in['status'], ['draft', 'published'], true)) {
            $errors['status'] = 'Choose draft or published.';
        }
        return $errors;
    }

    /** Creates a page (id null) or updates one. @return array<string,string> errors */
    public static function savePage(?int $id, array $in, int $actorId): array
    {
        $errors = self::validatePage($in, $id);
        if ($errors !== []) {
            return $errors;
        }
        $body = RichText::clean((string) $in['body']);
        $row = [
            'slug' => $in['slug'], 'title' => $in['title'], 'body' => $body, 'status' => $in['status'],
            'seo_title' => trim((string) $in['seo_title']) ?: null, 'seo_description' => trim((string) $in['seo_description']) ?: null,
            'published_at' => $in['status'] === 'published' ? date('Y-m-d H:i:s') : null,
        ];
        if ($id === null) {
            Database::query(
                'INSERT INTO cms_pages (slug, title, body, status, seo_title, seo_description, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                array_values($row)
            );
            $id = (int) Database::connection()->lastInsertId();
            AuditService::log($actorId, 'cms.page.create', 'page ' . $id . ': /' . $row['slug']);
        } else {
            // Keep the first publish time when a published page is edited again.
            $current = self::page($id);
            if ($current !== null && $current['status'] === 'published' && $row['status'] === 'published' && $current['published_at'] !== null) {
                $row['published_at'] = $current['published_at'];
            }
            Database::query(
                'UPDATE cms_pages SET slug = ?, title = ?, body = ?, status = ?, seo_title = ?, seo_description = ?, published_at = ? WHERE id = ?',
                [...array_values($row), $id]
            );
            AuditService::log($actorId, 'cms.page.update', 'page ' . $id . ': /' . $row['slug'] . ' (' . $row['status'] . ')');
        }
        return [];
    }

    public static function deletePage(int $id, int $actorId): void
    {
        $p = self::page($id);
        if ($p !== null) {
            Database::query('DELETE FROM cms_pages WHERE id = ?', [$id]);
            AuditService::log($actorId, 'cms.page.delete', 'page ' . $id . ': /' . $p['slug']);
        }
    }

    // ---- banners ---------------------------------------------------------------------------------

    /** Every banner for the admin, in show order. */
    public static function banners(): array
    {
        return Database::query('SELECT * FROM cms_banners ORDER BY sort_order, id')->fetchAll();
    }

    public static function banner(int $id): ?array
    {
        return Database::query('SELECT * FROM cms_banners WHERE id = ?', [$id])->fetch() ?: null;
    }

    /** The banners a shopper sees now: active, and inside their start and end times. Empty while the module is off. */
    public static function liveBanners(): array
    {
        if (!self::enabled()) {
            return [];
        }
        $now = date('Y-m-d H:i:s');
        return Database::query(
            'SELECT id, title, image, link FROM cms_banners
              WHERE active = 1 AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)
              ORDER BY sort_order, id',
            [$now, $now]
        )->fetchAll();
    }

    /** A banner's link must be an address on this site, or a full https address. */
    public static function safeLink(string $link): bool
    {
        if ($link === '') {
            return true;
        }
        return (str_starts_with($link, '/') && !str_starts_with($link, '//')) || preg_match('#^https://[^\s]+$#', $link) === 1;
    }

    /** @return array<string,string> errors (empty when valid) */
    public static function validateBanner(array $in): array
    {
        $errors = (new Validator($in, ['title' => 'required|max:160', 'link' => 'max:255', 'sort_order' => 'max:9']))->errors();
        if (!self::safeLink((string) $in['link'])) {
            $errors['link'] = 'The link must start with / (on this site) or be a full https:// address.';
        }
        foreach (['starts_at', 'ends_at'] as $f) {
            if ($in[$f] !== '' && strtotime((string) $in[$f]) === false) {
                $errors[$f] = 'That date is not valid.';
            }
        }
        if ($in['starts_at'] !== '' && $in['ends_at'] !== '' && strtotime((string) $in['ends_at']) <= strtotime((string) $in['starts_at'])) {
            $errors['ends_at'] = 'The end must be after the start.';
        }
        return $errors;
    }

    /**
     * Creates a banner (id null) or updates one. The image is required for a new banner and optional for an edit.
     * @param array|null $file one uploaded file, or null to keep the image
     * @return array<string,string> errors
     */
    public static function saveBanner(?int $id, array $in, ?array $file, int $actorId): array
    {
        $errors = self::validateBanner($in);
        $current = $id !== null ? self::banner($id) : null;
        if ($id === null && $file === null) {
            $errors['image'] = 'Choose a banner image.';
        }
        if ($errors !== []) {
            return $errors; // nothing is saved, and no image is kept, while anything is wrong
        }
        $image = $current['image'] ?? null;
        if ($file !== null) {
            try {
                $image = UploadService::saveImage($file, 'banners');
            } catch (\InvalidArgumentException $e) {
                $errors['image'] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        $row = [
            'title' => $in['title'], 'image' => $image, 'link' => trim((string) $in['link']) ?: null,
            'starts_at' => $in['starts_at'] !== '' ? date('Y-m-d H:i:s', strtotime((string) $in['starts_at'])) : null,
            'ends_at' => $in['ends_at'] !== '' ? date('Y-m-d H:i:s', strtotime((string) $in['ends_at'])) : null,
            'active' => ($in['active'] ?? '0') === '1' ? 1 : 0,
            'sort_order' => max(0, (int) $in['sort_order']),
        ];
        if ($id === null) {
            Database::query(
                'INSERT INTO cms_banners (title, image, link, starts_at, ends_at, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
                array_values($row)
            );
            AuditService::log($actorId, 'cms.banner.create', 'banner: ' . $row['title']);
        } else {
            Database::query(
                'UPDATE cms_banners SET title = ?, image = ?, link = ?, starts_at = ?, ends_at = ?, active = ?, sort_order = ? WHERE id = ?',
                [...array_values($row), $id]
            );
            if ($file !== null && $current !== null && $current['image'] !== $image) {
                UploadService::delete((string) $current['image']); // the old file goes only after the row points at the new one
            }
            AuditService::log($actorId, 'cms.banner.update', 'banner ' . $id . ': ' . $row['title']);
        }
        return [];
    }

    public static function deleteBanner(int $id, int $actorId): void
    {
        $b = self::banner($id);
        if ($b !== null) {
            Database::query('DELETE FROM cms_banners WHERE id = ?', [$id]);
            UploadService::delete((string) $b['image']);
            AuditService::log($actorId, 'cms.banner.delete', 'banner ' . $id . ': ' . $b['title']);
        }
    }
}
