<?php

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';
require __DIR__ . '/../src/PageviewQueue.php';

$config = require __DIR__ . '/../config/config.php';

$options = getopt('', [
    'batch-size::',
    'sleep::',
    'once',
    'shards::',
]);

$queue = new PageviewQueue(RedisClient::connection($config['redis']), $config['queue'] ?? []);
$batchSize = isset($options['batch-size']) ? (int) $options['batch-size'] : $queue->defaultBatchSize();
$batchSize = max(1, min(10000, $batchSize));
$sleepSeconds = isset($options['sleep']) ? (float) $options['sleep'] : 1.0;
$runOnce = array_key_exists('once', $options);
$shardArg = $options['shards'] ?? '';
$requestedShards = $shardArg ? array_filter(array_map('trim', explode(',', $shardArg))) : [];

$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);

do {
    $loopStart = microtime(true);
    $processed = 0;
    $queueDepth = 0;
    $shards = $queue->listShards();

    if (!empty($requestedShards)) {
        $shards = array_values(array_intersect($shards, $requestedShards));
    }

    foreach ($shards as $shard) {
        $queueDepth += $queue->getQueueDepth($shard);
        $batch = $queue->fetchBatch($shard, $batchSize);
        if (empty($batch)) {
            continue;
        }

        $events = [];
        foreach ($batch as $item) {
            $decoded = json_decode($item, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        if (!empty($events)) {
            $tracker->recordPageviews($events, $batchSize);
            $processed += count($events);
        }
    }

    $durationMs = (int) ((microtime(true) - $loopStart) * 1000);
    $metrics = [
        'queue_depth' => $queueDepth,
        'last_batch_ms' => $durationMs,
        'last_batch_size' => $processed,
        'last_processed_at' => date('Y-m-d H:i:s'),
    ];

    $redis->hMSet('metrics:ingest', $metrics);
    if ($processed > 0) {
        $redis->hIncrBy('metrics:ingest', 'processed_total', $processed);
    }

    if ($runOnce) {
        break;
    }

    if ($processed === 0) {
        usleep((int) ($sleepSeconds * 1000000));
    }
} while (true);
