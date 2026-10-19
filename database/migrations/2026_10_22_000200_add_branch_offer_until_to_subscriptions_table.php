<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Launch offer "the second branch free for the first year" (App\Billing\LaunchOffer): the date it ends for this
     * subscription, set once when it subscribes to the Complete plan while the offer runs. Kept per subscription so
     * switching the offer off later never takes it back from those who already got it.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('branch_offer_until')->nullable()->after('current_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('branch_offer_until');
        });
    }
};
