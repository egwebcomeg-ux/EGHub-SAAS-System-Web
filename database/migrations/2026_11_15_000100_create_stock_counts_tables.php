<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock counts (جرد): a count sheet of one branch for all its ingredients or a chosen few. Staff type what is on the
 * shelf (a draft, saved and resumed); a manager posts it, which writes one `count` movement per difference. Each line
 * keeps the system quantity of the moment it was counted, so sales between counting and posting are not a loss.
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
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->string('status', 10)->default('draft');         // draft | posted (read-only)
            $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'branch_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->decimal('expected', 14, 3)->nullable();          // system quantity when it was counted
            $t->decimal('counted', 14, 3)->nullable();           // null = not counted, skipped when posting
            $t->timestamp('counted_at')->nullable();
            $t->decimal('adjustment', 14, 3)->nullable();        // what posting moved: counted - expected
            $t->decimal('unit_cost', 12, 4)->nullable();         // the ingredient's cost when posted
            $t->timestamps();
            $t->unique(['stock_count_id', 'ingredient_id']);
        });

        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignId('stock_count_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('stock_count_id'));
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
