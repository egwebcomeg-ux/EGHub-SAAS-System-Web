<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp phone confirmation at signup: the owner sends one prepared message ("EGMENU <code>") from their own
 * number to the platform's WhatsApp; the inbound webhook matches sender + code and stamps phone_verified_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('phone_verified_at')->nullable()->after('phone');
            $t->string('phone_verification_code', 8)->nullable()->after('phone_verified_at');
            $t->index('phone_verification_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['phone_verification_code']);
            $t->dropColumn(['phone_verified_at', 'phone_verification_code']);
        });
    }
};
