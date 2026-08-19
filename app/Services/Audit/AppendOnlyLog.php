<?php

namespace App\Services\Audit;

use RuntimeException;

/**
 * The SQL that makes the audit log append-only, per database engine.
 *
 * This lives outside the migration so it can be tested directly: the protection
 * once skipped every driver it did not recognise, which left the audit log fully
 * mutable on MySQL while the SQLite test suite stayed green. An engine we do not
 * know how to protect must now stop the migration rather than pass quietly.
 *
 * We run MySQL (MariaDB speaks the same dialect) and SQLite in the test suite.
 * Both raise an error on a forbidden write - silently discarding it would let a
 * tamper attempt look like it succeeded.
 */
final class AppendOnlyLog
{
    public const TABLE = 'activity_log';

    private const UPDATE_MESSAGE = 'activity_log is append-only: updates are forbidden';

    private const DELETE_MESSAGE = 'activity_log is append-only: deletes are forbidden';

    /** @return array<int, literal-string> */
    public static function protect(string $driver): array
    {
        return match ($driver) {
            'sqlite' => self::sqlite(),
            'mysql', 'mariadb' => self::mysql(),
            default => throw self::unsupported($driver),
        };
    }

    /** @return array<int, literal-string> */
    public static function unprotect(string $driver): array
    {
        return match ($driver) {
            'sqlite', 'mysql', 'mariadb' => [
                'DROP TRIGGER IF EXISTS activity_log_no_update',
                'DROP TRIGGER IF EXISTS activity_log_no_delete',
            ],
            default => throw self::unsupported($driver),
        };
    }

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException(
            "No append-only protection is defined for the [{$driver}] database driver. ".
            'The audit log must never run unprotected - add the statements to '.
            AppendOnlyLog::class.' before using this engine.'
        );
    }

    /** @return array<int, literal-string> */
    private static function sqlite(): array
    {
        return [
            <<<'SQL'
                CREATE TRIGGER activity_log_no_update
                BEFORE UPDATE ON activity_log
                BEGIN
                    SELECT RAISE(ABORT, 'activity_log is append-only: updates are forbidden');
                END
            SQL,
            <<<'SQL'
                CREATE TRIGGER activity_log_no_delete
                BEFORE DELETE ON activity_log
                BEGIN
                    SELECT RAISE(ABORT, 'activity_log is append-only: deletes are forbidden');
                END
            SQL,
        ];
    }

    /** @return array<int, literal-string> */
    private static function mysql(): array
    {
        $update = self::UPDATE_MESSAGE;
        $delete = self::DELETE_MESSAGE;

        return [
            <<<SQL
                CREATE TRIGGER activity_log_no_update
                BEFORE UPDATE ON activity_log
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$update}'
            SQL,
            <<<SQL
                CREATE TRIGGER activity_log_no_delete
                BEFORE DELETE ON activity_log
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$delete}'
            SQL,
        ];
    }
}
