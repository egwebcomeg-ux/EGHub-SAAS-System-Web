<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory pack: ingredients with an average cost, stock per branch, a recipe per menu item (lines may depend
 * on a chosen option, e.g. "large" adds 100 ml milk) and a movement log. Orders deduct stock; cancelling restores it.
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
        Schema::create('ingredients', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name');
            $t->string('unit', 10);                                // g, kg, ml, l, pcs
            $t->decimal('cost_per_unit', 12, 4)->default(0);       // weighted average of purchases
            $t->decimal('min_stock', 12, 3)->default(0);           // low-stock alert under this, per branch
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('ingredient_stocks', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->decimal('quantity', 14, 3)->default(0);            // may go negative when sales outrun the count
            $t->timestamps();
            $t->unique(['ingredient_id', 'branch_id']);
        });

        Schema::create('recipe_lines', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_option_id')->nullable()->constrained()->cascadeOnDelete(); // only when this option is chosen
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->decimal('quantity', 12, 3);
            $t->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12);                                // purchase, sale, return, waste, count
            $t->decimal('quantity', 14, 3);                        // signed: + in, - out
            $t->decimal('unit_cost', 12, 4)->default(0);
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note', 200)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'branch_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('stock_deducted_at')->nullable()->after('loyalty_applied_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('stock_deducted_at'));
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('recipe_lines');
        Schema::dropIfExists('ingredient_stocks');
        Schema::dropIfExists('ingredients');
    }
};
