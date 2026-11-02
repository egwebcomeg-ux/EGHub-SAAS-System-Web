<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The texts written before the rename that visitors still read: four blog articles and two add-ons. */
    public function up(): void
    {
        DB::table('posts')->where('body', 'like', '%EgMenu%')->update(['body' => DB::raw("REPLACE(body, 'EgMenu', 'EgHub')")]);

        foreach ([
            'description' => ['دليل egmenu.app/restaurants' => 'دليل المطاعم', 'شارة egmenu' => 'شارة EgHub'],
            'description_en' => ['in egmenu.app/restaurants' => 'in the restaurant directory', 'no egmenu badge' => 'no EgHub badge'],
        ] as $column => $replacements) {
            foreach ($replacements as $old => $new) {
                DB::table('addon_prices')->where($column, 'like', "%$old%")->update([$column => DB::raw('REPLACE('.$column.', '.DB::getPdo()->quote($old).', '.DB::getPdo()->quote($new).')')]);
            }
        }
    }

    public function down(): void {}
};
