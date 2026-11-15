<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Str;

/** Support categories (one per ticket, required) and labels (many per ticket). Managed by admins. */
final class SupportTaxonomy
{
    public const NAME_MAX = 80;
    public const LABEL_MAX = 40;

    /** @return array<int, array<string, mixed>> */
    public static function categories(): array
    {
        return Database::query('SELECT id, name, slug, position, active FROM support_categories ORDER BY position, id')->fetchAll();
    }

    /** Categories a customer can pick from. @return array<int, array<string, mixed>> */
    public static function activeCategories(): array
    {
        return array_values(array_filter(self::categories(), fn($c) => (int) $c['active'] === 1));
    }

    /** @return array<int, array<string, mixed>> */
    public static function labels(): array
    {
        return Database::query('SELECT id, name, colour, active FROM support_labels ORDER BY name')->fetchAll();
    }

    /** Create (id null) or update a category. Returns an error message, or null when saved. */
    public static function saveCategory(?int $id, string $name, string $position, bool $active): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return 'Category name is required (up to ' . self::NAME_MAX . ' characters).';
        }
        if (preg_match('/^\d{1,3}$/', trim($position)) !== 1) {
            return 'Position must be a whole number, 0 to 999.';
        }
        $taken = (int) Database::query('SELECT COUNT(*) FROM support_categories WHERE name = ? AND id <> ?', [$name, $id ?? 0])->fetchColumn();
        if ($taken > 0) {
            return 'A category with that name already exists.';
        }
        $slug = Str::uniqueSlug('support_categories', 'slug', Str::slug($name), $id);
        try {
            if ($id === null) {
                Database::query('INSERT INTO support_categories (name, slug, position, active) VALUES (?, ?, ?, ?)', [$name, $slug, (int) $position, $active ? 1 : 0]);
            } else {
                $exists = (int) Database::query('SELECT COUNT(*) FROM support_categories WHERE id = ?', [$id])->fetchColumn();
                if ($exists === 0) {
                    return 'That category no longer exists.';
                }
                Database::query('UPDATE support_categories SET name = ?, slug = ?, position = ?, active = ? WHERE id = ?', [$name, $slug, (int) $position, $active ? 1 : 0, $id]);
            }
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), '1062')) {
                return 'A category with that name already exists.';
            }
            throw $e;
        }
        return null;
    }

    /** Create (id null) or update a label. Returns an error message, or null when saved. */
    public static function saveLabel(?int $id, string $name, string $colour, bool $active): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::LABEL_MAX) {
            return 'Label name is required (up to ' . self::LABEL_MAX . ' characters).';
        }
        $colour = strtolower(trim($colour));
        if (preg_match('/^#[0-9a-f]{6}$/', $colour) !== 1) {
            return 'Colour must be a hex colour such as #64748b.';
        }
        try {
            if ($id === null) {
                Database::query('INSERT INTO support_labels (name, colour, active) VALUES (?, ?, ?)', [$name, $colour, $active ? 1 : 0]);
            } else {
                $exists = (int) Database::query('SELECT COUNT(*) FROM support_labels WHERE id = ?', [$id])->fetchColumn();
                if ($exists === 0) {
                    return 'That label no longer exists.';
                }
                Database::query('UPDATE support_labels SET name = ?, colour = ?, active = ? WHERE id = ?', [$name, $colour, $active ? 1 : 0, $id]);
            }
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), '1062')) {
                return 'A label with that name already exists.';
            }
            throw $e;
        }
        return null;
    }
}
