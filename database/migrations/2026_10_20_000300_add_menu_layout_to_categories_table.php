<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** How one category shows its items on the public menu (App\Support\MenuLayouts); null = the menu setting. */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('menu_layout', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('menu_layout');
        });
    }
};
