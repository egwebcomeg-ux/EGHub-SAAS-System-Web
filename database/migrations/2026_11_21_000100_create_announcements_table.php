<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «إعلانات للمطاعم»: a short notice EgWeb writes in the admin, shown as a banner on the dashboards of every restaurant
 * or of one plan's restaurants between two moments. Platform data (no tenant_id): which restaurants see one is its
 * plan_id (null = all). Each dashboard user hides it for themselves (announcement_dismissals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->string('title', 120);
            $t->string('body', 500);
            $t->string('link', 255)->nullable();
            $t->string('level', 10)->default('info');          // info | warning
            // a plan's restaurants only; deleting the plan removes its announcements (never widened to everyone)
            $t->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->timestamps();
            $t->index(['starts_at', 'ends_at']);
        });

        Schema::create('announcement_dismissals', function (Blueprint $t) {
            $t->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->primary(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('announcements');
    }
};
