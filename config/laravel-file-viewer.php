<?php

return [
    'default_disk' => env('FILE_VIEWER_DISK', 'local'),
    'google_viewer_fallback' => env('FILE_VIEWER_GOOGLE_FALLBACK', false),

    'toolbar' => [
        'download' => env('FILE_VIEWER_TOOLBAR_DOWNLOAD', true),
        'open_in_new_tab' => env('FILE_VIEWER_TOOLBAR_NEW_TAB', false),
        'copy_link' => env('FILE_VIEWER_TOOLBAR_COPY_LINK', false),
        'fullscreen' => env('FILE_VIEWER_TOOLBAR_FULLSCREEN', true),
    ],
];
