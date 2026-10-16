<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashback expiry (setting cashback_expire_days): a cashback ledger line knows when it expires and when it was
 * settled (taken back on cancel, or expired). Expiry takes back what is still on the balance, never below zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable()->after('note');
            $t->timestamp('settled_at')->nullable()->after('expires_at');
            $t->index(['kind', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $t) {
            $t->dropIndex(['kind', 'expires_at']);
            $t->dropColumn(['expires_at', 'settled_at']);
        });
    }
};
