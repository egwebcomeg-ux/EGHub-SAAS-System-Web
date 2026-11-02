<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team's inbox also takes the EgHub page's Messenger and Instagram: a person is one conversation per channel, and
 * `phone` holds the sender's id there (Meta's ids and message ids are longer than a mobile and a wamid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_conversations', function (Blueprint $t) {
            $t->string('channel', 12)->default('whatsapp')->after('id'); // whatsapp | messenger | instagram
            $t->string('phone', 64)->change();
            $t->dropUnique(['phone']);
            $t->unique(['channel', 'phone']);
        });

        Schema::table('platform_conversation_messages', function (Blueprint $t) {
            $t->string('provider_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('platform_conversation_messages', function (Blueprint $t) {
            $t->string('provider_id', 120)->nullable()->change();
        });

        Schema::table('platform_conversations', function (Blueprint $t) {
            $t->dropUnique(['channel', 'phone']);
            $t->string('phone', 20)->change();
            $t->unique('phone');
            $t->dropColumn('channel');
        });
    }
};
