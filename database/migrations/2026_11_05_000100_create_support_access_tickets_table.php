<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time tickets of "sign in to the restaurant's dashboard" (App\Services\SupportAccess): an EgWeb admin on
 * admin.<domain> gets one, the browser carries it to app.<domain> within a minute, and it is used once. Only the
 * SHA-256 of the ticket is stored; each row is bound to the admin, the restaurant and the staff account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_tickets', function (Blueprint $t) {
            $t->id();
            $t->char('token_hash', 64)->unique();
            $t->foreignId('admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $t->string('tenant_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_tickets');
    }
};
