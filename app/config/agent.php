<?php

declare(strict_types=1);

return [
    'anthropic' => [
        'api_key'    => env('ANTHROPIC_API_KEY', ''),
        'model'      => env('ANTHROPIC_MODEL', 'claude-opus-4-6'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
    ],

    'approval' => [
        'auto_approve_threshold'  => (float) env('AGENT_AUTO_APPROVE_THRESHOLD', 0.10),
        'reject_if_savings_above' => (float) env('AGENT_REJECT_IF_SAVINGS_ABOVE', 0.20),
        'default_budget'          => (float) env('AGENT_DEFAULT_BUDGET', 1000.00),
    ],

    'onfly' => [
        'api_url'       => env('ONFLY_API_URL', 'https://api.onfly.com'),
        'gateway_url'   => env('ONFLY_GATEWAY_URL', 'https://toguro-app-prod.onfly.com'),
        'client_id'     => env('ONFLY_CLIENT_ID', '1212'),
        'client_secret' => env('ONFLY_CLIENT_SECRET', 'fLWgKiTE4qmkx7pXwfEcTB7yNjKiisygEbbinWEV'),
        'api_token'     => env('ONFLY_API_TOKEN', ''),
        'refresh_token' => env('ONFLY_REFRESH_TOKEN', ''),
    ],
];
