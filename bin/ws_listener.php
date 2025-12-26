<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PriceCache.php';
require_once __DIR__ . '/../src/MarketCache.php';

use Ratchet\Client\Connector;
use React\EventLoop\Loop;

$config = require __DIR__ . '/../src/Config.php';
$timezone = $config['polymarket']['timezone'] ?? 'Asia/Shanghai';
$gammaBase = $config['polymarket']['gamma_base'] ?? 'https://gamma-api.polymarket.com/markets/slug/';
$slugTemplate = $config['polymarket']['event_slug_template'] ?? 'btc-updown-15m-%d';

date_default_timezone_set($timezone);

const WS_CHAINLINK = 'wss://ws-live-data.polymarket.com/';
const WS_CLOB = 'wss://ws-subscriptions-clob.polymarket.com/ws/market';

$priceCache = new PriceCache($config['price']['cache_path']);
$marketCache = new MarketCache($config['market']['cache_path']);

$state = [
    'bucket_start' => null,
    'asset_id' => null,
    'price_to_beat' => null,
    'beat_locked' => false,
    'current_price' => null,
    'current_ts' => null,
    'up_prob' => null,
    'down_prob' => null,
];

function floorToQuarterBucket(int $timestampSeconds): int
{
    return intdiv($timestampSeconds, 900) * 900;
}

function currentBucket(): int
{
    return floorToQuarterBucket(time());
}

function resolveSlug(string $template, int $bucketStart): string
{
    if (str_contains($template, '{timestamp}')) {
        return str_replace('{timestamp}', (string) $bucketStart, $template);
    }

    if (str_contains($template, '%d')) {
        return sprintf($template, $bucketStart);
    }

    return $template;
}

function fetchAssetId(string $gammaBase, string $slug): ?string
{
    $url = $gammaBase . $slug;
    $response = @file_get_contents($url);
    if ($response === false) {
        return null;
    }

    $payload = json_decode($response, true);
    if (!is_array($payload) || !isset($payload['clobTokenIds'])) {
        return null;
    }

    $ids = json_decode($payload['clobTokenIds'], true);
    if (!is_array($ids) || $ids === []) {
        return null;
    }

    return $ids[0] ?? null;
}

$loop = Loop::get();
$connector = new Connector($loop, null, [
    'Origin' => 'https://polymarket.com',
]);

$chainlinkSub = json_encode([
    'action' => 'subscribe',
    'subscriptions' => [[
        'topic' => 'crypto_prices_chainlink',
        'type' => 'update',
        'filters' => '{"symbol":"btc/usd"}',
    ]],
]);

$connector(WS_CHAINLINK)->then(function ($conn) use (&$state, $chainlinkSub, $priceCache) {
    $conn->send($chainlinkSub);

    $conn->on('message', function ($msg) use (&$state, $priceCache) {
        $payload = json_decode((string) $msg, true);
        if (!is_array($payload) || ($payload['topic'] ?? '') !== 'crypto_prices_chainlink') {
            return;
        }

        $data = $payload['payload'] ?? [];
        $price = isset($data['value']) ? (float) $data['value'] : null;
        $timestampMs = (int) ($data['timestamp'] ?? 0);
        if ($price === null || $timestampMs <= 0) {
            return;
        }

        $timestampSeconds = intdiv($timestampMs, 1000);
        $bucketStart = floorToQuarterBucket($timestampSeconds);

        $state['current_price'] = $price;
        $state['current_ts'] = $timestampMs;

        if ($state['bucket_start'] !== $bucketStart) {
            $state['bucket_start'] = $bucketStart;
            $state['price_to_beat'] = $price;
            $state['beat_locked'] = true;
        }

        if (!$state['beat_locked'] && $state['bucket_start'] !== null) {
            $state['price_to_beat'] = $price;
            $state['beat_locked'] = true;
        }

        $ptb = $state['price_to_beat'] ?? $price;
        $delta = $price - $ptb;
        $upCents = max($delta, 0.0) * 100.0;
        $downCents = max(-$delta, 0.0) * 100.0;

        $priceCache->writeCurrent(
            $price,
            $timestampMs,
            $bucketStart * 1000,
            $ptb,
            round($upCents, 2),
            round($downCents, 2)
        );
    });
});

$clobConn = null;
$startClob = function () use (&$state, &$clobConn, $connector, $marketCache) {
    if (!$state['asset_id']) {
        return;
    }

    if ($clobConn) {
        $clobConn->close();
        $clobConn = null;
    }

    $connector(WS_CLOB)->then(function ($conn) use (&$state, &$clobConn, $marketCache) {
        $clobConn = $conn;

        $sub = json_encode([
            'type' => 'market',
            'assets_ids' => [$state['asset_id']],
        ], JSON_UNESCAPED_SLASHES);
        $conn->send($sub);

        $conn->on('message', function ($msg) use (&$state, $marketCache) {
            $payload = json_decode((string) $msg, true);
            if (!is_array($payload) || ($payload['event_type'] ?? '') !== 'price_change') {
                return;
            }

            foreach ($payload['price_changes'] ?? [] as $change) {
                if (($change['side'] ?? '') === 'BUY') {
                    $state['up_prob'] = (float) $change['price'] * 100;
                }
                if (($change['side'] ?? '') === 'SELL') {
                    $state['down_prob'] = (float) $change['price'] * 100;
                }
            }

            if ($state['up_prob'] !== null || $state['down_prob'] !== null) {
                $cached = $marketCache->read() ?? [];
                $upValue = $state['up_prob'] ?? ($cached['up_price'] ?? 0);
                $downValue = $state['down_prob'] ?? ($cached['down_price'] ?? 0);
                $timestampMs = (int) ($payload['timestamp'] ?? (microtime(true) * 1000));
                $marketCache->write($upValue, $downValue, $timestampMs);
            }
        });
    });
};

$loop->addPeriodicTimer(1, function () use (&$state, $gammaBase, $slugTemplate, $startClob) {
    $bucketStart = currentBucket();
    if ($state['bucket_start'] === $bucketStart) {
        return;
    }

    $state['bucket_start'] = $bucketStart;
    $slug = resolveSlug($slugTemplate, $bucketStart);
    $state['asset_id'] = fetchAssetId($gammaBase, $slug);
    $state['price_to_beat'] = null;
    $state['beat_locked'] = false;
    $state['up_prob'] = null;
    $state['down_prob'] = null;

    $startClob();
});

$loop->run();
