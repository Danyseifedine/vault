<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Reveal PINs - an organization-level instrument issued by whoever
         * holds `pins.manage`, never chosen by the user themselves.
         */
        Schema::create('pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pin_hash');
            $table->string('label')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['user_id', 'organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pins');
    }
};
