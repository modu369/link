<?php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PriceCache.php';
require_once __DIR__ . '/../src/WebSocketClient.php';

$config = require __DIR__ . '/../src/Config.php';
$cachePath = $config['price']['cache_path'] ?? __DIR__ . '/../storage/price.json';

$priceCache = new PriceCache($cachePath);

$wsHeaders = [
    'Origin: https://polymarket.com',
    'User-Agent: Mozilla/5.0 (compatible; PolymarketMonitor/1.0)',
];
$liveClient = new WebSocketClient($config['polymarket']['ws_live_url'], $wsHeaders);

$liveClient->connect();
$liveClient->send(json_encode([
    'action' => 'subscribe',
    'subscriptions' => [
        [
            'topic' => 'crypto_prices_chainlink',
            'type' => 'update',
            'filters' => json_encode(['symbol' => 'btc/usd']),
        ],
    ],
]));

function floorToQuarterMs(int $timestampMs): int
{
    $seconds = intdiv($timestampMs, 1000);
    $bucketSeconds = intdiv($seconds, 900) * 900;
    return $bucketSeconds * 1000;
}

while (true) {
    $liveMessage = $liveClient->receive();
    if ($liveMessage !== null) {
        $payload = json_decode($liveMessage, true);
        if (is_array($payload) && ($payload['topic'] ?? '') === 'crypto_prices_chainlink') {
            $payloadData = $payload['payload'] ?? [];
            $price = isset($payloadData['value']) ? (float) $payloadData['value'] : null;
            $timestampMs = (int) ($payloadData['timestamp'] ?? 0);
            if ($price !== null && $timestampMs > 0) {
                $roundStartMs = floorToQuarterMs($timestampMs);
                $cached = $priceCache->read();
                $priceToBeat = $price;
                if (is_array($cached) && ($cached['round_start_ms'] ?? null) === $roundStartMs) {
                    $priceToBeat = (float) $cached['price_to_beat'];
                }
                $delta = $price - $priceToBeat;
                $upCents = max($delta, 0.0) * 100.0;
                $downCents = max(-$delta, 0.0) * 100.0;
                $priceCache->writeCurrent($price, $timestampMs, $roundStartMs, $priceToBeat, round($upCents, 2), round($downCents, 2));
            }
        }
    }

    usleep(100000);
}
