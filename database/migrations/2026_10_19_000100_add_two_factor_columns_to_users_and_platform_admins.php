<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication (TOTP) for restaurant staff and platform admins. The secret and the hashed recovery
 * codes are stored encrypted (App\Models\Concerns\HasTwoFactor); confirmed_at is set once the first code matched.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'platform_admins'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->text('two_factor_secret')->nullable()->after('password');
                $t->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
                $t->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'platform_admins'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
            });
        }
    }
};
