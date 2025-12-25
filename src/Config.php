<?php

return [
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=ceshi;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'ceshi',
        'password' => getenv('DB_PASSWORD') ?: 'pai123456',
    ],
    'round' => [
        'duration_minutes' => getenv('ROUND_DURATION_MINUTES') ?: 60,
        'active_window_minutes' => getenv('RULE_ACTIVE_WINDOW_MINUTES') ?: 5,
    ],
    'polymarket' => [
        'event_slug' => getenv('POLYMARKET_EVENT_SLUG') ?: 'btc-updown-15m-%d',
        'event_slug_template' => getenv('POLYMARKET_EVENT_SLUG_TEMPLATE') ?: 'btc-updown-15m-%d',
        'market_urls' => [
            getenv('POLYMARKET_MARKET_URL') ?: 'https://clob.polymarket.com/markets?slug=%s',
            'https://data-api.polymarket.com/markets?slug=%s',
        ],
        'event_urls' => [
            getenv('POLYMARKET_EVENT_URL') ?: 'https://data-api.polymarket.com/events?slug=%s',
        ],
        'event_page_url' => getenv('POLYMARKET_EVENT_PAGE_URL') ?: 'https://polymarket.com/event/%s',
        'ws_live_url' => getenv('POLYMARKET_WS_LIVE_URL') ?: 'wss://ws-live-data.polymarket.com',
        'ws_market_url' => getenv('POLYMARKET_WS_MARKET_URL') ?: 'wss://ws-subscriptions-clob.polymarket.com/ws/market',
        'up_asset_id' => getenv('POLYMARKET_UP_ASSET_ID') ?: '',
        'down_asset_id' => getenv('POLYMARKET_DOWN_ASSET_ID') ?: '',
        'user_agent' => getenv('POLYMARKET_USER_AGENT') ?: 'Mozilla/5.0 (compatible; PolymarketMonitor/1.0)',
        'timeout_seconds' => getenv('POLYMARKET_TIMEOUT_SECONDS') ?: 5,
    ],
    'polling' => [
        'interval_ms' => getenv('POLL_INTERVAL_MS') ?: 500,
    ],
    'price' => [
        'provider_urls' => [
            getenv('PRICE_PROVIDER_URL') ?: 'https://data-api.polymarket.com/prices?symbol=BTC',
            'https://api.coinbase.com/v2/prices/BTC-USD/spot',
        ],
        'timeout_seconds' => 5,
        'cache_path' => getenv('PRICE_CACHE_PATH') ?: __DIR__ . '/../storage/price.json',
    ],
    'market' => [
        'cache_path' => getenv('MARKET_CACHE_PATH') ?: __DIR__ . '/../storage/market.json',
    ],
    'worker' => [
        'interval_ms' => getenv('WORKER_INTERVAL_MS') ?: 500,
    ],
];
