<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/StateStore.php';
require_once __DIR__ . '/../src/PriceCache.php';
require_once __DIR__ . '/../src/MarketCache.php';

use Ratchet\Client\Connector;
use React\EventLoop\Loop;

date_default_timezone_set('Asia/Shanghai');

const WS_CHAINLINK = 'wss://ws-live-data.polymarket.com/';
const WS_CLOB = 'wss://ws-subscriptions-clob.polymarket.com/ws/market';

const HEADERS = [
    'Origin' => 'https://polymarket.com',
];

function current15mTs(): int
{
    return intdiv(time(), 900) * 900;
}

function logf(string $message): void
{
    echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
}

function fetchAssetId(string $gammaBase, int $ts): ?string
{
    $url = $gammaBase . $ts;
    logf('[FETCH] ' . $url);

    $payload = @json_decode(@file_get_contents($url), true);
    if (!is_array($payload) || empty($payload['clobTokenIds'])) {
        return null;
    }

    $ids = json_decode($payload['clobTokenIds'], true);
    if (!is_array($ids)) {
        return null;
    }

    return $ids[0] ?? null;
}

$config = require __DIR__ . '/../src/Config.php';
$stateStore = new StateStore($config['state']['path']);
$priceCache = new PriceCache($config['price']['cache_path']);
$marketCache = new MarketCache($config['market']['cache_path']);
$gammaBase = $config['polymarket']['gamma_base'] ?? 'https://gamma-api.polymarket.com/markets/slug/btc-updown-15m-';

$state = [
    'market_ts' => null,
    'asset_id' => null,
    'current_price' => null,
    'current_ts' => null,
    'price_to_beat' => null,
    'beat_locked' => false,
    'up_prob' => null,
    'down_prob' => null,
];

$loop = Loop::get();
$connector = new Connector($loop);

logf('=== BTC REALTIME FINAL FIXED VERSION START ===');

$chainlinkSub = json_encode([
    'action' => 'subscribe',
    'subscriptions' => [[
        'topic' => 'crypto_prices_chainlink',
        'type' => 'update',
        'filters' => '{"symbol":"btc/usd"}',
    ]],
]);

$connector(WS_CHAINLINK, [], HEADERS)->then(function ($conn) use (&$state, $chainlinkSub, $priceCache, $stateStore) {
    logf('[CHAINLINK] CONNECTED');
    $conn->send($chainlinkSub);

    $conn->on('message', function ($msg) use (&$state, $priceCache, $stateStore) {
        $payload = json_decode((string) $msg, true);
        if (($payload['topic'] ?? '') !== 'crypto_prices_chainlink') {
            return;
        }

        $state['current_price'] = (float) $payload['payload']['value'];
        $state['current_ts'] = (int) $payload['payload']['timestamp'];

        if (!$state['beat_locked'] && $state['market_ts']) {
            $state['price_to_beat'] = $state['current_price'];
            $state['beat_locked'] = true;
            logf('[PRICE_TO_BEAT LOCKED] ' . number_format($state['price_to_beat'], 2));
        }

        $ptb = $state['price_to_beat'] ?? $state['current_price'];
        $delta = $state['current_price'] - $ptb;
        $upCents = max($delta, 0.0) * 100.0;
        $downCents = max(-$delta, 0.0) * 100.0;
        $bucketStart = $state['market_ts'] ?? current15mTs();

        $priceCache->writeCurrent(
            $state['current_price'],
            $state['current_ts'],
            $bucketStart * 1000,
            $ptb,
            round($upCents, 2),
            round($downCents, 2)
        );

        $stateStore->write([
            'current_price' => $state['current_price'],
            'current_ts' => $state['current_ts'],
            'price_to_beat' => $ptb,
            'bucket_start' => $bucketStart,
            'up_cents' => round($upCents, 2),
            'down_cents' => round($downCents, 2),
            'up_prob' => $state['up_prob'],
            'down_prob' => $state['down_prob'],
            'slug' => sprintf('btc-updown-15m-%d', $bucketStart),
        ]);
    });
});

$clobConn = null;
$startClob = function () use (&$state, &$clobConn, $connector, $marketCache, $stateStore) {
    if (!$state['asset_id']) {
        return;
    }

    if ($clobConn) {
        $clobConn->close();
        $clobConn = null;
    }

    $connector(WS_CLOB, [], HEADERS)->then(function ($conn) use (&$state, &$clobConn, $marketCache, $stateStore) {
        $clobConn = $conn;
        logf('[CLOB] CONNECTED');

        $sub = json_encode([
            'type' => 'market',
            'assets_ids' => [$state['asset_id']],
        ], JSON_UNESCAPED_SLASHES);

        $conn->send($sub);
        logf('[CLOB] SUBSCRIBE SENT');

        $conn->on('message', function ($msg) use (&$state, $marketCache, $stateStore) {
            $raw = (string) $msg;
            if ($raw === '[]') {
                return;
            }

            $payload = json_decode($raw, true);
            if (($payload['event_type'] ?? '') !== 'price_change') {
                return;
            }

            foreach ($payload['price_changes'] as $change) {
                if ($change['side'] === 'BUY') {
                    $state['up_prob'] = (float) $change['price'] * 100;
                }
                if ($change['side'] === 'SELL') {
                    $state['down_prob'] = (float) $change['price'] * 100;
                }
            }

            if ($state['up_prob'] !== null || $state['down_prob'] !== null) {
                $cached = $marketCache->read() ?? [];
                $upValue = $state['up_prob'] ?? ($cached['up_price'] ?? 0);
                $downValue = $state['down_prob'] ?? ($cached['down_price'] ?? 0);
                $timestampMs = (int) ($payload['timestamp'] ?? (microtime(true) * 1000));
                $marketCache->write($upValue, $downValue, $timestampMs);

                $currentState = $stateStore->read();
                $stateStore->write(array_merge($currentState, [
                    'up_prob' => $upValue,
                    'down_prob' => $downValue,
                ]));
            }
        });
    });
};

$loop->addPeriodicTimer(1, function () use (&$state, $gammaBase, $startClob, $stateStore) {
    $ts = current15mTs();
    if ($state['market_ts'] === $ts) {
        return;
    }

    logf('[TIME] new 15m bucket ' . date('H:i:s', $ts));

    $state['market_ts'] = $ts;
    $state['asset_id'] = fetchAssetId($gammaBase, $ts);
    $state['price_to_beat'] = null;
    $state['beat_locked'] = false;
    $state['up_prob'] = null;
    $state['down_prob'] = null;

    $stateStore->write([
        'bucket_start' => $ts,
        'slug' => sprintf('btc-updown-15m-%d', $ts),
        'price_to_beat' => $state['price_to_beat'],
        'up_prob' => $state['up_prob'],
        'down_prob' => $state['down_prob'],
    ]);

    $startClob();
});

$loop->run();
