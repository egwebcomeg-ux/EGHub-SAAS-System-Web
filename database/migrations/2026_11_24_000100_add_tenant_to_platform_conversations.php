<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The restaurant a conversation is about: its owner signed up with this mobile, and the bot helps them finish. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_conversations', function (Blueprint $t) {
            $t->string('tenant_id')->nullable()->after('channel')->index();
        });
    }

    public function down(): void
    {
        Schema::table('platform_conversations', function (Blueprint $t) {
            $t->dropIndex(['tenant_id']);
            $t->dropColumn('tenant_id');
        });
    }
};
