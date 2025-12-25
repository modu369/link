<?php

require __DIR__ . '/../../src/Database.php';
require __DIR__ . '/../../src/MarketDataService.php';
require __DIR__ . '/../../src/PolymarketApiClient.php';
require __DIR__ . '/../../src/RoundService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $roundService = new RoundService();
    $round = $roundService->getCurrentRound();

    echo json_encode([
        'event_title' => $round['event_title'],
        'open_time' => $round['open_time'],
        'close_time' => $round['close_time'],
        'open_price' => $round['open_price'],
        'current_price' => $round['current_price'],
        'up_position' => $round['up_position'],
        'down_position' => $round['down_position'],
        'server_time' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
