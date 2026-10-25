<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kind of TV or device a screen plays on (App\Support\ScreenDevices key: samsung, lg, xiaomi-box, fire-tv…), so the
 * dashboard shows that device's own setup steps, and its size and distance from the guests, which set the player's
 * text size (App\Support\ScreenShape). A pairing row keeps the kind guessed from the device's user agent, to suggest
 * it when the owner types the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tv_screens', function (Blueprint $t) {
            $t->string('device_type', 30)->nullable();
            $t->string('screen_size', 10)->nullable();          // 32 | 43 | 50 | 55 | 65 | 75 | small (a tablet)
            $t->string('viewing_distance', 10)->nullable();     // near | mid | far
            $t->unsignedSmallInteger('text_scale')->nullable();  // percent set by hand; null = from size and distance
        });
        Schema::table('tv_pairings', function (Blueprint $t) {
            $t->string('device_type', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tv_pairings', function (Blueprint $t) {
            $t->dropColumn('device_type');
        });
        Schema::table('tv_screens', function (Blueprint $t) {
            $t->dropColumn(['device_type', 'screen_size', 'viewing_distance', 'text_scale']);
        });
    }
};
