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
    'ipdb' => [
        // QQWry IPIP.net 格式库路径，可替换为实际部署路径
        'path' => getenv('IPDB_PATH') ?: __DIR__ . '/../data/qqwry.ipdb',
    ],
    'app' => [
        'base_url' => getenv('APP_BASE_URL') ?: 'http://localhost',
        'admin' => [
            'user' => getenv('ADMIN_USER') ?: 'admin',
            'pass' => getenv('ADMIN_PASS') ?: 'admin123',
        ],
    ],
    'branding' => [
        'base_url' => getenv('APP_BASE_URL') ?: 'http://localhost',
        'brand_title' => getenv('APP_BRAND_TITLE') ?: 'V6统计后台',
        'brand_subtitle' => getenv('APP_BRAND_SUBTITLE') ?: '亿级数据索引优化',
    ],
    'security' => [
        // 隐蔽登录入口标识，访问 /index.php?entry=xxx 时才会展示登录页
        'login_entry' => getenv('LOGIN_ENTRY') ?: 'admin',
    ],
    'retention' => [
        // 数据保留天数，超过后自动清理；设置为 0 可关闭
        'days' => (int) (getenv('RETENTION_DAYS') ?: 180),
        // 每天定时清理的小时（0-23）
        'cleanup_hour' => (int) (getenv('RETENTION_CLEANUP_HOUR') ?: 3),
    ],
    'queue' => [
        'enabled' => (bool) (getenv('QUEUE_ENABLED') ?: false),
        'key' => getenv('QUEUE_KEY') ?: 'queue:pageviews',
        'batch_size' => (int) (getenv('QUEUE_BATCH_SIZE') ?: 1000),
        'sharding' => [
            // site_id 或 time
            'strategy' => getenv('QUEUE_SHARD_STRATEGY') ?: 'time',
            // site_id 分片时生效
            'count' => (int) (getenv('QUEUE_SHARD_COUNT') ?: 8),
            // time 分片时生效
            'time_format' => getenv('QUEUE_SHARD_TIME_FORMAT') ?: 'YmdH',
            // 活跃分片集合 TTL（秒）
            'shards_set_ttl' => (int) (getenv('QUEUE_SHARDS_TTL') ?: 172800),
        ],
    ],
];
