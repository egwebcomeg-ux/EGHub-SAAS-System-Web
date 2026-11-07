<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The sales phone's FCM token: «اتصل» on the computer places the call on the phone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_call_devices', function (Blueprint $table) {
            $table->string('push_token', 512)->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('crm_call_devices', function (Blueprint $table) {
            $table->dropColumn('push_token');
        });
    }
};
