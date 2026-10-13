<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AI imports keep the stored paths of up to 5 photos as JSON: longer than a 255-char string. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_imports', fn (Blueprint $t) => $t->text('file')->change());
    }

    public function down(): void
    {
        Schema::table('menu_imports', fn (Blueprint $t) => $t->string('file')->change());
    }
};
