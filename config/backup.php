<?php

return [
    'path' => env('BACKUP_PATH', storage_path('app/backups')),
    'private_path' => env('BACKUP_PRIVATE_PATH', storage_path('app/private')),
    'password' => env('BACKUP_PASSWORD'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'schedule' => env('BACKUP_SCHEDULE', '01:00'),
    'database_timeout' => (int) env('BACKUP_DATABASE_TIMEOUT', 300),
    'mysqldump_path' => env(
        'BACKUP_MYSQLDUMP_PATH',
        PHP_OS_FAMILY === 'Windows' ? base_path('../../mysql/bin/mysqldump.exe') : 'mysqldump',
    ),
];
