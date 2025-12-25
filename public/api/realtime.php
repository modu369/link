<?php

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/PriceCache.php';
require_once __DIR__ . '/../../src/MarketCache.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = require __DIR__ . '/../../src/Config.php';
$priceCache = new PriceCache($config['price']['cache_path']);
$marketCache = new MarketCache($config['market']['cache_path']);

$pricePayload = $priceCache->read();
$marketPayload = $marketCache->read();

echo json_encode([
    'current_price' => $pricePayload['current_price'] ?? null,
    'current_timestamp_ms' => $pricePayload['current_timestamp_ms'] ?? null,
    'up_position' => $marketPayload['up_price'] ?? null,
    'down_position' => $marketPayload['down_price'] ?? null,
    'market_timestamp_ms' => $marketPayload['timestamp_ms'] ?? null,
]);
