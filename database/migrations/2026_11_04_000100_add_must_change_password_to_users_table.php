<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A restaurant made by EgWeb for a prospect (App\Services\WelcomeKit): the owner signs in with a temporary
     * password and must pick their own before anything else. The temporary password is kept encrypted only until
     * then, so the welcome page can show it; any password change clears both (App\Models\User).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->text('temporary_password')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'temporary_password']);
        });
    }
};
