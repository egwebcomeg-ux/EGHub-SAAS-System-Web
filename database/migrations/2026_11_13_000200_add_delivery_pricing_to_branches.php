<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a branch prices delivery (App\Services\Delivery\DeliveryPricing):
 *  - delivery_pricing: "zones" (the branch's delivery zones, drawn on the map or typed by hand; the default) or
 *    "distance" (straight-line distance from the branch pin, branches.lat / lng).
 *  - delivery_rings: the distance rings, [{km, fee, min, minutes}, ...] sorted by km: a point up to `km` away (and
 *    beyond the previous ring) pays that ring's fee; past the last ring the branch does not deliver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $t) {
            $t->string('delivery_pricing', 10)->default('zones')->after('lng');
            $t->json('delivery_rings')->nullable()->after('delivery_pricing');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $t) {
            $t->dropColumn(['delivery_pricing', 'delivery_rings']);
        });
    }
};
