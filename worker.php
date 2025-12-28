<?php
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/RedisClient.php';
require __DIR__ . '/src/Tracker.php';

$config = require __DIR__ . '/config/config.php';

$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);

$queueConfig = $config['queue'] ?? [];
$rollupConfig = $config['rollup'] ?? [];

$batchSize = max(1, (int) ($queueConfig['worker_batch'] ?? 200));
$baseSleepMs = max(10, (int) ($queueConfig['worker_sleep_ms'] ?? 200));
$maxSleepMs = max($baseSleepMs, (int) ($queueConfig['worker_max_sleep_ms'] ?? 2000));
$rollupInterval = max(5, (int) ($rollupConfig['interval_seconds'] ?? 30));
$cleanupInterval = max(60, (int) ($rollupConfig['cleanup_interval_seconds'] ?? 300));

$backoffMs = $baseSleepMs;
$lastRollup = 0;
$lastCleanup = 0;

while (true) {
    $processed = $tracker->drainQueuedPageviews($batchSize);

    $now = time();
    if ($now - $lastRollup >= $rollupInterval) {
        $tracker->runRollup();
        $lastRollup = $now;
    }
    if ($now - $lastCleanup >= $cleanupInterval) {
        $tracker->cleanupRetention();
        $tracker->prunePageviewsByWindow();
        $lastCleanup = $now;
    }

    if ($processed === 0) {
        usleep($backoffMs * 1000);
        $backoffMs = min($backoffMs * 2, $maxSleepMs);
    } else {
        $backoffMs = $baseSleepMs;
    }
}
