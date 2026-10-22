<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the guest paid the driver at the door (cash, card on the driver's machine, InstaPay, mobile wallet): the
 * driver's cash settlement counts only the cash ones, the others are paid on the spot through the POS payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('delivery_payment_method', 10)->nullable()->after('cash_collected');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('delivery_payment_method');
        });
    }
};
