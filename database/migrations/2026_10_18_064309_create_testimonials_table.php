<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Real owner quotes for the marketing site, entered by the platform team with the owner's consent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->string('name', 80);
            $table->string('role', 120)->default('');
            $table->string('role_en', 120)->default('');
            $table->text('quote');
            $table->text('quote_en')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('photo')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['is_published', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
