<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An archived plan is retired for good: never offered or assignable again (App\Models\Plan::scopeOffered).
     * A plan can be archived only while no live subscription or open invoice uses it (App\Models\Plan::blockers).
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('contact_sales');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
