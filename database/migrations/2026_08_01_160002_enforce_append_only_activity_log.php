<?php

use App\Services\Audit\AppendOnlyLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the audit log append-only AT THE DATABASE LEVEL.
 *
 * App-level rules are not enough: anyone with a DB connection could rewrite
 * history. No code path - not even an admin's - may UPDATE or DELETE here.
 *
 * The statements live in AppendOnlyLog so they can be unit-tested per driver.
 * An engine with no protection defined stops this migration: running the vault
 * on an unprotected audit log is worse than not running it at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (AppendOnlyLog::protect(DB::getDriverName()) as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        foreach (AppendOnlyLog::unprotect(DB::getDriverName()) as $statement) {
            DB::unprepared($statement);
        }
    }
};
