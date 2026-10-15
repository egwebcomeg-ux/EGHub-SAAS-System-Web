<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra menu languages (feature extra_languages): names and descriptions of categories, items, option groups and
 * options in fr/it/de/... Arabic and English stay in the rows' own name / name_en columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_translations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('translatable_type', 20); // App\Models\MenuTranslation::TYPES key
            $table->unsignedBigInteger('translatable_id');
            $table->string('locale', 5);
            $table->string('name', 150)->default('');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['translatable_type', 'translatable_id', 'locale'], 'menu_translations_row_unique');
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_translations');
    }
};
