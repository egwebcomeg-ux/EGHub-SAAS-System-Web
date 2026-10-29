<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company account of a corporate contract: every payment the company makes is a row of corporate_payments
 * (corporate_contracts.paid_amount stays as their sum, for the lists and reports). A contract is billed `prepaid`
 * (the company pays, then draws) or on `account` (it draws, then pays; credit_limit caps the debt, null = no cap).
 * The amount a contract already had as paid becomes its opening payment, so no figure is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_payments', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('corporate_contract_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('method', 12);                          // cash | transfer | cheque | instapay | card | other
            $t->string('reference', 60)->nullable();           // transfer / cheque / receipt number
            $t->date('paid_on');
            $t->string('note', 200)->nullable();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();         // entered by mistake: kept, not counted
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 200)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'corporate_contract_id']);
        });

        Schema::table('corporate_contracts', function (Blueprint $t) {
            $t->string('billing', 10)->default('prepaid');     // prepaid | account
            $t->decimal('credit_limit', 12, 2)->nullable();    // account billing: the most the company may owe
        });

        $now = now();
        DB::table('corporate_contracts')->where('paid_amount', '>', 0)->orderBy('id')->get(['id', 'tenant_id', 'paid_amount', 'created_at', 'created_by'])
            ->each(fn ($c) => DB::table('corporate_payments')->insert([
                'tenant_id' => $c->tenant_id, 'corporate_contract_id' => $c->id, 'amount' => $c->paid_amount, 'method' => 'other',
                'paid_on' => substr((string) $c->created_at, 0, 10), 'note' => 'مدفوع قبل دفتر الدفعات', 'received_by' => $c->created_by,
                'created_at' => $now, 'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::table('corporate_contracts', function (Blueprint $t) {
            $t->dropColumn(['billing', 'credit_limit']);
        });
        Schema::dropIfExists('corporate_payments');
    }
};
