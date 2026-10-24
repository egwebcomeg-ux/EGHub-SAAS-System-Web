<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The guest's WhatsApp confirmation of a cash-on-delivery delivery order (App\Services\Orders\OrderConfirmation).
     * Only a badge on the order: the kitchen never waits for it.
     *  - confirm_token: random, unique across restaurants; the buttons' payloads and the fallback page carry it
     *  - confirm_status: pending | confirmed | edit | cancel_requested ("no reply" is pending past the setting's minutes)
     *  - confirm_via: whatsapp | link | phone (staff after a call)
     * whatsapp_messages gets the quick-reply buttons a message carries and the confirmation page link sent instead by
     * providers that cannot show buttons.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('confirm_token', 32)->nullable()->unique();
            $table->string('confirm_status', 20)->nullable();
            $table->string('confirm_via', 10)->nullable();
            $table->timestamp('confirm_sent_at')->nullable();
            $table->timestamp('confirm_replied_at')->nullable();
            $table->unsignedBigInteger('confirm_by')->nullable();
            $table->unsignedBigInteger('confirm_message_id')->nullable()->index();
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->json('buttons')->nullable()->after('body');
            $table->string('link', 500)->nullable()->after('buttons');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['buttons', 'link']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['confirm_token']);
            $table->dropIndex(['confirm_message_id']);
            $table->dropColumn(['confirm_token', 'confirm_status', 'confirm_via', 'confirm_sent_at', 'confirm_replied_at', 'confirm_by', 'confirm_message_id']);
        });
    }
};
