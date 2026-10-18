<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seasonal looks of the public menu (Ramadan, Eid, Christmas…: greeting, banner, logo, accent between two dates),
 * a short video per menu item (uploaded file or a YouTube / Instagram / TikTok link), and on orders the share of the
 * discount (or the free gift) the cart goal gave ("add X and get Y free").
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
        Schema::create('seasons', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name', 80);
            $t->string('preset', 12)->default('custom');   // ramadan | eid | christmas | summer | valentine | custom
            $t->date('starts_on');                          // restaurant's local dates, both inclusive
            $t->date('ends_on');
            $t->string('greeting', 120)->default('');
            $t->string('greeting_en', 120)->default('');
            $t->string('banner')->nullable();               // replaces the menu cover while the season runs
            $t->string('logo')->nullable();                 // alternate logo
            $t->string('accent', 7)->nullable();            // #RRGGBB, overrides the theme accent
            $t->boolean('decoration')->default(true);       // light CSS/SVG pattern of the preset
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'starts_on', 'ends_on']);
        });

        Schema::table('menu_items', function (Blueprint $t) {
            $t->string('video')->nullable()->after('image');        // tenants/{id}/videos/{uuid}.mp4|webm on the public disk
            $t->string('video_url', 300)->nullable()->after('video'); // YouTube / Instagram / TikTok link
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('goal_discount', 10, 2)->default(0)->after('coupon_discount');
            $t->string('goal_reward', 120)->nullable()->after('goal_discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['goal_discount', 'goal_reward']);
        });
        Schema::table('menu_items', function (Blueprint $t) {
            $t->dropColumn(['video', 'video_url']);
        });
        Schema::dropIfExists('seasons');
    }
};
