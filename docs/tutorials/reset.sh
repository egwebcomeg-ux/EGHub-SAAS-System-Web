#!/bin/sh
# Fresh demo data for the tutorials: migrate --seed, demo-cafe on Enterprise, DemoContent, the extras in prep/.
# Runs in the app copy at $APP (default: this repository) with the PHP command in $PHP (default: php). It wipes the
# database, so it refuses anything but a local SQLite copy.
APP=${APP:-$(cd "$(dirname "$0")/../.." && pwd)}
PHP=${PHP:-php}
cd "$APP" || exit 1
if ! grep -q '^APP_ENV=local' .env || ! grep -q '^DB_CONNECTION=sqlite' .env; then
    echo "refusing: only for a local copy (APP_ENV=local, DB_CONNECTION=sqlite)"; exit 1
fi
rm -f database/database.sqlite && touch database/database.sqlite
$PHP artisan migrate --seed --force >/dev/null 2>&1 || { echo migrate failed; exit 1; }
$PHP artisan tinker --execute 'App\Models\Tenant::find("demo-cafe")->subscription()->first()->update(["plan_id"=>App\Models\Plan::where("code","enterprise")->value("id"),"status"=>"active","current_period_end"=>now()->addYear()]); App\Services\Entitlements::flush(); app(App\Services\DemoContent::class)->seed(App\Models\Tenant::find("demo-cafe")); echo "ok\n";' 2>&1 | grep -v DEPRECATED | grep -v "^\s*$" | tail -3
cp "$(dirname "$0")/prep/demo-extra.php" storage/app/tut-demo-extra.php
$PHP artisan tinker storage/app/tut-demo-extra.php < /dev/null 2>&1 | grep 'extra ok'
rm -f storage/app/tut-demo-extra.php
$PHP artisan cache:clear >/dev/null 2>&1
rm -f "$(dirname "$0")/state.json"
