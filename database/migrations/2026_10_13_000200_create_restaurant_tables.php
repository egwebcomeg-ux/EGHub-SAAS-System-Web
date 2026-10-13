<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything a restaurant owns. Every table carries tenant_id (BelongsToTenant scope) and every unique index
 * is per tenant, so rows of one restaurant can never collide with or leak into another.
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
        Schema::create('tenant_settings', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('key', 64);
            $t->text('value')->nullable();
            $t->unique(['tenant_id', 'key']);
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name');
            $t->string('name_en')->default('');
            $t->string('slug', 40);                        // egmenu.app/{tenant}?branch={slug}
            $t->string('address')->default('');
            $t->string('phone', 20)->default('');
            $t->string('whatsapp', 20)->nullable();        // null = restaurant default
            $t->string('working_hours')->default('');
            $t->string('maps_url')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'slug']);
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name');
            $t->string('name_en')->default('');
            $t->string('description')->default('');
            $t->string('description_en')->default('');
            $t->string('image')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('category_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('name_en')->default('');
            $t->text('description')->nullable();
            $t->text('description_en')->nullable();
            $t->decimal('price', 10, 2)->default(0);
            $t->decimal('cost', 10, 2)->nullable();        // for profit reports / recipe costing later
            $t->string('image')->nullable();
            $t->json('badges')->nullable();                // keys of the restaurant's own badges
            $t->unsignedInteger('calories')->nullable();
            $t->json('allergens')->nullable();
            $t->boolean('is_active')->default(true);       // hidden from the menu when false
            $t->boolean('is_sold_out')->default(false);    // shown but can't be ordered
            $t->boolean('is_featured')->default(false);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'category_id', 'sort_order']);
        });

        // Modifier groups per item: "Size" (single, required) or "Extras" (multiple, 0..n).
        Schema::create('item_option_groups', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('name_en')->default('');
            $t->string('type', 10)->default('single');     // single | multiple
            $t->boolean('is_required')->default(false);
            $t->unsignedTinyInteger('max_choices')->nullable(); // multiple: null = no cap
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('item_options', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('item_option_group_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('name_en')->default('');
            $t->decimal('price', 10, 2)->default(0);       // added to the item price
            $t->boolean('is_default')->default(false);
            $t->boolean('is_sold_out')->default(false);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        // Per-branch overrides. A missing row or a null column means "same as the main menu".
        Schema::create('branch_items', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->decimal('price', 10, 2)->nullable();
            $t->boolean('is_available')->default(true);
            $t->boolean('is_sold_out')->default(false);
            $t->unique(['branch_id', 'menu_item_id']);
        });

        Schema::create('dining_tables', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->string('name', 40);                        // "5", "Terrace 2"
            $t->string('qr_token', 32)->unique();          // egmenu.app/{tenant}/t/{qr_token}: unguessable, rotatable
            $t->unsignedTinyInteger('seats')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['branch_id', 'name']);
        });

        // Guests who left a phone (order, loyalty card): the restaurant's own customer list.
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('name')->default('');
            $t->string('phone', 20);
            $t->date('birthday')->nullable();
            $t->string('card_token', 32)->unique();        // digital loyalty card URL / QR
            $t->unsignedInteger('visits_count')->default(0);
            $t->decimal('total_spent', 12, 2)->default(0);
            $t->integer('points_balance')->default(0);
            $t->boolean('marketing_opt_in')->default(false);
            $t->timestamp('last_visit_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'phone']);
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('number');                 // per-restaurant running number shown to staff
            $t->string('type', 12)->default('dine_in');    // dine_in | takeaway | delivery
            $t->string('source', 12)->default('qr');       // qr | pos | whatsapp
            $t->string('status', 12)->default('new');      // new | preparing | ready | served | cancelled
            $t->string('customer_name')->default('');
            $t->string('customer_phone', 20)->default('');
            $t->text('notes')->nullable();
            $t->decimal('subtotal', 10, 2)->default(0);
            $t->decimal('discount', 10, 2)->default(0);
            $t->decimal('service', 10, 2)->default(0);
            $t->decimal('tax', 10, 2)->default(0);
            $t->decimal('total', 10, 2)->default(0);
            $t->string('payment_status', 10)->default('unpaid'); // unpaid | paid
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // POS / waiter
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'branch_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');                            // snapshot: the order stays right after menu edits
            $t->decimal('unit_price', 10, 2);              // item price + chosen options
            $t->unsignedSmallInteger('quantity');
            $t->json('options')->nullable();               // [{group, name, price}]
            $t->string('notes')->default('');
            $t->decimal('line_total', 10, 2);
        });

        Schema::create('order_status_histories', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 12)->nullable();
            $t->string('to_status', 12);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        /*
         * Loyalty. One program per restaurant (for now), three mechanics:
         *   visits: 1 stamp per qualifying visit (min order, max 1 per day), reward after N stamps
         *   items:  1 stamp per qualifying item bought (e.g. any coffee), reward after N stamps ("5th coffee free")
         *   points: X points per EGP spent, rewards priced in points
         * Stamps/points are only granted by a staff action or a served/paid order, never by the guest alone.
         */
        Schema::create('loyalty_programs', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('type', 10)->default('visits');     // visits | items | points
            $t->string('title');
            $t->string('title_en')->default('');
            $t->unsignedTinyInteger('stamps_required')->default(5);
            $t->json('qualifying_items')->nullable();      // items: {categories: [...], items: [...]}
            $t->decimal('min_order', 10, 2)->default(0);   // visits: smallest order that earns a stamp
            $t->decimal('points_per_egp', 6, 2)->default(1);
            $t->string('reward_type', 20)->default('free_item'); // free_item | discount_percent | discount_fixed
            $t->json('reward_items')->nullable();          // free_item: allowed item/category ids
            $t->decimal('reward_value', 10, 2)->nullable();
            $t->string('reward_label');
            $t->string('reward_label_en')->default('');
            $t->unsignedSmallInteger('stamps_expire_days')->nullable(); // null = never
            $t->json('card_design')->nullable();           // colors, stamp icon, background
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('loyalty_cards', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('stamps')->default(0);   // progress towards the next reward
            $t->unsignedSmallInteger('rewards_available')->default(0);
            $t->unsignedInteger('lifetime_stamps')->default(0);
            $t->unsignedInteger('rewards_redeemed')->default(0);
            $t->timestamp('last_stamp_at')->nullable();
            $t->timestamps();
            $t->unique(['loyalty_program_id', 'customer_id']);
        });

        // Append-only ledger: every stamp, point, reward and redemption with who/what caused it.
        Schema::create('loyalty_transactions', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('loyalty_card_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12);                        // stamp | points | reward | redeem | reverse | expire
            $t->integer('amount');                         // stamps or points (+/-)
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('note')->default('');
            $t->timestamp('created_at')->useCurrent();
        });

        // Menu imports from a photo/PDF (AI) or a spreadsheet: parsed rows wait here for review before applying.
        Schema::create('menu_imports', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->string('source', 10);                      // image | pdf | excel
            $t->string('file');
            $t->string('status', 12)->default('pending');  // pending | parsed | applied | failed
            $t->json('result')->nullable();                // [{category, items:[{name, name_en, price, options}]}]
            $t->text('error')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $this->tenant($t);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 60);
            $t->string('subject')->default('');
            $t->json('meta')->nullable();
            $t->string('ip', 45)->default('');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'menu_imports', 'loyalty_transactions', 'loyalty_cards', 'loyalty_programs',
            'order_status_histories', 'order_items', 'orders', 'customers', 'dining_tables', 'branch_items',
            'item_options', 'item_option_groups', 'menu_items', 'categories', 'branches', 'tenant_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
