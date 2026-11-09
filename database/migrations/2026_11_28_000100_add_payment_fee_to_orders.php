<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a mobile-wallet payment added to the order (setting wallet_fee_percent): part of orders.total, shown on the receipt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('payment_fee', 10, 2)->default(0)->after('delivery_fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('payment_fee');
        });
    }
};
