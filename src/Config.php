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
        'event_slug' => getenv('POLYMARKET_EVENT_SLUG') ?: 'btc-updown-15m-1766628900',
        'event_urls' => [
            getenv('POLYMARKET_EVENT_URL') ?: 'https://polymarket.com/api/event/%s',
            'https://gamma-api.polymarket.com/events?slug=%s',
        ],
        'user_agent' => getenv('POLYMARKET_USER_AGENT') ?: 'Mozilla/5.0 (compatible; PolymarketMonitor/1.0)',
        'timeout_seconds' => getenv('POLYMARKET_TIMEOUT_SECONDS') ?: 5,
    ],
    'polling' => [
        'interval_ms' => getenv('POLL_INTERVAL_MS') ?: 1000,
    ],
];
