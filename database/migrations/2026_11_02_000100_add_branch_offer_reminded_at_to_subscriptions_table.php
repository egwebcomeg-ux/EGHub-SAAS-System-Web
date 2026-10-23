<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the owners were told the launch offer's free branch ends soon (App\Billing\Lifecycle), so that reminder
     * is sent once per subscription.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('branch_offer_reminded_at')->nullable()->after('branch_offer_until');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('branch_offer_reminded_at');
        });
    }
};
