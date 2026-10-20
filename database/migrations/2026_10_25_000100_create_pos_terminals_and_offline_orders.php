<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS terminals and offline sales. A terminal is a named till of a branch ("كاشير 1", code K1) that one device
 * claims (the device keeps a token, only its hash is stored here); one open shift per terminal. A sale made while
 * the internet was down is kept on the device and synced later: it keeps its temporary reference (OFF-K1-3), the
 * time it really happened (created_offline_at) and is flagged for review when the menu changed in between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_terminals', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('code', 8);                              // K1, K2 … (in the offline reference)
            $t->boolean('is_active')->default(true);
            $t->string('device_token', 64)->nullable();         // sha256 of the claiming device's token
            $t->string('device_label', 120)->nullable();        // browser of the device, for the manager
            $t->timestamp('claimed_at')->nullable();
            $t->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_seen_at')->nullable();
            $t->unsignedInteger('offline_seq')->default(0);     // highest OFF-{code}-{n} synced, so a reset device goes on from it
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::table('shifts', function (Blueprint $t) {
            $t->foreignId('terminal_id')->nullable()->after('user_id')->constrained('pos_terminals')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('terminal_id')->nullable()->after('shift_id')->constrained('pos_terminals')->nullOnDelete();
            $t->string('offline_ref', 24)->nullable()->after('terminal_id');
            $t->timestamp('created_offline_at')->nullable()->after('offline_ref');
            $t->timestamp('synced_at')->nullable()->after('created_offline_at');
            $t->boolean('needs_review')->default(false)->after('synced_at');
            $t->json('review_notes')->nullable()->after('needs_review');
            // how the order was paid, line by line ([{method, amount, tendered?}]): a split payment, an offline sale
            $t->json('payments')->nullable()->after('payment_method');
            $t->unique(['tenant_id', 'offline_ref'], 'orders_tenant_offline_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropUnique('orders_tenant_offline_ref_unique');
            $t->dropConstrainedForeignId('terminal_id');
            $t->dropColumn(['offline_ref', 'created_offline_at', 'synced_at', 'needs_review', 'review_notes', 'payments']);
        });
        Schema::table('shifts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('terminal_id');
        });
        Schema::dropIfExists('pos_terminals');
    }
};
