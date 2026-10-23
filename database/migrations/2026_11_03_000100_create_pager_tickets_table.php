<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest pager (plan feature pager, App\Services\Orders\Pager): the guest's phone rings when staff call them.
 *
 *  - pager_tickets: the standalone pager station for places that take orders on another POS. Staff type the order
 *    number (or take the next one), the guest scans the ticket's QR, staff call and close it. A number is unique per
 *    branch and day; a ticket nobody closed expires after the hours the restaurant set.
 *  - orders.pager_*: the same pager on a real order (QR on the receipt): how many times it was called, the last call,
 *    when the guest's page was last open and when the guest said they picked it up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pager_tickets', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('number');
            $t->date('service_date');
            $t->string('token', 24)->unique();
            $t->string('status', 12)->default('waiting'); // waiting | called | picked_up | expired
            $t->timestamp('first_called_at')->nullable(); // the wait in the reports: opened → first call
            $t->timestamp('called_at')->nullable();       // the last call (what staff see on the queue)
            $t->unsignedSmallInteger('call_count')->default(0);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('picked_up_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'branch_id', 'service_date', 'number'], 'pager_tickets_branch_day_number_unique');
            $t->index(['tenant_id', 'branch_id', 'status'], 'pager_tickets_branch_status_index');
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedSmallInteger('pager_calls')->default(0);
            $t->timestamp('pager_called_at')->nullable();
            $t->timestamp('pager_seen_at')->nullable();
            $t->timestamp('pager_picked_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['pager_calls', 'pager_called_at', 'pager_seen_at', 'pager_picked_up_at']);
        });
        Schema::dropIfExists('pager_tickets');
    }
};
