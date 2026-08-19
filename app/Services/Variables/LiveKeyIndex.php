<?php

namespace App\Services\Variables;

use RuntimeException;

/**
 * Keeps variable keys unique among the variables that still exist.
 *
 * A plain `unique(project_id, key)` looks right and is subtly wrong once
 * variables are soft-deleted: deleting DATABASE_URL would burn that key in that
 * project forever, because the deleted row still occupies it. The constraint we
 * actually want is "unique among rows where deleted_at is null", which every
 * engine expresses differently:
 *
 * - SQLite: a partial index (`WHERE deleted_at IS NULL`).
 * - MySQL and MariaDB: a stored generated column that holds the key for a live
 *   row and NULL for a soft-deleted one, with a unique index over it - NULLs
 *   never collide in a unique index. This works on both engines; a functional
 *   index (the `(CASE ...)` expression MySQL 8 alone allows) does NOT, because
 *   MariaDB has no functional indexes and rejects the syntax outright.
 *
 * Same rule as the audit log's protection: an engine we cannot express it on
 * throws, rather than silently creating a table with no constraint at all.
 */
final class LiveKeyIndex
{
    public const NAME = 'variables_live_key_unique';

    /** @return array<int, literal-string> */
    public static function create(string $driver): array
    {
        return match ($driver) {
            'sqlite' => [
                'CREATE UNIQUE INDEX variables_live_key_unique ON variables (project_id, "key") WHERE deleted_at IS NULL',
            ],
            'mysql', 'mariadb' => [
                'ALTER TABLE variables ADD COLUMN live_key VARCHAR(255) '.
                'GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN `key` END) STORED',
                'CREATE UNIQUE INDEX variables_live_key_unique ON variables (project_id, live_key)',
            ],
            default => throw self::unsupported($driver),
        };
    }

    /** @return array<int, literal-string> */
    public static function drop(string $driver): array
    {
        return match ($driver) {
            'sqlite' => ['DROP INDEX IF EXISTS variables_live_key_unique'],
            'mysql', 'mariadb' => [
                'DROP INDEX variables_live_key_unique ON variables',
                'ALTER TABLE variables DROP COLUMN live_key',
            ],
            default => throw self::unsupported($driver),
        };
    }

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException(
            "No live-key uniqueness index is defined for the [{$driver}] database driver. ".
            'Without it two live variables could share a key in one project.'
        );
    }
}
