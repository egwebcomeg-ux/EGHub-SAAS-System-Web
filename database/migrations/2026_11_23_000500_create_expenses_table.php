<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a restaurant spends outside the drawer (rent, salaries, bills), for its profit report. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); // null: the whole restaurant
            $t->string('category', 20);
            $t->decimal('amount', 12, 2);
            $t->date('spent_on');
            $t->string('note', 200)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
