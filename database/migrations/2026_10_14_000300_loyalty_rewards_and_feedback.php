<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rewards a guest earned on the loyalty card, guest feedback, and the "counted once" mark on orders. */
return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->string('tenant_id')->index();
        $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
    }

    public function up(): void
    {
        // One row per reward reached on a card (e.g. "30% off" at stamp 5, "free coffee" at stamp 10).
        Schema::create('loyalty_rewards', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('loyalty_card_id')->constrained()->cascadeOnDelete();
            $t->string('label');
            $t->string('type', 20)->default('free_item');  // free_item | discount_percent | discount_fixed
            $t->decimal('value', 10, 2)->nullable();
            $t->string('status', 10)->default('available'); // available | redeemed | expired
            $t->timestamp('redeemed_at')->nullable();
            $t->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['loyalty_card_id', 'status']);
        });

        Schema::create('feedback', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('rating');             // 1..5
            $t->text('comment')->nullable();
            $t->string('table_name', 40)->nullable();
            $t->string('ip_hash', 64)->default('');        // rate limiting without storing the address
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $t) {
            // set when the order counted for the customer (visit, spend, stamps/points); cleared again on reversal
            $t->timestamp('loyalty_applied_at')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('loyalty_applied_at'));
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('loyalty_rewards');
    }
};
