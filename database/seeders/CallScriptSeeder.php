<?php

namespace Database\Seeders;

use App\Services\Crm\CallScript;
use Illuminate\Database\Seeder;

/**
 * The default sales call script of the admin's customer follow-up (App\Support\CallScriptDefaults). Safe to re-run on
 * production (`php artisan db:seed --class=CallScriptSeeder --force`): only items whose key is missing are added, so
 * what the platform owner edited or hid is never touched, and nothing is deleted.
 */
class CallScriptSeeder extends Seeder
{
    public function run(): void
    {
        CallScript::installDefaults();
    }
}
