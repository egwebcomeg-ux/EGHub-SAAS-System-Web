<?php

use App\Services\Crm\CallScript;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The words for a place that never signed up. Only missing items are added: what the owner edited stays. */
    public function up(): void
    {
        Schema::table('crm_script_items', function (Blueprint $t) {
            $t->string('kind', 30)->change();
        });
        CallScript::installDefaults();
    }

    public function down(): void {}
};
