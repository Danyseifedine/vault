<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes spatie/laravel-tags to The Vault's hierarchy.
 *
 * Both null = organization-global · project_id only = project tag ·
 * environment_id set = environment tag. A variable may only be tagged with a
 * tag whose scope contains it (validated in App\Actions\Tags).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            $table->unsignedBigInteger('project_id')->nullable()->after('organization_id');
            $table->unsignedBigInteger('environment_id')->nullable()->after('project_id');

            $table->index(['organization_id', 'project_id', 'environment_id'], 'tags_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex('tags_scope_index');
            $table->dropColumn(['organization_id', 'project_id', 'environment_id']);
        });
    }
};
