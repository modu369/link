<?php

require_once __DIR__ . '/../../src/bootstrap.php';

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$side = $payload['side'] ?? 'buy';
$outcome = $payload['outcome'] ?? 'up';
$size = isset($payload['size']) ? (float) $payload['size'] : $config['polymarket']['order_size'];
$price = isset($payload['price']) ? (float) $payload['price'] : null;
$eventSlug = $payload['slug'] ?? $config['polymarket']['event_slug'];
$tagSlug = $config['polymarket']['gamma_default_tag'];
$limit = $config['polymarket']['gamma_default_limit'];

$snapshotResponse = $marketService->fetchMarketSnapshot($eventSlug !== '' ? $eventSlug : null, $tagSlug, $limit);
if (!$snapshotResponse['ok']) {
    http_response_code(502);
    echo json_encode($snapshotResponse);
    exit;
}

$snapshot = $snapshotResponse['data'];
if ($price === null) {
    $price = $outcome === 'down' ? ($snapshot['down_price'] ?? 0) : ($snapshot['up_price'] ?? 0);
}

$result = $tradeService->placeManualOrder($side, $outcome, $size, $price, $snapshot);

header('Content-Type: application/json; charset=utf-8');

echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_SLASHES);
