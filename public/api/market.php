<?php

require __DIR__ . '/../../src/Database.php';
require __DIR__ . '/../../src/PriceService.php';
require __DIR__ . '/../../src/RoundService.php';

header('Content-Type: application/json');

try {
    $roundService = new RoundService();
    $priceService = new PriceService();

    $round = $roundService->getCurrentRound();
    $currentPrice = $priceService->fetchCurrentPrice();

    $openPrice = (float) $round['open_price'];
    $priceDelta = $currentPrice - $openPrice;
    $upPosition = max(0, min(100, 50 + ($priceDelta / $openPrice) * 100));
    $downPosition = 100 - $upPosition;

    echo json_encode([
        'open_time' => $round['open_time'],
        'close_time' => $round['close_time'],
        'open_price' => $openPrice,
        'current_price' => $currentPrice,
        'up_position' => round($upPosition, 2),
        'down_position' => round($downPosition, 2),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
