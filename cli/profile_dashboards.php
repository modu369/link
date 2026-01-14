#!/usr/bin/env php
<?php
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/RedisClient.php';
require __DIR__ . '/../src/Tracker.php';

$options = getopt('', ['site:', 'range::', 'mode::']);

if (!isset($options['site'])) {
    fwrite(STDERR, "Usage: php cli/profile_dashboards.php --site=ID [--range=today] [--mode=overview|trend|both]\n");
    exit(1);
}

$siteId = (int) $options['site'];
$range = $options['range'] ?? 'today';
$mode = $options['mode'] ?? 'both';
$allowedModes = ['overview', 'trend', 'both'];
if (!in_array($mode, $allowedModes, true)) {
    fwrite(STDERR, "Invalid mode, allowed: overview, trend, both\n");
    exit(1);
}

$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$redis = RedisClient::connection($config['redis']);
$tracker = new Tracker($db, $redis, $config);

class SegmentProfiler
{
    private array $segments = [];

    public function run(string $label, callable $callback)
    {
        $start = hrtime(true);
        $error = null;
        $result = null;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;
        $this->segments[] = [
            'label' => $label,
            'ms' => $durationMs,
            'summary' => $this->summarize($result),
            'error' => $error,
        ];

        return $result;
    }

    public function report(string $title): void
    {
        usort($this->segments, fn($a, $b) => $b['ms'] <=> $a['ms']);

        $total = array_reduce($this->segments, fn($carry, $row) => $carry + $row['ms'], 0.0);
        echo "\n== {$title} ==\n";
        echo str_pad('Segment', 32) . str_pad('Time (ms)', 12) . "Summary" . PHP_EOL;
        echo str_repeat('-', 80) . PHP_EOL;

        foreach ($this->segments as $row) {
            $label = str_pad($row['label'], 32);
            $time = str_pad(number_format($row['ms'], 2), 12, ' ', STR_PAD_LEFT);
            $summary = $row['error'] ? '[ERROR] ' . $row['error'] : $row['summary'];
            echo $label . $time . $summary . PHP_EOL;
        }

        echo str_repeat('-', 80) . PHP_EOL;
        echo 'Total: ' . number_format($total, 2) . " ms" . PHP_EOL;
    }

    private function summarize($result): string
    {
        if ($result === null) {
            return 'null';
        }

        if (is_array($result)) {
            if ($result === []) {
                return 'array (empty)';
            }

            $isAssoc = array_keys($result) !== range(0, count($result) - 1);
            if ($isAssoc) {
                $parts = [];
                foreach ($result as $key => $value) {
                    $parts[] = $key . (is_array($value) ? '(' . count($value) . ')' : '');
                    if (count($parts) >= 6) {
                        break;
                    }
                }
                return 'map ' . implode(', ', $parts);
            }

            return 'list len=' . count($result);
        }

        return gettype($result);
    }
}

$profiler = new SegmentProfiler();

if (in_array($mode, ['overview', 'both'], true)) {
    echo "Profiling overview for site {$siteId}, range {$range}\n";
    $profiler->run('overview:totals', fn() => $tracker->getTotals($siteId, $range));
    $profiler->run('overview:daily', fn() => $tracker->getDailyStats($siteId, $range));
    $profiler->run('overview:hourly', fn() => $tracker->getHourlyStats($siteId, $range));
    $profiler->run('overview:trend_lines', fn() => $tracker->getTrendLines($siteId, $range));
    $profiler->run('overview:predictions', fn() => $tracker->getPredictions($siteId));
    $profiler->run('overview:regions', fn() => $tracker->getRegionStats($siteId, $range, 20));
    $profiler->run('overview:china_map', fn() => $tracker->getRegionStats($siteId, $range, 200));
    $profiler->run('overview:country_map', fn() => $tracker->getCountryStats($siteId, $range, 200));
    $profiler->run('overview:devices', fn() => $tracker->getDeviceBreakdown($siteId, $range));
    $profiler->run('overview:browsers', fn() => $tracker->getBrowserBreakdown($siteId, $range, 6));
    $profiler->run('overview:new_vs_returning', fn() => $tracker->getNewVsReturning($siteId, $range));
    $profiler->run('overview:top_referrers', fn() => $tracker->getTopReferrers($siteId, $range));
    $profiler->run('overview:top_pages', fn() => $tracker->getTopPages($siteId, $range, 10));
    $profiler->run('overview:entry_pages', fn() => $tracker->getEntryPages($siteId, $range, 15));
    $profiler->run('overview:composed', fn() => $tracker->getOverview($siteId, $range));

    $profiler->report('Overview timing');
}

if (in_array($mode, ['trend', 'both'], true)) {
    $trendProfiler = new SegmentProfiler();
    echo "\nProfiling trend for site {$siteId}, range {$range}\n";
    $trendProfiler->run('trend:getTrendData', fn() => $tracker->getTrendData($siteId, $range));
    $trendProfiler->run('trend:getOverview', fn() => $tracker->getOverview($siteId, $range));
    $trendProfiler->run('trend:getTrendLines', fn() => $tracker->getTrendLines($siteId, $range));
    $trendProfiler->report('Trend timing');
}
