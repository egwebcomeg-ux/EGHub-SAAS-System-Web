<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders lists and reports filter one restaurant by date (and often by status): without these, MySQL scans every
     * order of the restaurant. Added only when missing, so a server where they were created by hand is left alone.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasIndex('orders', ['tenant_id', 'created_at'])) {
                $table->index(['tenant_id', 'created_at'], 'orders_tenant_created_idx');
            }
            if (! Schema::hasIndex('orders', ['tenant_id', 'status', 'created_at'])) {
                $table->index(['tenant_id', 'status', 'created_at'], 'orders_tenant_status_created_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['orders_tenant_created_idx', 'orders_tenant_status_created_idx'] as $name) {
                if (Schema::hasIndex('orders', $name)) {
                    $table->dropIndex($name);
                }
            }
        });
    }
};
