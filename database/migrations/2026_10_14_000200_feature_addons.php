<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-ons can now unlock features, not only raise limits: the plans stay a simple QR menu for small cafes, and
 * operations (POS, kitchen display, call center, inventory) are sold on top of any plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addon_prices', function (Blueprint $t) {
            $t->string('name_en')->default('')->after('name');
            $t->string('description')->default('')->after('name_en');
            $t->string('description_en')->default('')->after('description');
            $t->string('type', 10)->default('limit')->after('key');   // limit (adds to a limit) | feature (unlocks features)
            $t->json('features')->nullable()->after('type');          // feature keys a "feature" add-on unlocks
            $t->integer('sort_order')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('addon_prices', function (Blueprint $t) {
            $t->dropColumn(['name_en', 'description', 'description_en', 'type', 'features', 'sort_order']);
        });
    }
};
