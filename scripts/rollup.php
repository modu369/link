<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$config = require __DIR__ . '/../config/config.php';

try {
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    $tracker = new Tracker($db, $redis, $config);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to initialize tracker: {$e->getMessage()}\n");
    exit(1);
}

$options = getopt('', ['lookback::', 'delay::']);
$lookback = max(1, (int) ($options['lookback'] ?? 2));
$delay = max(0, (int) ($options['delay'] ?? 1));

$now = new DateTimeImmutable('now');
$windowEnd = $now->modify("-{$delay} minutes");
$windowStart = $windowEnd->modify("-{$lookback} minutes");

$tracker->rollupHourlyWindow($windowStart, $windowEnd);
echo sprintf(
    "Rolled up pageviews from %s to %s\n",
    $windowStart->format('Y-m-d H:i:s'),
    $windowEnd->format('Y-m-d H:i:s')
);
