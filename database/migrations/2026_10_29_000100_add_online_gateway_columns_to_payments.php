<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online subscription payments (App\Billing\OnlinePayments, Kashier first):
 *  - merchant_ref: our order reference sent to the gateway and echoed back in its webhook (unique per attempt);
 *  - method: what the restaurant actually paid with (card, wallet, valu, souhoola, bank_installments ...);
 *  - installments: paid through an installment / buy-now-pay-later provider;
 *  - failure_reason: the gateway's reason when the attempt failed (or why a paid attempt needs review).
 * payments.reference keeps the gateway transaction id, as it does for manual transfer references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('merchant_ref', 64)->nullable()->unique()->after('idempotency_key');
            $t->string('method', 40)->nullable()->after('gateway');
            $t->boolean('installments')->default(false)->after('method');
            $t->string('failure_reason', 255)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique(['merchant_ref']);
            $t->dropColumn(['merchant_ref', 'method', 'installments', 'failure_reason']);
        });
    }
};
