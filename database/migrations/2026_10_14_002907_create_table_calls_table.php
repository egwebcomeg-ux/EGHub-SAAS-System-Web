<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A guest at a table calls the waiter or asks for the bill from the menu; staff see it on the order board. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_calls', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                        // waiter | bill
            $table->timestamp('handled_at')->nullable();       // null = still waiting
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'handled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_calls');
    }
};
