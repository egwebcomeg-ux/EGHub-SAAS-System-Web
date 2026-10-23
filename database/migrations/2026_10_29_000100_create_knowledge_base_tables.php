<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The restaurant's knowledge base ("الأسئلة والأجوبة"): questions and answers the team gives every day (opening hours,
 * parking, delivery areas, allergens, policies...). visibility: internal (staff only: the call center, the POS and the
 * order board) | public (also on the guest menu's about page as frequently asked questions, and in its FAQPage JSON-LD).
 * The answer is plain text with line breaks; http(s) links in it are linked when shown, everything else is escaped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('name_en', 80)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'sort_order']);
        });

        Schema::create('kb_articles', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('kb_categories')->nullOnDelete();
            $t->string('question', 255);
            $t->string('question_en', 255)->nullable();
            $t->text('answer');
            $t->text('answer_en')->nullable();
            $t->string('visibility', 10)->default('internal');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('tags', 255)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'visibility', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
