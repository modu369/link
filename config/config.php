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
        // 汇总与维度表保留天数，超过后自动清理；设置为 0 可关闭
        'days' => (int) (getenv('RETENTION_DAYS') ?: 180),
        // pageviews 表保留天数，允许与汇总表不同
        'pageviews_days' => (int) (getenv('RETENTION_PAGEVIEWS_DAYS') ?: 60),
        // 每天定时清理的小时（0-23）
        'cleanup_hour' => (int) (getenv('RETENTION_CLEANUP_HOUR') ?: 3),
    ],
    'ingest' => [
        // 采集模式：direct 直接写库；queue 写入 Redis 队列由后台任务异步入库
        'mode' => getenv('INGEST_MODE') ?: 'queue',
        'queue_key' => getenv('INGEST_QUEUE_KEY') ?: 'tracker:ingest:pageviews',
        // 处理中队列，避免消费异常导致数据丢失
        'processing_key' => getenv('INGEST_PROCESSING_KEY') ?: 'tracker:ingest:pageviews:processing',
        // 队列长度上限，防止异常堆积；0 表示不限制
        'max_queue_length' => (int) (getenv('INGEST_QUEUE_MAX') ?: 100000),
        // 自动抽样消费队列，避免忘记启动 worker 时数据堆积
        'auto_drain' => getenv('INGEST_AUTO_DRAIN') === false
            ? true
            : (filter_var(getenv('INGEST_AUTO_DRAIN'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true),
        'auto_drain_every' => (int) (getenv('INGEST_AUTO_DRAIN_EVERY') ?: 20),
        'auto_drain_batch' => (int) (getenv('INGEST_AUTO_DRAIN_BATCH') ?: 50),
        // 处理中的数据超过该秒数将重新入队
        'stalled_after' => (int) (getenv('INGEST_STALLED_AFTER') ?: 300),
    ],
];
