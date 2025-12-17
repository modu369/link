<?php
return [
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=tjceshi;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'tjceshi',
        'pass' => getenv('DB_PASS') ?: '123456',
    ],
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => getenv('REDIS_PORT') ?: 6379,
        'prefix' => getenv('REDIS_PREFIX') ?: 'tracker:',
    ],
    'app' => [
        'base_url' => getenv('APP_BASE_URL') ?: 'http://localhost',
        'admin' => [
            'user' => getenv('ADMIN_USER') ?: 'admin',
            'pass' => getenv('ADMIN_PASS') ?: 'admin123',
        ],
    ],
    'retention' => [
        // 数据保留天数，超过后自动清理；设置为 0 可关闭
        'days' => (int) (getenv('RETENTION_DAYS') ?: 180),
        // 每天定时清理的小时（0-23）
        'cleanup_hour' => (int) (getenv('RETENTION_CLEANUP_HOUR') ?: 3),
    ],
];
