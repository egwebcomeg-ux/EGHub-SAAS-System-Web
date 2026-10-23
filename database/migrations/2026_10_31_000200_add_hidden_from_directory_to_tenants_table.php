<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * EgWeb's own demo and test restaurants: switched on from the admin, they stay out of the public directory, the
     * sitemap and the "they use EgMenu" list whatever their owner setting says (App\Services\Seo\Directory).
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('hidden_from_directory')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('hidden_from_directory');
        });
    }
};
