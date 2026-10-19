<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One announcements system on the menu: the popup that opens with the menu becomes a kind of banner (is_popup) with
 * the banner's picture, title, link and dates, how often a guest sees it, and its view / tap counters. Every popup is
 * copied into a banner once (banners.popup_id remembers where it came from, so running this again copies nothing
 * twice). The popups table stays as it was, readable, until it is dropped later. Only the newest live popup of each
 * restaurant stays switched on: one popup at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $t) {
            if (! Schema::hasColumn('banners', 'is_popup')) {
                $t->boolean('is_popup')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('banners', 'popup_frequency')) {
                $t->string('popup_frequency', 10)->default('session')->after('is_active'); // session | day | once | always
            }
            if (! Schema::hasColumn('banners', 'views')) {
                $t->unsignedInteger('views')->default(0);
            }
            if (! Schema::hasColumn('banners', 'clicks')) {
                $t->unsignedInteger('clicks')->default(0);
            }
            if (! Schema::hasColumn('banners', 'popup_id')) {
                $t->unsignedBigInteger('popup_id')->nullable()->index(); // the old popup it was copied from
            }
        });

        if (! Schema::hasTable('popups')) {
            return;
        }
        $copied = DB::table('banners')->whereNotNull('popup_id')->pluck('popup_id')->all();
        $popups = DB::table('popups')->whereNotIn('id', $copied)->orderBy('id')->get();
        // one popup at a time: the newest switched-on one of each restaurant (unless one is already on)
        $keepOn = DB::table('popups')->where('is_active', true)->groupBy('tenant_id')->selectRaw('tenant_id, max(id) as id')->pluck('id', 'tenant_id');
        $alreadyOn = DB::table('banners')->where('is_popup', true)->where('is_active', true)->pluck('tenant_id')->flip();

        foreach ($popups as $popup) {
            $text = fn (?string $title, ?string $body) => trim((string) $title) !== '' ? (string) $title : Str::limit(trim((string) $body), 77);
            $itemOk = $popup->menu_item_id && DB::table('menu_items')->where('id', $popup->menu_item_id)->exists();
            DB::table('banners')->insert([
                'tenant_id' => $popup->tenant_id,
                'image' => (string) ($popup->image ?? ''), // an item popup without its own picture shows the item's photo
                'title' => $text($popup->title, $popup->body),
                'title_en' => $text($popup->title_en, $popup->body_en),
                'menu_item_id' => $itemOk ? $popup->menu_item_id : null,
                'category_id' => null,
                'link_url' => ! $itemOk && $popup->link_url ? $popup->link_url : null,
                'starts_on' => $popup->starts_on,
                'ends_on' => $popup->ends_on,
                'is_active' => $popup->is_active && ($keepOn[$popup->tenant_id] ?? null) == $popup->id && ! isset($alreadyOn[$popup->tenant_id]),
                'is_popup' => true,
                'popup_frequency' => in_array($popup->frequency, ['session', 'day', 'once', 'always'], true) ? $popup->frequency : 'session',
                'views' => (int) $popup->views,
                'clicks' => (int) $popup->clicks,
                'popup_id' => $popup->id,
                'sort_order' => 0,
                'created_at' => $popup->created_at,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('banners')->where('is_popup', true)->delete();
        Schema::table('banners', function (Blueprint $t) {
            $t->dropIndex(['popup_id']);
            $t->dropColumn(['is_popup', 'popup_frequency', 'views', 'clicks', 'popup_id']);
        });
    }
};
