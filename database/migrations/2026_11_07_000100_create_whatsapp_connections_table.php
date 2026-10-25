<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant's own WhatsApp Business number on the Cloud API (App\Models\WhatsAppConnection): one per restaurant.
 *  - access_token / app_secret: the system user's permanent token and the restaurant app's secret, encrypted
 *    (Laravel encrypted casts, so the columns are text); never shown back, logged or audited
 *  - waba_id / phone_number_id: Meta's public ids (they arrive in clear in every webhook); phone_number_id is unique
 *    across restaurants because the inbound webhook is routed by it
 *  - webhook_key / verify_token: the restaurant's own callback URL /webhooks/whatsapp/{webhook_key} and its handshake
 *  - status: draft | connected | failed; templates: our message templates in its WABA, name => language => status
 * whatsapp_messages.channel records which number sent a message: platform (EgHub's, one credit) | own (the
 * restaurant's, billed by Meta to its own WABA, no credit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->unique();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('waba_id', 30);
            $t->string('phone_number_id', 30)->unique();
            $t->text('access_token');
            $t->text('app_secret')->nullable();
            $t->string('webhook_key', 32)->unique();
            $t->string('verify_token', 40);
            $t->string('display_phone', 30)->nullable();
            $t->string('verified_name', 120)->nullable();
            $t->string('name_status', 40)->nullable();
            $t->string('quality_rating', 20)->nullable();
            $t->string('status', 12)->default('draft');
            $t->string('last_error', 255)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('subscribed_at')->nullable();
            $t->timestamp('webhook_verified_at')->nullable();
            $t->timestamp('last_inbound_at')->nullable();
            $t->json('templates')->nullable();
            $t->timestamp('templates_checked_at')->nullable();
            $t->timestamps();
        });

        Schema::table('whatsapp_messages', function (Blueprint $t) {
            $t->string('channel', 10)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $t) {
            $t->dropColumn('channel');
        });
        Schema::dropIfExists('whatsapp_connections');
    }
};
