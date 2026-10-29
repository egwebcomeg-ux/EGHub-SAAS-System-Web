<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packaging materials (cups, lids, bags, boxes, straws …) live in the inventory as ingredients of kind `packaging`.
 * A recipe line may apply only when the order leaves the place (takeaway / delivery: the takeaway cup and its lid),
 * and per-order rules take packaging for every takeaway or delivery order (one bag, one sticker). Orders deduct
 * both with their recipes and give them back when cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $t) {
            $t->string('kind', 12)->default('ingredient')->after('name');   // ingredient | packaging
        });

        Schema::table('recipe_lines', function (Blueprint $t) {
            $t->boolean('takeaway_only')->default(false)->after('quantity'); // only for takeaway / delivery orders
        });

        Schema::create('packaging_rules', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->string('order_type', 10);                                    // takeaway | delivery
            $t->decimal('quantity', 12, 3);                                  // taken once per order of that type
            $t->timestamps();
            $t->unique(['ingredient_id', 'order_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_rules');
        Schema::table('recipe_lines', fn (Blueprint $t) => $t->dropColumn('takeaway_only'));
        Schema::table('ingredients', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
