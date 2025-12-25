<?php

return [
    'app' => [
        'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
        'poll_interval_ms' => (int) (getenv('APP_POLL_INTERVAL_MS') ?: 2000),
    ],
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=polymarket;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'polymarket',
        'password' => getenv('DB_PASSWORD') ?: 'polymarket',
    ],
    'polymarket' => [
        'base_url' => getenv('POLYMARKET_BASE_URL') ?: 'https://polymarket.com',
        'events_endpoint' => getenv('POLYMARKET_EVENTS_ENDPOINT') ?: '/api/events',
        'markets_endpoint' => getenv('POLYMARKET_MARKETS_ENDPOINT') ?: '/api/markets',
        'order_endpoint' => getenv('POLYMARKET_ORDER_ENDPOINT') ?: '/api/orders',
        'api_key' => getenv('POLYMARKET_API_KEY') ?: '',
        'api_secret' => getenv('POLYMARKET_API_SECRET') ?: '',
        'api_passphrase' => getenv('POLYMARKET_API_PASSPHRASE') ?: '',
        'event_slug' => getenv('POLYMARKET_EVENT_SLUG') ?: '',
        'up_label' => getenv('POLYMARKET_UP_LABEL') ?: 'Up',
        'down_label' => getenv('POLYMARKET_DOWN_LABEL') ?: 'Down',
        'buy_threshold' => (float) (getenv('POLYMARKET_BUY_THRESHOLD') ?: 0.55),
        'sell_threshold' => (float) (getenv('POLYMARKET_SELL_THRESHOLD') ?: 0.45),
        'order_size' => (float) (getenv('POLYMARKET_ORDER_SIZE') ?: 10),
    ],
];
