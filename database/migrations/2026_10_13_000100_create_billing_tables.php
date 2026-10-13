<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platform-level tables: plans (data, never hardcoded), subscriptions, invoices, payments, usage. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();              // basic | pro | business
            $t->string('name');
            $t->string('name_en')->default('');
            $t->string('tagline')->default('');
            $t->string('tagline_en')->default('');
            $t->decimal('monthly_price', 10, 2)->default(0);
            $t->decimal('original_price', 10, 2)->nullable(); // crossed-out "was" price on the pricing page
            $t->json('cycle_discounts')->nullable();       // {"6":10,"12":17} percent off per cycle length
            $t->boolean('is_public')->default(true);       // shown on the pricing page
            $t->boolean('is_featured')->default(false);    // "most popular" ribbon
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        // Features (booleans) and limits (integers, null = unlimited) of a plan, editable from the super admin.
        Schema::create('plan_features', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->string('key', 40);
            $t->string('type', 10);                        // feature | limit
            $t->integer('value')->nullable();              // feature: 0/1 · limit: n or null (unlimited)
            $t->unique(['plan_id', 'key']);
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->unique();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained();
            $t->string('status', 20);                      // trialing | active | past_due | canceled
            $t->unsignedTinyInteger('cycle_months')->default(1);
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('current_period_start')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->timestamp('grace_ends_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'current_period_end']);
        });

        // Paid extras on top of the plan, e.g. {key: branches, quantity: 2}
        Schema::create('subscription_addons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $t->string('key', 40);
            $t->unsignedInteger('quantity')->default(1);
            $t->decimal('unit_price', 10, 2)->default(0);
            $t->timestamps();
            $t->unique(['subscription_id', 'key']);
        });

        Schema::create('addon_prices', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();               // branches | staff
            $t->string('name');
            $t->decimal('monthly_price', 10, 2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $t->string('number', 30)->unique();
            $t->string('kind', 20);                        // subscription | upgrade | addon
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('cycle_months')->nullable();
            $t->json('lines');
            $t->decimal('subtotal', 10, 2);
            $t->decimal('discount', 10, 2)->default(0);
            $t->decimal('total', 10, 2);
            $t->string('currency', 3)->default('EGP');
            $t->string('status', 12)->default('open');    // open | paid | void
            $t->timestamp('due_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->json('meta')->nullable();                  // what to apply when paid (plan, cycle, addons)
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('gateway', 20);                     // instapay | vodafone_cash | paymob | kashier | geidea
            $t->string('reference')->nullable();           // gateway transaction id / transfer reference
            $t->string('idempotency_key')->unique();
            $t->decimal('amount', 10, 2);
            $t->string('status', 12);                      // pending | succeeded | failed
            $t->json('payload')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable(); // platform admin for manual payments
            $t->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->text('value')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['platform_settings', 'payments', 'invoices', 'addon_prices', 'subscription_addons', 'subscriptions',
            'plan_features', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
