<?php

use App\Support\PrepKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard operations: food / drinks parts of an order (each category is prepared as food or drinks, copied onto
 * the order lines so "food ready" and "drinks ready" are marked apart), table sessions (every dine-in order of a
 * table until the cashier closes it with one bill) and the read mark of the notifications bell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $t) {
            $t->string('prep_kind', 10)->default(PrepKind::FOOD)->after('kitchen_station_id'); // food | drinks
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->string('prep_kind', 10)->default(PrepKind::FOOD)->after('kitchen_station_id');
        });

        // existing categories start from their names; their order lines follow
        foreach (DB::table('categories')->get(['id', 'name', 'name_en']) as $c) {
            if (PrepKind::guess($c->name, $c->name_en) === PrepKind::DRINKS) {
                DB::table('categories')->where('id', $c->id)->update(['prep_kind' => PrepKind::DRINKS]);
                DB::table('order_items')->whereIn('menu_item_id', DB::table('menu_items')->where('category_id', $c->id)->select('id'))
                    ->update(['prep_kind' => PrepKind::DRINKS]);
            }
        }

        Schema::create('table_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('total', 10, 2)->default(0); // cached: the session's orders that are not cancelled
            $t->timestamps();
            $t->index(['dining_table_id', 'closed_at']);
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('table_session_id')->nullable()->after('dining_table_id')->constrained()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('notifications_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notifications_seen_at'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('table_session_id'));
        Schema::dropIfExists('table_sessions');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('prep_kind'));
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('prep_kind'));
    }
};
