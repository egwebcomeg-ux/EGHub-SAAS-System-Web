<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_call_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('token_hash', 64)->unique();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('crm_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->string('external_id', 64);
            $t->string('direction', 10);                 // incoming | outgoing | missed | rejected
            $t->string('phone', 20)->nullable()->index();
            $t->timestamp('started_at')->index();
            $t->unsignedInteger('duration_seconds')->default(0);
            $t->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $t->foreignId('prospect_id')->nullable()->constrained('crm_prospects')->nullOnDelete();
            $t->string('outcome', 20)->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('summarized_at')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->timestamps();
            $t->unique(['admin_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_calls');
        Schema::dropIfExists('crm_call_devices');
    }
};
