<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company visits of a corporate contract. The company asks through its private link (corporate_contracts.portal_token,
 * at least booking_notice_hours ahead) or staff enter it; a manager confirms or declines. Confirming splits the rep's
 * budget: a meals voucher valid on the visit day only, on the rep's mobile, and a voucher with the rest for the
 * company (corporate_vouchers.corporate_booking_id, valid_from, a mode of their own); the budget is charged to the
 * company account (corporate_contracts.extra_value, on top of the printed vouchers). Closing the visit moves what
 * the meals voucher did not use onto the company's voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_bookings', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('corporate_contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('visit_at');
            $t->unsignedSmallInteger('party_size');
            $t->decimal('budget', 12, 2);
            $t->string('rep_name', 100);
            $t->string('rep_phone', 20);
            $t->string('notes', 500)->nullable();
            $t->string('source', 10);                          // portal | staff
            $t->string('status', 12)->default('requested');    // requested | confirmed | declined | cancelled | closed
            $t->decimal('meals_amount', 12, 2)->nullable();    // confirmed: the part spent on meals on the visit day
            $t->decimal('voucher_amount', 12, 2)->nullable();  // confirmed: the rest, as a voucher for the company
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decline_reason', 200)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'visit_at']);
            $t->index(['tenant_id', 'corporate_contract_id']);
        });

        Schema::table('corporate_contracts', function (Blueprint $t) {
            $t->string('portal_token', 24)->nullable()->unique();
            $t->unsignedSmallInteger('booking_notice_hours')->default(24);
            $t->decimal('extra_value', 12, 2)->default(0);     // charged for visits, on top of quantity × voucher_value
        });

        Schema::table('corporate_vouchers', function (Blueprint $t) {
            $t->foreignId('corporate_booking_id')->nullable()->constrained()->nullOnDelete();
            $t->date('valid_from')->nullable();                // a visit's meals voucher works on the visit day only
            $t->string('mode', 10)->nullable();                // null = the contract's mode
        });
    }

    public function down(): void
    {
        Schema::table('corporate_vouchers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('corporate_booking_id');
            $t->dropColumn(['valid_from', 'mode']);
        });
        Schema::table('corporate_contracts', function (Blueprint $t) {
            $t->dropUnique(['portal_token']);
            $t->dropColumn(['portal_token', 'booking_notice_hours', 'extra_value']);
        });
        Schema::dropIfExists('corporate_bookings');
    }
};
