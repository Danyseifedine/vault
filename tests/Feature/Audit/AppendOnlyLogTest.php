<?php

namespace Tests\Feature\Audit;

use App\Services\Audit\AppendOnlyLog;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The append-only guarantee is only as good as the database it was actually
 * installed on.
 *
 * This suite exists because the protection silently did nothing on MySQL for a
 * while: the migration quietly skipped every driver it did not recognise, so the
 * tests (SQLite) were green while the real database was wide open. Silence is
 * the bug - an unknown driver must now fail loudly.
 */
class AppendOnlyLogTest extends TestCase
{
    use RefreshDatabase;

    /** MySQL in dev and production, SQLite for this suite. */
    private const DRIVERS = ['sqlite', 'mysql', 'mariadb'];

    public function test_every_driver_we_run_on_gets_real_protection(): void
    {
        foreach (self::DRIVERS as $driver) {
            $sql = implode(' ', AppendOnlyLog::protect($driver));

            $this->assertStringContainsStringIgnoringCase('activity_log', $sql, "{$driver} does not touch the table");
            $this->assertStringContainsStringIgnoringCase('update', $sql, "{$driver} does not block updates");
            $this->assertStringContainsStringIgnoringCase('delete', $sql, "{$driver} does not block deletes");
        }
    }

    /**
     * Blocking must mean "the write fails", not "the write is quietly
     * discarded" - otherwise an attacker's UPDATE looks like it worked.
     */
    public function test_every_driver_refuses_loudly_rather_than_ignoring_the_write(): void
    {
        $raises = [
            'sqlite' => 'RAISE(ABORT',
            'mysql' => 'SIGNAL SQLSTATE',
            'mariadb' => 'SIGNAL SQLSTATE',
        ];

        foreach ($raises as $driver => $needle) {
            $this->assertStringContainsString(
                $needle,
                implode(' ', AppendOnlyLog::protect($driver)),
                "{$driver} does not raise an error on a forbidden write",
            );
        }
    }

    /**
     * The actual regression: an unrecognised driver used to fall through to a
     * no-op, leaving the audit log unprotected with nothing to show for it.
     */
    public function test_an_unsupported_driver_fails_loudly_instead_of_skipping(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only/i');

        AppendOnlyLog::protect('sqlsrv');
    }

    /**
     * We dropped the PostgreSQL statements when the stack settled on MySQL.
     * Pointing the vault at Postgres must therefore stop the migration, not
     * install an audit log nothing is guarding.
     */
    public function test_an_engine_we_no_longer_support_stops_the_migration(): void
    {
        $this->expectException(RuntimeException::class);

        AppendOnlyLog::protect('pgsql');
    }

    public function test_the_rollback_statements_cover_every_supported_driver(): void
    {
        foreach (self::DRIVERS as $driver) {
            $this->assertNotEmpty(AppendOnlyLog::unprotect($driver), "{$driver} cannot be rolled back");
        }
    }

    public function test_unprotecting_an_unsupported_driver_also_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        AppendOnlyLog::unprotect('sqlsrv');
    }

    public function test_the_connection_this_suite_runs_on_is_actually_protected(): void
    {
        $this->assertContains(
            DB::getDriverName(),
            self::DRIVERS,
            'The test connection runs on a driver with no append-only protection.',
        );
    }

    public function test_updating_an_audit_entry_is_rejected_by_the_database(): void
    {
        app(AuditRecorder::class)->record('variable.created', properties: ['n' => 1]);

        $this->expectException(QueryException::class);

        DB::table('activity_log')->update(['event' => 'quietly.changed']);
    }

    public function test_deleting_an_audit_entry_is_rejected_by_the_database(): void
    {
        app(AuditRecorder::class)->record('variable.created', properties: ['n' => 1]);

        $this->expectException(QueryException::class);

        DB::table('activity_log')->delete();
    }
}
