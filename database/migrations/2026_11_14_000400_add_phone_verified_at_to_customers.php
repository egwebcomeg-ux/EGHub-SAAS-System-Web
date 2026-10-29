<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the guest proved the mobile is theirs (a code sent to it, or a message from it to the EgHub WhatsApp number).
 * A restaurant may require it before an online order (setting online_verify_phone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->timestamp('phone_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn('phone_verified_at');
        });
    }
};
