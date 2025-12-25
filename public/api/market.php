<?php

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/MarketDataService.php';
require_once __DIR__ . '/../../src/PolymarketApiClient.php';
require_once __DIR__ . '/../../src/RoundService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $roundService = new RoundService();
    $round = $roundService->getCurrentRound();

    $config = require __DIR__ . '/../../src/Config.php';

    echo json_encode([
        'event_title' => $round['event_title'],
        'open_time' => $round['open_time'],
        'close_time' => $round['close_time'],
        'open_price' => $round['open_price'],
        'event_slug' => $config['polymarket']['event_slug'],
        'ws_live_url' => $config['polymarket']['ws_live_url'],
        'ws_market_url' => $config['polymarket']['ws_market_url'],
        'up_asset_id' => $config['polymarket']['up_asset_id'],
        'down_asset_id' => $config['polymarket']['down_asset_id'],
        'server_time' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
