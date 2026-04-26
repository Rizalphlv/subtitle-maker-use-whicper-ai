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

    'openai' => [
        'api_key'   => env('OPENAI_API_KEY'),
        'endpoint'  => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1'),
        'model'     => env('OPENAI_WHISPER_MODEL', 'whisper-1'),
    ],

    'groq' => [
        'api_key'   => env('GROQ_API_KEY'),
        'endpoint'  => env('GROQ_ENDPOINT', 'https://api.groq.com/openai/v1'),
        'model'     => env('GROQ_WHISPER_MODEL', 'whisper-large-v3'),
    ],

];
