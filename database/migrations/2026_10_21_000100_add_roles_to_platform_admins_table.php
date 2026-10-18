<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform admin team (App\Support\AdminPermissions): each admin has one role, optional extra permissions and can be
     * switched off. Kept on platform_admins on purpose: the spatie roles tables belong to restaurant staff only.
     * Everyone who is an admin today keeps full access (owner).
     */
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->string('role', 20)->default('owner')->after('email');
            $table->json('permissions')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('permissions');
        });

        DB::table('platform_admins')->update(['role' => 'owner', 'is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropColumn(['role', 'permissions', 'is_active']);
        });
    }
};
