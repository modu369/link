<?php
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/RedisClient.php';
require __DIR__ . '/src/PageviewRollupWorker.php';

$config = require __DIR__ . '/config/config.php';

try {
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    $worker = new PageviewRollupWorker($db, $redis, $config);
} catch (Throwable $e) {
    fwrite(STDERR, "rollup worker init failed: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}

$worker->run();
