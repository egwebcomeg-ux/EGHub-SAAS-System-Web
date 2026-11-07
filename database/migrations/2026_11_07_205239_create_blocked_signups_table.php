<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Emails and mobile numbers the platform refuses a new account to (a copycat): one row each, kept after the
     * restaurant they came from is deleted.
     */
    public function up(): void
    {
        Schema::create('blocked_signups', function (Blueprint $table) {
            $table->id();
            $table->string('email', 120)->nullable()->index();
            $table->string('phone', 20)->nullable()->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('reason', 200)->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_signups');
    }
};
