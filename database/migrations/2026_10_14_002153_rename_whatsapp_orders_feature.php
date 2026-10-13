<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** "WhatsApp orders" became "online ordering" (takeaway & delivery from the menu link, also sent on WhatsApp). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plan_features')->where('key', 'whatsapp_orders')->update(['key' => 'online_ordering']);
    }

    public function down(): void
    {
        DB::table('plan_features')->where('key', 'online_ordering')->update(['key' => 'whatsapp_orders']);
    }
};
