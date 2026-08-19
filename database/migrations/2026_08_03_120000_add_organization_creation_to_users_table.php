<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Starting an organization is the one power a grant cannot express: a grant
 * row always belongs to an organization, and the one being created does not
 * exist yet. So it lives on the account, handed out from the command line -
 * the same place account bootstrap lives, because both are system-level acts
 * that no organization has authority over.
 *
 * Default false: an invited teammate can never start their own organization,
 * however much they were granted inside yours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_create_organizations')->default(false)->after('status');
        });

        // Everyone who already exists could create organizations a moment ago
        // (nothing gated it), so keep what they had rather than silently
        // taking it away. New accounts start without it.
        DB::table('users')->update(['can_create_organizations' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_create_organizations');
        });
    }
};
