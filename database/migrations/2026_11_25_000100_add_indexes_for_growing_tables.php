<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the lookups that scan a whole table once it has grown: Meta's status webhooks find the message by
 * provider_id, a guest's order history by customer, an item's sales by menu_item, the admin home's open invoices
 * by status, a restaurant's domains and audit page by restaurant, and the scheduler's tenant loops by status.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => columns of one index */
    private const INDEXES = [
        'whatsapp_messages' => ['provider_id'],
        'orders' => ['tenant_id', 'customer_id'],
        'order_items' => ['menu_item_id'],
        'invoices' => ['status'],
        'domains' => ['tenant_id'],
        'audit_logs' => ['tenant_id', 'created_at'],
        'tenants' => ['status'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($columns));
            }
        }
    }
};
