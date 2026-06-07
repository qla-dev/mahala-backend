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

    'google' => [
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))
        ))),
    ],

    'apple' => [
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('APPLE_CLIENT_IDS', ''))
        ))),
    ],

    'revenuecat' => [
        'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
        'pro_entitlement_id' => env('REVENUECAT_PRO_ENTITLEMENT_ID', 'pro'),
    ],

    'post_ai_moderation' => [
        'enabled' => filter_var(env('POST_AI_MODERATION_ENABLED', false), FILTER_VALIDATE_BOOL),
        'text_model' => env('POST_AI_MODERATION_TEXT_MODEL', 'google/gemini-flash-1.5-8b'),
        'vision_model' => env('POST_AI_MODERATION_VISION_MODEL', 'google/gemini-2.0-flash-001'),
        'timeout' => (int) env('POST_AI_MODERATION_TIMEOUT', 45),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'http_referer' => env('OPENROUTER_HTTP_REFERER', env('APP_URL')),
        'title' => env('OPENROUTER_TITLE', env('APP_NAME')),
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

];
