<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu analytics without tracking people: a daily counter of menu visits (one per browser session and day)
 * and of table QR scans, plus a running scan count per table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_visits', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('scans')->default(0);
            $table->unique(['tenant_id', 'day']);
        });

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->unsignedInteger('scans_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dining_tables', fn (Blueprint $table) => $table->dropColumn(['scans_count', 'last_scanned_at']));
        Schema::dropIfExists('menu_visits');
    }
};
