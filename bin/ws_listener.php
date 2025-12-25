<?php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PriceCache.php';
require_once __DIR__ . '/../src/WebSocketClient.php';

$config = require __DIR__ . '/../src/Config.php';
$cachePath = $config['price']['cache_path'] ?? __DIR__ . '/../storage/price.json';

$client = new WebSocketClient('wss://ws-live-data.polymarket.com');
$cache = new PriceCache($cachePath);

$client->connect();
$client->send(json_encode([
    'action' => 'subscribe',
    'subscriptions' => [
        [
            'topic' => 'crypto_prices_chainlink',
            'type' => 'update',
            'filters' => json_encode(['symbol' => 'btc/usd']),
        ],
    ],
]));

while (true) {
    $message = $client->receive();
    if ($message === null) {
        usleep(200000);
        continue;
    }

    $payload = json_decode($message, true);
    if (!is_array($payload)) {
        continue;
    }

    if (($payload['topic'] ?? '') !== 'crypto_prices_chainlink' || ($payload['type'] ?? '') !== 'update') {
        continue;
    }

    $value = $payload['payload']['full_accuracy_value'] ?? null;
    if ($value === null || !is_numeric($value)) {
        continue;
    }

    $price = (float) $value / 1e18;
    $timestampMs = (int) ($payload['payload']['timestamp'] ?? $payload['timestamp'] ?? (microtime(true) * 1000));
    $cache->write($price, $timestampMs);
}
