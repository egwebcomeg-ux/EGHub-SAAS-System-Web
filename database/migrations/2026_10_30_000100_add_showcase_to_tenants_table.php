<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The moving logo strip on the egmenu.app home page (App\Services\Seo\SiteShowcase): EgWeb turns it on per
     * restaurant from the admin, only after the owner agreed. showcase_sort orders the strip (null = after the
     * numbered ones, by name).
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('showcase_on_site')->default(false);
            $table->unsignedSmallInteger('showcase_sort')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['showcase_on_site', 'showcase_sort']);
        });
    }
};
