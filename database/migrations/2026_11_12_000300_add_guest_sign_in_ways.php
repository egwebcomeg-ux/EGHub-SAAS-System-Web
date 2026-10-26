<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New ways into a guest's "حسابي" (App\Services\Customers\SignIn):
 *  - customers.google_sub / customers.email: the Google account a guest signed in with at THIS restaurant (the
 *    OpenID subject and the verified email, nothing else). The same Google account at another restaurant is another
 *    guest row there. A guest who came in with Google may have no mobile yet, so customers.phone becomes nullable
 *    (Customer reads a missing mobile as "", and unique(tenant_id, phone) still holds for every real mobile).
 *  - guest_sign_ins: short-lived, single-use sign-in steps that cross requests or hosts (platform side, not part of
 *    a restaurant's backup): the Google hand-off ticket from eghub.app to the restaurant's own host, and the
 *    WhatsApp code waiting for the guest's message on the EgHub number. Only hashes of the browser secrets are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('phone', 20)->nullable()->change();
            $t->string('google_sub', 64)->nullable()->after('phone');
            $t->string('email', 190)->nullable()->after('google_sub');
            $t->unique(['tenant_id', 'google_sub']);
            $t->index(['tenant_id', 'email']);
        });

        Schema::create('guest_sign_ins', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('kind', 12);                          // google | whatsapp
            $t->char('secret_hash', 64)->unique();          // sha256 of the hand-off ticket / the browser's cookie
            $t->string('code', 8)->nullable();              // whatsapp: the 6 digits the guest sends
            $t->string('phone', 20)->nullable();            // whatsapp: the sender's mobile, from the webhook only
            $t->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $t->char('nonce_hash', 64)->nullable();         // google: binds the ticket to the browser that started
            $t->timestamp('expires_at');
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
            $t->index(['kind', 'code', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_sign_ins');
        Schema::table('customers', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'google_sub']);
            $t->dropIndex(['tenant_id', 'email']);
            $t->dropColumn(['google_sub', 'email']);
        });
    }
};
