<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** The doctor's "last backup" and "last weekly backups" stamps keep their dates under the new name. */
    public function up(): void
    {
        foreach (['last_backup_at', 'tenant_backups_at'] as $stamp) {
            if (($at = cache()->get("egmenu:$stamp")) !== null) {
                cache()->forever("eghub:$stamp", $at);
            }
        }
    }

    public function down(): void {}
};
