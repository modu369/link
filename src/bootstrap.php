<?php

$config = require __DIR__ . '/../config.php';

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/PolymarketClient.php';
require_once __DIR__ . '/MarketService.php';
require_once __DIR__ . '/TradeService.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Db($config['db']);
$client = new PolymarketClient($config['polymarket']);
$marketService = new MarketService($client, $db, $config['polymarket']['up_label'], $config['polymarket']['down_label']);
$tradeService = new TradeService($client, $db);
