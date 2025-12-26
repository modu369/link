<?php

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/MarketCacheService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $config = require __DIR__ . '/../../src/Config.php';
    $cacheService = new MarketCacheService($config);
    $priceCache = $cacheService->readPrice() ?? [];
    $marketCache = $cacheService->readMarket() ?? [];

    $priceToBeat = $priceCache['price_to_beat'] ?? 0;
    $currentPrice = $priceCache['current_price'] ?? $priceToBeat;
    $upPosition = $marketCache['up_price'] ?? ($priceCache['up_cents'] ?? 0);
    $downPosition = $marketCache['down_price'] ?? ($priceCache['down_cents'] ?? 0);
    $roundStartMs = $priceCache['round_start_ms'] ?? null;
    $openTime = $roundStartMs ? (new DateTimeImmutable('@' . (int) ($roundStartMs / 1000)))->format('Y-m-d H:i:s') : (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $closeTime = $roundStartMs ? (new DateTimeImmutable('@' . (int) ($roundStartMs / 1000)))->modify('+15 minutes')->format('Y-m-d H:i:s') : (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
    $eventSlug = $config['polymarket']['event_slug_template'];

    echo json_encode([
        'event_title' => 'Bitcoin Up or Down',
        'open_time' => $openTime,
        'close_time' => $closeTime,
        'open_price' => $priceToBeat,
        'current_price' => $currentPrice,
        'up_position' => $upPosition,
        'down_position' => $downPosition,
        'event_slug' => $eventSlug,
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
