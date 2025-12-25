<?php

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/MarketDataService.php';
require __DIR__ . '/../src/PolymarketApiClient.php';
require __DIR__ . '/../src/RoundService.php';
require __DIR__ . '/../src/AccountService.php';
require __DIR__ . '/../src/RuleService.php';
require __DIR__ . '/../src/TradeService.php';
require __DIR__ . '/../src/PolymarketClient.php';
require __DIR__ . '/../src/WorkerRunner.php';

$runner = new WorkerRunner();

try {
    $runner->runOnce();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Worker completed at ' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . PHP_EOL);
