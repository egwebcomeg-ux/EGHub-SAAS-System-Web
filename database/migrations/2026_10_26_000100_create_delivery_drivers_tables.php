<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery drivers: the driver of a delivery order and its delivery steps (assigned → picked up → delivered, or
 * failed with a reason) with the time of each, the cash the driver collected and when the manager settled it.
 * driver_locations keeps the latest position of each driver (one row per driver); delivery_track_points a short
 * trail per order while it is out for delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('driver_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $t->string('delivery_status', 12)->nullable()->after('driver_id'); // assigned | picked_up | delivered | failed
            $t->timestamp('driver_assigned_at')->nullable()->after('delivery_status');
            $t->timestamp('picked_up_at')->nullable()->after('driver_assigned_at');
            $t->timestamp('delivered_at')->nullable()->after('picked_up_at');
            $t->timestamp('delivery_failed_at')->nullable()->after('delivered_at');
            $t->string('delivery_fail_reason', 200)->nullable()->after('delivery_failed_at');
            $t->decimal('cash_collected', 10, 2)->nullable()->after('delivery_fail_reason');
            $t->timestamp('cash_settled_at')->nullable()->after('cash_collected');
            $t->foreignId('cash_settled_by')->nullable()->after('cash_settled_at')->constrained('users')->nullOnDelete();
            // named: the default name is longer than MySQL's 64 characters
            $t->index(['tenant_id', 'driver_id', 'delivery_status'], 'orders_driver_status_idx');
        });

        Schema::create('driver_locations', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); // the order out for delivery then
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->unsignedInteger('accuracy')->nullable(); // metres, as the browser reported it
            $t->timestamp('recorded_at');
            $t->timestamps();
        });

        Schema::create('delivery_track_points', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->timestamp('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_track_points');
        Schema::dropIfExists('driver_locations');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex('orders_driver_status_idx');
            $t->dropConstrainedForeignId('cash_settled_by');
            $t->dropConstrainedForeignId('driver_id');
            $t->dropColumn(['delivery_status', 'driver_assigned_at', 'picked_up_at', 'delivered_at', 'delivery_failed_at',
                'delivery_fail_reason', 'cash_collected', 'cash_settled_at']);
        });
    }
};
