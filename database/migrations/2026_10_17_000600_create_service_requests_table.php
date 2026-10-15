<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Requests for EgWeb's one-time services from egmenu.app/services (platform level, not a restaurant's data). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $t) {
            $t->id();
            $t->string('service', 40);                 // App\Support\SiteServices key
            $t->string('name', 100);
            $t->string('restaurant', 120);
            $t->string('phone', 20);
            $t->string('email')->nullable();
            $t->unsignedInteger('quantity')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 12)->default('new');   // new | contacted | done
            $t->string('locale', 5)->default('ar');
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
