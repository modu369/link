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
        // Redis 队列名称
        'name' => getenv('PAGEVIEW_QUEUE') ?: 'pageview_queue',
        // auto_drain 频率（秒），降低频率以减少瞬时负载
        'auto_drain_seconds' => (int) (getenv('AUTO_DRAIN_SECONDS') ?: 30),
        'auto_drain_batch' => (int) (getenv('AUTO_DRAIN_BATCH') ?: 50),
        // Worker 批量处理与 backoff 配置
        'worker_batch' => (int) (getenv('WORKER_BATCH') ?: 200),
        'worker_sleep_ms' => (int) (getenv('WORKER_SLEEP_MS') ?: 200),
        'worker_max_sleep_ms' => (int) (getenv('WORKER_MAX_SLEEP_MS') ?: 2000),
    ],
    'rollup' => [
        // 仅保留小时间窗 pageviews，历史数据通过 rollup 读取
        'pageview_window_days' => (int) (getenv('PAGEVIEW_WINDOW_DAYS') ?: 3),
        // rollup 延迟窗口，避免聚合未完成的小时
        'lag_minutes' => (int) (getenv('ROLLUP_LAG_MINUTES') ?: 10),
        // 首次 rollup 回补小时数
        'backfill_hours' => (int) (getenv('ROLLUP_BACKFILL_HOURS') ?: 24),
        // rollup 触发频率（秒）
        'interval_seconds' => (int) (getenv('ROLLUP_INTERVAL_SECONDS') ?: 30),
        // 清理任务频率（秒）
        'cleanup_interval_seconds' => (int) (getenv('ROLLUP_CLEANUP_INTERVAL_SECONDS') ?: 300),
        // 是否在请求线程执行 retention 清理（默认关闭）
        'cleanup_in_request' => (bool) (getenv('ROLLUP_CLEANUP_IN_REQUEST') ?: false),
    ],
];
