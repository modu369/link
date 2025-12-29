#!/usr/bin/env php
<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);
$ingestQueueKey = $config['ingest']['queue_key'] ?? 'tracker:ingest:pageviews';
$ingestProcessingKey = $config['ingest']['processing_key'] ?? 'tracker:ingest:pageviews:processing';

$options = getopt('', ['max::', 'sleep::', 'loop::', 'worker::', 'workers::']);
$batchSize = max(1, (int) ($options['max'] ?? 500));
$sleepSeconds = max(0, (int) ($options['sleep'] ?? 1));
$loop = array_key_exists('loop', $options);
$workerIndex = max(1, (int) ($options['worker'] ?? 1));
$workerCount = max(1, (int) ($options['workers'] ?? 1));

do {
    $loopStarted = microtime(true);
    $queueLen = (int) $redis->lLen($ingestQueueKey);
    $processingLen = (int) $redis->lLen($ingestProcessingKey);
    $processed = $tracker->drainIngestQueue($batchSize);
    $elapsed = microtime(true) - $loopStarted;

    echo sprintf(
        "[ingest worker %d/%d] processed=%d batch=%d queue=%d processing=%d elapsed=%.2fs\n",
        $workerIndex,
        $workerCount,
        $processed,
        $batchSize,
        $queueLen,
        $processingLen,
        $elapsed
    );

    if ($queueLen > ($batchSize * 5)) {
        echo sprintf(
            "[ingest worker %d/%d] backlog_high queue=%d (consider increasing workers or batch size if CPU/DB allow)\n",
            $workerIndex,
            $workerCount,
            $queueLen
        );
    }

    if (!$loop) {
        break;
    }

    if ($processed === 0 && $sleepSeconds > 0) {
        sleep($sleepSeconds);
    }
} while (true);
