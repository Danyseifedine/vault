<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apps are gone.
 *
 * They were a second axis of narrowing - a member restricted to the `mobile`
 * app saw only the variables assigned to it - and that axis is not wanted:
 * access now stops at the environment. The create migrations no longer build
 * these tables, so this only has work to do on databases that already ran them.
 *
 * The grant rows matter as much as the tables: `apps.manage` is no longer an
 * `App\Enums\Permission` case, and a leftover row would blow up the enum cast
 * the moment anything read that user's grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Children first - both carry a foreign key into `apps`.
        Schema::dropIfExists('app_variable');
        Schema::dropIfExists('app_user');
        Schema::dropIfExists('apps');

        DB::table('grants')->where('permission', 'apps.manage')->delete();
    }

    /**
     * Deliberately one-way. Rebuilding empty tables would be a lie: the
     * assignments and restrictions they held are gone for good.
     */
    public function down(): void {}
};
