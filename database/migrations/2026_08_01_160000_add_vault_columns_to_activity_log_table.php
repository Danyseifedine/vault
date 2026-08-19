<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends spatie/laravel-activitylog into The Vault's tamper-evident audit log.
 *
 * Every entry stores a sha256 of its own payload combined with the previous
 * entry's hash, so deleting or editing any row breaks the chain at that exact
 * point (see App\Services\Audit\ChainVerifier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropIndex(['hash']);
            $table->dropColumn([
                'ip', 'user_agent',
                'organization_id', 'project_id', 'previous_hash', 'hash',
            ]);
        });
    }
};
