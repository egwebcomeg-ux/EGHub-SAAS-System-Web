<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order number starts again with every shift of the branch (App\Services\Orders\OrderNumbers), so it is no longer
 * unique per restaurant: the plain index keeps the lookups by number fast. The new index goes in before the unique one
 * goes out, so tenant_id always has one for its foreign key (MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->index(['tenant_id', 'branch_id', 'number']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->unique(['tenant_id', 'number']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'branch_id', 'number']);
        });
    }
};
