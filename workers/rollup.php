<?php

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';

$config = require __DIR__ . '/../config/config.php';

$options = getopt('', [
    'days::',
    'start::',
    'end::',
    'interval::',
    'once',
]);

$intervalSeconds = isset($options['interval']) ? (int) $options['interval'] : 300;
$runOnce = array_key_exists('once', $options);

$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);

do {
    $today = new DateTimeImmutable('today');
    if (!empty($options['start'])) {
        $startDate = new DateTimeImmutable($options['start']);
        $endDate = !empty($options['end']) ? new DateTimeImmutable($options['end']) : $today;
    } else {
        $days = isset($options['days']) ? (int) $options['days'] : 1;
        $days = max(1, $days);
        $startDate = $today->modify(sprintf('-%d days', $days));
        $endDate = $today;
    }

    $cursor = $startDate;
    $totalRows = 0;
    while ($cursor < $endDate) {
        $next = $cursor->modify('+1 day');
        $statement = $db->prepare(
            'INSERT INTO pageview_rollups (site_id, period_start, pageviews, unique_visitors, unique_ips)
            SELECT site_id,
                   DATE(occurred_at) AS period_start,
                   COUNT(*) AS pageviews,
                   SUM(is_unique = 1) AS unique_visitors,
                   COUNT(DISTINCT ip_hash) AS unique_ips
            FROM pageviews
            WHERE occurred_at >= :start AND occurred_at < :end
            GROUP BY site_id, DATE(occurred_at)
            ON DUPLICATE KEY UPDATE
                pageviews = VALUES(pageviews),
                unique_visitors = VALUES(unique_visitors),
                unique_ips = VALUES(unique_ips),
                updated_at = NOW()'
        );

        $statement->execute([
            ':start' => $cursor->format('Y-m-d H:i:s'),
            ':end' => $next->format('Y-m-d H:i:s'),
        ]);

        $totalRows += $statement->rowCount();

        $delaySeconds = max(0, time() - $next->getTimestamp());
        $redis->hMSet('metrics:rollup', [
            'last_rollup_at' => date('Y-m-d H:i:s'),
            'last_rollup_start' => $cursor->format('Y-m-d'),
            'last_rollup_end' => $next->format('Y-m-d'),
            'last_rollup_delay_seconds' => $delaySeconds,
        ]);

        $cursor = $next;
    }

    $redis->hMSet('metrics:rollup', [
        'last_rollup_rows' => $totalRows,
        'last_rollup_finished_at' => date('Y-m-d H:i:s'),
    ]);

    if ($runOnce) {
        break;
    }

    sleep(max(1, $intervalSeconds));
} while (true);
