<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid wallet & gift cards (feature `wallet`). The balance lives on customers.credit_balance; every change is a
 * row of wallet_transactions (the ledger), so the sum of a customer's deltas always equals the balance. Top-ups and
 * gift-card sales taken at the POS are recorded in the cashier's shift as cash movements of their own kind.
 */
return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->string('tenant_id')->index();
        $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
    }

    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('code', 12)->unique();
            $t->decimal('amount', 10, 2);
            $t->decimal('balance', 10, 2);
            $t->foreignId('buyer_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->string('recipient_phone', 20)->nullable();
            $t->string('message', 200)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('status', 10)->default('active');      // active | redeemed | expired | void
            $t->foreignId('redeemed_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->timestamp('redeemed_at')->nullable();
            $t->string('payment_method', 20)->nullable();      // how the buyer paid at the POS
            $t->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->decimal('delta', 10, 2);                       // + credit, − debit
            $t->decimal('balance_after', 10, 2);
            $t->string('kind', 12);                            // topup | payment | refund | gift_redeem | adjustment
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('gift_card_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $t->string('method', 20)->nullable();              // top-ups: how the guest paid
            $t->string('note', 200)->default('');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'customer_id']);
            $t->index(['order_id', 'kind']);
        });

        // shift drawer: in | out | topup | gift, with the method for top-ups and gift cards (cash counts in the drawer)
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->string('type', 12)->change();
            $t->string('method', 20)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->dropColumn('method');
        });
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('gift_cards');
    }
};
