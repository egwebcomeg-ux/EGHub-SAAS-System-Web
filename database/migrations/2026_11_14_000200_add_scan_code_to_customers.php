<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The code the guest's card QR carries (customers.scan_code): the counter finds the guest by it, and opened in a
 * browser it shows nothing but itself. The card link (card_token) is the guest's key to their account and balance,
 * so it is no longer printed as a QR anyone can photograph. Made when the card is first shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('scan_code', 20)->nullable();
            $t->unique(['tenant_id', 'scan_code']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'scan_code']);
            $t->dropColumn('scan_code');
        });
    }
};
