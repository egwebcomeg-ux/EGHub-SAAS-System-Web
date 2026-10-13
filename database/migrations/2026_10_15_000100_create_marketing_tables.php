<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing (ideas from Scratch Cafe): timed offers, scheduled popups, "goes well with" pairings and combos.
 * Branch targeting follows the same rule everywhere: branch_id null = every branch.
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
        // "Happy hour": X% off a category (or the whole menu) on chosen days between two times (may cross midnight).
        Schema::create('offers', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('title');
            $t->string('title_en')->default('');
            $t->unsignedTinyInteger('discount_percent');
            $t->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete(); // null = whole menu
            $t->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $t->json('days')->nullable();                  // [0..6] Sunday=0; null = every day
            $t->time('starts_at')->nullable();             // null = all day
            $t->time('ends_at')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('popups', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('type', 10)->default('image');      // image | item
            $t->string('title')->default('');
            $t->string('title_en')->default('');
            $t->text('body')->nullable();
            $t->text('body_en')->nullable();
            $t->string('image')->nullable();
            $t->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('button_text')->default('');
            $t->string('link_url')->nullable();
            $t->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('frequency', 10)->default('session'); // always | session | day | once
            $t->unsignedTinyInteger('delay_seconds')->default(2);
            $t->unsignedInteger('views')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // "Goes well with": suggested after the guest adds the item.
        Schema::create('item_pairings', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('paired_item_id')->constrained('menu_items')->cascadeOnDelete();
            $t->unique(['menu_item_id', 'paired_item_id']);
        });

        // A combo is a menu item made of other items; the saving shown = sum of parts − combo price.
        Schema::create('combo_items', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('combo_id')->constrained('menu_items')->cascadeOnDelete();
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('quantity')->default(1);
        });

        Schema::table('order_items', function (Blueprint $t) {
            $t->decimal('original_price', 10, 2)->nullable()->after('unit_price'); // unit price before a timed offer
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('original_price'));
        foreach (['combo_items', 'item_pairings', 'popups', 'offers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
