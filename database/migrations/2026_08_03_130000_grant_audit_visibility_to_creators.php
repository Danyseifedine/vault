<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reading someone else's audit trail became its own permission.
 *
 * Grants are rows, so a new permission is simply a permission nobody holds -
 * including the people who created the organizations that already exist and
 * were seeded every permission there was at the time. This hands it to each
 * organization's creator, which is where the seeding would have put it, and
 * to nobody else: every other member reads their own trail until someone
 * grants them more.
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
                'permission' => Permission::ViewAllActivity->value,
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
            ->where('permission', Permission::ViewAllActivity->value)
            ->delete();
    }
};
