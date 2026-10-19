<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate prepaid vouchers (feature `corporate_vouchers`): a company buys N vouchers of a value for its staff
 * (a contract), each voucher is a printed card with a code and a QR, redeemed at the POS. Every use is a row of
 * corporate_voucher_redemptions (the company statement); a cancelled order gives the value back (refunded_at).
 * orders.corporate_amount is the part of an order paid by vouchers (the rest is paid another way).
 * users.manager_pin (hashed) lets a manager approve an exception on the cashier's device.
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
        Schema::create('corporate_contracts', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name', 150);                           // the company
            $t->string('contact_name', 100)->nullable();
            $t->string('contact_phone', 20)->nullable();
            $t->string('reference', 60)->nullable();           // contract / invoice number
            $t->string('prefix', 8);                           // of the serials: EVA-0001
            $t->decimal('voucher_value', 10, 2);
            $t->unsignedInteger('quantity');
            $t->date('expires_on');
            $t->string('mode', 10)->default('single');         // single | balance
            $t->json('branch_ids')->nullable();                // null = every branch
            $t->json('days')->nullable();                      // 0 (Sunday) … 6, null = every day
            $t->string('hours_from', 5)->nullable();           // HH:MM, both null = any time
            $t->string('hours_to', 5)->nullable();
            $t->decimal('min_order', 10, 2)->nullable();
            $t->decimal('paid_amount', 12, 2)->default(0);
            $t->string('paid_status', 10)->default('unpaid');  // unpaid | partial | paid
            $t->text('notes')->nullable();
            $t->string('status', 10)->default('active');       // active | paused | void
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('corporate_vouchers', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('corporate_contract_id')->constrained()->cascadeOnDelete();
            $t->string('serial', 20);
            $t->string('code', 12);
            $t->decimal('value', 10, 2);
            $t->decimal('balance', 10, 2);
            $t->string('status', 10)->default('active');       // active | used | void
            $t->date('expires_on');
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('linked_at')->nullable();
            $t->string('pending_phone', 20)->nullable();       // the guest asked to link it; the cashier confirms
            $t->timestamp('pending_at')->nullable();
            $t->unsignedInteger('print_count')->default(0);
            $t->timestamp('printed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'serial']);
            $t->index(['tenant_id', 'customer_id']);
        });

        Schema::create('corporate_voucher_redemptions', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('corporate_voucher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('corporate_contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 10, 2);                      // taken off the order
            $t->decimal('forfeited', 10, 2)->default(0);       // single mode: the rest of the value, lost
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();       // the cashier
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); // manager, for an exception
            $t->string('exception_reason', 200)->nullable();
            $t->timestamp('refunded_at')->nullable();          // the order was cancelled: the value went back
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'corporate_contract_id'], 'cv_redemptions_tenant_contract_idx'); // named: the default is longer than MySQL's 64 characters
            $t->index('order_id');
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('corporate_amount', 10, 2)->default(0);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('manager_pin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('manager_pin');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('corporate_amount');
        });
        Schema::dropIfExists('corporate_voucher_redemptions');
        Schema::dropIfExists('corporate_vouchers');
        Schema::dropIfExists('corporate_contracts');
    }
};
