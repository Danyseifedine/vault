<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the starter-kit user into a Vault user.
 *
 * Accounts are created by invitation, not registration, so a user can exist
 * before they have a name or a password: the owner reserves the seat, the
 * person fills it in during onboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->string('status')->default('active')->after('email');
            $table->string('job_title')->nullable()->after('status');
            $table->json('stack')->nullable()->after('job_title');
            $table->timestamp('profile_completed_at')->nullable()->after('stack');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'job_title', 'stack', 'profile_completed_at']);
            $table->string('name')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
