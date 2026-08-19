<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The whole access model in one table: one row per (user, permission, scope).
 *
 * Scope columns: both NULL = the entire organization; project set = that
 * project and all its environments; both set = one environment (environment
 * rows always denormalize their project_id). A row covers everything beneath
 * its scope, including projects and environments created later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('permission');
            // nullOnDelete, deliberately: who granted it is history, not a
            // dependency - a granter deleting their account must not be blocked
            // by every grant they ever wrote.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
        });

        /*
         * Both MySQL/MariaDB and SQLite let every NULL through a unique index,
         * so a plain index over the nullable scope columns would not stop two
         * org-wide grants (both scope columns NULL) for the same user and
         * permission. Coalescing NULL to 0 closes that hole.
         *
         * On MySQL and MariaDB this is done with VIRTUAL generated columns, not
         * an expression index or STORED columns:
         *  - an expression index (`(coalesce(...))` inside the index) is MySQL
         *    8-only; MariaDB has no functional indexes and rejects the syntax.
         *  - STORED columns are refused here, because MySQL will not put a
         *    cascading foreign key on the base column of a stored generated
         *    column, and those cascades are load-bearing.
         * VIRTUAL columns index cleanly on both engines and leave the cascades
         * intact. SQLite keeps its expression index, which it supports.
         */
        $statements = match (DB::getDriverName()) {
            'mysql', 'mariadb' => [
                'alter table `grants` '
                .'add column `project_scope` bigint unsigned generated always as (coalesce(`project_id`, 0)) virtual, '
                .'add column `environment_scope` bigint unsigned generated always as (coalesce(`environment_id`, 0)) virtual',
                'create unique index `grants_scope_unique` on `grants` '
                .'(`user_id`, `organization_id`, `permission`, `project_scope`, `environment_scope`)',
            ],
            'sqlite' => [
                'create unique index "grants_scope_unique" on "grants" '
                .'("user_id", "organization_id", "permission", '
                .'coalesce("project_id", 0), coalesce("environment_id", 0))',
            ],
            default => [],
        };

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grants');
    }
};
