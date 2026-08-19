<?php

use App\Services\Variables\LiveKeyIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A variable is DEFINED once per project and holds one VALUE per environment.
 *
 * That split is what makes completeness natural: an environment with no value
 * row for a defined variable simply *is* the gap the dashboard reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->text('description')->nullable();
            $table->string('sensitivity')->default('sensitive');
            $table->string('change_safety')->default('safe');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        // Uniqueness applies to LIVE variables only - see LiveKeyIndex. A plain
        // unique(project_id, key) would let a soft delete burn that key forever.
        foreach (LiveKeyIndex::create(DB::getDriverName()) as $statement) {
            DB::statement($statement);
        }

        Schema::create('variable_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            // Encrypted at rest via the model cast: a database dump on its own
            // leaks nothing.
            $table->text('value');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->unique(['variable_id', 'environment_id']);
        });

        Schema::create('variable_value_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variable_value_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            // History is as sensitive as the present - encrypted too.
            $table->text('value');
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->unique(['variable_value_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variable_value_versions');
        Schema::dropIfExists('variable_values');
        Schema::dropIfExists('variables');
    }
};
