<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds / returns of paid POS orders: the whole order or some lines (quantities), with the reason, the manager who
 * approved, how the money went back (methods: {cash: 50, corporate: 100}) and the shift it left. The order keeps the
 * total refunded and its refund status; each line how many were returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
            $t->boolean('is_full')->default(false);
            $t->decimal('amount', 10, 2);
            $t->string('method', 20);                         // the main method, or "split"
            $t->json('methods');                              // {method: amount}
            $t->json('lines')->nullable();                    // [{order_item_id, name, qty, amount}]
            $t->string('reason', 200);
            $t->boolean('restocked')->default(false);
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            // named: the default name is longer than MySQL's 64 characters
            $t->index(['tenant_id', 'branch_id', 'created_at'], 'order_refunds_branch_day_idx');
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('refunded_amount', 10, 2)->default(0)->after('total');
            $t->string('refund_status', 10)->nullable()->after('refunded_amount'); // partial | full
        });

        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedSmallInteger('refunded_qty')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropColumn('refunded_qty');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['refunded_amount', 'refund_status']);
        });
        Schema::dropIfExists('order_refunds');
    }
};
