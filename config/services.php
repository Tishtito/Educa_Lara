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

    // Continue with Google (docs/authentication.md). The API accepts ID tokens
    // issued to any of these OAuth clients: the web client (browsers, and the
    // audience Android's native sign-in asks for) and the iOS client. With none
    // set, Google sign-in is switched off and its routes answer 404.
    'google' => [
        'client_ids' => array_values(array_filter(array_map('trim', [
            (string) env('GOOGLE_WEB_CLIENT_ID', ''),
            (string) env('GOOGLE_ANDROID_CLIENT_ID', ''),
            (string) env('GOOGLE_IOS_CLIENT_ID', ''),
        ]))),
        'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
    ],

    // M-Pesa Daraja: STK push and Paybill (C2B) payments for school
    // subscriptions (docs/billing.md).
    'mpesa' => [
        'env' => env('MPESA_ENV', 'sandbox'),   // sandbox | production
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        // The Paybill (business short code) STK pushes charge and C2B payments reach.
        'shortcode' => env('MPESA_SHORTCODE'),
        // Paybill payments (C2B). In production this is the same Paybill as
        // above; the sandbox issues a separate C2B test shortcode (600xxx).
        'c2b_shortcode' => env('MPESA_C2B_SHORTCODE') ?: env('MPESA_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        // Public https base Safaricom calls back, e.g. https://api.educa.co.ke (a tunnel in development).
        'callback_base_url' => rtrim((string) env('MPESA_CALLBACK_BASE_URL', ''), '/'),
        // Daraja does not sign callbacks: the webhook URLs carry this secret instead.
        'webhook_secret' => env('MPESA_WEBHOOK_SECRET'),
        // Optional comma-separated list of IPs allowed to call the webhooks (Safaricom's, in production).
        'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('MPESA_ALLOWED_IPS', ''))))),

        // Local development: `composer run dev` opens an ngrok tunnel
        // (`php artisan mpesa:tunnel`) and M-Pesa calls back through it while
        // MPESA_CALLBACK_BASE_URL is empty. Never used outside APP_ENV=local.
        'ngrok' => [
            'enabled' => (bool) env('NGROK_ENABLED', true),
            'bin' => env('NGROK_BIN', 'ngrok'),
            // A reserved domain keeps the URL the same on every start, e.g. example.ngrok-free.dev.
            'domain' => env('NGROK_DOMAIN'),
            'upstream' => env('NGROK_UPSTREAM', '127.0.0.1:8000'),
        ],
    ],

];
