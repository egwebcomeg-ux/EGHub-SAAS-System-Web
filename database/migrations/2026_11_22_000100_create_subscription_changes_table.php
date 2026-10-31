<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The history of every subscription: one row each time its status or plan changes (App\Models\SubscriptionChange,
 * written from Subscription::booted()), with what caused it. The admin subscriptions report reads it for the months
 * it covers. Nothing is backfilled: the months before it existed keep the inference from the invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $t->string('tenant_id');
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->unsignedBigInteger('from_plan_id')->nullable();
            $t->unsignedBigInteger('to_plan_id')->nullable();
            $t->string('cause', 30);
            $t->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $t->timestamp('changed_at')->useCurrent();
            $t->index(['changed_at', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
    }
};
