<?php

return [
    'app' => [
        'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
        'poll_interval_ms' => (int) (getenv('APP_POLL_INTERVAL_MS') ?: 1000),
    ],
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=polymarket;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'polymarket',
        'password' => getenv('DB_PASSWORD') ?: 'polymarket',
    ],
    'polymarket' => [
        'gamma_base_url' => getenv('POLYMARKET_GAMMA_BASE_URL') ?: 'https://gamma-api.polymarket.com',
        'gamma_events_endpoint' => getenv('POLYMARKET_GAMMA_EVENTS_ENDPOINT') ?: '/events/pagination',
        'gamma_default_tag' => getenv('POLYMARKET_GAMMA_DEFAULT_TAG') ?: '15M',
        'gamma_default_limit' => (int) (getenv('POLYMARKET_GAMMA_DEFAULT_LIMIT') ?: 1),
        'clob_base_url' => getenv('POLYMARKET_CLOB_BASE_URL') ?: 'https://clob.polymarket.com',
        'order_endpoint' => getenv('POLYMARKET_ORDER_ENDPOINT') ?: '/orders',
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
    'price_feed' => [
        'base_url' => getenv('PRICE_FEED_BASE_URL') ?: 'https://data-api.polymarket.com',
        'endpoint' => getenv('PRICE_FEED_ENDPOINT') ?: '/prices/btc-usd',
        'price_path' => getenv('PRICE_FEED_PATH') ?: 'price',
    ],
];
