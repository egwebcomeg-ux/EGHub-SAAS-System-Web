<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant/cafe. Its id is the slug of its public menu (egmenu.app/{slug}).
 * Domains only hold verified custom domains (Business plan); the slug itself never needs a row there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('status', 20)->default('active');   // active | suspended
            $t->timestamp('suspended_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->json('data')->nullable();
        });

        Schema::create('domains', function (Blueprint $t) {
            $t->increments('id');
            $t->string('domain')->unique();
            $t->string('tenant_id');
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
    }
};
