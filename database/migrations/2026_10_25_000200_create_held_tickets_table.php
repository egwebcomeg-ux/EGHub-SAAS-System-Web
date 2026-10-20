<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Held POS tickets ("تعليق"): the cashier parks the current order with a name or a table note and any terminal of
 * the branch resumes it later. The ticket is the cart as the POS keeps it (item ids, options, quantities, notes);
 * prices are computed again when it is placed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_tickets', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('label', 80);
            $t->json('cart');
            $t->decimal('total', 10, 2)->default(0);
            $t->unsignedSmallInteger('items_count')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_tickets');
    }
};
