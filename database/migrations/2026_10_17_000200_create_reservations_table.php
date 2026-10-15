<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table reservations (reservations feature): guests book a time slot from the menu link (/reserve), staff from the
 * dashboard. The guest follows / cancels the booking on /reserve/{token}. Rules live in the restaurant settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 100);
            $t->string('phone', 20);
            $t->unsignedSmallInteger('party_size');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');                       // starts_at + the restaurant's table time; used for capacity
            $t->string('status', 12)->default('pending');  // pending | confirmed | seated | cancelled | no_show
            $t->string('notes', 500)->nullable();
            $t->string('source', 10)->default('guest');    // guest | staff
            $t->string('token', 32)->unique();             // guest page /reserve/{token}
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('seated_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'branch_id', 'starts_at']);
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
