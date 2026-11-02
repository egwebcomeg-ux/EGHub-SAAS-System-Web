<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The automatic answer handed over and no person answered yet: it keeps answering questions until someone does. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_conversations', function (Blueprint $t) {
            $t->timestamp('waiting_for_team_since')->nullable()->after('bot_paused_until');
        });
    }

    public function down(): void
    {
        Schema::table('platform_conversations', function (Blueprint $t) {
            $t->dropColumn('waiting_for_team_since');
        });
    }
};
