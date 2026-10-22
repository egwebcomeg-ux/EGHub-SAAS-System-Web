<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-restaurant backups ("النسخ الاحتياطية"): one row per zip the owner can download from the dashboard. The file
 * itself lives on the private disk (storage/app/private/backups/{tenant}/{filename}), never under public/.
 * kind: manual (the owner pressed the button) | scheduled (the weekly egmenu:tenant-backups run).
 * status: building | ready | failed. expires_at: when a manual backup is pruned (scheduled ones are kept by count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_backups', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('filename', 80);
            $t->unsignedBigInteger('size')->default(0);
            $t->string('kind', 10)->default('manual');
            $t->string('status', 10)->default('building');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('error', 500)->nullable();
            $t->json('summary')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backups');
    }
};
