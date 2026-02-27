#!/usr/bin/env php
<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

ini_set('memory_limit', '512M');

$config = require __DIR__ . '/../config/config.php';

// 【修改点1】将服务初始化包裹在 try-catch 中。如果启动时数据库/Redis不通，直接退出，等待守护进程重试
try {
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    $tracker = new Tracker($db, $redis, $config);
} catch (Throwable $e) {
    echo "[ingest worker] Initialization failed: " . $e->getMessage() . "\n";
    exit(1); // 返回非 0 状态码，进程守护会自动重启
}

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

    // 【修改点2】主动探活：每次执行批量消费前，检测 MySQL 和 Redis 连接是否存活
    // 如果发生 "MySQL server has gone away" 或超时，这里会立即触发异常并退出进程
    try {
        $db->query("SELECT 1");
        $redis->ping();
    } catch (Throwable $e) {
        echo sprintf("[ingest worker %d/%d] Fatal: Connection lost (%s). Exiting for restart...\n", $workerIndex, $workerCount, $e->getMessage());
        exit(1); 
    }

    $hasMoreData = false;

    // 【修改点3】将业务消费逻辑包裹，防止由于查询超时引发的进程假死
    try {
        $queueLen = (int) $redis->lLen($ingestQueueKey);
        $processingLen = (int) $redis->lLen($ingestProcessingKey);
        $botQueueLen = (int) $redis->lLen($botQueueKey);
        $botProcessingLen = (int) $redis->lLen($botProcessingKey);
        $blockedQueueLen = (int) $redis->lLen($blockedQueueKey);
        $blockedProcessingLen = (int) $redis->lLen($blockedProcessingKey);
        
        $processed = (int) $tracker->drainIngestQueue($batchSize);
        $botProcessed = (int) $tracker->drainBotQueue($batchSize);
        $blockedProcessed = (int) $tracker->drainBlockedDomainQueue($batchSize);
        
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

        // 【核心逻辑新增】判断本轮处理是否跑满了最大额度
        // 如果任意一个队列的处理量达到了 batchSize，说明里面很可能还有货，标记为需要继续抽干
        $hasMoreData = ($processed === $batchSize || $botProcessed === $batchSize || $blockedProcessed === $batchSize);

    } catch (Throwable $e) {
        // 如果处理中发生未被 Tracker 拦截的致命异常，同样退出进程交由守护程序重启
        echo sprintf("[ingest worker %d/%d] Execution Error: %s\n", $workerIndex, $workerCount, $e->getMessage());
        exit(1);
    }

    if (!$loop) {
        break;
    }

    // 【满载跳过休眠】：如果判定队列里还有未处理完的数据，跳过下面的休眠，立刻开始下一轮拉取
    if ($hasMoreData) {
        continue;
    }

    // 当所有队列都被彻底抽干（处理数量都小于 batchSize）时，才真正进入休眠
    if ($sleepSeconds > 0) {
        // 计算还需要休眠多久 = 设定的休眠时间 - 最后一轮抽干数据消耗的时间
        $actualSleepSeconds = $sleepSeconds - $elapsed;
        
        // 如果处理时间已经超过了设定的 sleep 时间，那就直接进入下一次循环，不休眠
        if ($actualSleepSeconds > 0) {
            // 取整后进行休眠
            sleep((int)$actualSleepSeconds);
        }
    }
} while (true);