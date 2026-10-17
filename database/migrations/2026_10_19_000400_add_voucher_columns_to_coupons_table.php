<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Discount vouchers on the loyalty card: a coupon can be held by one guest (only that mobile may use it), belong to
 * a printed batch of single-use codes, start on a date, and discount only some categories or items. Orders keep
 * the share of their discount that came from the coupon (receipt line, voucher usage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('source', 12)->default('campaign')->after('code'); // campaign | automation | voucher | batch
            $t->string('batch', 40)->nullable()->after('source');
            $t->string('note', 200)->nullable()->after('batch');
            $t->timestamp('starts_at')->nullable()->after('min_order');
            $t->json('qualifying')->nullable()->after('starts_at');     // {categories: [ids], items: [ids]}
            $t->index(['tenant_id', 'batch']);
        });

        DB::table('coupons')->whereIn('id', DB::table('campaign_automations')->whereNotNull('coupon_id')->select('coupon_id'))
            ->update(['source' => 'automation']);

        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('coupon_discount', 10, 2)->default(0)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('coupon_discount'));
        Schema::table('coupons', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'batch']);
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['source', 'batch', 'note', 'starts_at', 'qualifying']);
        });
    }
};
