<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The company's rep a batch of vouchers was handed to (name and mobile), written when the vouchers are made. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporate_vouchers', function (Blueprint $t) {
            $t->string('handed_to', 100)->nullable()->after('print_count');
            $t->string('handed_to_phone', 20)->nullable()->after('handed_to');
        });
    }

    public function down(): void
    {
        Schema::table('corporate_vouchers', function (Blueprint $t) {
            $t->dropColumn(['handed_to', 'handed_to_phone']);
        });
    }
};
