<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate voucher redemptions outside the POS ("صرف الفاوتشرات" screen): a use on a paper bill has no order
 * (order_id null) and keeps the bill amount, an optional reference and note. Every use of one payment shares a
 * batch (one receipt, one cancel). A manager can cancel a paper-bill use: refunded_by / refund_reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporate_voucher_redemptions', function (Blueprint $t) {
            $t->string('batch', 24)->nullable();
            $t->decimal('bill_amount', 10, 2)->nullable();     // paper bill: what the bill came to
            $t->string('reference', 60)->nullable();           // paper bill number
            $t->string('note', 200)->nullable();
            $t->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('refund_reason', 200)->nullable();
            // named: the default names are longer than MySQL's 64 characters
            $t->index(['tenant_id', 'batch'], 'cv_redemptions_tenant_batch_idx');
            $t->index(['tenant_id', 'branch_id', 'created_at'], 'cv_redemptions_branch_day_idx');
        });
    }

    public function down(): void
    {
        Schema::table('corporate_voucher_redemptions', function (Blueprint $t) {
            $t->dropIndex('cv_redemptions_tenant_batch_idx');
            $t->dropIndex('cv_redemptions_branch_day_idx');
            $t->dropConstrainedForeignId('refunded_by');
            $t->dropColumn(['batch', 'bill_amount', 'reference', 'note', 'refund_reason']);
        });
    }
};
