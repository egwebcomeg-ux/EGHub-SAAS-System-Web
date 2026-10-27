<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest accounts v2 (App\Services\Customers\SignIn, App\Services\Customers\Outreach):
 *  - customers.email_verified_at: the email was proven (Google, an emailed link or code, a password reset). Rows that
 *    came from Google are verified already. customers.password: the guest's own password at THIS restaurant (hashed;
 *    the same email at another restaurant is another account). customers.pending_email: an email typed at checkout,
 *    never trusted until an emailed link is used and a served order carries it. customers.pending_phone: the mobile
 *    a new account typed that another guest here already has (verified later, never taken over).
 *  - orders.guest_email: the email typed with that order; orders.campaign_recipient_id: the manual campaign row the
 *    order came from (its personal link).
 *  - campaigns.options: the manual campaign's settings (personal coupon, claim links).
 *  - campaign_recipients: one row per guest of a manual WhatsApp / SMS campaign (sent by staff from their phone, or
 *    by the configured SMS provider), with the personal coupon, the link's ref and the account-claim link.
 *  - guest_sign_ins gains email (the address a link or code was sent to), code_hash (the 6-digit code, hashed) and
 *    attempts (wrong codes) for the kinds email, reset, claim and phone (a code sent to the mobile: by the restaurant's
 *    own WhatsApp number or by SMS), with channel and the estimated cost so the restaurant sees what its codes cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->timestamp('email_verified_at')->nullable()->after('email');
            $t->string('password')->nullable()->after('email_verified_at');
            $t->string('pending_email', 190)->nullable()->after('password');
            $t->string('pending_phone', 20)->nullable()->after('pending_email');
        });
        DB::table('customers')->whereNotNull('google_sub')->whereNotNull('email')->update(['email_verified_at' => now()]);

        Schema::table('orders', function (Blueprint $t) {
            $t->string('guest_email', 190)->nullable();
            $t->unsignedBigInteger('campaign_recipient_id')->nullable()->index();
        });

        Schema::table('campaigns', function (Blueprint $t) {
            $t->json('options')->nullable();
        });

        Schema::create('campaign_recipients', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 20);
            $t->string('name', 100)->default('');
            $t->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $t->string('ref', 12)->unique();                 // ?ref= on the personal link (attribution)
            $t->text('claim_link')->nullable();             // encrypted: the personal account-claim link
            $t->timestamp('sent_at')->nullable();           // «ابعت» tapped (staff's own WhatsApp)
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('sms_sent_at')->nullable();
            $t->string('sms_status', 12)->nullable();       // sent | failed | skipped
            $t->string('sms_error', 190)->nullable();
            $t->timestamp('opted_out_at')->nullable();
            $t->timestamps();
            $t->unique(['campaign_id', 'customer_id']);
        });

        Schema::table('guest_sign_ins', function (Blueprint $t) {
            $t->string('email', 190)->nullable();
            $t->char('code_hash', 64)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('channel', 16)->nullable();          // phone codes: whatsapp | sms (the restaurant's usage)
            $t->decimal('cost', 8, 3)->default(0);          // estimated cost of sending it (config whatsapp.auth_cost / sms)
            $t->index(['tenant_id', 'kind', 'email']);
            $t->index(['tenant_id', 'kind', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('guest_sign_ins', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'kind', 'email']);
            $t->dropIndex(['tenant_id', 'kind', 'phone']);
            $t->dropColumn(['email', 'code_hash', 'attempts', 'channel', 'cost']);
        });
        Schema::dropIfExists('campaign_recipients');
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn('options'));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['campaign_recipient_id']);
            $t->dropColumn(['guest_email', 'campaign_recipient_id']);
        });
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['email_verified_at', 'password', 'pending_email', 'pending_phone']));
    }
};
