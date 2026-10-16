<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing automations (plan feature campaigns): one row per restaurant and kind (welcome, winback, birthday) with
 * its message, optional coupon and send window. Every message an automation sends is a whatsapp_messages row that
 * points back to it (automation_id), which is also how the per-guest cooldown is enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_automations', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('kind', 12);                          // welcome | winback | birthday
            $t->boolean('is_enabled')->default(false);
            $t->text('message');
            $t->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('days')->default(30);   // winback: days without a visit
            $t->unsignedTinyInteger('send_from')->default(10); // local hour the window opens (inclusive)
            $t->unsignedTinyInteger('send_to')->default(21);   // local hour the window closes (exclusive)
            $t->timestamps();
            $t->unique(['tenant_id', 'kind']);
        });

        Schema::table('whatsapp_messages', function (Blueprint $t) {
            $t->unsignedBigInteger('automation_id')->nullable()->after('campaign_id');
            $t->index(['automation_id', 'customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $t) {
            $t->dropIndex(['automation_id', 'customer_id', 'created_at']);
            $t->dropColumn('automation_id');
        });
        Schema::dropIfExists('campaign_automations');
    }
};
