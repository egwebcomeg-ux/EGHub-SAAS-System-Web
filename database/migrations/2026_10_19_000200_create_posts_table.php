<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Blog of the marketing site (platform level, not per restaurant): Markdown articles for cafe and restaurant owners. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 120)->unique();
            $t->string('title', 160);
            $t->string('title_en', 160)->default('');
            $t->string('excerpt', 300)->default('');
            $t->string('excerpt_en', 300)->default('');
            $t->text('body');                               // Markdown, Arabic
            $t->text('body_en')->nullable();                // Markdown, English (optional, falls back to Arabic)
            $t->string('cover')->nullable();                // public disk path
            $t->foreignId('author_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->boolean('is_published')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->string('meta_description', 160)->default('');
            $t->string('meta_description_en', 160)->default('');
            $t->timestamps();
            $t->index(['is_published', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
