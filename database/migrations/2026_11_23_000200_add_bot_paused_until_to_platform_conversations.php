<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The automatic answer of «محادثات واتساب» stays quiet in a conversation until this time (the team has it). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_conversations', function (Blueprint $t) {
            $t->timestamp('bot_paused_until')->nullable()->after('last_inbound_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_conversations', function (Blueprint $t) {
            $t->dropColumn('bot_paused_until');
        });
    }
};
