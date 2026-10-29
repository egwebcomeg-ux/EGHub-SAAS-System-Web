// Runs PHP in the demo restaurant before a scenario (prepare): `php artisan tinker` in the app, or the command in
// TINKER (e.g. TINKER="docker exec app php artisan tinker"). The code runs with the demo-cafe tenant initialised.
const { execFileSync } = require('child_process');

module.exports = function tinker(code) {
    const [cmd, ...args] = (process.env.TINKER || 'php artisan tinker').split(' ');
    // in the app copy ($APP, default this repository), where `artisan` is
    const cwd = process.env.APP || require('path').resolve(__dirname, '../..');
    return execFileSync(cmd, [...args, '--execute', `tenancy()->initialize(App\\Models\\Tenant::find("demo-cafe")); ${code}`], { encoding: 'utf8', cwd });
};

/** Opens a cashier shift for the owner when none is open (taking money needs one). */
module.exports.openShift = () => module.exports(
    '$u = App\\Models\\User::where("email","owner@demo.test")->first(); if (! App\\Models\\Shift::whereNull("closed_at")->exists()) { App\\Models\\Shift::forceCreate(["tenant_id" => "demo-cafe", "branch_id" => App\\Models\\Branch::value("id"), "user_id" => $u->id, "opening_cash" => 500, "opened_at" => now()]); }');
