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
];
