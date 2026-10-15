<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Self-order kiosks (kiosk pack): a tablet at the counter opens a secret link per kiosk and takes orders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosks', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('token', 20)->unique();
            $t->boolean('is_active')->default(true);
            $t->json('settings')->nullable(); // idle_seconds, order_types, require_phone, print_number (App\Models\Kiosk::DEFAULTS)
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosks');
    }
};
