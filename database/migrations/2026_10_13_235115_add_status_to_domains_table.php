<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom domain requests. source: egweb = registered through egweb.com.eg, own = a domain the restaurant already has.
 * status: requested → pending (waiting for DNS / EgWeb setup) → active (verified_at set, served) or rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('source', 10)->default('own')->after('tenant_id');
            $table->string('status', 12)->default('pending')->after('source');
            $table->string('note', 300)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['source', 'status', 'note']);
        });
    }
};
