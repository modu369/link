<?php

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/StateStore.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = require __DIR__ . '/../../src/Config.php';
$stateStore = new StateStore($config['state']['path']);
$payload = $stateStore->read();

echo json_encode([
    'current_price' => $payload['current_price'] ?? null,
    'current_timestamp_ms' => $payload['current_ts'] ?? null,
    'price_to_beat' => $payload['price_to_beat'] ?? null,
    'up_position' => $payload['up_prob'] ?? ($payload['up_cents'] ?? null),
    'down_position' => $payload['down_prob'] ?? ($payload['down_cents'] ?? null),
    'bucket_start' => $payload['bucket_start'] ?? null,
    'slug' => $payload['slug'] ?? null,
]);
