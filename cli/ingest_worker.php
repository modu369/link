#!/usr/bin/env php
<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);

$options = getopt('', ['max::', 'sleep::', 'loop::', 'worker::', 'workers::']);
$batchSize = max(1, (int) ($options['max'] ?? 500));
$sleepSeconds = max(0, (int) ($options['sleep'] ?? 1));
$loop = array_key_exists('loop', $options);
$workerIndex = max(1, (int) ($options['worker'] ?? 1));
$workerCount = max(1, (int) ($options['workers'] ?? 1));

do {
    $processed = $tracker->drainIngestQueue($batchSize);

    echo sprintf(
        "[ingest worker %d/%d] processed=%d batch=%d\n",
        $workerIndex,
        $workerCount,
        $processed,
        $batchSize
    );

    if (!$loop) {
        break;
    }

    if ($processed === 0 && $sleepSeconds > 0) {
        sleep($sleepSeconds);
    }
} while (true);
