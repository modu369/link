<?php

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/StateStore.php';
require_once __DIR__ . '/../../src/MarketCacheService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $config = require __DIR__ . '/../../src/Config.php';
    $stateStore = new StateStore($config['state']['path']);
    $state = $stateStore->read();
    $cacheService = new MarketCacheService($config);
    $cachePayload = $cacheService->readRealtime();

    $priceToBeat = $state['price_to_beat'] ?? ($cachePayload['price_to_beat'] ?? 0);
    $currentPrice = $state['current_price'] ?? ($cachePayload['current_price'] ?? $priceToBeat);
    $upPosition = $state['up_prob'] ?? ($state['up_cents'] ?? ($cachePayload['up_position'] ?? 0));
    $downPosition = $state['down_prob'] ?? ($state['down_cents'] ?? ($cachePayload['down_position'] ?? 0));
    $bucketStart = $state['bucket_start'] ?? null;
    $openTime = $bucketStart ? (new DateTimeImmutable('@' . (int) $bucketStart))->format('Y-m-d H:i:s') : (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $closeTime = $bucketStart ? (new DateTimeImmutable('@' . (int) $bucketStart))->modify('+15 minutes')->format('Y-m-d H:i:s') : (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
    $eventSlug = $state['slug'] ?? null;
    if (!$eventSlug) {
        $template = $config['polymarket']['event_slug_template'] ?? 'btc-updown-15m-%d';
        $timezone = $config['polymarket']['timezone'] ?? 'Asia/Shanghai';
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $bucketStart = intdiv($now->getTimestamp(), 900) * 900;
        if (str_contains($template, '{timestamp}')) {
            $eventSlug = str_replace('{timestamp}', (string) $bucketStart, $template);
        } elseif (str_contains($template, '%d')) {
            $eventSlug = sprintf($template, $bucketStart);
        } else {
            $eventSlug = $template;
        }
    }

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
