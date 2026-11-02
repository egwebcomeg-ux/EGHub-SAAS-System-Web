<?php

use App\Services\Crm\CallScript;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** The words for a place that never signed up. Only missing items are added: what the owner edited stays. */
    public function up(): void
    {
        CallScript::installDefaults();
    }

    public function down(): void {}
};
