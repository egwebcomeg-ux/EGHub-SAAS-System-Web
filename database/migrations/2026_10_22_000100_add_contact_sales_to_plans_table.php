<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plan sold by talking to sales (Enterprise): it shows "contact us" instead of a price everywhere, a restaurant
     * can never subscribe to it or get an invoice for it from the dashboard, and only the super admin assigns it.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('contact_sales')->default(false)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('contact_sales');
        });
    }
};
