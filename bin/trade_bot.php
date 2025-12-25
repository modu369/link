<?php

require_once __DIR__ . '/../src/bootstrap.php';

$eventSlug = $config['polymarket']['event_slug'];
$snapshotResponse = $marketService->fetchMarketSnapshot($eventSlug !== '' ? $eventSlug : null, false);
if (!$snapshotResponse['ok']) {
    fwrite(STDERR, "Failed to fetch market snapshot: " . json_encode($snapshotResponse) . "\n");
    exit(1);
}

$snapshot = $snapshotResponse['data'];
$actions = $tradeService->autoTrade(
    $snapshot,
    $config['polymarket']['buy_threshold'],
    $config['polymarket']['sell_threshold'],
    $config['polymarket']['order_size']
);

fwrite(STDOUT, json_encode(['ok' => true, 'actions' => $actions], JSON_UNESCAPED_SLASHES) . "\n");
