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

    // The agent that proposes HTML list settings for a new source (App\Actions\ProposeListSettings).
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1'),
        // USD per million tokens, per model, for the estimated cost of a screening (input / cached input / output); a model not listed, or listed with nulls, has no estimate.
        'prices' => [
            'gpt-5.6-luna' => ['input' => env('OPENAI_PRICE_LUNA_INPUT'), 'cached' => env('OPENAI_PRICE_LUNA_CACHED'), 'output' => env('OPENAI_PRICE_LUNA_OUTPUT')],
            'gpt-5.6-terra' => ['input' => env('OPENAI_PRICE_TERRA_INPUT'), 'cached' => env('OPENAI_PRICE_TERRA_CACHED'), 'output' => env('OPENAI_PRICE_TERRA_OUTPUT')],
            'gpt-5.6-sol' => ['input' => env('OPENAI_PRICE_SOL_INPUT'), 'cached' => env('OPENAI_PRICE_SOL_CACHED'), 'output' => env('OPENAI_PRICE_SOL_OUTPUT')],
            'gpt-6-astra' => ['input' => env('OPENAI_PRICE_ASTRA_INPUT'), 'cached' => env('OPENAI_PRICE_ASTRA_CACHED'), 'output' => env('OPENAI_PRICE_ASTRA_OUTPUT')],
        ],
        // USD per million tokens for the image models (text input / image output), from the pricing page as of 2026-09-23.
        'image_prices' => [
            'gpt-image-2.5-flare' => ['input' => 5.00, 'output' => 30.00],
            'gpt-image-2.5-sunburst' => ['input' => 5.00, 'output' => 30.00],
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
