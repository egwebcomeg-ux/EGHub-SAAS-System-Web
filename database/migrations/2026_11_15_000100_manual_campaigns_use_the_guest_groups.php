<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One set of guest groups (App\Services\Customers\Segments) for every campaign: the free campaigns sent from the
 * staff's own phone (channel manual) stored the old group keys of the customers page. Their send lists were made
 * when they were created, so only the name of who they went to changes: loyal → regular, top → vip, and away
 * ("nothing for 30 days or more") becomes the same filter of the EgHub WhatsApp campaigns, inactive with 30 days.
 * new, imported and birthday keep their keys. EgHub WhatsApp campaigns already used the new keys and are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $manual = fn () => DB::table('campaigns')->where('channel', 'manual');
        $manual()->where('segment', 'loyal')->update(['segment' => 'regular']);
        $manual()->where('segment', 'top')->update(['segment' => 'vip']);
        $manual()->where('segment', 'away')->update(['segment' => 'inactive', 'segment_value' => 30]);
    }

    public function down(): void
    {
        $manual = fn () => DB::table('campaigns')->where('channel', 'manual');
        $manual()->where('segment', 'regular')->update(['segment' => 'loyal']);
        $manual()->where('segment', 'vip')->update(['segment' => 'top']);
        $manual()->where('segment', 'inactive')->where('segment_value', 30)->update(['segment' => 'away', 'segment_value' => null]);
    }
};
