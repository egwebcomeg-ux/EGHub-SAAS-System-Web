<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reps of a company ("المناديب"): a company is added on its own, then each rep under it gets a balance of his own
 * (corporate_reps.balance, charged to the company when it is put: corporate_contracts.rep_value) and his vouchers are
 * issued out of that balance (corporate_vouchers.corporate_rep_id). Every move of the balance is a row of
 * corporate_rep_movements. A rep may also order on the company's account, outside the vouchers: an allowance kept on
 * one voucher of his own (corporate_vouchers.direct, linked to his mobile), charged to the company only as it is used
 * (corporate_contracts.direct_value follows the uses that stand).
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
        Schema::create('corporate_reps', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('corporate_contract_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('phone', 20)->nullable();
            $t->decimal('balance', 12, 2)->default(0);         // put for him and not yet on vouchers
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'corporate_contract_id']);
            $t->index(['tenant_id', 'phone']);
        });

        Schema::create('corporate_rep_movements', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('corporate_rep_id')->constrained()->cascadeOnDelete();
            $t->foreignId('corporate_contract_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);                            // topup | withdraw | issue | return
            $t->decimal('amount', 12, 2);                      // always positive; the kind says which way
            $t->decimal('balance_after', 12, 2);
            $t->string('note', 200)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'corporate_contract_id'], 'corp_rep_moves_tenant_contract_idx');
        });

        Schema::table('corporate_vouchers', function (Blueprint $t) {
            $t->foreignId('corporate_rep_id')->nullable()->after('corporate_contract_id')->constrained()->nullOnDelete();
            $t->boolean('direct')->default(false)->after('corporate_rep_id'); // the rep's allowance on the company's account
        });

        Schema::table('corporate_contracts', function (Blueprint $t) {
            $t->decimal('rep_value', 12, 2)->default(0);       // put on the reps' balances
            $t->decimal('direct_value', 12, 2)->default(0);    // the reps' orders on the company's account
        });
    }

    public function down(): void
    {
        Schema::table('corporate_contracts', function (Blueprint $t) {
            $t->dropColumn(['rep_value', 'direct_value']);
        });
        Schema::table('corporate_vouchers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('corporate_rep_id');
            $t->dropColumn('direct');
        });
        Schema::dropIfExists('corporate_rep_movements');
        Schema::dropIfExists('corporate_reps');
    }
};
