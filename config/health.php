<?php

return [
    'scheduler_max_age_minutes' => (int) env('HEALTH_SCHEDULER_MAX_AGE_MINUTES', 5),
    'backup_max_age_hours' => (int) env('HEALTH_BACKUP_MAX_AGE_HOURS', 30),
    'queue_backlog_warning' => (int) env('HEALTH_QUEUE_BACKLOG_WARNING', 100),
    'minimum_free_space_mb' => (int) env('HEALTH_MINIMUM_FREE_SPACE_MB', 1024),
    'alert_repeat_hours' => (int) env('HEALTH_ALERT_REPEAT_HOURS', 12),
    'alert_mail_enabled' => (bool) env('HEALTH_ALERT_MAIL_ENABLED', false),
];
