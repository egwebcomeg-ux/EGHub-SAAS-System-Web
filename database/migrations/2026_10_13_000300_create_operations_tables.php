<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant operations, ideas taken from the Filetto project: kitchen stations, delivery zones (polygons with
 * sub-zones), cashier shifts with a cash drawer, call-center customer book and richer order tracking.
 */
return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->string('tenant_id')->index();
        $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
    }

    public function up(): void
    {
        // Kitchen screens can show one station (grill, drinks, desserts); items follow their category's station.
        Schema::create('kitchen_stations', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete(); // null = every branch
            $t->string('name');
            $t->string('name_en')->default('');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::table('categories', function (Blueprint $t) {
            $t->foreignId('kitchen_station_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        // Delivery areas: a polygon on the map, or a child of another zone inheriting its empty values.
        Schema::create('delivery_zones', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('delivery_zones')->cascadeOnDelete();
            $t->string('name');
            $t->string('name_en')->default('');
            $t->json('polygon')->nullable();               // [[lat, lng], ...]
            $t->string('color', 7)->default('#E8642C');
            $t->decimal('fee', 10, 2)->nullable();
            $t->decimal('min_order', 10, 2)->nullable();
            $t->unsignedSmallInteger('estimated_minutes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        // A cashier's shift: money can only be taken inside an open shift; closing compares counted vs expected cash.
        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->decimal('opening_cash', 10, 2)->default(0);
            $t->decimal('expected_cash', 10, 2)->nullable();
            $t->decimal('closing_cash', 10, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $t->string('type', 4);                         // in | out
            $t->decimal('amount', 10, 2);
            $t->string('reason')->default('');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        // Call-center customer book.
        Schema::table('customers', function (Blueprint $t) {
            $t->string('phone_2', 20)->nullable()->after('phone');
            $t->json('addresses')->nullable()->after('birthday');    // [{label, zone_id, text, lat, lng}]
            $t->text('notes')->nullable()->after('addresses');
            $t->string('source', 12)->default('menu')->after('notes'); // menu | pos | callcenter | import
            $t->decimal('credit_balance', 10, 2)->default(0)->after('points_balance'); // pay-later customers
            $t->timestamp('last_contacted_at')->nullable()->after('last_visit_at');
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->uuid('client_uuid')->nullable()->after('number');   // idempotency: a retried submit never doubles
            $t->string('tracking_token', 32)->nullable()->after('client_uuid');
            $t->foreignId('delivery_zone_id')->nullable()->after('dining_table_id')->constrained()->nullOnDelete();
            $t->json('delivery_address')->nullable()->after('customer_phone');
            $t->decimal('delivery_fee', 10, 2)->default(0)->after('tax');
            $t->timestamp('scheduled_for')->nullable()->after('notes'); // joins the kitchen queue shortly before
            $t->string('payment_method', 20)->nullable()->after('payment_status'); // cash | card | instapay | wallet | credit
            $t->foreignId('shift_id')->nullable()->after('payment_method')->constrained()->nullOnDelete();
            $t->timestamp('preparing_at')->nullable();
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->unique(['tenant_id', 'client_uuid']);
            $t->unique('tracking_token');
        });

        Schema::table('order_items', function (Blueprint $t) {
            $t->foreignId('kitchen_station_id')->nullable()->after('menu_item_id')->constrained()->nullOnDelete();
            $t->timestamp('ready_at')->nullable();        // station bumped this line
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('kitchen_station_id');
            $t->dropColumn('ready_at');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'client_uuid']);
            $t->dropUnique(['tracking_token']);
            $t->dropConstrainedForeignId('delivery_zone_id');
            $t->dropConstrainedForeignId('shift_id');
            $t->dropColumn(['client_uuid', 'tracking_token', 'delivery_address', 'delivery_fee', 'scheduled_for', 'payment_method',
                'preparing_at', 'ready_at', 'completed_at', 'cancelled_at', 'cancel_reason']);
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['phone_2', 'addresses', 'notes', 'source', 'credit_balance', 'last_contacted_at']);
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('delivery_zones');
        Schema::table('categories', function (Blueprint $t) {
            $t->dropConstrainedForeignId('kitchen_station_id');
        });
        Schema::dropIfExists('kitchen_stations');
    }
};
