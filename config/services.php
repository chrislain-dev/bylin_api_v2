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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'fedapay' => [

       'public_key' => env('FEDAPAY_PUBLIC_KEY'),
       'secret_key' => env('FEDAPAY_SECRET_KEY'),
       'webhook_secret' => env('FEDAPAY_WEBHOOK_SECRET'),
       'environment' => env('FEDAPAY_ENVIRONMENT', 'sandbox'),
    ],

    // Cahier des charges §9 : finalisation de commande via WhatsApp
    'whatsapp' => [
        // Numéro au format international sans "+" ni espaces, ex: 22997000000
        'number' => env('WHATSAPP_BUSINESS_NUMBER', ''),
    ],

];
