<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EgHub screens, second round:
 *  - screen groups (tv_groups) with one shared playlist: a slide belongs to a screen or to a group, and a screen in a
 *    group plays the group's playlist unless it keeps its own (own_playlist);
 *  - zones: a playlist may split the screen into a main area and a side area (zone_layout), each slide plays in one;
 *  - ready designs: a "promo" slide drawn by the player from a template with the brand, an item or free text;
 *  - urgent messages (tv_alerts) shown on the chosen screens within seconds, until a time or until cleared;
 *  - what each screen shows right now (now_showing), reported by the player;
 *  - a «امسح واطلب» QR in the corner of a screen (show_qr).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_groups', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('zone_layout', 10)->default('full');   // full | side | halves
            $t->timestamps();
        });

        Schema::table('tv_screens', function (Blueprint $t) {
            $t->foreignId('tv_group_id')->nullable()->constrained('tv_groups')->nullOnDelete();
            $t->boolean('own_playlist')->default(false);         // in a group but plays its own slides
            $t->string('zone_layout', 10)->default('full');
            $t->boolean('show_qr')->default(false);
            $t->json('now_showing')->nullable();                 // {main: slide id, side: slide id, at: unix}
        });

        Schema::table('tv_slides', function (Blueprint $t) {
            $t->dropForeign(['tv_screen_id']);
        });
        Schema::table('tv_slides', function (Blueprint $t) {
            $t->unsignedBigInteger('tv_screen_id')->nullable()->change();
            $t->foreign('tv_screen_id')->references('id')->on('tv_screens')->cascadeOnDelete();
            $t->foreignId('tv_group_id')->nullable()->after('tv_screen_id')->constrained('tv_groups')->cascadeOnDelete();
            $t->string('zone', 5)->default('main');              // main | side
            $t->string('template', 20)->nullable();              // promo: App\Support\ScreenDesigns key
            $t->json('options')->nullable();                     // promo: title, subtitle, price, was, bg, badge, ends_at
            $t->index(['tv_group_id', 'sort_order']);
        });

        Schema::create('tv_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('text', 200);
            $t->string('style', 10)->default('banner');          // banner | full
            $t->json('screen_ids');                              // the screens it shows on
            $t->timestamp('ends_at')->nullable();                 // null = until cleared
            $t->timestamp('cleared_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_alerts');
        Schema::table('tv_slides', function (Blueprint $t) {
            $t->dropIndex(['tv_group_id', 'sort_order']);
            $t->dropForeign(['tv_group_id']);
            $t->dropColumn(['tv_group_id', 'zone', 'template', 'options']);
        });
        Schema::table('tv_screens', function (Blueprint $t) {
            $t->dropForeign(['tv_group_id']);
            $t->dropColumn(['tv_group_id', 'own_playlist', 'zone_layout', 'show_qr', 'now_showing']);
        });
        Schema::dropIfExists('tv_groups');
    }
};
