<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order & menu operations: timed sold-out and spicy level on items, dayparts on categories, and menu banners.
 * Allergens already live in menu_items.allergens (json); they only get a UI now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $t) {
            $t->timestamp('available_from')->nullable()->after('is_sold_out'); // sold out until then; null = until further notice
            $t->unsignedTinyInteger('spicy_level')->default(0)->after('allergens'); // 0 = not spicy … 3 = very hot
        });

        Schema::table('categories', function (Blueprint $t) {
            $t->time('visible_from')->nullable()->after('is_active');   // daypart, e.g. breakfast 07:00–12:00 (may cross midnight)
            $t->time('visible_until')->nullable()->after('visible_from');
            $t->json('visible_days')->nullable()->after('visible_until'); // [0..6] Sunday = 0; null = every day
        });

        // Swipeable picture strip under the public menu header (feature offers): a picture, an optional title and
        // an optional link to an item, a category or an address. At most 5 are live at a time.
        Schema::create('banners', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('image');
            $t->string('title')->default('');
            $t->string('title_en')->default('');
            $t->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('link_url')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn(['visible_from', 'visible_until', 'visible_days']));
        Schema::table('menu_items', fn (Blueprint $t) => $t->dropColumn(['available_from', 'spicy_level']));
    }
};
