<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$config = require __DIR__ . '/../config/config.php';

$options = getopt('', ['days::', 'pageviews-days::', 'batch-size::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php cli/cleanup_retention.php [--days=90] [--pageviews-days=30] [--batch-size=50000]\n";
    echo "If arguments are omitted, configured retention settings are used.\n";
    exit(0);
}

try {
    $db = Database::connection($config['db']);
    $redis = RedisClient::connection($config['redis']);
    $tracker = new Tracker($db, $redis, $config);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to initialize tracker: {$e->getMessage()}\n");
    exit(1);
}

$retention = $tracker->getRetentionSettings($config['retention'] ?? []);
$rollupDays = isset($options['days']) ? max(0, (int) $options['days']) : (int) ($retention['days'] ?? 0);
$pageviewsDays = isset($options['pageviews-days'])
    ? max(0, (int) $options['pageviews-days'])
    : (int) ($retention['pageviews_days'] ?? 0);
$batchSize = isset($options['batch-size']) ? max(1000, (int) $options['batch-size']) : null;

if ($rollupDays <= 0 && $pageviewsDays <= 0) {
    echo "No retention days configured; nothing to clean.\n";
    exit(0);
}

$tracker->manualCleanup($rollupDays, $pageviewsDays, $batchSize);
echo "Cleanup completed. Rollups: {$rollupDays} days, Pageviews: {$pageviewsDays} days.\n";
