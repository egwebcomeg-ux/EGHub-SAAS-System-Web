<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced loyalty cards (features cashback / prepaid_packages / membership):
 *  - prepaid packages ("10 coffees for 150 EGP, valid 30 days") sold at the POS; each customer's copy counts its uses;
 *  - membership tiers by visits / spend with an automatic discount on every order;
 *  - cashback needs no table: it is a settings percentage credited to the wallet ledger (kind cashback).
 */
return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->string('tenant_id')->index();
        $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->cascadeOnDelete();
    }

    public function up(): void
    {
        Schema::create('prepaid_packages', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name', 80);
            $t->string('name_en', 80)->default('');
            $t->string('description', 200)->default('');
            $t->decimal('price', 10, 2);
            $t->unsignedSmallInteger('uses');                  // how many items / visits the package holds
            $t->unsignedSmallInteger('valid_days');            // from the day it is sold
            $t->json('qualifying')->nullable();                // {categories: [...], items: [...]} the uses are good for
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('customer_packages', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('prepaid_package_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 80);                            // snapshot: the package can be renamed later
            $t->decimal('price_paid', 10, 2);
            $t->unsignedSmallInteger('uses_total');
            $t->unsignedSmallInteger('uses_left');
            $t->timestamp('expires_at');
            $t->string('status', 10)->default('active');      // active | used | expired | void
            $t->string('payment_method', 20)->nullable();
            $t->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'customer_id', 'status']);
        });

        // Append-only: every use of a package with who gave it and the order it went with.
        Schema::create('package_uses', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('customer_package_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note', 100)->default('');
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('membership_tiers', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name', 40);
            $t->string('name_en', 40)->default('');
            $t->unsignedInteger('min_visits')->default(0);
            $t->decimal('min_spent', 12, 2)->default(0);
            $t->decimal('discount_percent', 5, 2)->default(0);
            $t->string('color', 7)->default('#1E2D4F');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        // The member discount is part of orders.discount; kept apart here for the reports and the receipt.
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('member_discount', 10, 2)->default(0)->after('discount');
            $t->string('member_tier', 40)->nullable()->after('member_discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['member_discount', 'member_tier']);
        });
        Schema::dropIfExists('membership_tiers');
        Schema::dropIfExists('package_uses');
        Schema::dropIfExists('customer_packages');
        Schema::dropIfExists('prepaid_packages');
    }
};
