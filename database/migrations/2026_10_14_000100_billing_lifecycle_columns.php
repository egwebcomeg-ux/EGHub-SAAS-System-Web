<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Columns the subscription lifecycle needs: what the current period was worth, and reminder bookkeeping. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->decimal('period_value', 10, 2)->nullable()->after('current_period_end'); // paid for this period (proration)
            $t->timestamp('reminded_at')->nullable()->after('grace_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropColumn(['period_value', 'reminded_at']);
        });
    }
};
