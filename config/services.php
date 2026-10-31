<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Push notifications (App\Services\FirebaseMessaging). The service account key is a secret: set the path to its
    // JSON file, or (on Vercel) the whole file base64-encoded. Never commit it or put it under public/.
    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', env('VITE_FIREBASE_PROJECT_ID')),
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'credentials_base64' => env('FIREBASE_CREDENTIALS_BASE64'),
    ],

];
