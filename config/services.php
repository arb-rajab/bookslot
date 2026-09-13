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

    // D-0006/D-0030. STRIPE_APPLICATION_FEE_BPS is the platform's cut of
    // each deposit, taken via application_fee_amount on the destination
    // charge — a config value, not a fabricated business number.
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'application_fee_bps' => (int) env('STRIPE_APPLICATION_FEE_BPS', 0),
    ],

    // D-0051: the RabbitMQ management plugin's HTTP API (already enabled in
    // docker-compose.yml's `rabbitmq` service image, "for local debugging"
    // — this is that same API, now also read by
    // OwnerQueueHealthController for the admin frontend's queue-health
    // view). Deliberately a distinct, separate credential/URL from the
    // AMQP connection config/queue.php's `rabbitmq` connection uses — the
    // management API is a different port and protocol (HTTP, not AMQP),
    // even though it's the same broker and, in every environment this
    // project actually runs in, the same username/password.
    'rabbitmq_management' => [
        'url' => env('RABBITMQ_MANAGEMENT_URL', 'http://127.0.0.1:15672'),
        'user' => env('RABBITMQ_USER', 'bookslot'),
        'password' => env('RABBITMQ_PASSWORD', 'bookslot_local_only'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'queue' => env('RABBITMQ_QUEUE', 'bookslot'),
    ],

];
