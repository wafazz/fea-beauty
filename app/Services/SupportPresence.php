<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Whether a member is looking at the site right now (checked in recently while the tab was visible). */
final class SupportPresence
{
    /** A member counts as online if they checked in within this many seconds. */
    public const ONLINE_SECONDS = 120;

    public static function touch(int $userId): void
    {
        Database::query(
            'INSERT INTO member_presence (user_id, seen_at) VALUES (?, NOW(3)) ON DUPLICATE KEY UPDATE seen_at = NOW(3)',
            [$userId]
        );
    }

    public static function isOnline(int $userId): bool
    {
        return (int) Database::query(
            'SELECT COUNT(*) FROM member_presence WHERE user_id = ? AND seen_at >= NOW(3) - INTERVAL ' . self::ONLINE_SECONDS . ' SECOND',
            [$userId]
        )->fetchColumn() > 0;
    }
}
