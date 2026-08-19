<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared vault: the credentials a team holds in common that are not `.env`
 * variables - a server password, an SSH key, a signing certificate, a bank
 * letter. Same discipline as everything else here (encrypted at rest, masked
 * in lists, revealed only through one guarded path), but scoped to an
 * organization instead of a person or a project.
 *
 * Deliberately NOT the personal vault with a foreign key swapped: personal
 * items answer to their owner alone and have no permissions at all, while
 * these answer to `shared.view` / `shared.reveal` / `shared.manage` and are
 * read by other people. Two different security models, two tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('shared_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // A group can freely mix the two: "staging server" holding a
            // password and the .pem that goes with it.
            $table->foreignId('shared_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('secret');
            $table->string('name');
            // Null for file items - their contents live in encrypted storage.
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            // Who added it is history, not a dependency: deleting that account
            // must not take the team's credential with it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'shared_group_id']);
        });

        // Existing organizations: the creator holds every permission there was
        // when they made it, so hand them the new three as well. Everyone else
        // starts with none of them, which is the point.
        $creators = DB::table('organizations')
            ->join('organization_user', function ($join) {
                $join->on('organization_user.organization_id', '=', 'organizations.id')
                    ->on('organization_user.user_id', '=', 'organizations.created_by');
            })
            ->whereNotNull('organizations.created_by')
            ->get(['organizations.id as organization_id', 'organizations.created_by as user_id']);

        foreach ($creators as $creator) {
            foreach (self::SEEDED as $permission) {
                $held = DB::table('grants')
                    ->where('user_id', $creator->user_id)
                    ->where('organization_id', $creator->organization_id)
                    ->where('permission', $permission)
                    ->whereNull('project_id')
                    ->whereNull('environment_id')
                    ->exists();

                if ($held) {
                    continue;
                }

                DB::table('grants')->insert([
                    'user_id' => $creator->user_id,
                    'organization_id' => $creator->organization_id,
                    'project_id' => null,
                    'environment_id' => null,
                    'permission' => $permission,
                    'created_by' => $creator->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_secrets');
        Schema::dropIfExists('shared_groups');

        DB::table('grants')->whereIn('permission', self::SEEDED)->delete();
    }

    private const SEEDED = [
        Permission::ViewSharedVault->value,
        Permission::RevealSharedVault->value,
        Permission::ManageSharedVault->value,
    ];
};
