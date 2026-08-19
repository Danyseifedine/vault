<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personal vault: credentials that belong to a person, not to a project.
 *
 * Everything here is owner-scoped with NO administrative override - not for
 * the organization owner, not for anyone. That is the whole point of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('personal_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_group_id')->nullable()->constrained()->nullOnDelete();
            // A group can freely mix the two: "my VPS" holding a username, a
            // password and the .pem file together.
            $table->string('type')->default('secret');
            $table->string('name');
            // Null for file items - their contents live in encrypted storage.
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'personal_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_secrets');
        Schema::dropIfExists('personal_groups');
    }
};
