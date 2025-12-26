<?php

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/PriceCache.php';
require_once __DIR__ . '/../../src/MarketCache.php';
require_once __DIR__ . '/../../src/MarketCacheService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = require __DIR__ . '/../../src/Config.php';
$cacheService = new MarketCacheService($config);
$payload = $cacheService->readRealtime();

echo json_encode([
    'current_price' => $payload['current_price'],
    'current_timestamp_ms' => $payload['current_timestamp_ms'],
    'up_position' => $payload['up_position'],
    'down_position' => $payload['down_position'],
    'market_timestamp_ms' => $payload['market_timestamp_ms'],
]);
