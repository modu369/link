#!/usr/bin/env php
<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

ini_set('memory_limit', '512M');

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);
$ingestQueueKey = $config['ingest']['queue_key'] ?? 'tracker:ingest:pageviews';
$ingestProcessingKey = $config['ingest']['processing_key'] ?? 'tracker:ingest:pageviews:processing';
$botQueueKey = $config['ingest']['bot_queue_key'] ?? 'tracker:ingest:bot_logs';
$botProcessingKey = $config['ingest']['bot_processing_key'] ?? 'tracker:ingest:bot_logs:processing';
$blockedQueueKey = $config['ingest']['blocked_domain_queue_key'] ?? 'tracker:ingest:blocked_domains';
$blockedProcessingKey = $config['ingest']['blocked_domain_processing_key'] ?? 'tracker:ingest:blocked_domains:processing';

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
    $botQueueLen = (int) $redis->lLen($botQueueKey);
    $botProcessingLen = (int) $redis->lLen($botProcessingKey);
    $blockedQueueLen = (int) $redis->lLen($blockedQueueKey);
    $blockedProcessingLen = (int) $redis->lLen($blockedProcessingKey);
    $processed = $tracker->drainIngestQueue($batchSize);
    $botProcessed = $tracker->drainBotQueue($batchSize);
    $blockedProcessed = $tracker->drainBlockedDomainQueue($batchSize);
    $elapsed = microtime(true) - $loopStarted;

    echo sprintf(
        "[ingest worker %d/%d] processed=%d batch=%d queue=%d processing=%d bot_processed=%d bot_queue=%d bot_processing=%d blocked_processed=%d blocked_queue=%d blocked_processing=%d elapsed=%.2fs\n",
        $workerIndex,
        $workerCount,
        $processed,
        $batchSize,
        $queueLen,
        $processingLen,
        $botProcessed,
        $botQueueLen,
        $botProcessingLen,
        $blockedProcessed,
        $blockedQueueLen,
        $blockedProcessingLen,
        $elapsed
    );

    if ($queueLen > ($batchSize * 5) || $botQueueLen > ($batchSize * 5) || $blockedQueueLen > ($batchSize * 5)) {
        echo sprintf(
            "[ingest worker %d/%d] backlog_high queue=%d bot_queue=%d blocked_queue=%d (consider increasing workers or batch size if CPU/DB allow)\n",
            $workerIndex,
            $workerCount,
            $queueLen,
            $botQueueLen,
            $blockedQueueLen
        );
    }

    if (!$loop) {
        break;
    }

    if ($sleepSeconds > 0) {
        sleep($sleepSeconds);
    }
} while (true);
