<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emptying the activity log became its own permission.
 *
 * Same shape as the migration that handed out `audit.view-all`: a new
 * permission is one nobody holds yet, including the creators of organizations
 * that already exist and were seeded every permission there was at the time.
 * This puts it where the seeding would have, and nowhere else.
 *
 * It is deliberately NOT given to anyone but a creator. `audit.wipe` is the
 * only permission in the product that destroys evidence, and it should have to
 * be handed over on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        $creators = DB::table('organizations')
            ->join('organization_user', function ($join) {
                $join->on('organization_user.organization_id', '=', 'organizations.id')
                    ->on('organization_user.user_id', '=', 'organizations.created_by');
            })
            ->whereNotNull('organizations.created_by')
            ->get(['organizations.id as organization_id', 'organizations.created_by as user_id']);

        foreach ($creators as $creator) {
            $key = [
                'user_id' => $creator->user_id,
                'organization_id' => $creator->organization_id,
                'project_id' => null,
                'environment_id' => null,
                'permission' => Permission::WipeActivity->value,
            ];

            // A null in a `where` never matches, so the scope columns are
            // asked for as nulls explicitly.
            $held = DB::table('grants')
                ->where('user_id', $key['user_id'])
                ->where('organization_id', $key['organization_id'])
                ->where('permission', $key['permission'])
                ->whereNull('project_id')
                ->whereNull('environment_id')
                ->exists();

            if ($held) {
                continue;
            }

            DB::table('grants')->insert($key + [
                'created_by' => $creator->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('grants')
            ->where('permission', Permission::WipeActivity->value)
            ->delete();
    }
};
