<?php

return [
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=polymarket;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
    'round' => [
        'duration_minutes' => getenv('ROUND_DURATION_MINUTES') ?: 60,
        'active_window_minutes' => getenv('RULE_ACTIVE_WINDOW_MINUTES') ?: 5,
    ],
    'price' => [
        'provider_url' => getenv('PRICE_PROVIDER_URL') ?: 'https://api.coinbase.com/v2/prices/BTC-USD/spot',
        'timeout_seconds' => 5,
    ],
];
