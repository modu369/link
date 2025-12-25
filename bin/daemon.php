<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MarketDataService.php';
require_once __DIR__ . '/../src/PolymarketApiClient.php';
require_once __DIR__ . '/../src/RoundService.php';
require_once __DIR__ . '/../src/AccountService.php';
require_once __DIR__ . '/../src/RuleService.php';
require_once __DIR__ . '/../src/TradeService.php';
require_once __DIR__ . '/../src/PolymarketClient.php';
require_once __DIR__ . '/../src/WorkerRunner.php';

$config = require __DIR__ . '/../src/Config.php';
$intervalMs = (int) $config['worker']['interval_ms'];
$intervalMs = $intervalMs > 0 ? $intervalMs : 500;

$runner = new WorkerRunner();

while (true) {
    try {
        $runner->runOnce();
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }

    usleep($intervalMs * 1000);
}
