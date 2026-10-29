<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers and their purchase invoices. A purchase is typed as a draft, then posted: each line is received into the
 * branch stock (a `purchase` movement) and moves the ingredient's weighted-average cost. A manager may cancel a posted
 * purchase, which takes the goods out again; it stays on the supplier statement crossed out.
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
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('notes', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->date('purchased_on');
            $t->string('reference', 60)->nullable();            // the supplier's invoice number
            $t->string('status', 10)->default('draft');         // draft | posted | cancelled
            $t->decimal('total', 12, 2)->default(0);
            $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();          // posted by mistake: goods taken out again, kept on the statement
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 200)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'supplier_id', 'purchased_on']);
        });

        Schema::create('purchase_lines', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->decimal('quantity', 14, 3);
            $t->decimal('unit_cost', 12, 4);
            $t->decimal('total', 12, 2);
            $t->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignId('purchase_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_id'));
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
