<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'expo' => [
        'push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        'receipts_url' => env('EXPO_RECEIPTS_URL', 'https://exp.host/--/api/v2/push/getReceipts'),
        // Only needed when "enhanced push security" is on for the Expo project.
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    // "Continue with Google" on the website (and the app's web sign-in sheet).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    // The app's App Store page, linked from the invitation web page.
    'app_store_url' => env('APP_STORE_URL'),

    // Native Sign in with Apple in the iOS app.
    'apple' => [
        // The app's bundle id: Apple issues identity tokens for this audience.
        'client_id' => env('APPLE_CLIENT_ID', 'no.handlelistaapp'),
        // A Sign in with Apple key lets the server revoke the app's access at
        // Apple when an account is deleted. Sign-in works without it, but App
        // Store Review Guideline 5.1.1(v) requires the revocation: set it in
        // production.
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],

];
