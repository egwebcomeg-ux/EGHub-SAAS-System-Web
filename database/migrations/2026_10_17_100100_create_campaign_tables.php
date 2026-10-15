<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing campaigns on WhatsApp to a segment of the guest list, with an optional discount coupon the guest types
 * (or opens from the campaign link) when ordering. Guests can be excluded with customers.whatsapp_opt_out.
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
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('code', 30);
            $t->string('type', 10)->default('percent');    // percent | amount
            $t->decimal('value', 10, 2);
            $t->decimal('max_discount', 10, 2)->nullable(); // cap of a percent coupon
            $t->decimal('min_order', 10, 2)->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->unsignedInteger('max_uses')->nullable();    // null = unlimited
            $t->unsignedInteger('uses')->default(0);
            $t->boolean('once_per_customer')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('campaigns', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name', 100);
            $t->string('channel', 10)->default('whatsapp');
            $t->string('segment', 12)->default('all');      // all | inactive | visits | birthday | loyalty | spent
            $t->decimal('segment_value', 10, 2)->nullable(); // N days / N visits / X EGP
            $t->text('message');
            $t->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('scheduled'); // scheduled | sending | sent | stopped | cancelled
            $t->string('stop_reason', 20)->nullable();      // no_credits
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedBigInteger('cursor')->default(0);   // last customer id handled (batches of 50)
            $t->unsignedInteger('sent_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0); // opted out
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->boolean('whatsapp_opt_out')->default(false)->after('marketing_opt_in');
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('coupon_id')->nullable()->after('discount')->constrained()->nullOnDelete();
            $t->string('coupon_code', 30)->nullable()->after('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('coupon_id');
            $t->dropColumn('coupon_code');
        });
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('whatsapp_opt_out'));
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('coupons');
    }
};
