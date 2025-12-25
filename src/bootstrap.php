<?php

$config = require __DIR__ . '/../config.php';

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/PriceFeed.php';
require_once __DIR__ . '/PolymarketClient.php';
require_once __DIR__ . '/MarketService.php';
require_once __DIR__ . '/TradeService.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Db($config['db']);
$client = new PolymarketClient($config['polymarket']);
$priceHttp = new HttpClient($config['price_feed']['base_url']);
$priceFeed = new PriceFeed($priceHttp, $config['price_feed']['endpoint'], $config['price_feed']['price_path']);
$marketService = new MarketService(
    $client,
    $db,
    $priceFeed,
    $config['polymarket']['event_slug_prefix'],
    $config['polymarket']['event_interval_seconds'],
    $config['polymarket']['up_label'],
    $config['polymarket']['down_label']
);
$tradeService = new TradeService($client, $db);
