<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The EgHub Android apps (WebView wrappers around app.<domain>, window.EgHubNative):
 *  - device_tokens: the FCM push token of a signed-in staff member's phone (restaurant and waiter apps). One row per
 *    token (token_hash unique): a phone that signs in with another account moves its token to that account.
 *  - order_prints: which copies of an order the cashier app already printed by itself (receipt, kitchen:<station>),
 *    one row per order and role, so the same order is never printed twice by the automatic printing.
 *  - users.push_preferences: which notifications a staff member wants on their phone (null = the role's defaults).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('platform', 20)->default('android');
            $t->string('app', 20);                       // pos | restaurant | driver | kiosk | waiter
            $t->text('token');
            $t->char('token_hash', 64)->unique();        // sha256 of the token (tokens are too long for a unique index)
            $t->string('app_version', 30)->nullable();
            $t->string('device', 120)->nullable();       // manufacturer + model, shown to the user
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('order_prints', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('role', 60);                      // receipt | kitchen:<station id> | kitchen:main
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['order_id', 'role']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->json('push_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('push_preferences'));
        Schema::dropIfExists('order_prints');
        Schema::dropIfExists('device_tokens');
    }
};
