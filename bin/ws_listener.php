<?php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PriceCache.php';
require_once __DIR__ . '/../src/MarketCache.php';
require_once __DIR__ . '/../src/WebSocketClient.php';

$config = require __DIR__ . '/../src/Config.php';
$cachePath = $config['price']['cache_path'] ?? __DIR__ . '/../storage/price.json';
$marketCachePath = $config['market']['cache_path'] ?? __DIR__ . '/../storage/market.json';

$priceCache = new PriceCache($cachePath);
$marketCache = new MarketCache($marketCachePath);

$liveClient = new WebSocketClient($config['polymarket']['ws_live_url']);
$marketClient = new WebSocketClient($config['polymarket']['ws_market_url']);

$liveClient->connect();
$liveClient->send(json_encode([
    'action' => 'subscribe',
    'subscriptions' => [
        [
            'topic' => 'crypto_prices',
            'type' => 'subscribe',
            'filters' => json_encode(['symbol' => 'btc/usd']),
        ],
    ],
]));

$marketClient->connect();
$assetIds = array_filter([$config['polymarket']['up_asset_id'], $config['polymarket']['down_asset_id']]);
if ($assetIds !== []) {
    $marketClient->send(json_encode([
        'assets_ids' => $assetIds,
        'type' => 'market',
    ]));
}

while (true) {
    $liveMessage = $liveClient->receive();
    if ($liveMessage !== null) {
        $payload = json_decode($liveMessage, true);
        if (is_array($payload) && in_array(($payload['topic'] ?? ''), ['crypto_prices', 'crypto_prices_chainlink'], true)) {
            $data = $payload['payload']['data'] ?? [];
            $timestampMs = 0;
            $price = null;
            if (is_array($data) && $data !== []) {
                $latest = end($data);
                $timestampMs = (int) ($latest['timestamp'] ?? 0);
                $price = isset($latest['value']) ? (float) $latest['value'] : null;
            } else {
                $value = $payload['payload']['full_accuracy_value'] ?? null;
                if ($value !== null && is_numeric($value)) {
                    $price = (float) $value / 1e18;
                    $timestampMs = (int) ($payload['payload']['timestamp'] ?? $payload['timestamp'] ?? (microtime(true) * 1000));
                }
            }

            if ($timestampMs > 0 && $price !== null) {
                $roundStartMs = $timestampMs - ($timestampMs % 900000);
                $cached = $priceCache->read();
                $priceToBeat = $price;
                if (is_array($cached) && ($cached['round_start_ms'] ?? null) === $roundStartMs) {
                    $priceToBeat = (float) $cached['price_to_beat'];
                }
                $priceCache->writeCurrent($price, $timestampMs, $roundStartMs, $priceToBeat);
            }
        }
    }

    $marketMessage = $marketClient->receive();
    if ($marketMessage !== null) {
        $payload = json_decode($marketMessage, true);
        if (is_array($payload) && ($payload['event_type'] ?? '') === 'price_change') {
            $changes = $payload['price_changes'] ?? [];
            $up = null;
            $down = null;
            foreach ($changes as $change) {
                $side = $change['side'] ?? '';
                $assetId = $change['asset_id'] ?? '';
                $price = isset($change['price']) ? (float) $change['price'] * 100 : null;
                if ($price === null) {
                    continue;
                }

                if ($config['polymarket']['up_asset_id'] !== '' && $assetId === $config['polymarket']['up_asset_id'] && $side === 'BUY') {
                    $up = $price;
                } elseif ($config['polymarket']['down_asset_id'] !== '' && $assetId === $config['polymarket']['down_asset_id'] && $side === 'SELL') {
                    $down = $price;
                } elseif ($config['polymarket']['up_asset_id'] === '' && $side === 'BUY') {
                    $up = $price;
                } elseif ($config['polymarket']['down_asset_id'] === '' && $side === 'SELL') {
                    $down = $price;
                }
            }
            if ($up !== null || $down !== null) {
                $cached = $marketCache->read() ?? [];
                $upValue = $up ?? ($cached['up_price'] ?? 0);
                $downValue = $down ?? ($cached['down_price'] ?? 0);
                $timestampMs = (int) ($payload['timestamp'] ?? (microtime(true) * 1000));
                $marketCache->write($upValue, $downValue, $timestampMs);
            }
        }
    }

    usleep(100000);
}
