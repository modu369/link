<?php

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/MarketDataService.php';
require_once __DIR__ . '/../../src/PolymarketApiClient.php';
require_once __DIR__ . '/../../src/RoundService.php';
require_once __DIR__ . '/../../src/MarketCacheService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $roundService = new RoundService();
    $round = $roundService->getCurrentRound();

    $config = require __DIR__ . '/../../src/Config.php';
    $cacheService = new MarketCacheService($config);
    $priceCache = $cacheService->readPrice() ?? [];
    $marketCache = $cacheService->readMarket() ?? [];

    $openPrice = $round['open_price'] ?: ($priceCache['price_to_beat'] ?? $round['open_price']);
    $currentPrice = $round['current_price'] ?: ($priceCache['current_price'] ?? $round['current_price']);
    $upPosition = $round['up_position'] ?: ($marketCache['up_price'] ?? $round['up_position']);
    $downPosition = $round['down_position'] ?: ($marketCache['down_price'] ?? $round['down_position']);

    echo json_encode([
        'event_title' => $round['event_title'],
        'open_time' => $round['open_time'],
        'close_time' => $round['close_time'],
        'open_price' => $openPrice,
        'current_price' => $currentPrice,
        'up_position' => $upPosition,
        'down_position' => $downPosition,
        'event_slug' => $round['event_slug'],
        'ws_live_url' => null,
        'ws_market_url' => null,
        'up_asset_id' => null,
        'down_asset_id' => null,
        'server_time' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
