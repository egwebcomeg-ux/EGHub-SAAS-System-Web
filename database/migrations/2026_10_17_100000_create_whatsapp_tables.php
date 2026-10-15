<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp channel: every message sent (or skipped) is a row; each restaurant pays per message from a credit
 * wallet topped up by buying credit packs (addon_prices.type = credits, addon_prices.credits = messages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->unique();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->integer('balance')->default(0);
            $t->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->integer('delta');
            $t->string('reason', 12);                       // purchase | message | refund | adjustment
            $t->string('reference', 80)->nullable();        // invoice number, whatsapp message id, admin note
            $t->integer('balance_after')->default(0);
            $t->timestamps();
        });

        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('campaign_id')->nullable()->index();
            $t->string('to', 20);
            $t->string('template', 40);
            $t->string('locale', 2)->default('ar');
            $t->json('params')->nullable();
            $t->text('body')->nullable();                   // the text as the guest reads it
            $t->string('status', 10)->default('queued');    // queued | sent | failed
            $t->string('provider_id', 120)->nullable();
            $t->string('error', 255)->nullable();           // no_credits | provider message
            $t->unsignedSmallInteger('cost_credits')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::table('addon_prices', function (Blueprint $t) {
            $t->unsignedInteger('credits')->nullable()->after('features'); // type=credits: messages in the pack
        });
    }

    public function down(): void
    {
        Schema::table('addon_prices', fn (Blueprint $t) => $t->dropColumn('credits'));
        foreach (['whatsapp_messages', 'credit_transactions', 'credit_wallets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
