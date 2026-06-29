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

    'serpapi' => [
        'api_key' => env('SERPAPI_API_KEY'),
    ],

    'socrata' => [
        'app_token' => env('SOCRATA_APP_TOKEN'),
        'endpoints' => [
            'nyc' => [
                'domain' => 'data.cityofnewyork.us',
                'dataset_id' => env('SOCRATA_NYC_DATASET_ID', 'py6s-7cay'),
                'fields' => ['dba', 'address', 'boro', 'zip', 'phone', 'latitude', 'longitude', 'grade', 'score', 'inspection_date'],
            ],
            'sf' => [
                'domain' => 'data.sfgov.org',
                'dataset_id' => env('SOCRATA_SF_DATASET_ID', 'vw6y-z8j6'),
                'fields' => ['business_name', 'street_address', 'city', 'postal_code', 'phone', 'latitude', 'longitude', 'inspection_score', 'inspection_date'],
            ],
        ],
    ],

    'ai' => [
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model' => env('AI_MODEL', 'llama-3.3-70b-versatile'),
    ],

];
