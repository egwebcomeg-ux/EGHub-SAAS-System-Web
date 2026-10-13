<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Menu boards for TVs in the shop (Business): a secret link per screen that reloads itself when the menu changes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_screens', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); // null = first active branch
            $t->string('name');
            $t->string('token', 20)->unique();
            $t->json('category_ids')->nullable();                 // null = every category
            $t->string('layout', 10)->default('all');            // all = one page in columns, rotate = pages that take turns
            $t->unsignedTinyInteger('columns')->default(3);
            $t->unsignedSmallInteger('rotate_seconds')->default(12);
            $t->string('theme', 10)->default('dark');
            $t->boolean('show_images')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_screens');
    }
};
