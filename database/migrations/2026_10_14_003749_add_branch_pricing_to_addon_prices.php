<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * per_branch: the pack is priced per active branch (each branch after the first at a discount).
 * min_plan:   lowest plan code that may buy it (e.g. operations need at least "basic"); null = any plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addon_prices', function (Blueprint $table) {
            $table->boolean('per_branch')->default(false)->after('type');
            $table->string('min_plan', 30)->nullable()->after('per_branch');
        });
    }

    public function down(): void
    {
        Schema::table('addon_prices', function (Blueprint $table) {
            $table->dropColumn(['per_branch', 'min_plan']);
        });
    }
};
