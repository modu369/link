<?php

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/StateStore.php';
require_once __DIR__ . '/../../src/MarketCacheService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = require __DIR__ . '/../../src/Config.php';
$stateStore = new StateStore($config['state']['path']);
$payload = $stateStore->read();
if ($payload === []) {
    $cacheService = new MarketCacheService($config);
    $payload = $cacheService->readRealtime();
}

echo json_encode([
    'current_price' => $payload['current_price'] ?? null,
    'current_timestamp_ms' => $payload['current_ts'] ?? ($payload['current_timestamp_ms'] ?? null),
    'price_to_beat' => $payload['price_to_beat'] ?? null,
    'up_position' => $payload['up_prob'] ?? ($payload['up_cents'] ?? ($payload['up_position'] ?? null)),
    'down_position' => $payload['down_prob'] ?? ($payload['down_cents'] ?? ($payload['down_position'] ?? null)),
    'bucket_start' => $payload['bucket_start'] ?? null,
    'slug' => $payload['slug'] ?? null,
]);
