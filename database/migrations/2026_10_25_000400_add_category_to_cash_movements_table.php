<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cash put into / taken out of the drawer gets a category: expense (مصروف), float top-up, or other. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->string('category', 12)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->dropColumn('category');
        });
    }
};
