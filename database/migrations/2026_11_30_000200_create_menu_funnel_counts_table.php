<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest's way through the public menu without tracking people: per day, how many visits reached each step
 * (opened the menu, an item, added to the cart, opened the cart, pressed send, order placed) and what stopped them
 * (a missing mobile, no delivery area, the restaurant's refusal message). Counters only, like menu_item_views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_funnel_counts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('day');
            $table->string('event', 20);
            // what stopped the guest (empty for a step): a known code or the restaurant's refusal message
            $table->string('detail', 150)->default('');
            $table->unsignedInteger('count')->default(0);
            $table->unique(['tenant_id', 'day', 'event', 'detail'], 'menu_funnel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_funnel_counts');
    }
};
