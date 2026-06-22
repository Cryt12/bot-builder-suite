<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
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

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'gemma4:e4b'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'url'     => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        'model'   => env('OPENROUTER_MODEL', 'google/gemma-4:mini'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

];
