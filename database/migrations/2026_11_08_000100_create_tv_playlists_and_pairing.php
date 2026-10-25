<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EgHub screens: a playlist per TV screen (menu, image, video, offer and ready-order slides, each with its own hours),
 * the restaurant's screen media library, and pairing a device with a 6-digit code instead of typing a link. A paired
 * device keeps a long random token; only its SHA-256 hash is stored (tv_screens.device_token_hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tv_screens', function (Blueprint $t) {
            $t->string('orientation', 10)->default('landscape');  // landscape | portrait
            $t->string('language', 5)->default('');               // '' = the restaurant's default, ar, en, both
            $t->string('ticker_text', 300)->nullable();             // a bottom ticker on every slide
            $t->json('days')->nullable();                            // the whole playlist's hours: [0..6] Sunday=0, null = every day
            $t->time('starts_at')->nullable();
            $t->time('ends_at')->nullable();
            $t->string('device_token_hash', 64)->nullable()->unique();
            $t->timestamp('paired_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->json('device_info')->nullable();                    // app version, device type, resolution (heartbeat)
            $t->timestamp('reload_requested_at')->nullable();
        });

        Schema::create('tv_media', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('kind', 10);                     // image | video
            $t->string('path');                         // public disk: tenants/{id}/screens/{uuid}.webp|mp4|webm
            $t->string('name', 120)->default('');       // the uploaded file's name, for the owner only
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamps();
        });

        Schema::create('tv_slides', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('tv_screen_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10);                                  // menu | image | video | offer | ready
            $t->unsignedInteger('sort_order')->default(0);
            $t->unsignedSmallInteger('duration')->default(15);       // seconds; 0 on a video = until it ends
            $t->json('category_ids')->nullable();                    // menu: null = every category
            $t->string('layout', 10)->default('list');              // menu: list | grid | featured
            $t->unsignedTinyInteger('columns')->default(3);
            $t->boolean('show_images')->default(true);
            $t->foreignId('item_id')->nullable()->constrained('menu_items')->nullOnDelete();   // menu featured: the item
            $t->foreignId('media_id')->nullable()->constrained('tv_media')->nullOnDelete();    // image / video
            $t->foreignId('side_media_id')->nullable()->constrained('tv_media')->nullOnDelete(); // menu: media beside it (split)
            $t->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();              // offer: null = every running offer
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();             // ready: null = the screen's branch
            $t->json('days')->nullable();
            $t->time('starts_at')->nullable();
            $t->time('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['tv_screen_id', 'sort_order']);
        });

        // platform rows: a code shown on a device that is not linked to any restaurant yet
        Schema::create('tv_pairings', function (Blueprint $t) {
            $t->id();
            $t->string('code_hash', 64)->index();       // HMAC of the 6-digit code
            $t->string('secret_hash', 64)->unique();    // SHA-256 of the secret only the device that asked holds
            $t->string('tenant_id')->nullable();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('tv_screen_id')->nullable()->constrained()->cascadeOnDelete();
            $t->text('device_token')->nullable();       // encrypted, handed to the device once, then wiped
            $t->string('ip', 45)->default('');
            $t->string('user_agent', 255)->default('');
            $t->timestamp('expires_at')->index();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('collected_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_pairings');
        Schema::dropIfExists('tv_slides');
        Schema::dropIfExists('tv_media');
        Schema::table('tv_screens', function (Blueprint $t) {
            $t->dropUnique(['device_token_hash']);
            $t->dropColumn(['orientation', 'language', 'ticker_text', 'days', 'starts_at', 'ends_at', 'device_token_hash',
                'paired_at', 'last_seen_at', 'device_info', 'reload_requested_at']);
        });
    }
};
