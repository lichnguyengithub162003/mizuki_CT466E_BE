<?php

return [
    'session_ttl_minutes' => (int) env('POS_SESSION_TTL_MINUTES', 30),
    'bank' => [
        'bin' => env('POS_BANK_BIN'),
        'account_number' => env('POS_BANK_ACCOUNT_NUMBER'),
        'account_name' => env('POS_BANK_ACCOUNT_NAME'),
        'name' => env('POS_BANK_NAME'),
        'transfer_prefix' => env('POS_TRANSFER_PREFIX', 'MIZUKI'),
    ],
];
