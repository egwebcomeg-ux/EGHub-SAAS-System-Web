<?php

return [
    // where archives are written (local, outside public/)
    'path' => storage_path('app/backups'),

    // archives older than this many days are deleted locally
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 7),

    // optional second disk (e.g. "s3" for Backblaze B2 / any S3-compatible bucket); empty = local only
    'disk' => env('BACKUP_DISK'),

    // folder inside the second disk
    'remote_dir' => env('BACKUP_REMOTE_DIR', 'egmenu-backups'),

    // mysqldump binary
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

    // Per-restaurant backups the owner downloads from the dashboard (App\Services\Backups\TenantBackups). The zips
    // live on the private disk under backups/{tenant}/ (storage/app/private/backups/{tenant}/), never under public/.
    'tenant' => [
        // how many weekly (scheduled) backups each restaurant keeps
        'keep_scheduled' => (int) env('TENANT_BACKUP_KEEP_SCHEDULED', 4),
        // a manual backup is deleted this many days after it was made
        'keep_manual_days' => (int) env('TENANT_BACKUP_KEEP_MANUAL_DAYS', 30),
        // the owner may ask for one manual backup per this many minutes
        'manual_every_minutes' => (int) env('TENANT_BACKUP_MANUAL_EVERY_MINUTES', 60),
        // uploaded images are added until this many MB; the rest is skipped (and the README says so)
        'images_max_mb' => (int) env('TENANT_BACKUP_IMAGES_MAX_MB', 500),
        // orders older than this many months go into a monthly summary instead of row by row
        'orders_months' => (int) env('TENANT_BACKUP_ORDERS_MONTHS', 12),
    ],
];
